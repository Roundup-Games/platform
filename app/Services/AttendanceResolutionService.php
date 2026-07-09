<?php

namespace App\Services;

use App\Enums\AttendanceResolutionMethod;
use App\Enums\AttendanceStatus;
use App\Enums\NotificationCategory;
use App\Enums\ParticipantStatus;
use App\Jobs\ResolveAttendance;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Notifications\AttendanceResolved;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Consensus engine for game attendance resolution.
 *
 * Owns the weighted-vote consensus algorithm that resolves each approved
 * participant's attendance_status after a game completes. Extracted from
 * {@see AttendanceService} so the 1284-LOC intake/grief/dispute module no longer
 * fuses with the resolution algorithm — this service is a pure resolver with
 * one dependency: {@see ReliabilityScoreService}.
 *
 * Inputs: a completed Game with filed AttendanceReports.
 * Outputs: resolved attendance_status + reliability weight per participant,
 *   is_corroborated flags on agreeing reports, and AttendanceResolved
 *   notifications dispatched post-transaction.
 *
 * Triggered by:
 *  - {@see ResolveAttendance} (timeout sweeper + early-consensus)
 *  - Filament admin action in EditGame.php (manual resolution)
 *  - {@see AttendanceService::submitReport()} and {@see reportAttendance()}
 *    via markCorroborated() (the corroboration safety net)
 */
class AttendanceResolutionService
{
    public function __construct(
        private readonly ReliabilityScoreService $reliabilityService,
    ) {}

    // ── Tunable thresholds (read from config/attendance.php) ────

    public static function participationThreshold(): float
    {
        $v = config('attendance.participation_threshold', 0.5);

        return is_numeric($v) ? (float) $v : 0.5;
    }

    public static function noShowMajority(): float
    {
        $v = config('attendance.no_show_majority', 0.5);

        return is_numeric($v) ? (float) $v : 0.5;
    }

