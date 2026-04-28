<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\ParticipantStatus;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class AttendanceService
{
    /**
     * Hours after game completion before auto-attend kicks in.
     */
    public const AUTO_ATTEND_HOURS = 48;

    /**
     * Hours after game before report weight starts decaying.
     */
    public const TIMELINESS_THRESHOLD_HOURS = 72;

    /**
     * Maximum uncorroborated reports in 30 days before quarantine.
     */
    public const QUARANTINE_THRESHOLD = 3;

    /**
     * Quarantine lookback window in days.
     */
    public const QUARANTINE_LOOKBACK_DAYS = 30;

    /**
     * Reliability score below which reporter weight is reduced.
     */
    public const LOW_RELIABILITY_THRESHOLD = 50.0;

    /**
     * Weight multiplier for low-reliability reporters.
     */
    const LOW_RELIABILITY_MULTIPLIER = 0.5;

    /**
     * Weight multiplier for late reports (past timeliness threshold).
     */
    const LATE_REPORT_MULTIPLIER = 0.7;

    /**
     * Maximum players for a game to count as "small" (host cancel penalty threshold).
     */
    const HOST_CANCEL_MIN_ROSTER = 1;

    /**
     * Hours before game time for host cancellation to be considered "late".
     */
    const HOST_CANCEL_LATE_HOURS = 24;

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

        // Record the attendance on the participant
        $this->recordAttendance($reportedParticipant, $status, $reporter, $weight);

        // Create the attendance report record for grief tracking
        AttendanceReport::create([
            'game_id' => $game->id,
            'reporter_id' => $reporter->id,
            'reported_id' => $reported->id,
            'status' => $status,
            'weight_applied' => $weight,
            'is_corroborated' => false,
            'quarantined' => $griefCheck['quarantined'],
        ]);

        // Check for corroboration — same reported user, different reporter
        $this->checkCorroboration($game, $reported, $status);

        Log::info('Attendance reported', [
            'game_id' => $game->id,
            'reporter_id' => $reporter->id,
            'reported_id' => $reported->id,
            'status' => $status,
            'weight' => $weight,
            'quarantined' => $griefCheck['quarantined'],
        ]);

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

        if ($reporterScore < self::LOW_RELIABILITY_THRESHOLD) {
            $weightMultiplier *= self::LOW_RELIABILITY_MULTIPLIER;

            Log::info('Reduced report weight due to low reporter reliability', [
                'reporter_id' => $reporter->id,
                'reporter_score' => $reporterScore,
                'multiplier' => $weightMultiplier,
            ]);
        }

        // 2. Check volume: uncorroborated reports in last 30 days
        $uncorroboratedCount = AttendanceReport::where('reporter_id', $reporter->id)
            ->where('is_corroborated', false)
            ->where('created_at', '>=', now()->subDays(self::QUARANTINE_LOOKBACK_DAYS))
            ->count();

        if ($uncorroboratedCount >= self::QUARANTINE_THRESHOLD) {
            $quarantined = true;

            Log::warning('Reporter quarantined for excessive uncorroborated reports', [
                'reporter_id' => $reporter->id,
                'uncorroborated_count' => $uncorroboratedCount,
                'threshold' => self::QUARANTINE_THRESHOLD,
            ]);

            return [
                'allowed' => false,
                'weight_multiplier' => 0.0,
                'quarantined' => true,
                'reason' => 'Quarantined: ' . $uncorroboratedCount . ' uncorroborated reports in ' . self::QUARANTINE_LOOKBACK_DAYS . ' days',
            ];
        }

        // 3. Check timeliness: reduce weight if >72h since game
        $hoursSinceGame = $game->date_time->diffInHours(now());
        if ($hoursSinceGame > self::TIMELINESS_THRESHOLD_HOURS) {
            $weightMultiplier *= self::LATE_REPORT_MULTIPLIER;

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
        $cutoff = now()->subHours(self::AUTO_ATTEND_HOURS);

        // Find completed games older than 48h
        $games = Game::where('status', 'completed')
            ->where('date_time', '<=', $cutoff)
            ->get();

        $count = 0;

        foreach ($games as $game) {
            // Find approved participants with no attendance status yet
            $unreportedParticipants = $game->participants()
                ->where('status', ParticipantStatus::Approved->value)
                ->whereNull('attendance_status')
                ->get();

            foreach ($unreportedParticipants as $participant) {
                $this->recordAttendance($participant, AttendanceStatus::Attended->value);

                // Create an attendance report record (system-reported)
                AttendanceReport::create([
                    'game_id' => $game->id,
                    'reporter_id' => $participant->user_id, // self-reported by system
                    'reported_id' => $participant->user_id,
                    'status' => AttendanceStatus::Attended->value,
                    'weight_applied' => 1.0,
                    'is_corroborated' => true, // System reports are auto-corroborated
                    'quarantined' => false,
                ]);

                $count++;
            }
        }

        if ($count > 0) {
            Log::info('Auto-attend processed', [
                'games_checked' => $games->count(),
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
        if ($game->status !== 'canceled') {
            return;
        }

        // Check timing: was it cancelled within 24h of game time?
        $hoursUntilGame = now()->diffInHours($game->date_time, false);
        if ($hoursUntilGame >= self::HOST_CANCEL_LATE_HOURS) {
            return;
        }

        // Check roster: was there at least min_players worth of participants?
        $approvedCount = $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->count();

        if ($approvedCount < self::HOST_CANCEL_MIN_ROSTER) {
            return;
        }

        // Find the host's participant record
        $hostParticipant = $game->participants()
            ->where('user_id', $game->owner_id)
            ->first();

        if (! $hostParticipant) {
            return;
        }

        // Record the late cancel on the host's participant record
        $hostParticipant->forceFill([
            'attendance_status' => AttendanceStatus::LateCancel->value,
            'attendance_reported_at' => now(),
            'attendance_weight' => 1.0,
        ])->save();

        // Create report record
        AttendanceReport::create([
            'game_id' => $game->id,
            'reporter_id' => $game->owner_id,
            'reported_id' => $game->owner_id,
            'status' => AttendanceStatus::LateCancel->value,
            'weight_applied' => -0.3, // Late cancel penalty weight
            'is_corroborated' => true,
            'quarantined' => false,
        ]);

        // Recompute host's reliability
        $this->reliabilityService->recomputeAfterAttendance($hostParticipant);

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
            'auto_attend_at' => $game->date_time->addHours(self::AUTO_ATTEND_HOURS)->toIso8601String(),
        ]);

        // The actual auto-attend will be triggered by a scheduled command or
        // the autoAttendAfter48Hours() method called from a scheduler.
        // This method serves as a hook for any immediate post-completion logic.
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
}
