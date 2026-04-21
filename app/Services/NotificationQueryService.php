<?php

namespace App\Services;

use App\Enums\NotificationCategory;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Read-side service for querying and grouping notifications.
 *
 * Provides optimised queries for:
 *   - Bell dropdown: recent grouped notifications for quick scan.
 *   - Full history: paginated grouped notifications for /notifications page.
 *   - Unread count: badge counter.
 *
 * Grouping collapses notifications of the same type that fall within the same
 * calendar day (midnight-to-midnight, server timezone) into a single visual
 * row with an aggregated display string and actor list.
 */
class NotificationQueryService
{
    /**
     * Map notification class short-names to the data JSON key that holds the
     * actor's display name.  Used for building grouped display strings.
     *
     * When a notification type is not listed here (e.g. system status changes
     * with no actor), the actor extraction is skipped.
     */
    private const ACTOR_NAME_KEYS = [
        'NewFollower'          => 'follower_name',
        'GameInvitation'       => 'inviter_name',
        'CampaignInvitation'   => 'inviter_name',
        'TeamInvitation'       => 'inviter_name',
        'NewApplication'       => 'applicant_name',
        'ApplicationApproved'  => 'approver_name',
        'ApplicationRejected'  => 'rejector_name',
        'ParticipantJoined'    => 'participant_name',
        'ParticipantRemoved'   => 'remover_name',
        'TeamMemberRemoved'    => 'remover_name',
        'SessionAddedToCampaign' => 'dm_name',
    ];

    /**
     * Get the count of unread notifications for a user.
     */
    public function getUnreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    /**
     * Get recent notifications grouped by type + calendar day.
     *
     * Returns a collection of grouped view models suitable for the bell
     * dropdown. Each item contains:
     *   - type           — short class name of the notification
     *   - category       — NotificationCategory value or null
     *   - count          — number of notifications in this group
     *   - latest         — the most recent DatabaseNotification in the group
     *   - actor_names    — unique list of actor display names
     *   - display_string — human-readable summary (e.g. "Alice and Bob followed you")
     *   - is_read        — whether all notifications in the group are read
     *   - group_key      — unique key for frontend (type_date)
     *
     * @return Collection<int, object>
     */
    public function getGroupedForUser(User $user, int $limit = 10): Collection
    {
        $notifications = $user->notifications()
            ->orderBy('created_at', 'desc')
            ->limit($limit * 3) // fetch extra to allow grouping collapse
            ->get();

        return $this->buildGroups($notifications, $limit);
    }

    /**
     * Get a paginated list of all notifications grouped by type + calendar day.
     *
     * The paginator wraps the ungrouped count; each page contains grouped
     * view models collapsed from the raw notifications on that page.
     */
    public function getPaginatedForUser(User $user, int $perPage = 20): LengthAwarePaginator
    {
        $paginator = $user->notifications()
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $groups = $this->buildGroups(collect($paginator->items()), null);

        // Replace items with grouped collection while keeping paginator metadata
        $paginator->setCollection($groups);

        return $paginator;
    }

    /**
     * Build grouped view models from a flat notification collection.
     *
     * Grouping key: notification type short-name + date string (Y-m-d).
     * Within each group, actor names are deduplicated and used to build
     * a display string like "Alice followed you" or "Alice, Bob, and 3 others followed you".
     *
     * @param Collection $notifications  Flat list of DatabaseNotification models
     * @param int|null   $maxGroups      Cap on returned groups (null = no cap)
     * @return Collection<int, object>
     */
    protected function buildGroups(Collection $notifications, ?int $maxGroups): Collection
    {
        $groups = [];

        foreach ($notifications as $notification) {
            $shortType = (new \ReflectionClass($notification->type))->getShortName();
            $dateKey = $notification->created_at->toDateString();
            $groupKey = "{$shortType}_{$dateKey}";

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = (object) [
                    'type'           => $shortType,
                    'full_type'      => $notification->type,
                    'category'       => $this->resolveCategory($shortType),
                    'count'          => 0,
                    'latest'         => $notification,
                    'actor_names'    => [],
                    'is_read'        => true,
                    'group_key'      => $groupKey,
                    'created_at'     => $notification->created_at,
                ];
            }

            $group = $groups[$groupKey];
            $group->count++;

            // Update latest to the most recent notification
            if ($notification->created_at->gt($group->latest->created_at)) {
                $group->latest = $notification;
            }

            // Track unread state — group is unread if ANY notification is unread
            if ($notification->read_at === null) {
                $group->is_read = false;
            }

            // Extract actor name from data JSON
            $actorName = $this->extractActorName($shortType, $notification->data);
            if ($actorName !== null && !in_array($actorName, $group->actor_names, true)) {
                $group->actor_names[] = $actorName;
            }
        }

