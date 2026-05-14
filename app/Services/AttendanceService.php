<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
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

class AttendanceService
{
    // ── Tunable thresholds (read from config/attendance.php) ────

    public static function autoAttendHours(): int { return config('attendance.auto_attend_hours', 48); }
    public static function timelinessThresholdHours(): int { return config('attendance.timeliness_threshold_hours', 72); }
    public static function quarantineThreshold(): int { return config('attendance.quarantine_threshold', 3); }
    public static function quarantineLookbackDays(): int { return config('attendance.quarantine_lookback_days', 30); }
    public static function lowReliabilityThreshold(): float { return config('attendance.low_reliability_threshold', 50.0); }
    public static function lowReliabilityMultiplier(): float { return config('attendance.low_reliability_multiplier', 0.5); }
    public static function lateReportMultiplier(): float { return config('attendance.late_report_multiplier', 0.7); }
    public static function hostCancelMinRoster(): int { return config('attendance.host_cancel_min_roster', 1); }
    public static function hostCancelLateHours(): int { return config('attendance.host_cancel_late_hours', 24); }

    public function __construct(
        private readonly ReliabilityScoreService $reliabilityService,
    ) {}

    /**
     * Report attendance for a participant in a game.
     *
     * Validates that both reporter and reported are participants,
     * the game has occurred, and applies grief resistance checks.
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

        // Reporter must be a participant (approved or host)
        $reporterParticipant = $game->participants()
            ->where('user_id', $reporter->id)
            ->first();

        if (! $reporterParticipant) {
            return ['success' => false, 'reason' => 'Reporter is not a participant in this game'];
        }

        // Reported must be a participant (including host)
        $reportedParticipant = $game->participants()
            ->where('user_id', $reported->id)
            ->first();

        if (! $reportedParticipant) {
            return ['success' => false, 'reason' => 'Reported user is not a participant in this game'];
        }

        // Cannot self-report as host for own attendance (host attendance is self-evident)
        // Host CAN report others, and others CAN report the host
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

        // Record attendance, create report, and check corroboration atomically
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

            $this->checkCorroboration($game, $reported, $status);
        });

        Log::info('Attendance reported', [
            'game_id' => $game->id,
            'reporter_id' => $reporter->id,
            'reported_id' => $reported->id,
            'status' => $status,
            'weight' => $weight,
            'quarantined' => $griefCheck['quarantined'],
        ]);

        // Notify the reported user
        try {
            $notificationService = app(\App\Services\NotificationService::class);
            $report = AttendanceReport::where('game_id', $game->id)
                ->where('reported_id', $reported->id)
                ->where('reporter_id', $reporter->id)
                ->orderByDesc('created_at')
                ->first();
            if ($report) {
                $notificationService->send(
                    $reported,
                    new AttendanceReported($game, $report),
                    \App\Enums\NotificationCategory::AttendanceReported
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

        // 2. Check volume: uncorroborated reports in last 30 days
        $uncorroboratedCount = AttendanceReport::where('reporter_id', $reporter->id)
            ->where('is_corroborated', false)
            ->where('created_at', '>=', now()->subDays(self::quarantineLookbackDays()))
            ->count();

        if ($uncorroboratedCount >= self::quarantineThreshold()) {
            $quarantined = true;

            Log::warning('Reporter quarantined for excessive uncorroborated reports', [
                'reporter_id' => $reporter->id,
                'uncorroborated_count' => $uncorroboratedCount,
                'threshold' => self::quarantineThreshold(),
            ]);

            return [
                'allowed' => false,
                'weight_multiplier' => 0.0,
                'quarantined' => true,
                'reason' => 'Quarantined: ' . $uncorroboratedCount . ' uncorroborated reports in ' . self::quarantineLookbackDays() . ' days',
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

    /**
     * Record attendance on a participant record.
     *
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
     * Auto-attend all approved participants for games completed >48h ago
     * that have no attendance reports yet.
     *
     * @return int Number of participants auto-attended
     */
    public function autoAttendAfter48Hours(): int
    {
        $cutoff = now()->subHours(self::autoAttendHours());
        $count = 0;

        Game::where('status', 'completed')
            ->where('date_time', '<=', $cutoff)
            ->chunkById(100, function ($games) use (&$count) {
                foreach ($games as $game) {
                    DB::transaction(function () use ($game, &$count) {
                        $unreportedParticipants = $game->participants()
                            ->where('status', ParticipantStatus::Approved->value)
                            ->whereNull('attendance_status')
                            ->get();

                        foreach ($unreportedParticipants as $participant) {
                            $this->recordAttendance($participant, AttendanceStatus::Attended->value);

                            AttendanceReport::create([
                                'game_id' => $game->id,
                                'reporter_id' => $participant->user_id,
                                'reported_id' => $participant->user_id,
                                'status' => AttendanceStatus::Attended->value,
                                'weight_applied' => 1.0,
                                'is_corroborated' => true,
                                'quarantined' => false,
                            ]);

                            $count++;
                        }
                    });
                }
            });

        if ($count > 0) {
            Log::info('Auto-attend processed', [
                'participants_auto_attended' => $count,
            ]);
        }

        return $count;
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

        // Check roster: was there at least min_players worth of participants?
        $approvedCount = $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->count();

        if ($approvedCount < self::hostCancelMinRoster()) {
            return;
        }

        // Find the host's participant record
        $hostParticipant = $game->participants()
            ->where('user_id', $game->owner_id)
            ->first();

        if (! $hostParticipant) {
            return;
        }

        // Record the late cancel atomically: participant update + report + reliability
        DB::transaction(function () use ($hostParticipant, $game) {
            $hostParticipant->forceFill([
                'attendance_status' => AttendanceStatus::LateCancel->value,
                'attendance_reported_at' => now(),
                'attendance_weight' => ReliabilityScoreService::HOST_WEIGHTS['host_cancel_late'],
            ])->save();

            AttendanceReport::create([
                'game_id' => $game->id,
                'reporter_id' => $game->owner_id,
                'reported_id' => $game->owner_id,
                'status' => AttendanceStatus::LateCancel->value,
                'weight_applied' => ReliabilityScoreService::HOST_WEIGHTS['host_cancel_late'],
                'is_corroborated' => true,
                'quarantined' => false,
            ]);

            $this->reliabilityService->recomputeAfterAttendance($hostParticipant);
        });

        Log::info('Host cancellation offence recorded', [
            'game_id' => $game->id,
            'host_id' => $game->owner_id,
            'hours_until_game' => $hoursUntilGame,
            'approved_count' => $approvedCount,
        ]);
    }

