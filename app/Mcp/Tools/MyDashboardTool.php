<?php

namespace App\Mcp\Tools;

use App\Enums\McpAbility;
use App\Mcp\Concerns\ResolvesActingUser;
use App\Models\Game;
use App\Models\User;
use App\Services\DashboardCacheService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * The acting user's dashboard summary tool (M079/S02): the compact
 * week-at-a-glance an agent needs to answer "what's my week like?".
 *
 * Reuses the Dashboard week DTO (DashboardCacheService::getWeekData —
 * the same assembly the web dashboard renders, cache and all) reduced
 * to its summary plus a compact per-day game list, and adds one
 * next-session query using the dashboard-schedule participation
 * semantics. Deliberately NOT the full dashboard: prompts, feeds,
 * contributions, and quick actions stay on the web surface.
 */
#[Name(self::NAME)]
#[Description('Returns a compact summary of the acting user\'s week: this week\'s session counts (total, upcoming, hosting, playing), the week\'s games grouped by day, and their next upcoming session. This is the same week data the user\'s web dashboard shows, condensed for quick reference.')]
class MyDashboardTool extends Tool
{
    use ResolvesActingUser;

    /** The MCP tool name (single-sourced: registry, tests, kill-switch, audit). */
    public const NAME = 'my.dashboard';

    /** Tool-class scope (D277) — read-only, own-data. */
    public const ABILITY = McpAbility::Read;

    public function handle(Request $request): Response
    {
        $user = $this->actingUser($request);

        if ($user === null) {
            return Response::error('No authenticated acting user for this tool.');
        }

        $week = app(DashboardCacheService::class)->getWeekData($user);

        $summary = is_array($week['summary'] ?? null) ? $week['summary'] : [];
        $days = is_array($week['days'] ?? null) ? $week['days'] : [];

        $dayRows = [];
        foreach ($days as $day) {
            if (! is_array($day)) {
                continue;
            }

            $gameRows = [];
            $games = is_array($day['games'] ?? null) ? $day['games'] : [];
            foreach ($games as $game) {
                if (! is_array($game)) {
                    continue;
                }

                $gameRows[] = [
                    'id' => $game['id'] ?? null,
                    'name' => $game['name'] ?? null,
                    'date_time' => $game['date_time'] ?? null,
                    'is_hosting' => (bool) ($game['is_hosting'] ?? false),
                ];
            }

            $dayRows[] = [
                'date' => $day['date'] ?? null,
                'is_today' => (bool) ($day['is_today'] ?? false),
                'games' => $gameRows,
            ];
        }

        return Response::json([
            'week' => [
                'summary' => $summary,
                'days' => $dayRows,
            ],
            'next_session' => $this->nextSession($user),
        ]);
    }

    /**
     * The earliest upcoming session the user hosts or attends — the same
     * participation semantics as DashboardScheduleService / ActionCenter
     * (owner or approved participant, scheduled, future).
     *
     * @return array<string, mixed>|null
     */
    private function nextSession(User $user): ?array
    {
        $game = Game::query()
            ->participatingSchedule($user)
            ->orderBy('date_time')
            ->first();

        if ($game === null) {
            return null;
        }

        return [
            'id' => $game->id,
            'name' => $game->name,
            'date_time' => $game->date_time?->toIso8601String(),
            'is_hosting' => $game->owner_id === $user->id,
            'url' => route('games.detail', $game),
        ];
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            //
        ];
    }
}