        // Sort groups by latest notification date (desc)
        $sorted = collect($groups)->sortByDesc(fn ($g) => $g->created_at->timestamp);

        // Build display strings and apply cap
        $result = $sorted->values()->map(function ($group) {
            $group->display_string = $this->buildDisplayString($group);
            return $group;
        });

        if ($maxGroups !== null) {
            $result = $result->take($maxGroups);
        }

        return $result;
    }

    /**
     * Resolve the NotificationCategory enum value for a notification type short-name.
     */
    protected function resolveCategory(string $shortType): ?string
    {
        try {
            $category = NotificationCategory::from(
                \Illuminate\Support\Str::snake($shortType)
            );
            return $category->value;
        } catch (\ValueError) {
            return null;
        }
    }

    /**
     * Extract the actor display name from a notification's data payload.
     *
     * Uses the ACTOR_NAME_KEYS map to find the correct JSON key for each
     * notification type. Returns null for types without an actor (e.g. status changes).
     *
     * @param array<string, mixed> $data
     */
    protected function extractActorName(string $shortType, array $data): ?string
    {
        $nameKey = self::ACTOR_NAME_KEYS[$shortType] ?? null;

        if ($nameKey === null) {
            return null;
        }

        return $data[$nameKey] ?? null;
    }

    /**
     * Build a human-readable display string for a grouped notification.
     *
     * Pattern:
     *   - 1 actor:  "Alice followed you"
     *   - 2 actors: "Alice and Bob followed you"
     *   - 3+ actors: "Alice, Bob, and 3 others followed you"
     *   - No actors: use the notification's data type label as fallback
     */
    protected function buildDisplayString(object $group): string
    {
        $actors = $group->actor_names;
        $count = count($actors);
        $verb = $this->resolveVerb($group->type);

        if ($count === 0) {
            // No actor — use the latest notification's data for context
            $data = $group->latest->data;
            $entityName = $data['entity_name'] ?? $data['game_name'] ?? $data['campaign_name'] ?? null;

            if ($entityName !== null) {
                return "{$verb}: {$entityName}";
            }

            return $verb;
        }

        if ($count === 1) {
            return "{$actors[0]} {$verb}";
        }

        if ($count === 2) {
            return "{$actors[0]} and {$actors[1]} {$verb}";
        }

        $others = $count - 2;
        return "{$actors[0]}, {$actors[1]}, and {$others} other" . ($others > 1 ? 's' : '') . " {$verb}";
    }

    /**
     * Resolve a human-readable verb phrase for a notification type.
     *
     * The verb describes what happened — e.g. "followed you", "invited you to a game".
     * Falls back to the snake_case type as a generic label.
     */
    protected function resolveVerb(string $shortType): string
    {
        return match ($shortType) {
            'NewFollower'           => 'followed you',
            'GameInvitation'        => 'invited you to a game',
            'CampaignInvitation'    => 'invited you to a campaign',
            'TeamInvitation'        => 'invited you to a team',
            'SessionAddedToCampaign' => 'added a session to your campaign',
            'NewApplication'        => 'applied to join',
            'ApplicationApproved'   => 'approved your application',
            'ApplicationRejected'   => 'rejected your application',
            'ParticipantJoined'     => 'joined',
            'ParticipantRemoved'    => 'removed you from',
            'TeamMemberRemoved'     => 'removed you from a team',
            'GameCancelled'         => 'Game cancelled',
            'GameCompleted'         => 'Game completed',
            'CampaignCancelled'     => 'Campaign cancelled',
            'CampaignCompleted'     => 'Campaign completed',
            default                 => str_replace('_', ' ', \Illuminate\Support\Str::snake($shortType)),
        };
    }
}
