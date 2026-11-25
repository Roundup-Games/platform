<?php

namespace App\Services;

use App\Dto\NotificationGroup;
use App\Enums\NotificationCategory;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
        'NewFollower' => 'follower_name',
        'EntityInvitation' => 'inviter_name',
        'TeamInvitation' => 'inviter_name',
        'NewApplication' => 'applicant_name',
        'ApplicationApproved' => 'approver_name',
        'ApplicationRejected' => 'rejector_name',
        'ParticipantJoined' => 'participant_name',
        'ParticipantRemoved' => 'remover_name',
        'TeamMemberRemoved' => 'remover_name',
        'SessionAddedToCampaign' => 'dm_name',
        'CharterFullySigned' => 'signer_name',
        'RosterReinviteInvitation' => 'inviter_name',
        // Legacy class names — kept for records created before the
        // consolidate_notification_types migration ran.
        'GameInvitation' => 'inviter_name',
        'CampaignInvitation' => 'inviter_name',
    ];

    /**
     * Map notification class short-names to the data JSON key that holds the
     * actor's user ID. Used for building profile links.
     */
    private const ACTOR_ID_KEYS = [
        'NewFollower' => 'follower_id',
        'EntityInvitation' => 'inviter_id',
        'TeamInvitation' => 'inviter_id',
        'NewApplication' => 'applicant_id',
        'ApplicationApproved' => 'approver_id',
        'ApplicationRejected' => 'rejector_id',
        'ParticipantJoined' => 'participant_id',
        'ParticipantRemoved' => 'removed_user_id',
        'TeamMemberRemoved' => 'removed_user_id',
        'SessionAddedToCampaign' => 'dm_id',
        'CharterFullySigned' => 'signer_id',
        'RosterReinviteInvitation' => 'inviter_id',
        'GameInvitation' => 'inviter_id',
        'CampaignInvitation' => 'inviter_id',
    ];

    /**
     * Map notification class short-names to the data JSON keys for entity
     * name and ID. Used for building entity links in display strings.
     *
     * Each entry maps to ['name' => key, 'id' => key].
     */
    private const TARGET_ENTITY_KEYS = [
        'NewApplication' => ['name' => 'entity_name', 'id' => 'entity_id'],
        'ParticipantJoined' => ['name' => 'entity_name', 'id' => 'entity_id'],
        'ApplicationApproved' => ['name' => 'entity_name', 'id' => 'entity_id'],
        'ApplicationRejected' => ['name' => 'entity_name', 'id' => 'entity_id'],
        'ParticipantRemoved' => ['name' => 'entity_name', 'id' => 'entity_id'],
        'EntityCompleted' => ['name' => 'entity_name', 'id' => 'entity_id'],
        'EntityUpdated' => ['name' => 'entity_name', 'id' => 'entity_id'],
        'EntityCancelled' => ['name' => 'entity_name', 'id' => 'entity_id'],
        'WaitlistPromoted' => ['name' => 'entity_name', 'id' => 'entity_id'],
        'WaitlistPlaced' => ['name' => 'entity_name', 'id' => 'entity_id'],
        'PlayerBenched' => ['name' => 'entity_name', 'id' => 'entity_id'],
        'PromotedFromBench' => ['name' => 'entity_name', 'id' => 'entity_id'],
        'ConfirmationExpired' => ['name' => 'entity_name', 'id' => 'entity_id'],
        'SessionReminder' => ['name' => 'entity_name', 'id' => 'entity_id'],
        'BelowMinPlayersWarning' => ['name' => 'entity_name', 'id' => 'entity_id'],
        'SessionAddedToCampaign' => ['name' => 'campaign_name', 'id' => 'campaign_id'],
        'EntityInvitation' => null, // Dynamic — resolved at runtime
        'TeamInvitation' => ['name' => 'team_name', 'id' => null],
        'NewFollower' => null,
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
     *   - group_key      — unique key for frontend (type_date, extended per
     *                      burst for BatchedNotification rows — see groupKeyFor())
     *
     * @return Collection<int, NotificationGroup>
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
     *
     * @return \Illuminate\Pagination\LengthAwarePaginator<int, NotificationGroup>
     */
    public function getPaginatedForUser(User $user, int $perPage = 20): LengthAwarePaginator
    {
        $paginator = $user->notifications()
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $groups = $this->buildGroups(collect($paginator->items()), null);

        // Replace items with grouped collection while keeping paginator metadata.
        // A fresh paginator is built (typed for NotificationGroup) rather than
        // repurposing the DatabaseNotification-typed one, so the item type stays honest.
        /** @var \Illuminate\Pagination\LengthAwarePaginator<int, NotificationGroup> $grouped */
        $grouped = new \Illuminate\Pagination\LengthAwarePaginator(
            $groups,
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
            $paginator->getOptions(),
        );

        return $grouped;
    }

    /**
     * Derive the grouping key for one notification row (M082/S02 display
     * follow-up — the single source both buildGroups and the notifications
     * page's expanded-individuals lookup derive from, so the key can never
     * drift between writer and reader).
     *
     * Base shape: "{shortType}_{Y-m-d}". BatchedNotification rows extend
     * it to "{shortType}:{underlying category}:{entity uuid}_{Y-m-d}": a
     * flushed burst is already entity-scoped aggregation, so two bursts
     * that share a day but not a category/entity must NEVER merge — the
     * merged row would attribute one burst's actors to the other's verb
     * ("Sarah applied" while Sarah joined). Rows missing either binding
     * (malformed payloads) fall back to the base shape and degrade exactly
     * as they did before the split.
     *
     * @param  array<string, mixed>  $data
     */
    public static function groupKeyFor(string $shortType, array $data, string $dateKey): string
    {
        if ($shortType === 'BatchedNotification') {
            $category = $data['type'] ?? null;
            $entityId = $data['entity_id'] ?? null;

            if (is_string($category) && $category !== ''
                && is_string($entityId) && $entityId !== '') {
                return "BatchedNotification:{$category}:{$entityId}_{$dateKey}";
            }
        }

        return "{$shortType}_{$dateKey}";
    }

    /**
     * Parse a group key back into its filter parts — the inverse of
     * groupKeyFor() the bell's and the page's markAsRead use to scope
     * their read updates, and expandedGroupIndividuals uses to scope its
     * row fetch. Legacy "{shortType}_{date}" keys parse identically to
     * the pre-split format (category and entity stay null).
     *
     * @return null|array{0: string, 1: string, 2: string|null, 3: string|null}
     *                                                                          [shortType, dateString, ?category, ?entityId] — null when the
     *                                                                          key carries no date segment.
     */
    public static function parseGroupKey(string $groupKey): ?array
    {
        $parts = explode('_', $groupKey);
        $dateString = array_pop($parts);
        $head = implode('_', $parts);

        if ($head === '' || $dateString === '') {
            return null;
        }

        // "{shortType}:{category}:{entityId}" — segments beyond the first
        // are optional; a legacy plain shortType yields two nulls.
        $segments = explode(':', $head, 3);
        $shortType = $segments[0];
        $category = isset($segments[1]) && $segments[1] !== '' ? $segments[1] : null;
        $entityId = isset($segments[2]) && $segments[2] !== '' ? $segments[2] : null;

        return [$shortType, $dateString, $category, $entityId];
    }

    /**
     * Build grouped view models from a flat notification collection.
     *
     * Grouping key: notification type short-name + date string (Y-m-d) —
     * extended per Burst for BatchedNotification rows (see groupKeyFor()).
     * Within each group, actor names are deduplicated and used to build
     * a display string like "Alice followed you" or "Alice, Bob, and 3 others followed you".
     *
     * @param  Collection<int, DatabaseNotification>  $notifications  Flat list of DatabaseNotification models
     * @param  int|null  $maxGroups  Cap on returned groups (null = no cap)
     * @return Collection<int, NotificationGroup>
     */
    protected function buildGroups(Collection $notifications, ?int $maxGroups): Collection
    {
        /** @var array<string, NotificationGroup> $groups */
        $groups = [];

        foreach ($notifications as $notification) {
            $type = $notification->type;
            $shortType = class_exists($type) ? (new \ReflectionClass($type))->getShortName() : $type;
            $dateKey = ($notification->created_at ?? now())->toDateString();
            $groupKey = self::groupKeyFor($shortType, $notification->data, $dateKey);

            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = new NotificationGroup(
                    type: $shortType,
                    fullType: $notification->type,
                    category: $this->resolveCategory($shortType, $notification->data) ?? '',
                    count: 0,
                    latest: $notification,
                    actorNames: [],
                    actorIds: [],
                    isRead: true,
                    groupKey: $groupKey,
                    createdAt: Carbon::instance($notification->created_at ?? now()),
                    actionUrl: $notification->data['action_url'] ?? null,
                );
            }

            $group = $groups[$groupKey];
            $group->count++;

            // Update latest to the most recent notification
            if (($notification->created_at ?? now())->gt($group->latest->created_at ?? now())) {
                $group->latest = $notification;
                $group->actionUrl = $notification->data['action_url'] ?? $group->actionUrl;
            }

            // Track unread state — group is unread if ANY notification is unread
            if ($notification->read_at === null) {
                $group->isRead = false;
            }

            // Extract actor name and ID from data JSON. Batched rows carry
            // position-aligned actor NAMES+IDS arrays (one flushed burst =
            // one row whose display lists every actor), so they expand into
            // the group here instead of the single-value maps (M082/S02
            // display follow-up — closes the subjectless-verb gap).
            if ($shortType === 'BatchedNotification'
                && isset($notification->data['actor_names'])
                && is_array($notification->data['actor_names'])) {
                $names = $notification->data['actor_names'];
                $ids = isset($notification->data['actor_ids']) && is_array($notification->data['actor_ids'])
                    ? $notification->data['actor_ids']
                    : [];

                foreach ($names as $index => $actorName) {
                    if (! is_string($actorName) || $actorName === '' || in_array($actorName, $group->actorNames, true)) {
                        continue;
                    }

                    $actorId = $ids[$index] ?? null;
                    $group->actorNames[] = $actorName;
                    $group->actorIds[] = is_string($actorId) && $actorId !== '' ? $actorId : null;
                }
            } else {
                $actorName = $this->extractActorName($shortType, $notification->data);
                $actorId = $this->extractActorId($shortType, $notification->data);

                if ($actorName !== null && ! in_array($actorName, $group->actorNames, true)) {
                    $group->actorNames[] = $actorName;
                    $group->actorIds[] = $actorId;
                }
            }
        }

        // Stabilize actor ordering within each group. Notifications that arrive
        // in the same second (common — e.g. a burst of follows) tie on
        // created_at, and ORDER BY created_at DESC is non-deterministic under
        // ties, so actorNames (and therefore displayString) can vary between
        // page loads. Sorting alphabetically makes the display string
        // deterministic for both tests and real users.
        foreach ($groups as $group) {
            if (count($group->actorNames) > 1) {
                array_multisort($group->actorNames, $group->actorIds, SORT_ASC, SORT_STRING);
            }
        }

        // Batch-resolve actor user IDs to profile URLs (single query)
        $actorIds = [];
        foreach ($groups as $g) {
            foreach ($g->actorIds as $id) {
                if ($id !== null && ! in_array($id, $actorIds, true)) {
                    $actorIds[] = $id;
                }
            }
        }
        $usernames = count($actorIds) > 0
            ? User::whereIn('id', $actorIds)->pluck('slug', 'id')
            : collect();

        // Sort groups by latest notification date (desc)
        $sorted = collect($groups)->sortByDesc(fn (NotificationGroup $g) => $g->createdAt->timestamp);

        // Build display strings and apply cap
        $result = $sorted->values()->map(function ($group) use ($usernames) {
            $group->displayHtml = $this->buildDisplayHtml($group, $usernames);
            // Keep plain-text version for accessibility / screen readers
            $group->displayString = $this->buildDisplayString($group);

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
                Str::snake($shortType)
            );

            return $category->value;
        } catch (\ValueError) {
            // Class name didn't match — try the data payload's type field
        }

        // Fall back to the notification data's type (e.g. 'game_invitation')
        $dataType = $data['type'] ?? null;
        if (is_string($dataType) || is_int($dataType)) {
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
     * @param  array<string, mixed>  $data
     */
    protected function extractActorName(string $shortType, array $data): ?string
    {
        $nameKey = self::ACTOR_NAME_KEYS[$shortType] ?? null;

        if ($nameKey === null) {
            return null;
        }

        $val = $data[$nameKey] ?? null;

        return is_string($val) ? $val : null;
    }

    /**
     * Extract the actor user ID from a notification's data payload.
     *
     * Uses the ACTOR_ID_KEYS map to find the correct JSON key for each
     * notification type. Returns null for types without an actor.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractActorId(string $shortType, array $data): ?string
    {
        $idKey = self::ACTOR_ID_KEYS[$shortType] ?? null;

        if ($idKey === null) {
            return null;
        }

        $val = $data[$idKey] ?? null;

        return is_string($val) ? $val : null;
    }

    /**
     * Build an HTML display string with clickable links for actor names and entity names.
     *
     * @param  NotificationGroup  $group  Grouped notification view model
     * @param  Collection<(int|string), mixed>  $usernames  Map of user_id => username
     */
    protected function buildDisplayHtml(NotificationGroup $group, Collection $usernames): string
    {
        $actors = $group->actorNames;
        $actorIds = $group->actorIds;
        $data = $group->latest->data ?? [];
        $actorCount = count($actors);
        $verb = $this->resolveVerb($group->type, $data, $actorCount);

        // Build linked actor names
        $linkedActors = [];
        for ($i = 0; $i < $actorCount; $i++) {
            $name = e($actors[$i]);
            $id = $actorIds[$i] ?? null;
            $username = $id !== null ? $usernames->get($id) : null;

            if ($username !== null && $username !== '') {
                $url = route('profile.public', ['locale' => app()->getLocale(), 'user' => $username]);
                $linkedActors[] = '<a href="'.e($url).'" wire:navigate class="font-medium text-primary hover:text-primary/80 transition-colors">'.$name.'</a>';
            } else {
                $linkedActors[] = '<span class="font-medium">'.$name.'</span>';
            }
        }

        // Build linked entity name
        $entityName = $this->extractEntityName($group->type, $data);
        $entityUrl = $group->actionUrl;
        $linkedEntity = null;

        if ($entityName !== null) {
            $escapedName = e($entityName);
            if ($entityUrl !== null && $entityUrl !== '') {
                $linkedEntity = '<a href="'.e($entityUrl).'" wire:navigate class="font-medium text-primary hover:text-primary/80 transition-colors">'.$escapedName.'</a>';
            } else {
                $linkedEntity = '<span class="font-medium">'.$escapedName.'</span>';
            }
        }

        // Compose the HTML display string
        if ($actorCount === 0) {
            if ($linkedEntity !== null) {
                return e($verb).': '.$linkedEntity;
            }

            return e($verb);
        }

        $actorHtml = $this->formatActorList($linkedActors);

        if ($linkedEntity !== null) {
            return $actorHtml.' '.e($verb).' '.$linkedEntity;
        }

        return $actorHtml.' '.e($verb);
    }

    /**
     * Format a list of linked actor names into HTML.
     *
     * Pattern:
     *   - 1 actor:  "Alice"
     *   - 2 actors: "Alice and Bob"
     *   - 3+ actors: "Alice, Bob, and 3 others"
     *
     * @param  list<string>  $linkedActors
     */
    protected function formatActorList(array $linkedActors): string
    {
        $count = count($linkedActors);

        if ($count === 1) {
            return $linkedActors[0];
        }

        if ($count === 2) {
            return $linkedActors[0].' '.__('notifications.label_conjunction_and').' '.$linkedActors[1];
        }

        $others = $count - 2;

        return $linkedActors[0].', '.$linkedActors[1].', '.
            trans_choice('notifications.label_count_others', $others, ['count' => $others]);
    }

    /**
     * Extract the target entity name from a notification's data payload.
     *
     * THE shared payload-name resolution: the in-app rows (bell dropdown +
     * notifications page) resolve through here, and the weekly digest's
     * entity-aware mail rows resolve through here too (M081/S06 follow-up)
     * — payload name keys are heterogeneous ("{$type}_name" for the unified
     * classes, per-class keys via TARGET_ENTITY_KEYS, game_system_name for
     * the request family), so any surface that renders entity names from
     * stored payloads must reuse this method or it silently diverges from
     * the in-app display for exactly the classes whose keys differ.
     *
     * @param  string  $shortType  Notification class basename (class_basename of the stored type).
     * @param  array<string, mixed>  $data
     */
    public function extractEntityName(string $shortType, array $data): ?string
    {
        // Unified notification classes (EntityInvitation, etc.) use dynamic keys.
        // The name key derives from the ENTITY type ('game'/'campaign'), never
        // from the wire type: the wire value is "{entity}_{verb}"
        // (game_invitation, campaign_cancelled…), and "{$wireType}_name" is a
        // key no payload has ever carried. Deriving from the wire type made the
        // branch fall through to entity_name — which only
        // Entity{Updated,Cancelled,Completed} store — so EntityInvitation
        // names resolved to null and invitation rows lost their linked entity
        // on every surface (bell rows, notifications page, digest mail).
        if ($shortType === 'EntityInvitation' || $shortType === 'EntityCompleted' || $shortType === 'EntityUpdated' || $shortType === 'EntityCancelled') {
            $entityType = $data['entity_type'] ?? null;

            if (! is_string($entityType) && is_string($data['type'] ?? null)) {
                // Strip the known verb suffix from the wire type to recover
                // the entity type; future wire verbs must extend the suffix
                // list consciously.
                $stripped = preg_replace('/_(invitation|updated|cancelled|completed)$/', '', $data['type']);
                $entityType = is_string($stripped) ? $stripped : null;
            }

            if (is_string($entityType) && $entityType !== '') {
                $nameKey = "{$entityType}_name";
                $val = $data[$nameKey] ?? $data['entity_name'] ?? null;

                return is_string($val) ? $val : null;
            }
        }

        // BatchedNotification rows (M082/S02): the payload carries an explicit
        // entity_type plus the name under the conventional "{entity_type}_name"
        // key — resolve through the explicit type (mirroring the unified
        // classes' dynamic resolution) so the bell, notifications page, and
        // digest never degrade the linked entity for batched rows. The
        // entity_name/game_name/campaign_name fallback below stays as the
        // belt for legacy rows.
        if ($shortType === 'BatchedNotification') {
            $entityType = $data['entity_type'] ?? null;

            if (is_string($entityType) && $entityType !== '') {
                $nameKey = "{$entityType}_name";
                $val = $data[$nameKey] ?? $data['entity_name'] ?? null;

                return is_string($val) ? $val : null;
            }
        }

        $mapping = self::TARGET_ENTITY_KEYS[$shortType] ?? null;
        if ($mapping === null) {
            // Badge awards carry no entity_name/game_name/campaign_name — the
            // badge display name lives under badge_name, and without this
            // branch the bell row degraded to a verb-only line with no
            // linked name (M081 review; the name is display-only, resolved
            // per-recipient at send time by BadgeAwardedNotification).
            if ($shortType === 'BadgeAwardedNotification') {
                $val = $data['badge_name'] ?? null;

                return is_string($val) && $val !== '' ? $val : null;
            }

            // Game-system-request notifications store the requested system name
            // under game_system_name (approved/rejected) or existing_game_system_name
            // (duplicate). They have no entity_name/game_name/campaign_name key, so
            // without this they would render with no linked entity (M054/S03).
            if (in_array($shortType, ['GameSystemRequestApproved', 'GameSystemRequestRejected', 'GameSystemRequestDuplicate'], true)) {
                $val = $data['game_system_name'] ?? $data['existing_game_system_name'] ?? null;

                return is_string($val) ? $val : null;
            }

            $val = $data['entity_name'] ?? $data['game_name'] ?? $data['campaign_name'] ?? null;

            return is_string($val) ? $val : null;
        }

        $val = $data[$mapping['name']] ?? null;

        return is_string($val) ? $val : null;
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
    protected function buildDisplayString(NotificationGroup $group): string
    {
        $actors = $group->actorNames;
        $count = count($actors);
        $verb = $this->resolveVerb($group->type, $group->latest->data ?? [], $count);

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
     * Resolution tiers (per grammatical number):
     *  1. Translation key from snake-cased short class name (notifications.verb_game_invitation).
     *     When two or more actors are composed into one sentence, the `_plural`
     *     variant of that key is tried first (verb_game_invitation_plural) — German
     *     verbs must agree with a plural subject ("Anna und Ben folgen dir", not
     *     "…folgt dir"). Plural keys are optional per locale; English verbs are
     *     uninflected, so a locale without the plural variant falls back to the
     *     singular key.
     *  2. Translation key from data.type field (notifications.verb_game_invitation via 'game_invitation').
     *     Required for unified notification classes whose class names don't have dedicated
     *     translation keys. The data.type field carries the entity-specific discriminator.
     *  3. Humanized fallback from the snake-cased short type.
     *
     * Contract: Unified notification classes must always set data.type in toDatabase().
     *
     * @param  string  $shortType  Short class name (e.g. 'EntityInvitation')
     * @param  array<string, mixed>  $data  Notification data payload
     * @param  int  $actorCount  Number of actors sharing the sentence; >= 2 selects plural agreement
     */
    protected function resolveVerb(string $shortType, array $data, int $actorCount = 1): string
    {
        if ($actorCount >= 2) {
            $plural = $this->translateVerbTier(
                'notifications.verb_'.Str::snake($shortType).'_plural',
                $data,
                plural: true
            );
            if ($plural !== null) {
                return $plural;
            }
        }

        $singular = $this->translateVerbTier(
            'notifications.verb_'.Str::snake($shortType),
            $data,
            plural: false
        );

        return $singular ?? str_replace('_', ' ', Str::snake($shortType));
    }

    /**
     * Try one verb translation key through both resolution tiers: the given
     * class-name-derived key first, then the data.type-derived key for unified
     * notification classes. Returns null when neither key exists in the locale.
     *
     * @param  array<string, mixed>  $data
     */
    protected function translateVerbTier(string $key, array $data, bool $plural): ?string
    {
        $translated = __($key);

        // If the translation key doesn't exist, __() returns the key itself —
        // try the notification data's type field for unified notification classes.
        if ($translated !== $key) {
            return $translated;
        }

        $dataType = $data['type'] ?? null;
        if (is_string($dataType)) {
            $dataKey = 'notifications.verb_'.$dataType.($plural ? '_plural' : '');
            $dataTranslated = __($dataKey);
            if ($dataTranslated !== $dataKey) {
                return $dataTranslated;
            }
        }

        return null;
    }
}
