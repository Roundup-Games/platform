<?php

namespace App\Services;

use App\Dto\ActionItem;
use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Enums\RelationshipType;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameBulletin;
use App\Models\GameParticipant;
use App\Models\GMProfile;
use App\Models\Review;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\Concerns\DashboardFormatting;
use Illuminate\Support\Str;

/**
 * Aggregates actionable items from 12 sources into a prioritized list
 * for the dashboard Action Center.
 *
 * Single public method: getItems(User): array<ActionItem>
 * Also provides: getClearSummary(User): ?array
 */
class ActionCenterService
{
    use DashboardFormatting;

    // ── Public API ─────────────────────────────────────

    /**
     * Get all pending action items for the user, sorted by priority then recency.
     *
     * @return ActionItem[]
     */
    public function getItems(User $user): array
    {
        $items = array_merge(
            $this->getWaitlistConfirmations($user),
            $this->getBelowMinPlayerWarnings($user),
            $this->getPendingApplications($user),
            $this->getPendingInvitations($user),
            $this->getUnreportedAttendance($user),
            $this->getMissingRecaps($user),
            $this->getAvailableDebriefings($user),
            $this->getNewReviews($user),
            $this->getNewFollowers($user),
            $this->getCampaignSessionAlerts($user),
            $this->getHostBulletins($user),
            $this->getRecurrencePlanningNudges($user),
        );

        usort($items, function (ActionItem $a, ActionItem $b) {
            $priorityDiff = ActionItem::priorityOrder($a->priority) - ActionItem::priorityOrder($b->priority);
            if ($priorityDiff !== 0) {
                return $priorityDiff;
            }

            return $b->createdAt->timestamp <=> $a->createdAt->timestamp;
        });

        return $items;
    }

    /**
     * When no action items exist, return a warm all-clear message with
     * info about the user's next upcoming session.
     *
     * Accepts optional pre-computed items to avoid re-querying when the
     * caller already has the items (e.g., empty from cache miss).
     *
     * @param  array<int, ActionItem>|null  $items  Pre-computed items, or null to query.
     * @return array{message: string, next_game: array{name: string, date_time: string, url: string}|null}|null
     */
    public function getClearSummary(User $user, ?array $items = null): ?array
    {
        $items ??= $this->getItems($user);

        if (count($items) > 0) {
            return null;
        }

        $nextGame = $this->nextUpcomingGame($user);

        return [
            'message' => __('profile.dashboard_action_center_all_clear'),
            'next_game' => $nextGame ? [
                'name' => $nextGame->name,
                'date_time' => $nextGame->date_time?->toIso8601String() ?? '',
                'url' => route('games.show', $nextGame->id),
            ] : null,
        ];
    }

    // ── 1. Waitlist Confirmations (critical) ───────────

    /**
     * Games where the user is waitlisted and confirmation is expiring within 2 hours.
     *
     * @return array<int, ActionItem>
     */
    private function getWaitlistConfirmations(User $user): array
    {
        $confirmations = GameParticipant::query()
            ->where('user_id', $user->id)
            ->where('status', ParticipantStatus::Waitlisted)
            ->whereNotNull('confirmation_expires_at')
            ->where('confirmation_expires_at', '<=', now()->addHours(2))
            ->where('confirmation_expires_at', '>', now())
            ->with('game')
            ->get();

        return $confirmations->map(fn (GameParticipant $p) => new ActionItem(
            type: 'waitlist_confirmation',
            priority: 'critical',
            title: __('profile.dashboard_action_waitlist_title', ['game' => $p->game->name ?? '']),
            description: __('profile.dashboard_action_waitlist_desc'),
            actionUrl: $p->game ? route('games.show', $p->game->id) : '#',
            actionLabel: __('profile.dashboard_action_waitlist_action'),
            icon: 'schedule',
            createdAt: $p->waitlisted_at ?? $p->created_at ?? now(),
            metadata: [
                'expires_at' => $p->confirmation_expires_at?->toIso8601String(),
                'entity_type' => 'game',
                'entity_id' => $p->game?->id,
            ],
        ))->all();
    }

    // ── 2. Below Min-Player Warnings (critical) ────────

