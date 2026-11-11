<?php

namespace App\Mcp\Tools;

use App\Enums\McpAbility;
use App\Mcp\Concerns\BuildsHostPayloads;
use App\Mcp\Concerns\HandlesIdempotency;
use App\Mcp\Concerns\MarksAgentActor;
use App\Mcp\Concerns\ResolvesActingUser;
use App\Models\Campaign;
use App\Models\SessionZeroSurvey;
use App\Models\User;
use App\Services\Charter\CharterService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * The host charter-amend tool (M079/S03/T02): publish the next immutable
 * charter version.
 *
 * Delegates to {@see CharterService::amend()} — the identical commit path
 * the web charter composer walks: the version snapshot is append-only,
 * material amendments (tone axes, themes, §2–§5) reset every signature
 * to pending re-agreement while minor ones carry them, and
 * CharterAmended notifications fan out to approved participants through
 * the same NotificationService path. expected_base_version is REQUIRED
 * here (the optimistic-concurrency guard): a call built against a stale
 * version is refused instead of silently reverting an intervening
 * amendment.
 *
 * Authorization is CampaignPolicy::manageCharter — the one ability every
 * web GM charter surface resolves — so owner reach is identical across
 * surfaces.
 */
#[Name(self::NAME)]
#[Description('Amends the campaign\'s published charter: inserts the next immutable version with a changelog and repoints the charter. Material changes (tone axes, themes, safety or logistics sections) reset every player signature to pending re-agreement; minor ones carry signatures. Pass the current_version you amended against as expected_base_version — concurrent edits are refused. CharterAmended notifications fan out to all approved participants.')]
class HostCharterAmendTool extends Tool
{
    use BuildsHostPayloads;
    use HandlesIdempotency;
    use MarksAgentActor;
    use ResolvesActingUser;

    /** The MCP tool name (single-sourced: registry, tests, kill-switch, audit). */
    public const NAME = 'host.charter.amend';

    /** Tool-class scope (D277) — a host-workspace write. */
    public const ABILITY = McpAbility::Host;

    public function handle(Request $request): Response
    {
        $user = $this->actingUser($request);

        if ($user === null) {
            return Response::error('No authenticated acting user for this tool.');
        }

        $args = $request->validate([
            'campaign_id' => ['required', 'uuid'],
            // 'present' (not 'required') for the free-text fields: an
            // empty title or changelog is a refusal the service's
            // validator owns, surfaced with the invalid_charter code.
            'title' => ['present', 'string'],
            'content' => ['required', 'array'],
            'changelog' => ['present', 'string'],
            'expected_base_version' => ['required', 'integer', 'min:1'],
            self::IDEMPOTENCY_KEY_ARGUMENT => ['nullable', 'string', 'max:255'],
        ]);

        $campaignId = is_string($args['campaign_id'] ?? null) ? $args['campaign_id'] : '';
        $title = is_string($args['title'] ?? null) ? $args['title'] : '';
        $changelog = is_string($args['changelog'] ?? null) ? $args['changelog'] : '';
        $content = is_array($args['content'] ?? null) ? $args['content'] : [];
        $baseVersion = is_numeric($args['expected_base_version'] ?? null)
            ? (int) $args['expected_base_version']
            : 0;

        $response = $this->idempotentResponse(
            $request,
            fn (): Response => $this->executeAmend($user, $campaignId, $title, $content, $changelog, $baseVersion),
        );

        return $response instanceof Response ? $response : $response[0];
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function executeAmend(User $user, string $campaignId, string $title, array $content, string $changelog, int $baseVersion): Response
    {
        $campaign = Campaign::query()->find($campaignId);

        if (! $campaign instanceof Campaign) {
            return $this->hostDenial('not_found', 'Campaign not found.');
        }

        if (! $user->can('manageCharter', $campaign)) {
            return $this->hostDenial('not_authorized', 'Only the owner of this campaign can amend its charter.');
        }

        $charter = CharterService::charterFor($campaign);

        if (! $charter instanceof SessionZeroSurvey) {
            return $this->hostDenial('no_charter', 'This campaign has no charter to amend yet.');
        }

        try {
            $version = CharterService::amend($user, $charter, $title, $content, $changelog, $baseVersion);
        } catch (AuthorizationException) {
            // Defense in depth: the service re-authorizes through the same
            // gate; a drift between the two checks still answers 403-shape.
            return $this->hostDenial('not_authorized', 'Only the owner of this campaign can amend its charter.');
        } catch (ValidationException $e) {
            $messages = array_values(collect($e->errors())
                ->flatten()
                ->map(fn (mixed $message): string => is_string($message) ? $message : '')
                ->all());

            return $this->amendValidationDenial($messages);
        }

        $this->logAgentWrite('charter.amend', $user, [
            'campaign_id' => $campaign->id,
            'charter_id' => $charter->id,
            'version_number' => $version->version_number,
            'is_material' => $version->is_material,
        ]);

        return Response::json([
            'status' => 'charter_amended',
            'charter' => [
                'id' => $charter->id,
                'uuid' => $charter->uuid,
                'title' => trim($title),
                'current_version' => $version->version_number,
                'url' => route('charter.view', $charter->uuid),
            ],
            'amendment' => [
                'version_number' => $version->version_number,
                'is_material' => $version->is_material,
                'changelog' => $version->changelog,
                'published_at' => $version->published_at->toIso8601String(),
            ],
            'note' => $version->is_material
                ? 'This material amendment reset every signature to pending re-agreement.'
                : 'Signatures carried over — this was a minor amendment.',
        ]);
    }

    /**
     * Map the service's ValidationException refusals to stable tool codes:
     * the optimistic-concurrency and lifecycle refusals get their own
     * codes, everything else is the content validator speaking (joined
     * messages, still structured).
     *
     * @param  list<string>  $messages
     */
    private function amendValidationDenial(array $messages): Response
    {
        if (in_array(__('charter.error_amended_concurrently'), $messages, true)) {
            return $this->hostDenial('base_version_stale', 'The charter changed since expected_base_version was read — fetch the charter again and re-apply your amendment.');
        }

        if (in_array(__('charter.error_only_active_amendable'), $messages, true)) {
            return $this->hostDenial('not_amendable', 'Only an active, published charter can be amended.');
        }

        return $this->hostDenial('invalid_charter', $messages === [] ? 'The amendment failed validation.' : implode(' ', $messages));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'campaign_id' => $schema->string()->description('The UUID of the campaign whose charter to amend.')->required(),
            'title' => $schema->string()->description('The amended charter title (max 255 characters).')->required(),
            'content' => $schema->object()->description('The full amended charter content object — the same shape host.charter reads behind its completion rows and the composer edits. The service validates it against the charter content schema.')->required(),
            'changelog' => $schema->string()->description('What changed and why (required, max 2000 characters) — shown to players on CharterAmended.')->required(),
            'expected_base_version' => $schema->integer()->description('The current_version your content was built against (from host.charter). The amend refuses with base_version_stale when the charter has moved on.')->required(),
            self::IDEMPOTENCY_KEY_ARGUMENT => $schema->string()->description('Optional client-generated key: a retried call with the same key replays the first answer instead of executing again.'),
        ];
    }
}
