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
        'EntityInvitation'     => 'inviter_name',
        'TeamInvitation'       => 'inviter_name',
        'NewApplication'       => 'applicant_name',
        'ApplicationApproved'  => 'approver_name',
        'ApplicationRejected'  => 'rejector_name',
        'ParticipantJoined'    => 'participant_name',
        'ParticipantRemoved'   => 'remover_name',
        'TeamMemberRemoved'    => 'remover_name',
        'SessionAddedToCampaign' => 'dm_name',
        // Legacy class names — kept for records created before the
        // consolidate_notification_types migration ran.
        'GameInvitation'       => 'inviter_name',
        'CampaignInvitation'   => 'inviter_name',
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
                    'category'       => $this->resolveCategory($shortType, $notification->data),
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
     *
     * Two-tier resolution:
     *  1. Snake-case the short class name and match against the enum (e.g. 'GameInvitation' → 'game_invitation').
     *     This works for non-unified classes whose names map directly to enum cases.
     *  2. Fall back to the notification's data payload `type` field (e.g. 'game_invitation').
     *     Required for unified notification classes (EntityInvitation, EntityCancelled, etc.)
     *     whose class names don't match any enum case. The data.type field carries the
     *     entity-specific discriminator.
     *
     * Contract: Unified notification classes must always set data.type in toDatabase().
     *
     * @param  string  $shortType  Short class name (e.g. 'EntityInvitation')
     * @param  array<string, mixed>  $data  Notification data payload
     */
    protected function resolveCategory(string $shortType, array $data): ?string
    {
        // Try class-name-based resolution first
        try {
            $category = NotificationCategory::from(
                \Illuminate\Support\Str::snake($shortType)
            );
            return $category->value;
        } catch (\ValueError) {
            // Class name didn't match — try the data payload's type field
        }

        // Fall back to the notification data's type (e.g. 'game_invitation')
        $dataType = $data['type'] ?? null;
        if ($dataType !== null) {
            try {
                $category = NotificationCategory::from($dataType);
                return $category->value;
            } catch (\ValueError) {
                return null;
            }
        }

        return null;
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
        $verb = $this->resolveVerb($group->type, $group->latest->data ?? []);

        if ($count === 0) {
            // No actor — use the latest notification's data for context
            $data = $group->latest->data;
            $entityName = $data['entity_name'] ?? $data['game_name'] ?? $data['campaign_name'] ?? null;

            if ($entityName !== null) {
                return __('notifications.display_no_actor_with_entity', [
                    'verb' => $verb,
                    'entity' => $entityName,
                ]);
            }

            return __('notifications.display_no_actor', ['verb' => $verb]);
        }

        if ($count === 1) {
            return __('notifications.display_one_actor', [
                'actor' => $actors[0],
                'verb' => $verb,
            ]);
        }

        if ($count === 2) {
            return __('notifications.display_two_actors', [
                'actor1' => $actors[0],
                'actor2' => $actors[1],
                'verb' => $verb,
            ]);
        }

        $others = $count - 2;
        return trans_choice('notifications.display_many_actors', $others, [
            'actor1' => $actors[0],
            'actor2' => $actors[1],
            'count' => $others,
            'verb' => $verb,
        ]);
    }

    /**
     * Resolve a human-readable verb phrase for a notification type.
     *
     * Three-tier resolution:
     *  1. Translation key from snake-cased short class name (notifications.verb_game_invitation).
     *  2. Translation key from data.type field (notifications.verb_game_invitation via 'game_invitation').
     *     Required for unified notification classes whose class names don't have dedicated
     *     translation keys. The data.type field carries the entity-specific discriminator.
     *  3. Humanized fallback from the snake-cased short type.
     *
     * Contract: Unified notification classes must always set data.type in toDatabase().
     *
     * @param  string  $shortType  Short class name (e.g. 'EntityInvitation')
     * @param  array<string, mixed>  $data  Notification data payload
     */
    protected function resolveVerb(string $shortType, array $data): string
    {
        $key = 'notifications.verb_' . \Illuminate\Support\Str::snake($shortType);

        $translated = __($key);

        // If the translation key doesn't exist, __() returns the key itself —
        // try the notification data's type field for unified notification classes.
        if ($translated === $key) {
            $dataType = $data['type'] ?? null;
            if ($dataType !== null) {
                $dataKey = 'notifications.verb_' . $dataType;
                $dataTranslated = __($dataKey);
                if ($dataTranslated !== $dataKey) {
                    return $dataTranslated;
                }
            }

            return str_replace('_', ' ', \Illuminate\Support\Str::snake($shortType));
        }

        return $translated;
    }
}