    /**
     * Games the user owns that are scheduled within 48h but have fewer
     * approved participants than the minimum (default min_players = 2).
     *
     * @return array<int, ActionItem>
     */
    private function getBelowMinPlayerWarnings(User $user): array
    {
        $games = Game::query()
            ->where('owner_id', $user->id)
            ->where('status', GameStatus::Scheduled)
            ->where('date_time', '>', now())
            ->where('date_time', '<=', now()->addHours(48))
            ->withCount([
                'participants as approved_count' => fn ($q) => $q
                    ->where('status', ParticipantStatus::Approved),
            ])
            ->get()
            ->filter(fn (Game $g) => $g->approved_count < ($g->min_players ?? 2));

        return $games->map(fn (Game $g) => new ActionItem(
            type: 'below_min_players',
            priority: 'critical',
            title: __('profile.dashboard_action_min_players_title', ['game' => $g->name]),
            description: __('profile.dashboard_action_min_players_desc', [
                'current' => $g->approved_count,
                'min' => $g->min_players ?? 2,
            ]),
            actionUrl: route('games.manage-participants', $g->id),
            actionLabel: __('profile.dashboard_action_min_players_action'),
            icon: 'warning',
            createdAt: $g->created_at ?? now(),
            metadata: [
                'count' => $g->approved_count,
                'entity_type' => 'game',
                'entity_id' => $g->id,
            ],
        ))->all();
    }

    // ── 3. Pending Applications (high) ─────────────────

    /**
     * Games the user owns that have participants in Pending status.
     * Grouped per game with a count of pending applicants.
     *
     * @return array<int, ActionItem>
     */
    private function getPendingApplications(User $user): array
    {
        $games = Game::query()
            ->where('owner_id', $user->id)
            ->where('status', GameStatus::Scheduled)
            ->whereHas('participants', fn ($q) => $q
                ->where('status', ParticipantStatus::Pending))
            ->withCount([
                'participants as pending_count' => fn ($q) => $q
                    ->where('status', ParticipantStatus::Pending),
            ])
            ->get();

        return $games->map(fn (Game $g) => new ActionItem(
            type: 'pending_applications',
            priority: 'high',
            title: __('profile.dashboard_action_applications_title', ['game' => $g->name]),
            description: trans_choice('profile.dashboard_action_applications_desc', $g->pending_count ?? 0, [
                'count' => $g->pending_count ?? 0,
            ]),
            actionUrl: route('games.manage-participants', $g->id),
            actionLabel: __('profile.dashboard_action_applications_action'),
            icon: 'group_add',
            createdAt: $g->created_at ?? now(),
            metadata: [
                'count' => $g->pending_count,
                'entity_type' => 'game',
                'entity_id' => $g->id,
            ],
        ))->all();
    }

    // ── 4. Pending Invitations (high) ──────────────────

    /**
     * Games where the user was invited (role=invited, status=pending).
     * Falls back to status=Pending when role isn't set.
     *
     * @return array<int, ActionItem>
     */
    private function getPendingInvitations(User $user): array
    {
        $invitations = GameParticipant::query()
            ->where('user_id', $user->id)
            ->where('status', ParticipantStatus::Pending)
            ->where(function ($q) {
                $q->where('role', 'invited')
                    ->orWhereNull('role');
            })
            ->with('game')
            ->get();

        return $invitations->map(fn (GameParticipant $p) => new ActionItem(
            type: 'pending_invitation',
            priority: 'high',
            title: __('profile.dashboard_action_invitation_title', ['game' => $p->game->name ?? '']),
            description: __('profile.dashboard_action_invitation_desc'),
            actionUrl: $p->game ? route('games.show', $p->game->id) : '#',
            actionLabel: __('profile.dashboard_action_invitation_action'),
            icon: 'mail',
            createdAt: $p->created_at ?? now(),
            metadata: [
                'entity_type' => 'game',
                'entity_id' => $p->game?->id,
            ],
        ))->all();
    }

    // ── 5. Unreported Attendance (medium) ───────────────

