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
use App\Notifications\DisputeResolved;
use Escalated\Laravel\Enums\TicketChannel;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Tag;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class AttendanceService
{
    // ── Tunable thresholds (read from config/attendance.php) ────

    public static function timelinessThresholdHours(): int
    {
        $v = config('attendance.timeliness_threshold_hours', 72);

        return is_int($v) ? $v : 72;
    }

    public static function quarantineThreshold(): int
    {
        $v = config('attendance.quarantine_threshold', 3);

        // 0 (or any non-positive value) disables the volume quarantine entirely.
        return is_int($v) ? max($v, 0) : 3;
    }

    public static function quarantineLookbackDays(): int
    {
        $v = config('attendance.quarantine_lookback_days', 30);

        return is_int($v) ? $v : 30;
    }

    public static function lowReliabilityThreshold(): float
    {
        $v = config('attendance.low_reliability_threshold', 50.0);

        return is_numeric($v) ? (float) $v : 50.0;
    }

    public static function lowReliabilityMultiplier(): float
    {
        $v = config('attendance.low_reliability_multiplier', 0.5);

        return is_numeric($v) ? (float) $v : 0.5;
    }

    public static function lateReportMultiplier(): float
    {
        $v = config('attendance.late_report_multiplier', 0.7);

        return is_numeric($v) ? (float) $v : 0.7;
    }

    public static function hostCancelMinRoster(): int
    {
        $v = config('attendance.host_cancel_min_roster', 1);

        return is_int($v) ? $v : 1;
    }

    public static function hostCancelLateHours(): int
    {
        $v = config('attendance.host_cancel_late_hours', 24);

        return is_int($v) ? $v : 24;
    }

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
        // Rate limit the write path (per-user) as defense-in-depth against
        // client-side spam, independent of grief resistance. Limits DB inserts
        // + notification dispatch. Matches ParticipantService's limiter shape.
        $rateLimitKey = 'attendance-submit:'.$reporter->id;
        if (RateLimiter::tooManyAttempts($rateLimitKey, 10)) {
            return ['success' => false, 'reason' => __('games.error_attendance_rate_limited')];
        }
        RateLimiter::hit($rateLimitKey, 60);

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
        /** @var GameParticipant|null $reporterParticipant */
        $reporterParticipant = $game->participants()
            ->where('user_id', $reporter->id)
            ->where('status', ParticipantStatus::Approved->value)
            ->first();

        if (! $reporterParticipant) {
            return ['success' => false, 'reason' => 'Reporter is not an approved participant in this game'];
        }

        // Determine if reporter is the host
        $isHost = (string) $game->owner_id === (string) $reporter->id;

        // Apply grief resistance once for the reporter
        $griefCheck = $this->checkGriefResistance($reporter, $game);

        if (! $griefCheck['allowed']) {
            Log::warning('Attendance report batch blocked by grief resistance', [
                'game_id' => $game->id,
                'reporter_id' => $reporter->id,
                'reason' => $griefCheck['reason'] ?? 'quarantined',
            ]);

            return ['success' => false, 'reason' => 'Report blocked: '.($griefCheck['reason'] ?? 'reporter is quarantined')];
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
            $reportedId = $entry['reported_id'];
            $status = $entry['status'];

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

            // Re-evaluate corroboration now that this batch is recorded. If this
            // reporter is the second independent voice to agree on a status for
            // someone, all agreeing reports flip to is_corroborated=true — which
            // keeps them out of the grief-resistance uncorroborated-game count.
            app(AttendanceResolutionService::class)->markCorroborated($game);
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
        /** @var array{score?: float, game_count?: int, tier?: string, weights_applied?: array<string, mixed>}|null $reliabilityData */
        $reliabilityData = $reporter->reliability_score;
        $reporterScore = is_array($reliabilityData) && isset($reliabilityData['score'])
            ? (float) $reliabilityData['score']
            : 100.0;

        if ($reporterScore < self::lowReliabilityThreshold()) {
            $weightMultiplier *= self::lowReliabilityMultiplier();

            Log::info('Reduced report weight due to low reporter reliability', [
                'reporter_id' => $reporter->id,
                'reporter_score' => $reporterScore,
                'multiplier' => $weightMultiplier,
            ]);
        }

        // 2. Check volume: distinct game sessions with uncorroborated reports in last 30 days.
        //    Only counts games that resolved by EarlyConsensus (every approved
        //    participant reported). Absence of corroboration in a Timeout/Manual
        //    game just means low engagement — not a grief signal — so those are
        //    excluded. See config/attendance.php for the rationale and prod split.
        $threshold = self::quarantineThreshold();

        if ($threshold > 0) {
            $uncorroboratedGameCount = AttendanceReport::where('attendance_reports.reporter_id', $reporter->id)
                ->where('attendance_reports.is_corroborated', false)
                ->where('attendance_reports.created_at', '>=', now()->subDays(self::quarantineLookbackDays()))
                ->join('games', 'games.id', '=', 'attendance_reports.game_id')
                ->whereNotNull('games.attendance_resolved_at')
                ->where('games.attendance_resolution_method', AttendanceResolutionMethod::EarlyConsensus->value)
                ->distinct()
                ->count('attendance_reports.game_id');

            if ($uncorroboratedGameCount >= $threshold) {
                $quarantined = true;

                Log::warning('Reporter quarantined for excessive uncorroborated reports', [
                    'reporter_id' => $reporter->id,
                    'uncorroborated_game_count' => $uncorroboratedGameCount,
                    'threshold' => $threshold,
                ]);

                return [
                    'allowed' => false,
                    'weight_multiplier' => 0.0,
                    'quarantined' => true,
                    'reason' => 'Quarantined: '.$uncorroboratedGameCount.' uncorroborated early-consensus game sessions in '.self::quarantineLookbackDays().' days',
                ];
            }
        }

        // 3. Check timeliness: reduce weight if >72h since game
        $hoursSinceGame = $game->date_time ? $game->date_time->diffInHours(now()) : 0;
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
     * Legacy single-report method. Does not drive consensus resolution, but
     * DOES record corroboration (two independent reporters agreeing on a status)
     * so reports filed via this path still count out of the grief-resistance
     * quarantine the same way as submitReport().
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
        if ($game->date_time?->isFuture() ?? false) {
            return ['success' => false, 'reason' => 'Cannot report attendance for a future game'];
        }

        // Game must not be cancelled
        if ($game->status === GameStatus::Canceled) {
            return ['success' => false, 'reason' => 'Cannot report attendance for a cancelled game'];
        }

        // Reporter must be an approved participant or the game owner.
        /** @var GameParticipant|null $reporterParticipant */
        $reporterParticipant = $game->participants()
            ->where('user_id', $reporter->id)
            ->first();

        if (! $reporterParticipant && (string) $game->owner_id !== (string) $reporter->id) {
            return ['success' => false, 'reason' => 'Reporter is not a participant in this game'];
        }

        // Reported user must be a participant or the game owner.
        /** @var GameParticipant|null $reportedParticipant */
        $reportedParticipant = $game->participants()
            ->where('user_id', $reported->id)
            ->first();

        if (! $reportedParticipant && (string) $game->owner_id !== (string) $reported->id) {
            return ['success' => false, 'reason' => 'Reported user is not a participant in this game'];
        }

        // Cannot self-report as host for own attendance
        if ((string) $reporter->id === (string) $reported->id && (string) $game->owner_id === (string) $reporter->id) {
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

            return ['success' => false, 'reason' => 'Report blocked: '.($griefCheck['reason'] ?? 'reporter is quarantined')];
        }

        $weight = $griefCheck['weight_multiplier'];

        // Bail if reported participant has no record
        if ($reportedParticipant === null) {
            Log::error('Attendance report skipped: reported user has no participant record (data integrity gap)', [
                'game_id' => $game->id,
                'reported_id' => $reported->id,
                'is_owner' => (string) $game->owner_id === (string) $reported->id,
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

            // If this report is the second independent voice for a status,
            // corroborate all agreeing reports (same semantics as submitReport).
            app(AttendanceResolutionService::class)->markCorroborated($game);
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
            /** @var AttendanceReport|null $report */
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
            /** @var string $reportedId */
            $reportedId = $row->reported_id;

            if (! isset($tallies[$reportedId])) {
                // Only consensus-reportable statuses; late_cancel is a pre-game host
                // action and should not appear in vote tallies.
                $tallies[$reportedId] = ['attended' => 0, 'no_show' => 0, 'excused' => 0];
            }

            $statusKey = $row->status->value;

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
        /** @var GameParticipant|null $participant */
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
        $wh = config('attendance.reporting_window_hours', 72);
        $windowCloses = $windowOpens->copy()->addHours(is_numeric($wh) ? (int) $wh : 72);

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

        if ($game === null) {
            return ['success' => false, 'reason' => 'Game not found'];
        }

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
                'subject' => 'Attendance Dispute: '.$game->name,
                'description' => $reason,
                'status' => TicketStatus::Open->value,
                'priority' => TicketPriority::Medium->value,
                'department_id' => $department?->id,
                'ticket_type' => 'attendance_dispute',
                'channel' => TicketChannel::Web->value,
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
                'disputed_status' => $participant->attendance_status->value ?? '',
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
        // Defense-in-depth: verify admin privileges even though Filament gates access.
        // This prevents accidental exposure if the method is called from a non-Filament context.
        if (! app(ScopedRoleService::class)->isGlobalAdmin($admin)) {
            Log::warning('Unauthorized attendance admin override attempt', [
                'admin_id' => $admin->id,
                'participant_id' => $participant->id,
            ]);

            return ['success' => false, 'reason' => 'Only administrators can override attendance'];
        }

        // Validate: participant must have been disputed (unless called as direct override)
        if ($requireDispute && $participant->attendance_disputed_at === null) {
            return ['success' => false, 'reason' => 'Participant has not disputed their attendance'];
        }

        $game = $participant->game;

        if ($game === null) {
            return ['success' => false, 'reason' => 'Game not found'];
        }

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
                'reason' => 'Admin override: '.$overrideReason,
            ]);

            // Apply the new status
            $participant->forceFill([
                'attendance_status' => $newStatus,
                'attendance_reported_at' => now(),
                'attendance_weight' => ReliabilityScoreService::WEIGHTS[$newStatus->value],
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
            if ($participant->user !== null) {
                app(NotificationService::class)->send(
                    $participant->user,
                    new DisputeResolved($game, $resolutionKey),
                    NotificationCategory::DisputeResolved,
                );
            }
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
