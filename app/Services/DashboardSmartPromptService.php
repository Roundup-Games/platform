<?php

namespace App\Services;

use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Enums\RelationshipType;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Database\Eloquent\Collection;

/**
 * Stateless service that determines which contextual nudge to show
 * on the dashboard. Single public method: getPrompt().
 *
 * Priority chain (first non-null wins):
 *   1. pending_invitations  — unresponded game/campaign invites
 *   2. upcoming_session     — next game within 24 h
 *   3. just_completed       — recent game missing a recap
 *   4. empty_week           — Mon–Wed with nothing scheduled
 *   5. new_follower         — follower in last 24 h
 *   6. fallback_active      — time-of-day greeting + upcoming count
 *   7. fallback_new         — welcome for new users (< 7 days)
 */
class DashboardSmartPromptService
{
    public function __construct(
        private DashboardCacheService $dashboardCacheService,
    ) {}

    /**
     * Resolve the highest-priority smart prompt for the given user.
     *
     * @return array<string, mixed>
     */
    public function getPrompt(User $user): array
    {
        return $this->checkPendingInvitations($user)
            ?? $this->checkUpcomingSession($user)
            ?? $this->checkJustCompleted($user)
            ?? $this->checkEmptyWeek($user)
            ?? $this->checkNewFollower($user)
            ?? $this->checkFallbackActive($user);
    }

    // ── Priority 1: Pending invitations ────────────────

    /**
     * @return array<string, mixed>
     */
    private function checkPendingInvitations(User $user): ?array
    {
        /** @var Collection<int, GameParticipant> $pendingGames */
        $pendingGames = GameParticipant::query()
            ->whereBelongsTo($user)
            ->where('status', ParticipantStatus::Pending)
            ->with('game.owner')
            ->orderByDesc('created_at')
            ->get();

        $pendingCampaigns = CampaignParticipant::query()
            ->whereBelongsTo($user)
            ->where('status', ParticipantStatus::Pending)
            ->count();

        $total = $pendingGames->count() + $pendingCampaigns;

        if ($total === 0) {
            return null;
        }

        $first = $pendingGames->first();
        $game = $first?->game;

        $message = $total === 1 && $game
            ? __('profile.dashboard_prompt_msg_invited_single', [
                'name' => $game->owner->name ?? __('Someone'),
                'game' => $game->name,
            ])
            : trans_choice('profile.dashboard_prompt_msg_invited_multiple', $total, ['count' => $total]);

        return [
            'type' => 'pending_invitations',
            'message' => $message,
            'action_url' => route('games.index'),
            'action_label' => __('profile.dashboard_prompt_view_invitations'),
            'metadata' => [
                'count' => $total,
                'game_id' => $game?->id,
                'game_name' => $game?->name,
                'inviter_name' => $game?->owner?->name,
                'game_date' => $game?->date_time?->toIso8601String(),
            ],
        ];
    }

    // ── Priority 2: Upcoming session within 24 h ───────

    /**
     * @return array<string, mixed>
     */
    private function checkUpcomingSession(User $user): ?array
    {
        $game = $this->nextUpcomingGame($user);

        if (! $game || $game->date_time === null || $game->date_time->diffInHours(now(), absolute: false) < -24) {
            return null;
        }

        $diff = now()->diff($game->date_time);
        $timePhrase = $this->formatTimeUntil($diff);

        return [
            'type' => 'upcoming_session',
            'message' => __('profile.dashboard_prompt_msg_upcoming', [
                'time' => $timePhrase,
                'game' => $game->name,
            ]),
            'action_url' => route('games.show', $game),
            'action_label' => __('profile.dashboard_prompt_view_details'),
            'metadata' => [
                'game_id' => $game->id,
                'game_name' => $game->name,
                'date_time' => $game->date_time->toIso8601String(),
                'hours_until' => (int) now()->diffInHours($game->date_time),
            ],
        ];
    }

    // ── Priority 3: Just completed, recap missing ──────

    /**
     * @return array<string, mixed>
     */
    private function checkJustCompleted(User $user): ?array
    {
        $game = Game::query()
            ->where('status', GameStatus::Completed)
            ->where('updated_at', '>=', now()->subHours(48))
            ->where(function ($q) use ($user) {
                $q->whereBelongsTo($user, 'owner')
                    ->orWhereHas('participants', fn ($pq) => $pq
                        ->whereBelongsTo($user)
                        ->where('status', ParticipantStatus::Approved));
            })
            ->whereNull('recap')
            ->orderByDesc('updated_at')
            ->first();

        if (! $game) {
            return null;
        }

        return [
            'type' => 'just_completed',
            'message' => __('profile.dashboard_prompt_msg_completed_recap', ['game' => $game->name]),
            'action_url' => route('games.show', $game),
            'action_label' => __('profile.dashboard_prompt_write_recap'),
            'metadata' => [
                'game_id' => $game->id,
                'game_name' => $game->name,
            ],
        ];
    }

    // ── Priority 4: Empty week (Mon–Wed) ───────────────

