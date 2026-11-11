<?php

namespace App\Mcp\Tools;

use App\Enums\McpAbility;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Mcp\Concerns\BuildsGamePayloads;
use App\Mcp\Concerns\ResolvesActingUser;
use App\Models\Game;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * The participant game-detail tool (M079/S02): everything needed to
 * decide about one game.
 *
 * Deliberately NARROWER than the web GamePolicy::view: the agent surface
 * shows a game only when it is public or the acting user owns/joins it.
 * A protected game the user merely has social reach into (friend of the
 * host) is invisible here — read tools expose listing-shaped data, and
 * narrowing costs nothing an agent needs. Invisible or unknown ids both
 * answer "not found" with the same payload, so existence of a hidden
 * game is never confirmed (no policy leak, mirroring web 404 behavior
 * for unlistable entities).
 */
#[Name(self::NAME)]
#[Description('Returns the details of one game by id: date, venue (graduated by the same disclosure rules as the website), seats, safety tools, systems, cutoff, and a link to the web page. Only games that are public or that the acting user hosts or participates in are returned; anything else answers not found.')]
class GamesDetailTool extends Tool
{
    use BuildsGamePayloads;
    use ResolvesActingUser;

    /** The MCP tool name (single-sourced: registry, tests, kill-switch, audit). */
    public const NAME = 'games.detail';

    /** Tool-class scope (D277) — read-only discovery. */
    public const ABILITY = McpAbility::Read;

    public function handle(Request $request): Response
    {
        $user = $this->actingUser($request);

        if ($user === null) {
            return Response::error('No authenticated acting user for this tool.');
        }

        $args = $request->validate([
            'game_id' => ['required', 'uuid'],
        ]);

        $game = Game::query()
            ->where(function ($query) use ($user) {
                // Public, or the acting user's own game, or one they
                // participate in — the narrowed read scope of this surface.
                $query->where('visibility', Visibility::Public->value)
                    ->orWhere(function ($query) use ($user) {
                        $query->whereBelongsTo($user, 'owner')
                            ->orWhereHas('participants', fn ($participants) => $participants
                                ->whereBelongsTo($user)
                                ->where('status', '!=', ParticipantStatus::Removed->value));
                    });
            })
            ->with(['owner', 'gameSystems', 'campaign', 'linkedLocation'])
            ->withCount(['participants as participants_count' => fn ($q) => $q->where('status', ParticipantStatus::Approved->value)])
            ->withCount(['participants as waitlisted_count' => fn ($q) => $q->where('status', ParticipantStatus::Waitlisted->value)])
            ->withCount(['participants as benched_count' => fn ($q) => $q->where('status', ParticipantStatus::Benched->value)])
            ->with('participants')
            ->find($args['game_id']);

        if (! $game instanceof Game) {
            // Same answer for invisible and unknown ids — no existence oracle.
            return Response::error('Game not found.');
        }

        return Response::json(
            $this->gameDetailPayload($game, $user),
        );
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'game_id' => $schema->string()->description('The game UUID (from games.search or my.sessions).')->required(),
        ];
    }
}