    /**
     * Completed games where the attendance window is open and the user
     * is an approved participant who hasn't filed their report yet.
     *
     * Queries: game status = completed, attendance_resolved_at IS NULL,
     * attendance window is currently open, user is an approved participant,
     * and no AttendanceReport exists from this user.
     * Disappears once the user submits their report.
     *
     * @return array<int, ActionItem>
     */
    private function getUnreportedAttendance(User $user): array
    {
        $games = Game::query()
            ->where('status', GameStatus::Completed)
            ->whereNull('attendance_resolved_at')
            ->where(fn ($q) => $q
                ->whereNull('attendance_window_opens_at')
                ->orWhere('attendance_window_opens_at', '<=', now()))
            ->where(fn ($q) => $q
                ->whereNull('attendance_window_closes_at')
                ->orWhere('attendance_window_closes_at', '>', now()))
            ->whereHas('participants', fn ($q) => $q
                ->where('user_id', $user->id)
                ->where('status', ParticipantStatus::Approved))
            ->whereDoesntHave('attendanceReports', fn ($q) => $q
                ->where('reporter_id', $user->id))
            ->get();

        return $games->map(fn (Game $g) => new ActionItem(
            type: 'open_attendance_window',
            priority: 'medium',
            title: __('profile.dashboard_action_attendance_title', ['game' => $g->name]),
            description: __('profile.dashboard_action_attendance_desc'),
            actionUrl: route('games.show', $g->id),
            actionLabel: __('profile.dashboard_action_attendance_action'),
            icon: 'event_note',
            createdAt: $g->updated_at ?? now(),
            metadata: [
                'entity_type' => 'game',
                'entity_id' => $g->id,
            ],
        ))->all();
    }

    // ── 6. Missing Recaps (medium)

    /**
     * Games the user owns that are completed but have no recap, within 7 days.
     *
     * @return array<int, ActionItem>
     */
    private function getMissingRecaps(User $user): array
    {
        $games = Game::query()
            ->where('owner_id', $user->id)
            ->where('status', GameStatus::Completed)
            ->where('updated_at', '>=', now()->subDays(7))
            ->where(fn ($q) => $q->whereNull('recap')->orWhere('recap', ''))
            ->get();

        return $games->map(fn (Game $g) => new ActionItem(
            type: 'missing_recap',
            priority: 'medium',
            title: __('profile.dashboard_action_recap_title', ['game' => $g->name]),
            description: __('profile.dashboard_action_recap_desc'),
            actionUrl: route('games.show', $g->id),
            actionLabel: __('profile.dashboard_action_recap_action'),
            icon: 'edit_note',
            createdAt: $g->updated_at ?? now(),
            metadata: [
                'entity_type' => 'game',
                'entity_id' => $g->id,
            ],
        ))->all();
    }

    // ── 7. Available Debriefings (medium)

    /**
     * Completed games with debriefing tools where the user participated
     * but hasn't submitted a debriefing.
     *
     * @return array<int, ActionItem>
     */
    private function getAvailableDebriefings(User $user): array
    {
        // Games the user participated in that are completed and have debriefing tools,
        // but the user hasn't submitted a debriefing — filtered entirely at the SQL level.
        $games = Game::query()
            ->where('status', GameStatus::Completed)
            ->whereHas('participants', fn ($q) => $q
                ->where('user_id', $user->id)
                ->where('status', ParticipantStatus::Approved))
            ->where(fn ($q) => $q
                ->whereNotNull('safety_rules')
                ->whereJsonContains('safety_rules', 'debriefing')
            )
            ->whereDoesntHave('sessionDebriefings', fn ($q) => $q
                ->where('user_id', $user->id)
                ->whereNotNull('submitted_at'))
            ->get();

        return $games->map(fn (Game $g) => new ActionItem(
            type: 'available_debriefing',
            priority: 'medium',
            title: __('profile.dashboard_action_debriefing_title', ['game' => $g->name]),
            description: __('profile.dashboard_action_debriefing_desc'),
            actionUrl: route('games.show', $g->id),
            actionLabel: __('profile.dashboard_action_debriefing_action'),
            icon: 'auto_stories',
            createdAt: $g->updated_at ?? now(),
            metadata: [
                'entity_type' => 'game',
                'entity_id' => $g->id,
            ],
        ))->all();
    }

    // ── 8. New Reviews (medium)

