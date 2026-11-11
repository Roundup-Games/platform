<?php

namespace App\Mcp\Tools;

use App\Enums\McpAbility;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Mcp\Concerns\BuildsHostPayloads;
use App\Mcp\Concerns\ResolvesActingUser;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Services\ParticipantService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * The host roster tool (M079/S03): tonight's table at a glance.
 *
 * Reads exactly what the web Manage Participants page reads — approved
 * players (owner excluded, like the web approved list), the FIFO
 * waitlist, and the bench, via the same ParticipantService helpers the
 * web roster trait resolves — plus each player's reliability summary
 * (the public-profile attendance-history shape).
 *
 * Authorization resolves GamePolicy::update / CampaignPolicy::update:
 * the SAME abilities the web ManageParticipants components authorize
 * through, so host, scoped admin, and global-admin reach are identical
 * across surfaces. A non-host gets the 403-equivalent structured
 * denial; unknown ids answer not-found (mirroring the web 404).
 */
#[Name(self::NAME)]
#[Description('Returns the roster of one game or campaign the acting user hosts: participants by status (approved, waitlisted, benched) with role, join date, and each player\'s reliability summary, plus seat counts. Pass exactly one of game_id or campaign_id.')]
class HostRosterTool extends Tool
{
    use BuildsHostPayloads;
    use ResolvesActingUser;

    /** The MCP tool name (single-sourced: registry, tests, kill-switch, audit). */
    public const NAME = 'host.roster';

    /** Tool-class scope (D277) — host-workspace read. */
    public const ABILITY = McpAbility::Host;

    public function handle(Request $request): Response
    {
        $user = $this->actingUser($request);

        if ($user === null) {
            return Response::error('No authenticated acting user for this tool.');
        }

        $args = $request->validate([
            'game_id' => ['nullable', 'uuid', 'required_without:campaign_id'],
            'campaign_id' => ['nullable', 'uuid', 'required_without:game_id'],
        ]);

        $gameId = $args['game_id'] ?? null;
        $campaignId = $args['campaign_id'] ?? null;

        if ($gameId !== null && $campaignId !== null) {
            return $this->hostDenial('invalid_arguments', 'Provide exactly one of game_id or campaign_id, not both.');
        }

        if (is_string($gameId)) {
            return $this->gameRoster($user, $gameId);
        }

        if (is_string($campaignId)) {
            return $this->campaignRoster($user, $campaignId);
        }

        // Unreachable past required_without validation — the fail-closed
        // branch exists so a malformed dispatch still answers, never throws.
        return $this->hostDenial('invalid_arguments', 'Provide exactly one of game_id or campaign_id.');
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'game_id' => $schema->string()->description('The game UUID whose roster to return (from my.sessions or games.search).'),
            'campaign_id' => $schema->string()->description('The campaign UUID whose roster to return — provide this instead of game_id.'),
        ];
    }

    /**
     * One game's roster, resolving the same authorization the web
     * Manage Participants page uses (GamePolicy::update).
     */
    protected function gameRoster(User $user, string $gameId): Response
    {
        $game = Game::query()->find($gameId);

        if (! $game instanceof Game) {
            return $this->hostDenial('not_found', 'Game not found.');
        }

        if (! $user->can('update', $game)) {
            return $this->hostDenial('not_authorized', 'Only the host of this game can view its roster.');
        }

        $participants = app(ParticipantService::class);

        // Approved players, owner row excluded — the web approved list.
        $approved = $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->where('role', '!=', ParticipantRole::Owner->value)
            ->orderBy('created_at')
            ->with('user')
            ->get();

        // Waitlist and bench through the service helpers so queue
        // ordering (waitlisted_at / benched_at) matches the web exactly.
        $waitlisted = $participants->getWaitlistedParticipants($game);
        $benched = $participants->getBenchedParticipants($game);

        $taken = $participants->getApprovedParticipantCount($game);

        return Response::json([
            'entity' => [
                'type' => 'game',
                'id' => $game->id,
                'name' => $game->name,
                'date_time' => $game->date_time?->toIso8601String(),
                'status' => $game->status?->value,
                'campaign_id' => $game->campaign_id,
            ],
            'seats' => [
                'taken' => $taken,
                'max' => $game->max_players,
                'available' => is_int($game->max_players) ? max(0, $game->max_players - $taken) : 0,
            ],
            'participants' => $approved
                ->map(fn ($participant): array => $this->rosterRow($participant))
                ->merge($waitlisted->map(fn ($participant): array => $this->rosterRow($participant)))
                ->merge($benched->map(fn ($participant): array => $this->rosterRow($participant)))
                ->values()
                ->all(),
            'counts' => [
                'approved' => $approved->count(),
                'waitlisted' => $waitlisted->count(),
                'benched' => $benched->count(),
            ],
        ]);
    }

    /**
     * One campaign's roster, resolving CampaignPolicy::update — the
     * ability the web campaign Manage Participants page authorizes with.
     */
    protected function campaignRoster(User $user, string $campaignId): Response
    {
        $campaign = Campaign::query()->find($campaignId);

        if (! $campaign instanceof Campaign) {
            return $this->hostDenial('not_found', 'Campaign not found.');
        }

        if (! $user->can('update', $campaign)) {
            return $this->hostDenial('not_authorized', 'Only the organizer of this campaign can view its roster.');
        }

        $participants = app(ParticipantService::class);

        $approved = $campaign->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->where('role', '!=', ParticipantRole::Owner->value)
            ->orderBy('created_at')
            ->with('user')
            ->get();

        $waitlisted = $participants->getWaitlistedParticipants($campaign);
        $benched = $participants->getBenchedParticipants($campaign);

        $taken = $participants->getApprovedParticipantCount($campaign);

        return Response::json([
            'entity' => [
                'type' => 'campaign',
                'id' => $campaign->id,
                'name' => $campaign->name,
                'status' => $campaign->status?->value,
                'recurrence' => $campaign->recurrence,
            ],
            'seats' => [
                'taken' => $taken,
                'max' => $campaign->max_players,
                // Mirror HasCapacity: null (or 0) means UNLIMITED capacity —
                // reporting 0 available would tell the host their unlimited
                // campaign is full.
                'available' => $campaign->max_players !== null && $campaign->max_players !== 0
                    ? max(0, $campaign->max_players - $taken)
                    : null,
            ],
            'participants' => $approved
                ->map(fn ($participant): array => $this->rosterRow($participant))
                ->merge($waitlisted->map(fn ($participant): array => $this->rosterRow($participant)))
                ->merge($benched->map(fn ($participant): array => $this->rosterRow($participant)))
                ->values()
                ->all(),
            'counts' => [
                'approved' => $approved->count(),
                'waitlisted' => $waitlisted->count(),
                'benched' => $benched->count(),
            ],
        ]);
    }
}