    /**
     * Handle game completion — called when game status transitions to completed.
     *
     * Marks all approved participants for auto-attend scheduling.
     */
    public function handleGameCompletion(Game $game): void
    {
        Log::info('Game completion handled — auto-attend scheduled', [
            'game_id' => $game->id,
            'auto_attend_at' => $game->date_time->addHours(self::autoAttendHours())->toIso8601String(),
        ]);

        // The actual auto-attend will be triggered by a scheduled command or
        // the autoAttendAfter48Hours() method called from a scheduler.
        // This method serves as a hook for any immediate post-completion logic.
    }

    /**
     * Dispute an attendance report filed against a participant.
     *
     * The disputing user must own the participant record (i.e., they are the
     * one who was reported). Sets the dispute reason on the participant and
     * on the relevant attendance reports for this game/user.
     *
     * Authorization: the caller must be the reported participant's user,
     * the game host/owner, or a global admin.
     *
     * @return array{success: bool, reason: string}
     */
    public function disputeAttendanceReport(string $participantId, string $reason, User $caller): array
    {
        $participant = GameParticipant::find($participantId);

        if ($participant === null) {
            return ['success' => false, 'reason' => 'Participant not found'];
        }

        // Authorization check: caller must be the reported user, the game host, or a global admin
        $game = $participant->game;
        $isReportedUser = $participant->user_id === $caller->id;
        $isHost = $game && $game->owner_id === $caller->id;
        $isAdmin = app(ScopedRoleService::class)->isGlobalAdmin($caller);

        if (! $isReportedUser && ! $isHost && ! $isAdmin) {
            Log::warning('Dispute authorization denied', [
                'participant_id' => $participantId,
                'caller_id' => $caller->id,
                'reported_user_id' => $participant->user_id,
                'game_id' => $game?->id,
                'game_owner_id' => $game?->owner_id,
            ]);

            return ['success' => false, 'reason' => __('attendance.error_dispute_unauthorized')];
        }

        if ($participant->attendance_status === null) {
            return ['success' => false, 'reason' => 'No attendance report to dispute'];
        }

        if ($participant->attendance_dispute_reason !== null) {
            return ['success' => false, 'reason' => 'Attendance already disputed'];
        }

        // Set dispute reason on participant and mark reports atomically
        DB::transaction(function () use ($participant, $reason) {
            $participant->forceFill([
                'attendance_dispute_reason' => $reason,
            ])->save();

            AttendanceReport::where('game_id', $participant->game_id)
                ->where('reported_id', $participant->user_id)
                ->whereNull('dispute_reason')
                ->update([
                    'dispute_reason' => $reason,
                    'disputed_at' => now(),
                ]);
        });

        Log::info('Attendance report disputed', [
            'participant_id' => $participant->id,
            'game_id' => $participant->game_id,
            'user_id' => $participant->user_id,
            'attendance_status' => $participant->attendance_status?->value,
            'reason' => $reason,
        ]);

        return ['success' => true, 'reason' => 'Dispute filed'];
    }

