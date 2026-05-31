<?php

namespace App\Services;

use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\User;
use Carbon\Carbon;

/**
 * Builds the "Your Schedule" section for the established-mode dashboard.
 *
 * Provides upcoming games grouped by time window (today / this week / coming up),
 * a "host again" bridge for users with no upcoming games, and the next-upcoming
 * game summary consumed by ActionCenterService.
 */
class DashboardScheduleService
{
    /**
     * Get upcoming games for the user, grouped into time buckets.
     *
     * Queries games where user is owner or approved participant, status=scheduled,
     * date_time within next 14 days. Returns array grouped into:
     *   - today:       date is today
     *   - this_week:   before end of Sunday
     *   - coming_up:   after this week, within 14 days
     *
     * Each game entry: id, name, system_badge, date_time, relative_time,
     * player_count, max_players, is_hosting, campaign_name.
     * Sorted by date_time within each group.
     */
    public function getUpcomingGames(User $user): array
    {
        return $this->buildUpcomingGroups($user);
    }

    /**
     * Get the "host again" bridge for users with no upcoming games.
     *
     * When user has no upcoming games but has completed games as host,
     * returns their last completed game with a clone URL. Returns null
     * if user has upcoming games or no completed games.
     *
     * Delegates to DashboardCacheService to avoid duplicating the query logic.
     */
    public function getHostAgainBridge(User $user): ?array
    {
        // If user has upcoming games, no bridge needed
        if ($this->hasUpcomingGames($user)) {
            return null;
        }

        $cached = app(DashboardCacheService::class)->getHostAgain($user);

        // Cache returns empty array when no data; null when no completed games
        if (! empty($cached)) {
            return $cached;
        }

        return null;
    }

    /**
     * Get the single next upcoming game for the all-clear summary.
     *
     * Returns id, name, date_time, relative_time — or null.
     * Used by ActionCenterService::getClearSummary.
     */
    public function getNextUpcomingGame(User $user): ?array
    {
        $game = Game::query()
            ->where('status', GameStatus::Scheduled)
            ->where('date_time', '>', now())
            ->where(function ($q) use ($user) {
                $q->where('owner_id', $user->id)
                    ->orWhereHas('participants', fn ($pq) => $pq
                        ->where('user_id', $user->id)
                        ->where('status', ParticipantStatus::Approved));
            })
            ->orderBy('date_time')
            ->with(['gameSystem'])
            ->first();

        if ($game === null) {
            return null;
        }

        return [
            'id' => $game->id,
            'name' => $game->name,
            'date_time' => $game->date_time->toIso8601String(),
            'relative_time' => $this->formatRelativeTime($game->date_time),
        ];
    }

    // ── Internal helpers ───────────────────────────────

    /**
     * Check if user has any upcoming scheduled games.
     */
    private function hasUpcomingGames(User $user): bool
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
            ->exists();
    }

    /**
     * Build grouped upcoming games from a fresh 14-day query.
     *
     * Queries games directly rather than reusing getWeekData() because the
     * schedule timeline covers a 14-day window (not just this week), and
     * includes additional relations (campaign, participant counts).
     *
     * @return array{today: array, this_week: array, coming_up: array}
     */
    private function buildUpcomingGroups(User $user): array
    {
        $now = now();
        $endOfToday = $now->copy()->endOfDay();
        $endOfWeek = $now->copy()->endOfWeek(); // Sunday end
        $fourteenDaysOut = $now->copy()->addDays(14);

        // Single query: games where user is owner or approved participant,
        // scheduled in the next 14 days. Same pattern as hasUpcomingGames().
        $games = Game::query()
            ->where('status', GameStatus::Scheduled->value)
            ->where('date_time', '>', $now)
            ->where('date_time', '<=', $fourteenDaysOut)
            ->where(function ($q) use ($user) {
                $q->where('owner_id', $user->id)
                    ->orWhereHas('participants', fn ($pq) => $pq
                        ->where('user_id', $user->id)
                        ->where('status', ParticipantStatus::Approved->value));
            })
            ->with(['gameSystem', 'campaign', 'participants' => fn ($q) => $q->where('status', ParticipantStatus::Approved->value)])
            ->orderBy('date_time')
            ->get();

        $today = [];
        $thisWeek = [];
        $comingUp = [];

        foreach ($games as $game) {
            $entry = $this->serializeGameEntry($game, $user);

            if ($game->date_time->lte($endOfToday)) {
                $today[] = $entry;
            } elseif ($game->date_time->lte($endOfWeek)) {
                $thisWeek[] = $entry;
            } else {
                $comingUp[] = $entry;
            }
        }

        return [
            'today' => $today,
            'this_week' => $thisWeek,
            'coming_up' => $comingUp,
        ];
    }

    /**
     * Serialize a Game model into the schedule entry format.
     */
    private function serializeGameEntry(Game $game, User $user): array
    {
        $playerCount = $game->participants->count();

        return [
            'id' => $game->id,
            'name' => $game->name,
            'system_badge' => [
                'name' => $game->gameSystem?->name,
                'icon' => $game->gameSystem?->coverImageUrl('thumb'),
            ],
            'date_time' => $game->date_time->toIso8601String(),
            'relative_time' => $this->formatRelativeTime($game->date_time),
            'player_count' => $playerCount,
            'max_players' => $game->max_players,
            'is_hosting' => $game->owner_id === $user->id,
            'campaign_name' => $game->campaign?->name,
        ];
    }

    /**
     * Format a datetime as a human-friendly relative time string.
     *
     * Examples: "Today at 7 PM", "Tomorrow at 3 PM", "Fri at 6 PM", "Jan 15 at 2 PM"
     */
    private function formatRelativeTime(Carbon $dateTime): string
    {
        $now = now();
        $time = $dateTime->format('g A'); // e.g. "7 PM"

        if ($dateTime->isToday()) {
            return "Today at {$time}";
        }

        if ($dateTime->isTomorrow()) {
            return "Tomorrow at {$time}";
        }

        if ($dateTime->isSameWeek($now) && $dateTime->greaterThan($now)) {
            return $dateTime->format('D')." at {$time}"; // e.g. "Fri at 6 PM"
        }

        return $dateTime->format('M j')." at {$time}"; // e.g. "Jan 15 at 2 PM"
    }
}
