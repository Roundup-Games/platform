<?php

namespace App\Mcp\Tools;

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\McpAbility;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Mcp\Concerns\BuildsGamePayloads;
use App\Mcp\Concerns\ResolvesActingUser;
use App\Models\Campaign;
use App\Models\Game;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * The acting user's sessions tool (M079/S02): what do I have coming up?
 *
 * Upcoming games follow the exact dashboard-schedule semantics
 * (DashboardScheduleService): games the user hosts or attends as an
 * approved participant, scheduled, in the future. Campaigns follow the
 * My Campaigns board semantics: active campaigns the user organizes or
 * plays in approved, each with its next scheduled session. Compact rows
 * only — names, ids, dates, and the hosting flag.
 */
#[Name(self::NAME)]
#[Description('Lists the acting user\'s upcoming sessions: future games they host or are an approved participant in, plus their active campaigns with each campaign\'s next scheduled session. Compact rows: id, name, date, hosting flag.')]
class MySessionsTool extends Tool
{
    use BuildsGamePayloads;
    use ResolvesActingUser;

    /** The MCP tool name (single-sourced: registry, tests, kill-switch, audit). */
    public const NAME = 'my.sessions';

    /** Tool-class scope (D277) — read-only, own-data. */
    public const ABILITY = McpAbility::Read;

    public const MAX_LIMIT = 25;

    public const DEFAULT_LIMIT = 10;

    public function handle(Request $request): Response
    {
        $user = $this->actingUser($request);

        if ($user === null) {
            return Response::error('No authenticated acting user for this tool.');
        }

        $args = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ]);

        $limitValue = $args['limit'] ?? self::DEFAULT_LIMIT;
        $limit = is_int($limitValue) ? $limitValue : self::DEFAULT_LIMIT;

        // Same participation semantics as the dashboard schedule section —
        // single-sourced as Game::scopeParticipatingSchedule().
        $games = Game::query()
            ->participatingSchedule($user)
            ->with(['owner', 'gameSystems', 'campaign', 'linkedLocation'])
            ->withCount(['participants as participants_count' => fn ($q) => $q->where('status', ParticipantStatus::Approved->value)])
            ->withCount(['participants as waitlisted_count' => fn ($q) => $q->where('status', ParticipantStatus::Waitlisted->value)])
            ->withCount(['participants as benched_count' => fn ($q) => $q->where('status', ParticipantStatus::Benched->value)])
            ->orderBy('date_time')
            ->limit($limit)
            ->get();

        // Same board semantics as MyCampaignsBoardService: active campaigns
        // the user organizes or plays in as an approved player.
        $campaigns = Campaign::query()
            ->where('status', CampaignStatus::Active->value)
            ->where(function ($query) use ($user) {
                $query->whereBelongsTo($user, 'owner')
                    ->orWhereHas('participants', fn ($participants) => $participants
                        ->whereBelongsTo($user)
                        ->where('role', ParticipantRole::Player->value)
                        ->where('status', ParticipantStatus::Approved->value));
            })
            ->with(['gameSystems', 'owner'])
            ->with(['sessions' => fn ($q) => $q
                ->where('status', GameStatus::Scheduled->value)
                ->where('date_time', '>', now())
                ->orderBy('date_time')
                ->limit(1)])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        // One bulk disclosure pass for the page instead of a per-row
        // relationship resolution in gameRow().
        $venueLevels = $this->batchVenueLevels($games, $user);

        return Response::json([
            'upcoming_games' => $games
                ->map(fn (Game $game): array => $this->gameRow($game, $user, $venueLevels[to_string_id($game->getKey())] ?? null) + [
                    'is_hosting' => $game->owner_id === $user->id,
                ])
                ->values()
                ->all(),
            'campaigns' => $campaigns
                ->map(fn (Campaign $campaign): array => [
                    'id' => $campaign->id,
                    'name' => $campaign->name,
                    'role' => $campaign->owner_id === $user->id ? 'organizing' : 'playing',
                    'recurrence' => $campaign->recurrence,
                    'systems' => $campaign->gameSystems->pluck('name')->values()->all(),
                    'next_session' => ($session = $campaign->sessions->first()) !== null
                        ? ['id' => $session->id, 'name' => $session->name, 'date_time' => $session->date_time?->toIso8601String()]
                        : null,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->min(1)->max(self::MAX_LIMIT)->description('Maximum number of games (and campaigns) to return. Defaults to 10.'),
        ];
    }
}