    /**
     * Resolve a dispute by cross-referencing other participants' reports.
     *
     * If 2+ other reports say 'attended' for the same user in the same game,
     * auto-resolve in the player's favor (clear no_show, set attended).
     * Otherwise, the report stands with reduced weight.
     *
     * @return string The resolution outcome: 'resolved_favor' or 'upheld'
     */
    public function resolveDispute(GameParticipant $participant): string
    {
        $game = $participant->game;
        $user = $participant->user;

        // Get corroborating reports (other reporters saying 'attended')
        $corroboratingReports = $this->getCorroboratingReports($game, $user);

        $outcome = DB::transaction(function () use ($participant, $game, $user, $corroboratingReports) {
            if ($corroboratingReports->count() >= 2) {
                // Auto-resolve in player's favor
                $participant->forceFill([
                    'attendance_status' => AttendanceStatus::Attended,
                    'attendance_weight' => 1.0,
                ])->save();

                AttendanceReport::where('game_id', $game->id)
                    ->where('reported_id', $user->id)
                    ->whereNotNull('dispute_reason')
                    ->update([
                        'dispute_resolution' => 'resolved_favor',
                        'dispute_resolved_at' => now(),
                    ]);

                $this->reliabilityService->recomputeAfterAttendance($participant);

                Log::info('Dispute resolved in player favor', [
                    'participant_id' => $participant->id,
                    'game_id' => $game->id,
                    'user_id' => $user->id,
                    'corroborating_count' => $corroboratingReports->count(),
                ]);

                return 'resolved_favor';
            }

            // Report stands — reduce weight but don't clear
            $participant->forceFill([
                'attendance_weight' => max(0.3, ($participant->attendance_weight ?? 1.0) * 0.5),
            ])->save();

            AttendanceReport::where('game_id', $game->id)
                ->where('reported_id', $user->id)
                ->whereNotNull('dispute_reason')
                ->update([
                    'dispute_resolution' => 'upheld',
                    'dispute_resolved_at' => now(),
                ]);

            $this->reliabilityService->recomputeAfterAttendance($participant);

            Log::info('Dispute upheld — report stands with reduced weight', [
                'participant_id' => $participant->id,
                'game_id' => $game->id,
                'user_id' => $user->id,
                'corroborating_count' => $corroboratingReports->count(),
                'reduced_weight' => $participant->attendance_weight,
            ]);

            return 'upheld';
        });

        if ($outcome === 'upheld') {
            // Auto-create an Escalated ticket for manual review
            $this->createDisputeTicket($participant, $corroboratingReports);
        }

        // Notify the disputing user
        try {
            $notificationService = app(\App\Services\NotificationService::class);
            $notificationService->send(
                $user,
                new DisputeResolved($game, $outcome),
                \App\Enums\NotificationCategory::DisputeResolved
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to send dispute resolved notification', [
                'game_id' => $game->id,
                'user_id' => $user->id,
                'resolution' => $outcome,
                'error' => $e->getMessage(),
            ]);
        }