    /**
     * Reviews on the user's GM profile from the last 7 days.
     *
     * Note: "not yet viewed" tracking is not currently available in the
     * schema, so we surface all reviews from the last 7 days.
     *
     * @return array<int, ActionItem>
     */
    private function getNewReviews(User $user): array
    {
        $gmProfile = GMProfile::where('user_id', $user->id)->first();

        if (! $gmProfile) {
            return [];
        }

        $reviews = Review::query()
            ->where('gm_profile_id', $gmProfile->id)
            ->where('created_at', '>=', now()->subDays(7))
            ->with('reviewer')
            ->orderByDesc('created_at')
            ->get();

        return $reviews->map(fn (Review $r) => new ActionItem(
            type: 'new_review',
            priority: 'medium',
            title: __('profile.dashboard_action_review_title', [
                'rating' => $r->rating,
                'reviewer' => $r->reviewer->name ?? __('Someone'),
            ]),
            description: $r->body ? Str::limit($r->body, 100) : __('profile.dashboard_action_review_no_comment'),
            actionUrl: route('profile.public', ['user' => $user]),
            actionLabel: __('profile.dashboard_action_review_action'),
            icon: 'rate_review',
            createdAt: $r->created_at ?? now(),
            metadata: [
                'entity_type' => 'review',
                'entity_id' => $r->id,
            ],
        ))->all();
    }

    // ── 9. New Followers (low) ──────────────────────────

    /**
     * Users who started following the current user within the last 7 days.
     * Includes count of shared game systems.
     *
     * @return array<int, ActionItem>
     */
    private function getNewFollowers(User $user): array
    {
        $followers = UserRelationship::query()
            ->where('related_user_id', $user->id)
            ->where('type', RelationshipType::Follow)
            ->where('created_at', '>=', now()->subDays(7))
            ->with('user')
            ->orderByDesc('created_at')
            ->get();

        $userSystemIds = to_string_id_array($user->gameSystemPreferences()->pluck('game_system_id'));
        /** @var array<string> $userSystemIds */

        // Bulk-load follower preferences to avoid N+1
        $followerUserIds = $followers->pluck('user_id')
            ->filter(fn (mixed $id) => is_string($id))
            ->unique()->values()->toArray();
        /** @var array<string> $followerUserIds */
        $followerSystemMap = $this->bulkLoadGameSystemPreferences($followerUserIds);

        return $followers->map(function (UserRelationship $rel) use ($userSystemIds, $followerSystemMap) {
            $followerUser = $rel->user;
            $rawIds = $followerSystemMap[(string) $followerUser?->id] ?? [];
            $followerSystemIds = is_array($rawIds)
                ? array_values(array_filter($rawIds, fn (mixed $v) => is_string($v)))
                : [];
            $sharedCount = count(array_intersect($userSystemIds, $followerSystemIds));

            return new ActionItem(
                type: 'new_follower',
                priority: 'low',
                title: __('profile.dashboard_action_follower_title', [
                    'name' => $followerUser->name ?? __('Someone'),
                ]),
                description: $sharedCount > 0
                    ? trans_choice('profile.dashboard_action_follower_shared', $sharedCount, ['count' => $sharedCount])
                    : __('profile.dashboard_action_follower_desc'),
                actionUrl: $followerUser ? route('profile.public', ['user' => $followerUser]) : '#',
                actionLabel: __('profile.dashboard_action_follower_action'),
                icon: 'person_add',
                createdAt: $rel->created_at ?? now(),
                metadata: [
                    'shared_systems_count' => $sharedCount,
                    'entity_type' => 'user',
                    'entity_id' => $followerUser->id ?? '',
                ],
            );
        })->all();
    }

    // ── 10. Campaign Session Alerts (low) ──────────────

    /**
     * Campaigns the user participates in that have new sessions (games)
     * created within the last 3 days.
     *
     * Note: "hasn't viewed" tracking is not available, so we surface
     * campaigns with recently-added sessions.
     *
     * @return array<int, ActionItem>
     */
    private function getCampaignSessionAlerts(User $user): array
    {
        $campaignIds = CampaignParticipant::query()
            ->where('user_id', $user->id)
            ->where('status', ParticipantStatus::Approved)
            ->pluck('campaign_id');

        if ($campaignIds->isEmpty()) {
            return [];
        }

        // Campaigns that have new sessions (games) created in the last 3 days
        $campaigns = Campaign::query()
            ->whereIn('id', $campaignIds)
            ->whereHas('sessions', fn ($q) => $q
                ->where('created_at', '>=', now()->subDays(3)))
            ->get();

        return $campaigns->map(fn (Campaign $c) => new ActionItem(
            type: 'campaign_session_alert',
            priority: 'low',
            title: __('profile.dashboard_action_campaign_session_title', ['campaign' => $c->name]),
            description: __('profile.dashboard_action_campaign_session_desc'),
            actionUrl: route('campaigns.show', $c->id),
            actionLabel: __('profile.dashboard_action_campaign_session_action'),
            icon: 'campaign',
            createdAt: $c->updated_at ?? $c->created_at ?? now(),
            metadata: [
                'entity_type' => 'campaign',
                'entity_id' => $c->id,
            ],
        ))->all();
    }

