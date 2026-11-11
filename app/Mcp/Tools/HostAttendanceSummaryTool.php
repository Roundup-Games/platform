<?php

namespace App\Mcp\Tools;

use App\Enums\McpAbility;
use App\Enums\ParticipantStatus;
use App\Mcp\Concerns\BuildsHostPayloads;
use App\Mcp\Concerns\ResolvesActingUser;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\GameParticipant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * The host attendance-summary tool (M079/S03): what attendance
 * resolution concluded for one game, and where it stands when it
 * hasn't concluded yet.
 *
 * Surfaces the OUTPUT of AttendanceResolutionService's consensus —
 * each approved participant's resolved attendance_status (the exact
 * data ReliabilityScoreService consumes, hence what feeds the
 * reliability view), with per-status totals. A read never triggers
 * resolution: the sweeper, early-consensus, and manual paths own
 * that. Unresolved games surface the window state and filed-report
 * count instead, so an agent knows when host.attendance.report (S03/
 * T02) becomes useful without learning anything the participants'
 * report bodies contain.
 *
 * Authorization resolves GamePolicy::update — the same ability the
 * web host surfaces around attendance use — so host, scoped admin,
 * and global-admin reach match the web exactly.
 */
#[Name(self::NAME)]
#[Description('Returns the resolved attendance summary of one game the acting user hosts: resolution state (method, timestamps, reporting window), per-participant resolved attendance with each player\'s reliability summary, and totals by status. For unresolved games, reports the open window and filed-report count instead.')]
class HostAttendanceSummaryTool extends Tool
{
    use BuildsHostPayloads;
    use ResolvesActingUser;

    /** The MCP tool name (single-sourced: registry, tests, kill-switch, audit). */
    public const NAME = 'host.attendance-summary';

    /** Tool-class scope (D277) — host-workspace read. */
    public const ABILITY = McpAbility::Host;

    public function handle(Request $request): Response
    {
        $user = $this->actingUser($request);

        if ($user === null) {
            return Response::error('No authenticated acting user for this tool.');
        }

        $args = $request->validate([
            'game_id' => ['required', 'uuid'],
        ]);

        $game = Game::query()->find($args['game_id']);

        if (! $game instanceof Game) {
            return $this->hostDenial('not_found', 'Game not found.');
        }

        if (! $user->can('update', $game)) {
            return $this->hostDenial('not_authorized', 'Only the host of this game can view its attendance summary.');
        }

        $participants = $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->orderBy('created_at')
            ->with('user')
            ->get();

        return Response::json([
            'game' => [
                'id' => $game->id,
                'name' => $game->name,
                'date_time' => $game->date_time?->toIso8601String(),
                'status' => $game->status?->value,
            ],
            'resolution' => [
                'resolved' => $game->attendance_resolved_at !== null,
                'resolved_at' => $game->attendance_resolved_at?->toIso8601String(),
                'method' => $game->attendance_resolution_method,
                'window_opens_at' => $game->attendance_window_opens_at?->toIso8601String(),
                'window_closes_at' => $game->attendance_window_closes_at?->toIso8601String(),
                'reports_filed' => AttendanceReport::whereBelongsTo($game)->count(),
            ],
            'summary' => $this->statusTotals($participants->all()),
            'participants' => $participants
                ->map(fn ($participant): array => [
                    'user_id' => $participant->user_id,
                    'name' => $participant->user?->name,
                    // Pre-game/host-set statuses (late_cancel, excused)
                    // carry through resolution unchanged — exactly the
                    // value reliability scoring reads.
                    'attendance' => $participant->attendance_status?->value,
                    'reliability' => $this->reliabilitySummary($participant->user),
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
            'game_id' => $schema->string()->description('The game UUID whose resolved attendance to summarize (typically a completed game from my.sessions or host.roster).')->required(),
        ];
    }

    /**
     * Totals by resolved status over the approved roster, plus the
     * still-unresolved count. Counted over reportable consensus
     * outcomes; cancelled_early is a departure-side status that never
     * survives an approved roster row, so it needs no bucket.
     *
     * @param  array<int, GameParticipant>  $participants
     * @return array<string, int>
     */
    protected function statusTotals(array $participants): array
    {
        $totals = [
            'attended' => 0,
            'no_show' => 0,
            'excused' => 0,
            'late_cancel' => 0,
            'unresolved' => 0,
        ];

        foreach ($participants as $participant) {
            $status = $participant->attendance_status;

            if ($status === null) {
                $totals['unresolved']++;

                continue;
            }

            $key = $status->value;

            // An enum value can never be the 'unresolved' sentinel —
            // the bucket check alone routes every status.
            if (array_key_exists($key, $totals)) {
                $totals[$key]++;
            }
        }

        return $totals;
    }
}
