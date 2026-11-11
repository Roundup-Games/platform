<?php

namespace App\Mcp\Tools;

use App\Enums\McpAbility;
use App\Mcp\Concerns\BuildsHostPayloads;
use App\Mcp\Concerns\HandlesIdempotency;
use App\Mcp\Concerns\MarksAgentActor;
use App\Mcp\Concerns\ResolvesActingUser;
use App\Models\Game;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * The host attendance-report tool (M079/S03/T02): file the host's
 * per-participant report for a completed game.
 *
 * Delegates to {@see AttendanceService::submitReport()} — the identical
 * intake the web game-detail form walks (game completed, reporting
 * window, approved roster, no self-reports, host-excused-requires-reason,
 * no double reports). Grief resistance stays inside the service: the
 * reporter's weight multiplier and quarantine evaluation happen there,
 * and this tool exposes no argument that could touch weights or
 * thresholds (the dispatch-level argument discipline enforces the schema
 * at the gate). AttendanceReported notifications fan out through the
 * same NotificationService path as the web form.
 *
 * Authorization is GamePolicy::update — the same ability the web Manage
 * Participants surface resolves. In practice the service's own
 * reporter-must-be-an-approved-participant guard is the deciding rule
 * for this write, so per-entity reach here is host-only (an admin who is
 * not a participant of this game passes the policy gate and is then
 * refused by the service — fail-closed, mirroring the web form's
 * population); scoped/global admins reach it only when they are also
 * approved participants of the game.
 */
#[Name(self::NAME)]
#[Description('Submits the host\'s attendance reports for one completed game: one entry per participant (user_id, status attended/no_show/excused, optional reason — excused requires one, max 500 characters). Server-side grief-resistance weighting applies unchanged; report weights and thresholds cannot be set through this tool. AttendanceResolved notifications still wait for host.attendance.resolve. Accepts an optional idempotency_key: retries with the same key replay the first answer instead of reporting twice.')]
class HostAttendanceReportTool extends Tool
{
    use BuildsHostPayloads;
    use HandlesIdempotency;
    use MarksAgentActor;
    use ResolvesActingUser;

    /** The MCP tool name (single-sourced: registry, tests, kill-switch, audit). */
    public const NAME = 'host.attendance.report';

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
            'reports' => ['required', 'array', 'min:1'],
            'reports.*.user_id' => ['required', 'uuid', 'distinct'],
            'reports.*.status' => ['required', 'string', 'in:attended,no_show,excused'],
            'reports.*.reason' => ['nullable', 'string', 'max:500'],
            self::IDEMPOTENCY_KEY_ARGUMENT => ['nullable', 'string', 'max:255'],
        ]);

        $gameId = is_string($args['game_id'] ?? null) ? $args['game_id'] : '';

        $response = $this->idempotentResponse(
            $request,
            fn (): Response => $this->executeReport($user, $gameId, $args['reports'] ?? []),
        );

        // Single-response tool: the concern keeps the multi-response shape
        // for streamed tools, but this tool always answers exactly once.
        return $response instanceof Response ? $response : $response[0];
    }

    /**
     * Resolve, authorize, and submit through the web-intake service.
     * $entries is the validated reports argument (mixed until narrowed —
     * validation pins the shape, the loop narrows it defensively).
     */
    private function executeReport(User $user, string $gameId, mixed $entries): Response
    {
        $game = Game::query()->find($gameId);

        if (! $game instanceof Game) {
            return $this->hostDenial('not_found', 'Game not found.');
        }

        if (! $user->can('update', $game)) {
            return $this->hostDenial('not_authorized', 'Only the host of this game can report attendance.');
        }

        // Map the wire entries to the exact batch shape the service (and
        // the web form) consume. Validation already pinned the entry
        // shapes; the narrowing keeps the service contract typed.
        $reports = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (! is_array($entry)) {
                return $this->hostDenial('invalid_arguments', 'Each report must be an object with user_id, status, and an optional reason.');
            }

            $reason = $entry['reason'] ?? null;

            $reports[] = [
                'reported_id' => is_string($entry['user_id'] ?? null) ? $entry['user_id'] : '',
                'status' => is_string($entry['status'] ?? null) ? $entry['status'] : '',
                'reason' => is_string($reason) && $reason !== '' ? $reason : '',
            ];
        }

        if ($reports === []) {
            return $this->hostDenial('invalid_arguments', 'At least one report entry is required.');
        }

        /** @var array{success: bool, reason: string, created?: int} $result */
        $result = app(AttendanceService::class)->submitReport($game, $user, $reports);

        if (! $result['success']) {
            // The service's refusal IS the web refusal — surfaced verbatim
            // under a stable code so agents can branch without parsing.
            return $this->hostDenial('report_rejected', $result['reason']);
        }

        $this->logAgentWrite('attendance.report', $user, [
            'game_id' => $game->id,
            'report_count' => count($reports),
        ]);

        return Response::json([
            'status' => 'reports_submitted',
            // The service's true insert count — the race filter can drop
            // already-reported entries between validation and the insert, so
            // count($reports) could overstate what landed.
            'submitted' => is_int($result['created'] ?? null) ? $result['created'] : count($reports),
            'game' => [
                'id' => $game->id,
                'name' => $game->name,
                'url' => route('games.detail', $game),
            ],
            'note' => 'Reports recorded. Consensus resolution runs separately via host.attendance.resolve (or automatically once every participant has filed).',
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
            'game_id' => $schema->string()->description('The UUID of the completed game the reports belong to (from host.roster or host.attendance-summary).')->required(),
            'reports' => $schema->array()->min(1)->items($schema->object())->description('One entry per reported participant: {user_id: UUID, status: attended|no_show|excused, reason?: string}. Excused entries require a reason (max 500 characters). Report weights and grief-resistance rules are server-side.'),
            self::IDEMPOTENCY_KEY_ARGUMENT => $schema->string()->description('Optional client-generated key: a retried call with the same key replays the first answer instead of executing again.'),
        ];
    }
}