    // ── 11. Host Bulletins (medium) ────────────────────

    /**
     * Active bulletins from games where the user is an approved participant.
     * Only shows bulletins that are not expired and were created within the last 24h.
     * The host (owner) does not see their own bulletins here — they see them on the game page.
     *
     * @return array<int, ActionItem>
     */
    private function getHostBulletins(User $user): array
    {
        $bulletins = GameBulletin::query()
            ->whereHas('game', function ($q) use ($user) {
                $q->whereHas('participants', fn ($pq) => $pq
                    ->where('user_id', $user->id)
                    ->where('status', ParticipantStatus::Approved->value));
            })
            ->where('user_id', '!=', $user->id)
            ->notExpired()
            ->where('created_at', '>=', now()->subDay())
            ->with(['game', 'user'])
            ->orderByDesc('created_at')
            ->get();

        return $bulletins->map(fn (GameBulletin $b) => new ActionItem(
            type: 'host_bulletin',
            priority: 'medium',
            title: __('profile.dashboard_action_bulletin_title', ['game' => $b->game->name ?? '']),
            description: Str::limit($b->content, 100),
            actionUrl: $b->game ? route('games.show', $b->game->id) : '#',
            actionLabel: __('profile.dashboard_action_bulletin_action'),
            icon: 'campaign',
            createdAt: $b->created_at ?? now(),
            metadata: [
                'bulletin_id' => $b->id,
                'entity_type' => 'game',
                'entity_id' => $b->game?->id,
                'host_name' => $b->user?->name,
            ],
        ))->all();
    }

    // ── 12. Recurrence Planning Nudges (low) ───────────

    /**
     * Recurring campaigns the user owns that have fewer than ~2 sessions of
     * their cadence scheduled ahead. Emits a low-priority "plan ahead" nudge
     * that deep-links into prefill mode for adding the next session.
     *
     * Eligibility (Active status, cadence, plan-ahead horizon) lives entirely
     * in {@see RecurrenceService::shouldNudge()}; this source only scopes the
     * campaign query and maps results to ActionItems.
     *
     * @return array<int, ActionItem>
     */
    private function getRecurrencePlanningNudges(User $user): array
    {
        $campaigns = Campaign::query()
            ->where('owner_id', $user->id)
            ->where('status', CampaignStatus::Active)
            ->whereNotNull('recurrence')
            ->get();

        $service = app(RecurrenceService::class);

        $eligible = $campaigns->filter(fn (Campaign $c) => $service->shouldNudge($c));

        return $eligible->map(fn (Campaign $c) => new ActionItem(
            type: 'recurrence_planning',
            priority: 'low',
            title: __('profile.dashboard_action_recurrence_title', ['campaign' => $c->name]),
            description: __('profile.dashboard_action_recurrence_desc'),
            actionUrl: route('campaigns.add-session', [$c->id, 'prefill' => 1]),
            actionLabel: __('profile.dashboard_action_recurrence_action'),
            icon: 'event_repeat',
            createdAt: now(),
            metadata: [
                'entity_type' => 'campaign',
                'entity_id' => $c->id,
            ],
        ))->all();
    }

    // ── Helpers ─────────────────────────────────────────

    /**
     * Get the next upcoming game where the user is owner or approved participant.
     */
    private function nextUpcomingGame(User $user): ?Game
    {
        return Game::query()
            ->where('status', GameStatus::Scheduled)
            ->where('date_time', '>', now())
            ->where(function ($q) use ($user) {
                $q->where('owner_id', $user->id)
                    ->orWhereHas('participants', fn ($pq) => $pq
                        ->where('user_id', $user->id)
                        ->where('status', ParticipantStatus::Approved));
            })
            ->orderBy('date_time')
            ->first();
    }
}