        return $outcome;
    }

    /**
     * Get corroborating reports from other participants for a reported user.
     *
     * Returns attendance reports from other reporters in the same game
     * where they reported the user as 'attended'.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getCorroboratingReports(Game $game, User $reported): \Illuminate\Database\Eloquent\Collection
    {
        return AttendanceReport::where('game_id', $game->id)
            ->where('reported_id', $reported->id)
            ->where('reporter_id', '!=', $reported->id) // Exclude self-reports
            ->where('status', AttendanceStatus::Attended->value)
            ->get();
    }

    /**
     * Check if a report is corroborated by another reporter for the same user/status.
     *
     * When two independent reporters report the same status for the same user
     * in the same game, both reports are marked as corroborated.
     */
    private function checkCorroboration(Game $game, User $reported, string $status): void
    {
        $reportCount = AttendanceReport::where('game_id', $game->id)
            ->where('reported_id', $reported->id)
            ->where('status', $status)
            ->where('reporter_id', '!=', $reported->id) // Exclude self-reports
            ->count();

        if ($reportCount >= 2) {
            // Mark all matching reports as corroborated
            AttendanceReport::where('game_id', $game->id)
                ->where('reported_id', $reported->id)
                ->where('status', $status)
                ->where('reporter_id', '!=', $reported->id)
                ->update(['is_corroborated' => true]);

            Log::info('Attendance reports corroborated', [
                'game_id' => $game->id,
                'reported_id' => $reported->id,
                'status' => $status,
                'corroboration_count' => $reportCount,
            ]);
        }
    }

    /**
     * Create an Escalated ticket for an unresolved attendance dispute.
     *
     * Called when auto-corroboration fails (outcome = 'upheld').
     * Creates a ticket in the Events department tagged 'attendance-dispute'
     * so staff can manually review the dispute.
     */
    private function createDisputeTicket(GameParticipant $participant, $corroboratingReports): void
    {
        $game = $participant->game;
        $user = $participant->user;

        $department = Department::where('name', 'Events')->first();

        if (! $department) {
            Log::warning('Events department not found — cannot create dispute ticket', [
                'participant_id' => $participant->id,
                'game_id' => $game->id,
            ]);

            return;
        }

        $description = sprintf(
            "An attendance dispute could not be auto-resolved.\n\n" .
            "Game: %s (ID: %s)\n" .
            "Date: %s\n" .
            "Disputed status: %s\n" .
            "Dispute reason: %s\n" .
            "Corroborating reports: %d\n" .
            "Current weight: %.2f\n\n" .
            "Please review the attendance reports and resolve manually.",
            $game->name ?? 'Unknown',
            $game->id,
            $game->date_time?->format('Y-m-d H:i') ?? 'N/A',
            $participant->attendance_status?->value ?? 'unknown',
            $participant->attendance_dispute_reason ?? 'No reason provided',
            $corroboratingReports->count(),
            $participant->attendance_weight ?? 0.0,
        );

        $disputeReports = AttendanceReport::where('game_id', $game->id)
            ->where('reported_id', $user->id)
            ->whereNotNull('dispute_reason')
            ->get();

        $metadata = [
            'attendance_dispute' => true,
            'game_id' => $game->id,
            'participant_id' => $participant->id,
            'user_id' => $user->id,
            'dispute_reason' => $participant->attendance_dispute_reason,
            'disputed_status' => $participant->attendance_status?->value,
            'corroborating_count' => $corroboratingReports->count(),
            'attendance_report_ids' => $disputeReports->pluck('id')->toArray(),
        ];

        $ticket = Ticket::create([
            'requester_type' => User::class,
            'requester_id' => $user->id,
            'subject' => 'Attendance Dispute: ' . ($game->name ?? 'Game ' . $game->id),
            'description' => $description,
            'status' => TicketStatus::Open->value,
            'priority' => TicketPriority::Medium->value,
            'department_id' => $department->id,
            'ticket_type' => 'attendance_dispute',
            'channel' => TicketChannel::Web->value,
            'metadata' => $metadata,
        ]);

        // Apply attendance-dispute tag
        $tag = Tag::where('name', 'attendance-dispute')->first();
        if ($tag) {
            $ticket->tags()->syncWithoutDetaching([$tag->id]);
        }

        Log::info('Attendance dispute ticket created', [
            'ticket_id' => $ticket->id,
            'ticket_reference' => $ticket->reference,
            'participant_id' => $participant->id,
            'game_id' => $game->id,
            'user_id' => $user->id,
        ]);
    }

    /**
     * Resolve a dispute from a ticket resolution (manual staff review).
     *
     * When an Events department ticket with ticket_type=attendance_dispute is
     * resolved by staff, this method applies the resolution to the underlying
     * attendance dispute:
     * - resolved_favor: clears no_show, sets attended, full weight
     * - upheld: keeps current status (already upheld by auto-resolution)
     *
     * Sends DisputeResolved notification to the disputing user.
     */
    public function resolveDisputeFromTicket(Ticket $ticket): void
    {
        $metadata = $ticket->metadata ?? [];

        if (($metadata['attendance_dispute'] ?? false) !== true) {
            return;
        }

        $participantId = $metadata['participant_id'] ?? null;
        $gameId = $metadata['game_id'] ?? null;

        if (! $participantId || ! $gameId) {
            Log::warning('Attendance dispute ticket missing participant/game ID', [
                'ticket_id' => $ticket->id,
            ]);

            return;
        }

        $participant = GameParticipant::find($participantId);

        if (! $participant) {
            Log::warning('Participant not found for dispute ticket resolution', [
                'ticket_id' => $ticket->id,
                'participant_id' => $participantId,
            ]);

            return;
        }

        $game = $participant->game;
        $user = $participant->user;

        if (! $game || ! $user) {
            Log::warning('Dispute ticket resolution skipped: missing game or user relation', [
                'ticket_id' => $ticket->id,
                'participant_id' => $participant->id,
                'has_game' => $game !== null,
                'has_user' => $user !== null,
            ]);

            return;
        }

        // Determine outcome from metadata — default to resolved_favor when staff resolves
        // (staff resolving a ticket means they found in favor of the player)
        $outcome = $metadata['staff_resolution'] ?? 'resolved_favor';

        if ($outcome === 'resolved_favor') {
            DB::transaction(function () use ($participant, $game, $user) {
                $participant->forceFill([
                    'attendance_status' => AttendanceStatus::Attended,
                    'attendance_weight' => 1.0,
                ])->save();

                AttendanceReport::where('game_id', $game->id)
                    ->where('reported_id', $user->id)
                    ->whereNotNull('dispute_reason')
                    ->update([
                        'dispute_resolution' => 'resolved_favor',
                        'dispute_resolved_at' => now(),
                    ]);

                $this->reliabilityService->recomputeAfterAttendance($participant);
            });

            Log::info('Dispute resolved from ticket in player favor', [
                'ticket_id' => $ticket->id,
                'participant_id' => $participant->id,
                'game_id' => $game->id,
                'user_id' => $user->id,
            ]);
        }

        // Notify the disputing user
        try {
            $notificationService = app(\App\Services\NotificationService::class);
            $notificationService->send(
                $user,
                new DisputeResolved($game, $outcome),
                \App\Enums\NotificationCategory::DisputeResolved,
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to send dispute resolved notification from ticket', [
                'ticket_id' => $ticket->id,
                'game_id' => $game->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
