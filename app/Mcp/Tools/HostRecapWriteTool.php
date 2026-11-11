<?php

namespace App\Mcp\Tools;

use App\Enums\McpAbility;
use App\Mcp\Concerns\BuildsHostPayloads;
use App\Mcp\Concerns\HandlesIdempotency;
use App\Mcp\Concerns\MarksAgentActor;
use App\Mcp\Concerns\ResolvesActingUser;
use App\Models\Game;
use App\Models\User;
use App\Services\CampaignJournalService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * The host recap-write tool (M079/S03/T02): write a campaign session's
 * recap — the one and only recap record (kind=recap journal entry).
 *
 * The platform has NO draft-recap state: this tool IS the write. It
 * delegates to {@see CampaignJournalService::writeRecap()} — the same
 * write path the web session-end form uses — so every guard fires there
 * identically: game completed, campaign session (one-shots get the
 * Epilogue bulletin instead), host-only author, body at most 10000
 * characters, non-empty body, and write-once (race-protected by the
 * partial unique index). The service's exact guard list is surfaced here
 * as structured tool errors with stable codes, and the post-commit tail
 * (dashboard caches, SessionRecapped activity, RecapPosted notifications
 * to every approved participant but the author) fans out exactly as on
 * the web.
 *
 * Authorization is GamePolicy::update (the host-workspace gate); the
 * service's own owner guard remains the deciding rule for any co-host
 * reach and answers not_host.
 */
#[Name(self::NAME)]
#[Description('Writes the recap for one completed campaign session — there is no draft state and the write is final. The body becomes the session\'s kind=recap journal entry (max 10000 characters), RecapPosted notifications fan out to every approved participant but the author. Refuses with structured errors: not completed, not a campaign session, non-host, body over 10000 characters, empty body, or already written. Accepts an optional idempotency_key for safe retries.')]
class HostRecapWriteTool extends Tool
{
    use BuildsHostPayloads;
    use HandlesIdempotency;
    use MarksAgentActor;
    use ResolvesActingUser;

    /** The MCP tool name (single-sourced: registry, tests, kill-switch, audit). */
    public const NAME = 'host.recap.write';

    /** Tool-class scope (D277) — a host-workspace write. */
    public const ABILITY = McpAbility::Host;

    public function handle(Request $request): Response
    {
        $user = $this->actingUser($request);

        if ($user === null) {
            return Response::error('No authenticated acting user for this tool.');
        }

        $args = $request->validate([
            'game_id' => ['required', 'uuid'],
            // 'present' (not 'required'): an empty body is a valid wire
            // payload whose refusal belongs to the service's guard list,
            // surfaced with its stable code rather than a shape error.
            'body' => ['present', 'string'],
            self::IDEMPOTENCY_KEY_ARGUMENT => ['nullable', 'string', 'max:255'],
        ]);

        $gameId = is_string($args['game_id'] ?? null) ? $args['game_id'] : '';
        $body = is_string($args['body'] ?? null) ? $args['body'] : '';

        $response = $this->idempotentResponse(
            $request,
            fn (): Response => $this->executeWrite($user, $gameId, $body),
        );

        return $response instanceof Response ? $response : $response[0];
    }

    private function executeWrite(User $user, string $gameId, string $body): Response
    {
        $game = Game::query()->find($gameId);

        if (! $game instanceof Game) {
            return $this->hostDenial('not_found', 'Game not found.');
        }

        if (! $user->can('update', $game)) {
            return $this->hostDenial('not_authorized', 'Only the host of this game can write its recap.');
        }

        try {
            $entry = app(CampaignJournalService::class)->writeRecap($game, $user, $body);
        } catch (\LogicException $e) {
            return $this->recapDenial($e->getMessage());
        }

        $this->logAgentWrite('recap.write', $user, [
            'game_id' => $game->id,
            'entry_id' => $entry->id,
            'content_length' => mb_strlen($body),
        ]);

        return Response::json([
            'status' => 'recap_written',
            'game' => [
                'id' => $game->id,
                'name' => $game->name,
                'url' => route('games.detail', $game),
            ],
            'entry' => [
                'id' => $entry->id,
                'kind' => $entry->kind->value,
                'visibility' => $entry->visibility->value,
                'length' => mb_strlen($body),
            ],
            'note' => 'The recap is final — this session cannot be written again.',
        ]);
    }

    /**
     * The service's exact guard list, mapped to stable tool codes. The
     * match compares against the same translation keys the service
     * resolved, so the mapping is locale-independent by construction.
     */
    private function recapDenial(string $message): Response
    {
        return match ($message) {
            __('games.error_recap_game_not_completed') => $this->hostDenial('not_completed', 'The game is not completed yet; recaps are written after the session ends.'),
            __('journal.error_not_campaign_session') => $this->hostDenial('not_campaign_session', 'Only campaign sessions have recaps; one-shot games get an epilogue instead.'),
            __('games.error_recap_not_host') => $this->hostDenial('not_host', 'Only the host of this game can write its recap.'),
            __('journal.error_recap_too_long') => $this->hostDenial('body_too_long', 'The recap body exceeds the 10000-character limit.'),
            __('games.error_recap_empty') => $this->hostDenial('body_empty', 'The recap body is empty.'),
            __('games.error_recap_already_written') => $this->hostDenial('already_written', 'A recap has already been written for this session.'),
            default => $this->hostDenial('recap_rejected', $message),
        };
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'game_id' => $schema->string()->description('The UUID of the completed campaign session to write the recap for (from host.roster or my.sessions).')->required(),
            'body' => $schema->string()->description('The recap body (1 to 10000 characters). The write is final — there is no draft state and no edit path.')->required(),
            self::IDEMPOTENCY_KEY_ARGUMENT => $schema->string()->description('Optional client-generated key: a retried call with the same key replays the first answer instead of executing again.'),
        ];
    }
}