    /**
     * @return array<string, mixed>
     */
    private function checkEmptyWeek(User $user): ?array
    {
        $dayOfWeek = now()->dayOfWeekIso; // 1=Mon … 7=Sun

        if ($dayOfWeek > 3) {
            return null;
        }

        $weekData = $this->dashboardCacheService->getWeekData($user);
        $summary = is_array($weekData['summary'] ?? null) ? $weekData['summary'] : [];
        $gamesThisWeek = is_numeric($t = ($summary['total'] ?? 0)) ? (int) $t : 0;

        if ($gamesThisWeek > 0) {
            return null;
        }

        // Count nearby upcoming games for encouragement
        $nearbyCount = Game::query()
            ->where('status', GameStatus::Scheduled)
            ->where('date_time', '>=', now())
            ->where('date_time', '<=', now()->addDays(14))
            ->where(fn ($q) => $q->where('visibility', 'public'))
            ->count();

        return [
            'type' => 'empty_week',
            'message' => __('profile.dashboard_prompt_msg_empty_week'),
            'action_url' => route('discover'),
            'action_label' => $nearbyCount > 0 ? trans_choice('profile.dashboard_prompt_msg_browse_nearby', $nearbyCount, ['count' => $nearbyCount]) : __('profile.dashboard_prompt_find_game'),
            'metadata' => [
                'nearby_count' => $nearbyCount,
                'day_of_week' => $dayOfWeek,
            ],
        ];
    }

    // ── Priority 5: New follower ───────────────────────

    /**
     * @return array<string, mixed>
     */
    private function checkNewFollower(User $user): ?array
    {
        /** @var UserRelationship|null $follower */
        $follower = UserRelationship::query()
            ->whereBelongsTo($user, 'related')
            ->where('type', RelationshipType::Follow)
            ->where('created_at', '>=', now()->subHours(24))
            ->with('user.gameSystemPreferences')
            ->orderByDesc('created_at')
            ->first();

        if (! $follower) {
            return null;
        }

        $followerUser = $follower->user;

        // Shared game systems
        $userSystemIds = $user->gameSystemPreferences()->pluck('game_system_id')
            ->map(fn (mixed $id) => is_numeric($id) ? (string) $id : '')->values()->toArray();
        $followerSystemIds = [];
        if ($followerUser) {
            $followerSystemIds = $followerUser->gameSystemPreferences()->pluck('game_system_id')
                ->map(fn (mixed $id) => is_numeric($id) ? (string) $id : '')->values()->toArray();
        }
        $sharedSystems = array_intersect(
            array_map(fn (mixed $v) => is_numeric($v) ? (string) $v : '', $userSystemIds),
            array_map(fn (mixed $v) => is_numeric($v) ? (string) $v : '', $followerSystemIds)
        );

        $message = $sharedSystems
            ? trans_choice('profile.dashboard_prompt_msg_new_follower_shared', count($sharedSystems), [
                'name' => $followerUser?->name,
                'count' => count($sharedSystems),
            ])
            : __('profile.dashboard_prompt_msg_new_follower', ['name' => $followerUser?->name]);

        return [
            'type' => 'new_follower',
            'message' => $message,
            'action_url' => route('profile.show-authenticated', $followerUser),
            'action_label' => __('profile.dashboard_prompt_view_profile'),
            'metadata' => [
                'follower_id' => $followerUser?->id,
                'follower_name' => $followerUser?->name,
                'shared_system_count' => count($sharedSystems),
            ],
        ];
    }

    // ── Priority 6: Fallback active ────────────────────

    /**
     * @return array<string, mixed>
     */
    private function checkFallbackActive(User $user): array
    {
        $hour = now()->hour;
        $timeOfDay = $hour < 12 ? __('profile.dashboard_prompt_msg_time_morning') : ($hour < 17 ? __('profile.dashboard_prompt_msg_time_afternoon') : __('profile.dashboard_prompt_msg_time_evening'));
        $firstName = explode(' ', $user->name)[0];

        $upcomingCount = Game::query()
            ->where('status', GameStatus::Scheduled)
            ->where('date_time', '>', now())
            ->where(function ($q) use ($user) {
                $q->whereBelongsTo($user, 'owner')
                    ->orWhereHas('participants', fn ($pq) => $pq
                        ->whereBelongsTo($user)
                        ->where('status', ParticipantStatus::Approved));
            })
            ->count();

        $suffix = $upcomingCount > 0
            ? trans_choice('profile.dashboard_prompt_msg_upcoming_suffix', $upcomingCount, ['count' => $upcomingCount])
            : '';

        return [
            'type' => 'fallback_active',
            'message' => __('profile.dashboard_prompt_msg_greeting', [
                'time_of_day' => $timeOfDay,
                'name' => $firstName,
            ]).$suffix,
            'action_url' => $upcomingCount > 0 ? route('games.index') : null,
            'action_label' => $upcomingCount > 0 ? __('profile.dashboard_prompt_view_schedule') : null,
            'metadata' => [
                'time_of_day' => $timeOfDay,
                'upcoming_count' => $upcomingCount,
            ],
        ];
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
                $q->whereBelongsTo($user, 'owner')
                    ->orWhereHas('participants', fn ($pq) => $pq
                        ->whereBelongsTo($user)
                        ->where('status', ParticipantStatus::Approved));
            })
            ->orderBy('date_time')
            ->first();
    }

    /**
     * Format a DateInterval into a human-readable time phrase.
     */
    private function formatTimeUntil(\DateInterval $diff): string
    {
        $hours = ($diff->days * 24) + $diff->h;
        $minutes = $diff->i;

        if ($hours >= 1) {
            $timeStr = trans_choice('profile.dashboard_prompt_msg_hours', $hours, ['count' => $hours]);

            return __('profile.dashboard_prompt_msg_from_now', ['time' => $timeStr]);
        }

        if ($minutes >= 1) {
            $timeStr = trans_choice('profile.dashboard_prompt_msg_minutes', $minutes, ['count' => $minutes]);

            return __('profile.dashboard_prompt_msg_from_now', ['time' => $timeStr]);
        }

        return __('profile.dashboard_prompt_msg_time_now');
    }
}
