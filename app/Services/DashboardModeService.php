<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the user's dashboard mode: 'newcomer' or 'established'.
 *
 * A user is a newcomer when they have zero attended games AND their
 * account was created within the last 30 days. Once either condition
 * flips (they attend a game or their account ages past 30 days),
 * they transition to 'established'.
 *
 * The resolved mode is cached per-user for 5 minutes and invalidated
 * when a game completes (via invalidateForUser).
 */
class DashboardModeService
{
    private const TTL_MODE = 300; // 5 minutes

    private const NEWCOMER_MAX_AGE_DAYS = 30;

    /**
     * Resolve the dashboard mode for the given user.
     *
     * Returns 'newcomer' if the user has zero attended games AND
     * their account was created within the last 30 days.
     * Otherwise returns 'established'.
     *
     * Result is cached for 5 minutes with key dashboard:mode:{userId}.
     */
    public function resolve(User $user): string
    {
        $cacheKey = "dashboard:mode:{$user->id}";

        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            return $cached;
        }

        $attendedCount = $this->attendedGameCount($user);
        $mode = $this->computeModeFrom($user, $attendedCount);

        Cache::put($cacheKey, $mode, self::TTL_MODE);

        Log::debug('dashboard.mode_resolved', [
            'user_id' => $user->id,
            'mode' => $mode,
            'account_age_days' => $user->created_at?->diffInDays(now()),
            'attended_game_count' => $attendedCount,
        ]);

        return $mode;
    }

    /**
     * Count games the user has attended.
     *
     * Queries GameParticipant records where:
     *   - user_id matches
     *   - status is Approved
     *   - attendance_status is Attended
     *
     * Falls back to counting approved participation in completed games
     * when attendance_status is not set (backward compatibility).
     */
    public function attendedGameCount(User $user): int
    {
        // Primary: explicit attendance_status = Attended
        $attendedCount = GameParticipant::where('user_id', $user->id)
            ->where('status', ParticipantStatus::Approved->value)
            ->where('attendance_status', AttendanceStatus::Attended->value)
            ->count();

        if ($attendedCount > 0) {
            return $attendedCount;
        }

        // Fallback: approved participation in completed games (backward compat
        // for games that were completed before attendance tracking was added)
        return GameParticipant::where('user_id', $user->id)
            ->where('status', ParticipantStatus::Approved->value)
            ->whereHas('game', fn ($q) => $q->where('status', GameStatus::Completed->value))
            ->count();
    }

    /**
     * Invalidate the cached mode for a user.
     *
     * Should be called when a game completes (attendance is recorded).
     */
    public function invalidateForUser(User $user): void
    {
        Cache::forget("dashboard:mode:{$user->id}");

        Log::debug('dashboard.mode_cache_invalidated', [
            'user_id' => $user->id,
        ]);
    }

    /**
     * Compute the mode without caching.
     */
    private function computeModeFrom(User $user, int $attendedCount): string
    {
        $isNewAccount = $user->created_at
            && $user->created_at->isAfter(now()->subDays(self::NEWCOMER_MAX_AGE_DAYS));

        if ($attendedCount === 0 && $isNewAccount) {
            return 'newcomer';
        }

        return 'established';
    }
}
