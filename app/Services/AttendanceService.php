<?php

namespace App\Services;

use App\Enums\AttendanceResolutionMethod;
use App\Enums\AttendanceStatus;
use App\Enums\GameStatus;
use App\Enums\NotificationCategory;
use App\Enums\ParticipantStatus;
use App\Jobs\ResolveAttendance;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Notifications\AttendanceReported;
use App\Notifications\AttendanceResolved;
use App\Notifications\DisputeResolved;
use App\Services\NotificationService;
use App\Services\ReliabilityScoreService;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Tag;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AttendanceService
{
    // ── Tunable thresholds (read from config/attendance.php) ────

    public static function timelinessThresholdHours(): int { return config('attendance.timeliness_threshold_hours', 72); }
    public static function quarantineThreshold(): int { return config('attendance.quarantine_threshold', 3); }
    public static function quarantineLookbackDays(): int { return config('attendance.quarantine_lookback_days', 30); }
    public static function lowReliabilityThreshold(): float { return config('attendance.low_reliability_threshold', 50.0); }
    public static function lowReliabilityMultiplier(): float { return config('attendance.low_reliability_multiplier', 0.5); }
    public static function lateReportMultiplier(): float { return config('attendance.late_report_multiplier', 0.7); }
    public static function hostCancelMinRoster(): int { return config('attendance.host_cancel_min_roster', 1); }
    public static function hostCancelLateHours(): int { return config('attendance.host_cancel_late_hours', 24); }
    public static function participationThreshold(): float { return config('attendance.participation_threshold', 0.5); }
    public static function noShowMajority(): float { return config('attendance.no_show_majority', 0.5); }

    public function __construct(
        private readonly ReliabilityScoreService $reliabilityService,
    ) {}

    // ── 1. Report submission (consensus system) ─────────────────

    /**
     * Submit attendance reports for multiple participants in a single call.
     *
     * Each entry in $reports is ['reported_id' => uuid, 'status' => string, 'reason' => ?string].
     * Validates: reporter is participant, game is completed, window is open,
     * no self-reporting, host-excused requires reason, non-hosts can only use attended/no_show.
     * Creates one AttendanceReport per entry with grief resistance applied.
     * If all participants have filed after this batch, triggers early resolution.
     *
     * @param  array<array{reported_id: string, status: string, reason?: string}>  $reports
     * @return array{success: bool, reason: string}
     */
    public function submitReport(Game $game, User $reporter, array $reports): array
    {
        // Game must be completed
        if ($game->status !== GameStatus::Completed) {
            return ['success' => false, 'reason' => 'Cannot report attendance for a game that is not completed'];
        }

        // Attendance window must be open
        if ($game->attendance_window_opens_at && now()->lt($game->attendance_window_opens_at)) {
            return ['success' => false, 'reason' => 'Attendance reporting window has not opened yet'];
        }

        if ($game->attendance_window_closes_at && now()->gt($game->attendance_window_closes_at)) {
            return ['success' => false, 'reason' => 'Attendance reporting window has closed'];
        }

        // Reporter must be an approved participant
        $reporterParticipant = $game->participants()
            ->where('user_id', $reporter->id)
            ->where('status', ParticipantStatus::Approved->value)
            ->first();

        if (! $reporterParticipant) {
            return ['success' => false, 'reason' => 'Reporter is not an approved participant in this game'];
        }

        // Determine if reporter is the host
        $isHost = $game->owner_id === $reporter->id;

        // Apply grief resistance once for the reporter
        $griefCheck = $this->checkGriefResistance($reporter, $game);

        if (! $griefCheck['allowed']) {
            Log::warning('Attendance report batch blocked by grief resistance', [
                'game_id' => $game->id,
                'reporter_id' => $reporter->id,
                'reason' => $griefCheck['reason'] ?? 'quarantined',
            ]);

            return ['success' => false, 'reason' => 'Report blocked: ' . ($griefCheck['reason'] ?? 'reporter is quarantined')];
        }

        $weight = $griefCheck['weight_multiplier'];

        // Pre-fetch approved participant IDs to avoid N+1 in validation loop
        $approvedUserIds = $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->pluck('user_id')
            ->flip();

        // Check for duplicate reports (same reporter + reported user in this game)
        $alreadyReportedIds = AttendanceReport::where('game_id', $game->id)
            ->where('reporter_id', $reporter->id)
            ->pluck('reported_id')
            ->flip();

        // Validate each report entry
        $validStatuses = [AttendanceStatus::Attended->value, AttendanceStatus::NoShow->value, AttendanceStatus::Excused->value];

        foreach ($reports as $entry) {
            $reportedId = $entry['reported_id'] ?? null;
            $status = $entry['status'] ?? null;

            if (! $reportedId || ! $status) {
                return ['success' => false, 'reason' => 'Each report must include reported_id and status'];
            }

            if (! in_array($status, $validStatuses, true)) {
                return ['success' => false, 'reason' => "Invalid attendance status: {$status}. Allowed: attended, no_show, excused"];
            }

            // No self-reporting
            if ($reportedId === $reporter->id) {
                return ['success' => false, 'reason' => 'Cannot report your own attendance'];
            }

            // Non-host reporters can only use attended or no_show
            if (! $isHost && ! in_array($status, [AttendanceStatus::Attended->value, AttendanceStatus::NoShow->value], true)) {
                return ['success' => false, 'reason' => 'Non-host reporters can only report attended or no_show'];
            }

            // Host excused requires a reason
            if ($status === AttendanceStatus::Excused->value && empty($entry['reason'])) {
                return ['success' => false, 'reason' => 'Excused reports must include a reason'];
            }

            // Reported user must be an approved participant
            if (! isset($approvedUserIds[$reportedId])) {
                return ['success' => false, 'reason' => "Reported user {$reportedId} is not an approved participant"];
            }

            // Cannot report the same person twice in the same game
            if (isset($alreadyReportedIds[$reportedId])) {
                return ['success' => false, 'reason' => 'You have already reported attendance for this participant'];
            }
        }

        // Race guard: filter out already-reported users (handles double-submit between
        // the validation loop above and the DB insert below). This IS reachable when a
        // concurrent request inserts a report after the validation fast-fail check but
        // before we reach this filter.
        $reports = array_values(array_filter($reports, fn ($entry) => ! isset($alreadyReportedIds[$entry['reported_id']])));

        if (empty($reports)) {
            return ['success' => false, 'reason' => 'No new reports to submit (all already reported)'];
        }

        // Create reports in a transaction
        $created = 0;
        DB::transaction(function () use ($game, $reporter, $reports, $weight, $griefCheck, &$created) {
            foreach ($reports as $entry) {
                AttendanceReport::create([
                    'game_id' => $game->id,
                    'reporter_id' => $reporter->id,
                    'reported_id' => $entry['reported_id'],
                    'status' => $entry['status'],
                    'weight_applied' => $weight,
                    'is_corroborated' => false,
                    'quarantined' => $griefCheck['quarantined'],
                    'reason' => $entry['reason'] ?? null,
                ]);

                $created++;
            }
        });

        Log::info('Attendance reports submitted', [
            'game_id' => $game->id,
            'reporter_id' => $reporter->id,
            'report_count' => $created,
            'weight' => $weight,
            'quarantined' => $griefCheck['quarantined'],
        ]);

        // Send notification to each reported user (batch-fetch users and reports)
        try {
            $notificationService = app(NotificationService::class);
            $reportedIds = collect($reports)->pluck('reported_id')->unique();
            $usersById = User::whereIn('id', $reportedIds)->get()->keyBy('id');
            $freshReports = AttendanceReport::where('game_id', $game->id)
                ->where('reporter_id', $reporter->id)
                ->whereIn('reported_id', $reportedIds)
                ->get()
                ->groupBy('reported_id');

            foreach ($reports as $entry) {
                $reportedUser = $usersById[$entry['reported_id']] ?? null;
                if ($reportedUser) {
                    $report = ($freshReports[$entry['reported_id']] ?? collect())->last();

                    if ($report) {
                        $notificationService->send(
                            $reportedUser,
                            new AttendanceReported($game, $report),
                            NotificationCategory::AttendanceReported,
                        );
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to send some attendance reported notifications', [
                'game_id' => $game->id,
                'reporter_id' => $reporter->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Check if all approved participants have filed reports → trigger early resolution
        $this->checkAndResolveEarlyConsensus($game);

        return ['success' => true, 'reason' => "{$created} report(s) submitted"];
    }

    /**
     * Check if all approved participants have filed at least one report.
     * If so, trigger early consensus resolution.
     */
    private function checkAndResolveEarlyConsensus(Game $game): void
    {
        $approvedParticipantCount = $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->count();

        $distinctReporters = AttendanceReport::where('game_id', $game->id)
            ->distinct()
            ->count('reporter_id');

        if ($distinctReporters >= $approvedParticipantCount) {
            Log::info('All participants have filed reports — dispatching early consensus resolution', [
                'game_id' => $game->id,
                'participants' => $approvedParticipantCount,
                'reporters' => $distinctReporters,
            ]);

            // Dispatch to queue instead of running synchronously — resolution involves
            // per-participant reliability recomputation and notification dispatch which
            // can take seconds for large games. The idempotent guard in resolveGameAttendance
            // prevents double-resolution if the timeout job also fires.
            ResolveAttendance::dispatch($game, AttendanceResolutionMethod::EarlyConsensus);
        }
    }

    // ── 2. Consensus resolution ─────────────────────────────────

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
        $participants = $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->get();

        // Pre-fetch all reports for the game once (avoids N+1 inside participant loop)
        $allReports = AttendanceReport::where('game_id', $game->id)->get();

        // Total non-self participants (each participant's denominator excludes themselves)
        $totalParticipants = $participants->count();
        $participationThreshold = static::participationThreshold();
        $noShowMajority = static::noShowMajority();

        DB::transaction(function () use ($game, $participants, $totalParticipants, $resolutionMethod, $allReports, $participationThreshold, $noShowMajority) {
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
                    if ($participant->user_id === $game->owner_id) {
                        $hostWeight = (float) config('attendance.host_no_show_weight', -1.5);
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

            Log::info('Game attendance resolved', [
                'game_id' => $game->id,
                'resolution_method' => $resolutionMethod->value,
                'participants_resolved' => $participants->count(),
            ]);
        });

        // IMPORTANT: $participants->load('user') MUST run outside the transaction
        // to avoid stale relationship data from inside the DB lock.
        try {
            $notificationService = app(NotificationService::class);
            $participants->load('user');

            foreach ($participants as $participant) {
                if ($participant->attendance_status === null) {
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
            'attendance_weight' => ReliabilityScoreService::WEIGHTS[$status->value] ?? 0.0,
        ])->save();

        $this->reliabilityService->recomputeAfterAttendance($participant);
    }

    // ── 3. Grief resistance (kept from prior implementation) ────

    /**
     * Check grief resistance for a reporter on a specific game.
     *
     * Evaluates reporter reliability, report volume, and timeliness.
     *
     * @return array{allowed: bool, weight_multiplier: float, quarantined: bool, reason?: string}
     */
    public function checkGriefResistance(User $reporter, Game $game): array
    {
        $weightMultiplier = 1.0;
        $quarantined = false;

        // 1. Check reporter's own reliability score
        $reliabilityData = $reporter->reliability_score;
        $reporterScore = $reliabilityData['score'] ?? 100.0; // Default to full for newcomers

        if ($reporterScore < self::lowReliabilityThreshold()) {
            $weightMultiplier *= self::lowReliabilityMultiplier();

            Log::info('Reduced report weight due to low reporter reliability', [
                'reporter_id' => $reporter->id,
                'reporter_score' => $reporterScore,
                'multiplier' => $weightMultiplier,
            ]);
        }

        // 2. Check volume: distinct game sessions with uncorroborated reports in last 30 days
        //    Counting per-game, not per-report, so a host reporting 5 players in one session
        //    counts as 1 game — not 5 uncorroborated reports.
        $uncorroboratedGameCount = AttendanceReport::where('reporter_id', $reporter->id)
            ->where('is_corroborated', false)
            ->where('created_at', '>=', now()->subDays(self::quarantineLookbackDays()))
            ->distinct()
            ->count('game_id');

        if ($uncorroboratedGameCount >= self::quarantineThreshold()) {
            $quarantined = true;

            Log::warning('Reporter quarantined for excessive uncorroborated reports', [
                'reporter_id' => $reporter->id,
                'uncorroborated_game_count' => $uncorroboratedGameCount,
                'threshold' => self::quarantineThreshold(),
            ]);

            return [
                'allowed' => false,
                'weight_multiplier' => 0.0,
                'quarantined' => true,
                'reason' => 'Quarantined: ' . $uncorroboratedGameCount . ' uncorroborated game sessions in ' . self::quarantineLookbackDays() . ' days',
            ];
        }

        // 3. Check timeliness: reduce weight if >72h since game
        $hoursSinceGame = $game->date_time->diffInHours(now());
        if ($hoursSinceGame > self::timelinessThresholdHours()) {
            $weightMultiplier *= self::lateReportMultiplier();

            Log::info('Reduced report weight due to late reporting', [
                'reporter_id' => $reporter->id,
                'hours_since_game' => $hoursSinceGame,
                'multiplier' => $weightMultiplier,
            ]);
        }

        return [
            'allowed' => true,
            'weight_multiplier' => round($weightMultiplier, 2),
            'quarantined' => false,
        ];
    }

    // ── 4. Backward-compatible methods (kept) ───────────────────

    /**
     * Legacy single-report method. Does NOT apply corroboration or consensus logic.
     *
     * @deprecated Use submitReport() for consensus-based attendance reporting.
     *             This method is retained for backward compatibility only.
     *
     * @return array{success: bool, reason: string}
     */
    public function reportAttendance(Game $game, User $reporter, User $reported, string $status): array
    {
        // Validate status is a valid AttendanceStatus value
        $validStatuses = AttendanceStatus::values();
        if (! in_array($status, $validStatuses, true)) {
            return ['success' => false, 'reason' => "Invalid attendance status: {$status}"];
        }

        // Game must have occurred
        if ($game->date_time->isFuture()) {
            return ['success' => false, 'reason' => 'Cannot report attendance for a future game'];
        }

        // Game must not be cancelled
        if ($game->status === GameStatus::Canceled) {
            return ['success' => false, 'reason' => 'Cannot report attendance for a cancelled game'];
        }

        // Reporter must be an approved participant or the game owner.
        $reporterParticipant = $game->participants()
            ->where('user_id', $reporter->id)
            ->first();

        if (! $reporterParticipant && $game->owner_id !== $reporter->id) {
            return ['success' => false, 'reason' => 'Reporter is not a participant in this game'];
        }

        // Reported user must be a participant or the game owner.
        $reportedParticipant = $game->participants()
            ->where('user_id', $reported->id)
            ->first();

        if (! $reportedParticipant && $game->owner_id !== $reported->id) {
            return ['success' => false, 'reason' => 'Reported user is not a participant in this game'];
        }

        // Cannot self-report as host for own attendance
        if ($reporter->id === $reported->id && $game->owner_id === $reporter->id) {
            return ['success' => false, 'reason' => 'Host cannot self-report attendance'];
        }

        // Self-reporting is allowed for non-hosts (only 'attended' or 'excused')
        if ($reporter->id === $reported->id && ! in_array($status, ['attended', 'excused'], true)) {
            return ['success' => false, 'reason' => 'Self-reporting is only allowed for attended or excused status'];
        }

        // Apply grief resistance
        $griefCheck = $this->checkGriefResistance($reporter, $game);

        if (! $griefCheck['allowed']) {
            Log::warning('Attendance report blocked by grief resistance', [
                'game_id' => $game->id,
                'reporter_id' => $reporter->id,
                'reported_id' => $reported->id,
                'reason' => $griefCheck['reason'] ?? 'quarantined',
            ]);

            return ['success' => false, 'reason' => 'Report blocked: ' . ($griefCheck['reason'] ?? 'reporter is quarantined')];
        }

        $weight = $griefCheck['weight_multiplier'];

        // Bail if reported participant has no record
        if ($reportedParticipant === null) {
            Log::error('Attendance report skipped: reported user has no participant record (data integrity gap)', [
                'game_id' => $game->id,
                'reported_id' => $reported->id,
                'is_owner' => $game->owner_id === $reported->id,
            ]);

            return ['success' => false, 'reason' => 'Reported user has no participant record'];
        }

        // Record attendance, create report atomically
        DB::transaction(function () use ($reportedParticipant, $status, $reporter, $weight, $game, $reported, $griefCheck) {
            $this->recordAttendance($reportedParticipant, $status, $reporter, $weight);

            AttendanceReport::create([
                'game_id' => $game->id,
                'reporter_id' => $reporter->id,
                'reported_id' => $reported->id,
                'status' => $status,
                'weight_applied' => $weight,
                'is_corroborated' => false,
                'quarantined' => $griefCheck['quarantined'],
            ]);

        });

        Log::info('Attendance reported (legacy)', [
            'game_id' => $game->id,
            'reporter_id' => $reporter->id,
            'reported_id' => $reported->id,
            'status' => $status,
            'weight' => $weight,
            'quarantined' => $griefCheck['quarantined'],
        ]);

        // Notify the reported user
        try {
            $notificationService = app(NotificationService::class);
            $report = AttendanceReport::where('game_id', $game->id)
                ->where('reported_id', $reported->id)
                ->where('reporter_id', $reporter->id)
                ->orderByDesc('created_at')
                ->first();
            if ($report) {
                $notificationService->send(
                    $reported,
                    new AttendanceReported($game, $report),
                    NotificationCategory::AttendanceReported,
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to send attendance reported notification', [
                'game_id' => $game->id,
                'reporter_id' => $reporter->id,
                'reported_id' => $reported->id,
                'error' => $e->getMessage(),
            ]);
        }

        return ['success' => true, 'reason' => 'Attendance recorded'];
    }

    /**
     * Sets the attendance status, reporter, timestamp, and weight.
     * Triggers reliability recomputation for the reported user.
     */
    public function recordAttendance(GameParticipant $participant, string $status, ?User $reporter = null, ?float $weightOverride = null): void
    {
        $participant->forceFill([
            'attendance_status' => $status,
            'attendance_reported_by' => $reporter?->id,
            'attendance_reported_at' => now(),
            'attendance_weight' => $weightOverride ?? 1.0,
        ])->save();

        Log::info('Attendance recorded on participant', [
            'participant_id' => $participant->id,
            'user_id' => $participant->user_id,
            'game_id' => $participant->game_id,
            'status' => $status,
            'weight' => $weightOverride ?? 1.0,
            'reporter_id' => $reporter?->id,
        ]);

        // Trigger reliability recomputation
        $this->reliabilityService->recomputeAfterAttendance($participant);
    }

    /**
     * Record a host cancellation offence if cancelled <24h before game time.
     *
     * Only records if the roster was above min_players at the time of cancellation.
     */
    public function recordHostCancellationOffence(Game $game): void
    {
        // Only applies to cancelled games
        if ($game->status !== GameStatus::Canceled) {
            return;
        }

        // Check timing: was it cancelled within 24h of game time?
        $hoursUntilGame = now()->diffInHours($game->date_time, false);
        if ($hoursUntilGame >= self::hostCancelLateHours()) {
            return;
        }

        // Check peak roster
        $peakRoster = $game->participants()
            ->where('user_id', '!=', $game->owner_id)
            ->whereIn('status', [
                ParticipantStatus::Approved->value,
                ParticipantStatus::Removed->value,
            ])
            ->count();

        if ($peakRoster < self::hostCancelMinRoster()) {
            return;
        }

        // Find the host's participant record
        $hostParticipant = $game->participants()
            ->where('user_id', $game->owner_id)
            ->first();

        // Record the late cancel atomically: report + reliability
        DB::transaction(function () use ($hostParticipant, $game) {
            AttendanceReport::create([
                'game_id' => $game->id,
                'reporter_id' => $game->owner_id,
                'reported_id' => $game->owner_id,
                'status' => AttendanceStatus::LateCancel->value,
                'weight_applied' => ReliabilityScoreService::HOST_WEIGHTS['host_cancel_late'],
                'is_corroborated' => true,
                'quarantined' => false,
            ]);

            if ($hostParticipant) {
                $hostParticipant->forceFill([
                    'attendance_status' => AttendanceStatus::LateCancel->value,
                    'attendance_reported_at' => now(),
                    'attendance_weight' => ReliabilityScoreService::HOST_WEIGHTS['host_cancel_late'],
                ])->save();

                $this->reliabilityService->recomputeAfterAttendance($hostParticipant);
            } else {
                Log::warning('Host cancellation offence: no participant record for owner (data integrity issue), reliability score unchanged', [
                    'game_id' => $game->id,
                    'host_id' => $game->owner_id,
                ]);

                $host = User::find($game->owner_id);
                if ($host) {
                    $result = $this->reliabilityService->computeScore($host);
                    $host->forceFill([
                        'reliability_score' => [
                            'score' => $result['score'],
                            'game_count' => $result['game_count'],
                            'tier' => $result['tier'],
                            'weights_applied' => $result['weights_applied'],
                        ],
                        'reliability_computed_at' => now(),
                    ])->save();
                }
            }
        });

        Log::info('Host cancellation offence recorded', [
            'game_id' => $game->id,
            'host_id' => $game->owner_id,
            'hours_until_game' => $hoursUntilGame,
            'peak_roster' => $peakRoster,
        ]);
    }

    // ── 5. Read-only query methods ─────────────────────────────

    /**
     * Compute vote tallies for all reported participants in a game.
     *
     * Single grouped query: SELECT reported_id, status, COUNT(*) FROM attendance_reports GROUP BY reported_id, status.
     *
     * @return array<string, array{attended: int, no_show: int, excused: int}>
     */
    public function getVoteTallies(Game $game): array
    {
        $rows = AttendanceReport::where('game_id', $game->id)
            ->selectRaw('reported_id, status, COUNT(*) as count')
            ->groupBy('reported_id', 'status')
            ->get();

        $tallies = [];

        foreach ($rows as $row) {
            $reportedId = $row->reported_id;

            if (! isset($tallies[$reportedId])) {
                // Only consensus-reportable statuses; late_cancel is a pre-game host
                // action and should not appear in vote tallies.
                $tallies[$reportedId] = ['attended' => 0, 'no_show' => 0, 'excused' => 0];
            }

            $statusKey = $row->status instanceof AttendanceStatus
                ? $row->status->value
                : $row->status;

            if (isset($tallies[$reportedId][$statusKey])) {
                $tallies[$reportedId][$statusKey] = (int) $row->count;
            }
        }

        return $tallies;
    }

    /**
     * Check if a user has filed any attendance report for a game.
     */
    public function hasUserReported(Game $game, User $user): bool
    {
        return AttendanceReport::where('game_id', $game->id)
            ->where('reporter_id', $user->id)
            ->exists();
    }

    /**
     * Get the viewer's own resolved attendance status from their participant record.
     *
     * Returns null if the participant has no resolved status yet.
     */
    public function getUserReportedStatus(Game $game, User $viewer): ?AttendanceStatus
    {
        $participant = $game->participants()
            ->where('user_id', $viewer->id)
            ->first();

        return $participant?->attendance_status;
    }

    /**
     * Handle game completion — called when game status transitions to completed.
     *
     * Sets the attendance reporting window on the game.
     */
    public function handleGameCompletion(Game $game): void
    {
        $windowOpens = now();
        $windowCloses = $windowOpens->copy()->addHours(config('attendance.reporting_window_hours', 72));

        $game->forceFill([
            'attendance_window_opens_at' => $windowOpens,
            'attendance_window_closes_at' => $windowCloses,
        ])->save();

        Log::info('Game completion handled — attendance window set', [
            'game_id' => $game->id,
            'window_opens' => $windowOpens->toIso8601String(),
            'window_closes' => $windowCloses->toIso8601String(),
        ]);
    }

    // ── 6. Dispute flow ────────────────────────────────────────

    /**
     * Submit an attendance dispute for a NoShow participant.
     *
     * Authorization: caller must be the participant's user.
     * Validates: participant has a resolved attendance_status of NoShow,
     *            and has not already disputed (attendance_disputed_at is null).
     * Creates an Escalated ticket with full context, marks attendance_disputed_at.
     *
     * @return array{success: bool, reason: string}
     */
    public function disputeAttendanceStatus(GameParticipant $participant, string $reason, User $caller): array
    {
        // Authorization: caller must be the participant's user
        if ($participant->user_id !== $caller->id) {
            return ['success' => false, 'reason' => 'Only the affected participant can dispute their attendance'];
        }

        // Validate: must have a resolved attendance status of NoShow
        if ($participant->attendance_status !== AttendanceStatus::NoShow) {
            return ['success' => false, 'reason' => 'Only NoShow attendance can be disputed'];
        }

        // Validate: not already disputed
        if ($participant->attendance_disputed_at !== null) {
            return ['success' => false, 'reason' => 'This attendance has already been disputed'];
        }

        // Validate: reason is required
        if (empty(trim($reason))) {
            return ['success' => false, 'reason' => 'A reason for the dispute is required'];
        }

        $game = $participant->game;

        // Gather attendance report IDs for this participant
        $reportIds = AttendanceReport::where('game_id', $game->id)
            ->where('reported_id', $participant->user_id)
            ->pluck('id')
            ->toArray();

        // Find or skip the Events department
        $department = Department::where('name', 'Events')->first();

        if ($department === null) {
            Log::warning('attendance_dispute.missing_department', [
                'message' => 'Events department not found — dispute ticket will have no department',
                'participant_id' => $participant->id,
                'game_id' => $game->id,
            ]);
        }

        DB::transaction(function () use ($participant, $reason, $caller, $game, $reportIds, $department) {
            // Create Escalated ticket
            $ticket = Ticket::create([
                'requester_type' => User::class,
                'requester_id' => $caller->id,
                'subject' => 'Attendance Dispute: ' . $game->name,
                'description' => $reason,
                'status' => TicketStatus::Open->value,
                'priority' => \Escalated\Laravel\Enums\TicketPriority::Medium->value,
                'department_id' => $department?->id,
                'ticket_type' => 'attendance_dispute',
                'channel' => \Escalated\Laravel\Enums\TicketChannel::Web->value,
                'metadata' => [
                    'game_id' => $game->id,
                    'participant_id' => $participant->id,
                    'user_id' => $participant->user_id,
                    'disputed_status' => $participant->attendance_status->value,
                    'reason' => $reason,
                    'attendance_report_ids' => $reportIds,
                ],
            ]);

            // Apply attendance-dispute tag
            $tag = Tag::where('name', 'attendance-dispute')->first();
            if ($tag) {
                $ticket->tags()->syncWithoutDetaching([$tag->id]);
            }

            // Mark disputed_at on participant
            $participant->forceFill([
                'attendance_disputed_at' => now(),
            ])->save();

            Log::info('Attendance dispute ticket created', [
                'game_id' => $game->id,
                'participant_id' => $participant->id,
                'user_id' => $caller->id,
                'ticket_id' => $ticket->id,
                'ticket_reference' => $ticket->reference,
                'disputed_status' => $participant->attendance_status->value,
            ]);
        });

        return ['success' => true, 'reason' => 'Dispute submitted successfully'];
    }

    /**
     * Admin-resolve an attendance dispute by overriding a participant's status.
     *
     * Called from Filament (S05) or ticket resolution hook.
     * Sets the new attendance_status on the participant,
     * logs the override on an attendance report record,
     * and triggers reliability recalculation.
     *
     * @return array{success: bool, reason: string}
     */
    public function adminResolveAttendance(GameParticipant $participant, AttendanceStatus $newStatus, User $admin, string $overrideReason, bool $requireDispute = true): array
    {
        // Validate: participant must have been disputed (unless called as direct override)
        if ($requireDispute && $participant->attendance_disputed_at === null) {
            return ['success' => false, 'reason' => 'Participant has not disputed their attendance'];
        }

        $game = $participant->game;
        $oldStatus = $participant->attendance_status;

        DB::transaction(function () use ($participant, $newStatus, $admin, $overrideReason, $game, $oldStatus) {
            // Create an admin override report record
            AttendanceReport::create([
                'game_id' => $game->id,
                'reporter_id' => $admin->id,
                'reported_id' => $participant->user_id,
                'status' => $newStatus->value,
                'weight_applied' => 1.0,
                'is_corroborated' => true,
                'quarantined' => false,
                'reason' => 'Admin override: ' . $overrideReason,
            ]);

            // Apply the new status
            $participant->forceFill([
                'attendance_status' => $newStatus,
                'attendance_reported_at' => now(),
                'attendance_weight' => ReliabilityScoreService::WEIGHTS[$newStatus->value] ?? 0.0,
                'attendance_disputed_at' => null, // Clear dispute flag
            ])->save();

            // Trigger reliability recalculation
            $this->reliabilityService->recomputeAfterAttendance($participant);

            Log::info('Attendance admin-resolved after dispute', [
                'game_id' => $game->id,
                'participant_id' => $participant->id,
                'user_id' => $participant->user_id,
                'admin_id' => $admin->id,
                'old_status' => $oldStatus?->value,
                'new_status' => $newStatus->value,
                'reason' => $overrideReason,
            ]);
        });

        // Notify the affected user of the dispute resolution (via NotificationService
        // to respect channel preferences, block-list, and push dispatch)
        $wasNoShow = $oldStatus === AttendanceStatus::NoShow;
        $resolutionKey = ($wasNoShow && $newStatus !== AttendanceStatus::NoShow)
            ? 'resolved_favor'
            : 'upheld';

        try {
            app(NotificationService::class)->send(
                $participant->user,
                new DisputeResolved($game, $resolutionKey),
                NotificationCategory::DisputeResolved,
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to send DisputeResolved notification', [
                'user_id' => $participant->user_id,
                'game_id' => $game->id,
                'error' => $e->getMessage(),
            ]);
        }

        return ['success' => true, 'reason' => 'Attendance override applied'];
    }
}
