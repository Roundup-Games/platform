<?php

namespace App\Mcp\Tools;

use App\Enums\McpAbility;
use App\Mcp\Concerns\BuildsHostPayloads;
use App\Mcp\Concerns\ResolvesActingUser;
use App\Models\Campaign;
use App\Models\SessionZeroConfirmation;
use App\Models\SessionZeroSurvey;
use App\Services\Charter\CharterService;
use App\Services\Charter\CharterSigningService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * The host charter-status tool (M079/S03): where the campaign's charter
 * stands, without the charter's private underbelly.
 *
 * Reads the charter through CharterService::charterFor and the signing
 * state through CharterSigningService — the same read paths the web GM
 * surfaces (CharterComposer, CharterAudit, ViewCharter's GM strip)
 * resolve. Authorization is CampaignPolicy::manageCharter, the one
 * ability every GM charter surface already resolves through.
 *
 * PRIVACY (the web rules, mirrored exactly): per-player completion is
 * surfaced as a signature state ONLY — signed, pending re-agreement, or
 * unsigned. The §2 private boundary answers are the web GM's eyes only
 * (privateAnswersFor) and are deliberately NOT reachable through this
 * surface: never loaded, never mapped, never logged. Attestations are
 * likewise boolean checkmarks, not content, and stay behind the service
 * boundary.
 */
#[Name(self::NAME)]
#[Description('Returns the charter status of one campaign the acting user owns: lifecycle state (draft/active/archived), current version, amendment count, signing progress, countersignature state, and per-player completion (signed, pending re-agreement, or unsigned). Private boundary answers are never exposed.')]
class HostCharterTool extends Tool
{
    use BuildsHostPayloads;
    use ResolvesActingUser;

    /** The MCP tool name (single-sourced: registry, tests, kill-switch, audit). */
    public const NAME = 'host.charter';

    /** Tool-class scope (D277) — host-workspace read. */
    public const ABILITY = McpAbility::Host;

    public function handle(Request $request): Response
    {
        $user = $this->actingUser($request);

        if ($user === null) {
            return Response::error('No authenticated acting user for this tool.');
        }

        $args = $request->validate([
            'campaign_id' => ['required', 'uuid'],
        ]);

        $campaign = Campaign::query()->find($args['campaign_id']);

        if (! $campaign instanceof Campaign) {
            return $this->hostDenial('not_found', 'Campaign not found.');
        }

        if (! $user->can('manageCharter', $campaign)) {
            return $this->hostDenial('not_authorized', 'Only the owner of this campaign can view its charter status.');
        }

        $charter = CharterService::charterFor($campaign);

        return Response::json([
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
            ],
            'charter' => $charter === null ? null : $this->charterPayload($charter),
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
            'campaign_id' => $schema->string()->description('The campaign UUID whose charter status to return.')->required(),
        ];
    }

    /**
     * The charter status payload: lifecycle, version/amendment trail,
     * signing progress, and per-player completion — signature states
     * only, never private answers or attestations.
     *
     * @return array<string, mixed>
     */
    protected function charterPayload(SessionZeroSurvey $charter): array
    {
        $version = $charter->currentVersion();

        $versionCount = $charter->versions()->count();

        // Publish snapshots v1; every further version is an amendment.
        $amendmentCount = max(0, $versionCount - 1);

        $progress = CharterSigningService::signingProgress($charter);

        $players = CharterSigningService::roster($charter)
            ->map(function (array $row): array {
                $confirmation = $row['confirmation'];

                return [
                    'user_id' => $row['participant']->user_id,
                    'name' => $row['user']?->name,
                    // Current-version participant rows are always signed
                    // (sign writes signed rows; carrySignatures copies
                    // only signed ones) — the completion state is which
                    // row exists, never its content.
                    'signature' => $confirmation !== null
                        ? 'signed'
                        : ($row['reagreementPending'] ? 'pending_reagreement' : 'unsigned'),
                    'signed_at' => $confirmation?->confirmed_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return [
            'status' => $charter->status,
            'title' => $charter->title,
            'current_version' => $version?->version_number,
            'amendment_count' => $amendmentCount,
            'published_at' => $version?->published_at?->toIso8601String(),
            'signing' => [
                'approved' => $progress['approved'],
                'signed' => $progress['signed'],
                'percent' => $progress['percent'],
                'countersigned' => $version !== null && $charter->confirmations()
                    ->where('session_zero_survey_version_id', $version->id)
                    ->where('role', SessionZeroConfirmation::ROLE_GM)
                    ->where('status', SessionZeroConfirmation::STATUS_SIGNED)
                    ->exists(),
            ],
            'players' => $players,
            'url' => route('charter.view', $charter->uuid),
        ];
    }
}
