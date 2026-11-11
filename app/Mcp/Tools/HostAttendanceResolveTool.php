<?php

namespace App\Mcp\Tools;

use App\Enums\AttendanceResolutionMethod;
use App\Enums\AttendanceStatus;
use App\Enums\GameStatus;
use App\Enums\McpAbility;
use App\Enums\ParticipantStatus;
use App\Mcp\Concerns\BuildsHostPayloads;
use App\Mcp\Concerns\HandlesIdempotency;
use App\Mcp\Concerns\MarksAgentActor;
use App\Mcp\Concerns\ResolvesActingUser;
use App\Models\Game;
use App\Models\User;
use App\Services\AttendanceResolutionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * The host attendance-resolve tool (M079/S03/T02): trigger consensus
 * resolution for a completed game.
 *
 * Delegates to {@see AttendanceResolutionService::resolveGameAttendance()}
 * — the identical resolver the Filament manual action and the queued
 * timeout/early-consensus jobs run: weighted-vote consensus per approved
 * participant, reliability recomputation, and AttendanceResolved
 * notifications to every freshly-resolved participant. The service is
 * idempotent under a row lock; the tool mirrors the web action's
 * visibility guards (completed game, not yet resolved) as structured
 * denials so a caller never mistakes a skipped resolution for a done one.
 *
 * Authorization is GamePolicy::update — the same ability the web host
 * workspace resolves — so host reach is identical across surfaces.
 *
 * The resolution-method stamp is NOT agent-settable: early_consensus and
 * timeout are system-derived signals (dispatched only when every approved
 * participant has actually filed / by the timeout job), and
 * AttendanceService::checkGriefResistance() trusts exactly that when it
 * counts uncorroborated reports. A host-chosen stamp would forge that
 * trust input, so this tool always stamps Manual — the web host action's
 * stamp — with no argument to say otherwise (grief-resistance inputs stay
 * server-side, like every weight and threshold).
 */
#[Name(self::NAME)]
#[Description('Resolves consensus attendance for one completed game: weighted-vote consensus per approved participant, reliability scores recomputed, and AttendanceResolved notifications sent. Only a completed game whose attendance is not yet resolved can be resolved. Stamps the manual resolution method, exactly like the web host action — the early-consensus and timeout stamps are system-derived and cannot be requested. Accepts an optional idempotency_key: retries with the same key replay the first answer instead of resolving twice.')]
class HostAttendanceResolveTool extends Tool
{
    use BuildsHostPayloads;
    use HandlesIdempotency;
    use MarksAgentActor;
    use ResolvesActingUser;

    /** The MCP tool name (single-sourced: registry, tests, kill-switch, audit). */
    public const NAME = 'host.attendance.resolve';

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
            self::IDEMPOTENCY_KEY_ARGUMENT => ['nullable', 'string', 'max:255'],
        ]);

        $gameId = is_string($args['game_id'] ?? null) ? $args['game_id'] : '';

        $response = $this->idempotentResponse(
            $request,
            fn (): Response => $this->executeResolve($user, $gameId),
        );

        return $response instanceof Response ? $response : $response[0];
    }

    private function executeResolve(User $user, string $gameId): Response
    {
        $game = Game::query()->find($gameId);

        if (! $game instanceof Game) {
            return $this->hostDenial('not_found', 'Game not found.');
        }

        if (! $user->can('update', $game)) {
            return $this->hostDenial('not_authorized', 'Only the host of this game can resolve its attendance.');
        }

        // The web action's visibility guards, surfaced instead of silently
        // no-op'ing (the service itself is idempotent and would skip).
        if ($game->status !== GameStatus::Completed) {
            return $this->hostDenial('not_completed', 'Only a completed game\'s attendance can be resolved.');
        }

        if ($game->attendance_resolved_at !== null) {
            return $this->hostDenial('already_resolved', sprintf(
                'Attendance for this game was already resolved (%s, %s).',
                $game->attendance_resolution_method ?? 'method unknown',
                $game->attendance_resolved_at->toIso8601String(),
            ));
        }

        $method = AttendanceResolutionMethod::Manual;

        app(AttendanceResolutionService::class)->resolveGameAttendance($game, $method);

        $this->logAgentWrite('attendance.resolve', $user, [
            'game_id' => $game->id,
            'method' => $method->value,
        ]);

        /** @var Game $fresh */
        $fresh = Game::query()->findOrFail($game->getKey());

        return Response::json([
            'status' => 'attendance_resolved',
            'game' => [
                'id' => $fresh->id,
                'name' => $fresh->name,
                'url' => route('games.detail', $fresh),
            ],
            'resolution' => [
                'method' => $fresh->attendance_resolution_method,
                'resolved_at' => $fresh->attendance_resolved_at?->toIso8601String(),
            ],
            'summary' => $this->resolvedSummary($fresh),
        ]);
    }

    /**
     * Post-resolution tally over the approved roster — the same resolved
     * counts host.attendance-summary reads.
     *
     * @return array{attended: int, no_show: int, excused: int, late_cancel: int, unresolved: int}
     */
    private function resolvedSummary(Game $game): array
    {
        $statuses = $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->pluck('attendance_status');

        $summary = ['attended' => 0, 'no_show' => 0, 'excused' => 0, 'late_cancel' => 0, 'unresolved' => 0];

        foreach ($statuses as $status) {
            if ($status instanceof AttendanceStatus && isset($summary[$status->value])) {
                $summary[$status->value]++;
            } else {
                $summary['unresolved']++;
            }
        }

        return $summary;
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'game_id' => $schema->string()->description('The UUID of the completed game whose attendance to resolve (from host.attendance-summary).')->required(),
            self::IDEMPOTENCY_KEY_ARGUMENT => $schema->string()->description('Optional client-generated key: a retried call with the same key replays the first answer instead of executing again.'),
        ];
    }
}