    /**
     * Resolve attendance for all participants of a game using consensus.
     *
     * Idempotent: skips if game already has attendance_resolved_at set.
     * For each approved participant without a pre-game status:
     *   - Collects non-self reports filed for this person
     *   - Checks participation threshold (filed reports >= 50% of total non-self participants)
     *   - If threshold not met: defaults to Attended
     *   - If threshold met: counts weighted no_show votes vs attended votes
     *   - If no_show weighted sum > 50% of total weighted filed: NoShow (unless host excused)
     *   - Checks host excused override
     * Sets attendance_status on each participant, sets resolved_at and resolution_method on game.
     * Triggers ReliabilityScoreService recompute for each resolved participant.
     */
    public function resolveGameAttendance(Game $game, ?AttendanceResolutionMethod $method = null): void
    {
        // Idempotent guard
        if ($game->attendance_resolved_at !== null) {
            Log::info('Game attendance already resolved — skipping', [
                'game_id' => $game->id,
                'resolved_at' => $game->attendance_resolved_at->toIso8601String(),
            ]);

            return;
        }

        $resolutionMethod = $method ?? AttendanceResolutionMethod::Timeout;

        // Get all approved participants
        /** @var Collection<int, GameParticipant> $participants */
        $participants = $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->get();

        // Pre-fetch all reports for the game once (avoids N+1 inside participant loop)
        $allReports = AttendanceReport::whereBelongsTo($game)->get();

        // Total non-self participants (each participant's denominator excludes themselves)
        $totalParticipants = $participants->count();
        $participationThreshold = static::participationThreshold();
        $noShowMajority = static::noShowMajority();

        // Whether THIS call actually performed the resolution. The transaction may
        // bail when a concurrent caller (early-consensus job vs timeout sweeper, or
        // a retried job whose first attempt already resolved) won the lockForUpdate
        // race. The notification fan-out below must only fire when this caller did
        // the work — otherwise a bailing caller re-sends an AttendanceResolved
        // notification to every participant (duplicate user-visible notifications).
        $resolved = false;

        DB::transaction(function () use ($game, $participants, $totalParticipants, $resolutionMethod, $allReports, $participationThreshold, $noShowMajority, &$resolved) {
            // Lock the game row to prevent concurrent resolution (e.g., early-consensus
            // job and timeout sweeper racing for the same game).
            /** @var Game|null $locked */
            $locked = Game::where('id', $game->id)->lockForUpdate()->first();
            if ($locked === null || $locked->attendance_resolved_at !== null) {
                return; // Already resolved by another process
            }

            // Record corroboration as part of resolution. This is the safety net
            // for games that resolve via timeout sweeper or the legacy
            // reportAttendance() path, where submitReport()'s inline
            // markCorroborated() call may never have run. Idempotent.
            $this->markCorroborated($game);

            foreach ($participants as $participant) {
                // Skip participants who already have a pre-game or host-set status
                // (e.g., late_cancel from host cancellation offence, excused set pre-game).
                // These users already know their outcome and don't need a resolution
                // notification — the notification loop below also skips them.
                if ($participant->attendance_status !== null) {
                    continue;
                }

                // Collect non-self reports filed for this person (from pre-fetched collection)
                $reports = $allReports
                    ->where('reported_id', $participant->user_id)
                    ->where('reporter_id', '!=', $participant->user_id);

                // Denominator: total non-self participants
                $totalNonSelf = $totalParticipants - 1;

                if ($totalNonSelf <= 0) {
                    // Solo game (only this participant) — default to attended
                    $this->applyResolvedStatus($participant, AttendanceStatus::Attended);

                    continue;
                }

                $filedReportCount = $reports->count();

                // Participation threshold: filed reports >= 50% of total non-self participants
                $thresholdMet = $filedReportCount >= ($totalNonSelf * $participationThreshold);

                if (! $thresholdMet) {
                    // Not enough reporters participated — default to Attended
                    $this->applyResolvedStatus($participant, AttendanceStatus::Attended);

                    Log::info('Attendance defaulting to Attended (threshold not met)', [
                        'game_id' => $game->id,
                        'user_id' => $participant->user_id,
                        'filed_reports' => $filedReportCount,
                        'total_non_self' => $totalNonSelf,
                    ]);

                    continue;
                }

                // Count weighted votes
                $weightedNoShow = 0.0;
                $weightedAttended = 0.0;
                $weightedExcused = 0.0;

                foreach ($reports as $report) {
                    $w = $report->weight_applied ?? 1.0;

                    if ($report->status === AttendanceStatus::NoShow) {
                        $weightedNoShow += $w;
                    } elseif ($report->status === AttendanceStatus::Attended) {
                        $weightedAttended += $w;
                    } elseif ($report->status === AttendanceStatus::Excused) {
                        $weightedExcused += $w;
                    }
                }

                $totalWeighted = $weightedNoShow + $weightedAttended + $weightedExcused;

                // Check for host-excused override (use $game from closure scope to avoid N+1)
                $hostExcusedReport = $reports->first(function ($r) use ($game) {
                    return $r->status === AttendanceStatus::Excused
                        && $r->reporter_id === $game->owner_id;
                });

                if ($hostExcusedReport) {
                    // Host has excused this participant
                    $this->applyResolvedStatus($participant, AttendanceStatus::Excused);

                    Log::info('Attendance resolved as Excused (host override)', [
                        'game_id' => $game->id,
                        'user_id' => $participant->user_id,
                    ]);

                    continue;
                }

                if ($totalWeighted <= 0) {
                    // No meaningful votes — default to Attended
                    $this->applyResolvedStatus($participant, AttendanceStatus::Attended);

                    continue;
                }

                // If no_show weighted sum > 50% of total weighted: NoShow
                if ($weightedNoShow > ($totalWeighted * $noShowMajority)) {
                    $resolvedStatus = AttendanceStatus::NoShow;

                    // If this participant is the game owner (host), apply host_no_show weight
                    if ((string) $participant->user_id === (string) $game->owner_id) {
                        $hw = config('attendance.host_no_show_weight', -1.5);
                        $hostWeight = is_numeric($hw) ? (float) $hw : -1.5;
                        $participant->forceFill([
                            'attendance_status' => $resolvedStatus,
                            'attendance_reported_at' => now(),
                            'attendance_weight' => $hostWeight,
                        ])->save();

                        $this->reliabilityService->recomputeAfterAttendance($participant);

                        Log::info('Attendance resolved as NoShow (host, weighted)', [
                            'game_id' => $game->id,
                            'user_id' => $participant->user_id,
                            'weighted_no_show' => $weightedNoShow,
                            'total_weighted' => $totalWeighted,
                            'host_weight' => $hostWeight,
                        ]);

                        continue;
                    }

                    $this->applyResolvedStatus($participant, $resolvedStatus);

                    Log::info('Attendance resolved as NoShow (weighted consensus)', [
                        'game_id' => $game->id,
                        'user_id' => $participant->user_id,
                        'weighted_no_show' => $weightedNoShow,
                        'total_weighted' => $totalWeighted,
                    ]);

                    continue;
                }

                // Default: Attended
                $this->applyResolvedStatus($participant, AttendanceStatus::Attended);

                Log::info('Attendance resolved as Attended (weighted consensus)', [
                    'game_id' => $game->id,
                    'user_id' => $participant->user_id,
                    'weighted_attended' => $weightedAttended,
                    'weighted_no_show' => $weightedNoShow,
                    'total_weighted' => $totalWeighted,
                ]);
            }

            // Mark game as resolved
            $game->forceFill([
                'attendance_resolved_at' => now(),
                'attendance_resolution_method' => $resolutionMethod->value,
            ])->save();

            $resolved = true;

            Log::info('Game attendance resolved', [
                'game_id' => $game->id,
                'resolution_method' => $resolutionMethod->value,
                'participants_resolved' => $participants->count(),
            ]);
        });

        // Only fan out when THIS call performed the resolution. A concurrent winner
        // (or a retried job) bailed inside the transaction and must not re-send
        // AttendanceResolved notifications that the winning caller already sent.
        if (! $resolved) {
            return;
        }

        // IMPORTANT: Refresh participants from DB after the transaction.
        // The in-memory $participants collection still holds pre-transaction values
        // (attendance_status = null for newly-resolved participants) because forceFill()->save()
        // inside the transaction updates the DB but not the stale collection.
        // load('user') only eager-loads the relationship — it does NOT refresh attributes.
        try {
            $notificationService = app(NotificationService::class);
            /** @var Collection<int, GameParticipant> $resolvedParticipants */
            $resolvedParticipants = $game->participants()
                ->where('status', ParticipantStatus::Approved->value)
                ->with('user')
                ->get();

            foreach ($resolvedParticipants as $participant) {
                if ($participant->attendance_status === null) {
                    continue;
                }

                if ($participant->user === null) {
                    continue;
                }

                $notificationService->send(
                    $participant->user,
                    new AttendanceResolved($game, $participant->attendance_status),
                    NotificationCategory::AttendanceResolved,
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to send attendance resolved notifications', [
                'game_id' => $game->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Apply a resolved attendance status to a participant and trigger reliability recompute.
     */
    private function applyResolvedStatus(GameParticipant $participant, AttendanceStatus $status): void
    {
        $participant->forceFill([
            'attendance_status' => $status,
            'attendance_reported_at' => now(),
            'attendance_weight' => ReliabilityScoreService::WEIGHTS[$status->value],
        ])->save();

        $this->reliabilityService->recomputeAfterAttendance($participant);
    }

    /**
     * Mark attendance reports as corroborated when two or more independent
     * (non-self) reporters agree on the same status for a reported user.
     *
     * Restores the corroboration semantics that the consensus rewrite dropped.
     * This no longer drives the grief-resistance quarantine (which is now
     * scoped to EarlyConsensus games — see config/attendance.php), but it still
     * sets is_corroborated correctly for the rare multi-reporter games, keeping
     * the data model honest and powering the Filament "corroborated" column.
     *
     * Agreement is per (reported user, status): two reporters must pick the SAME
     * status for that user. Reporters disagreeing (one "attended", one
     * "no_show") do not corroborate either report — matching the original
     * checkCorroboration() behaviour. Self-reports (reporter_id = reported_id,
     * e.g. host late-cancel offences) never count toward corroboration.
     *
     * Idempotent: reports already corroborated are skipped, and re-running on a
     * fully-corroborated game is a no-op. Returns the number of reports newly
     * marked corroborated.
     */
    public function markCorroborated(Game $game): int
    {
        $gameId = $game->id;

        // Find (reported_id, status) groups with >= 2 distinct non-self reporters.
        // Count ALL reporters regardless of current corroboration state so a group
        // where one report is already corroborated still satisfies the threshold.
        $groups = AttendanceReport::where('game_id', $gameId)
            ->whereColumn('reporter_id', '!=', 'reported_id')
            ->select('reported_id', 'status')
            ->selectRaw('COUNT(DISTINCT reporter_id) AS reporter_count')
            ->groupBy('reported_id', 'status')
            ->havingRaw('COUNT(DISTINCT reporter_id) >= 2')
            ->get();

        if ($groups->isEmpty()) {
            return 0;
        }

        $corroboratedCount = 0;
        foreach ($groups as $group) {
            $corroboratedCount += (int) AttendanceReport::where('game_id', $gameId)
                ->where('reported_id', $group->reported_id)
                ->where('status', $group->status)
                ->where('reporter_id', '!=', $group->reported_id)
                ->where('is_corroborated', false)
                ->update(['is_corroborated' => true]);
        }

        Log::info('Attendance reports corroborated', [
            'game_id' => $gameId,
            'corroborated_report_count' => $corroboratedCount,
            'corroborated_groups' => $groups->count(),
        ]);

        return $corroboratedCount;
    }
}
