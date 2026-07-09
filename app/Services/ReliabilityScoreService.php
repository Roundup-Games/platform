<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class ReliabilityScoreService
{
    // NOTE (M048-S03): Under the explicit-owner-participant model (S01), game
    // owners always have a GameParticipant record. computeScore() queries
    // GameParticipant naturally, so host attendance and host cancellation
    // offences are scored identically to regular participants — the only
    // difference being the steeper HOST_WEIGHTS applied by resolveWeight().

    /**
     * Weight constants for each attendance status.
     * Positive = good behaviour, Negative = bad behaviour.
     */
    public const WEIGHTS = [
        // Attended = full positive credit
        'attended' => 1.0,
        // Late cancel = mild penalty (cancelled close to game time)
        'late_cancel' => -0.3,
        // No-show = severe penalty
        'no_show' => -1.0,
        // Excused = neutral (legitimate reason, cancelled well ahead)
        'excused' => 0.0,
        // Cancelled early = neutral (cancelled >24h before game)
        'cancelled_early' => 0.0,
    ];

    /**
     * Host-specific penalty weights.
     * Hosts who cancel games late or no-show their own games face
     * steeper penalties than regular players because they affect
     * all participants, not just themselves.
     */
    public const HOST_WEIGHTS = [
        // Host cancels their own game <24h before start
        'host_cancel_late' => -1.2,
        // Host no-shows their own game
        'host_no_show' => -1.5,
    ];

    /**
     * Minimum number of games required to be classified as Reliable or Active.
     */
    public const MIN_GAMES = 5;

    /**
     * Score threshold for the Reliable tier (percentage).
     */
    public const RELIABLE_THRESHOLD = 95.0;

    /**
     * Compute the full reliability score for a user.
     *
     * Queries all game_participants records with an attendance_status set,
     * applies weight constants, and returns the score as a percentage
     * along with tier classification.
     *
     * @return array{score: float, game_count: int, tier: string, weights_applied: array<string, float>}
     */
    public function computeScore(User $user): array
    {
        $participants = GameParticipant::whereBelongsTo($user)
            ->whereNotNull('attendance_status')
            ->with('game')
            ->get();

        $gameCount = $participants->count();
        $weightsApplied = [];
        $weightedSum = 0.0;

        foreach ($participants as $participant) {
            /** @var AttendanceStatus $status */
            $status = $participant->attendance_status;
            $key = $status->value;
            $weight = $this->resolveWeight($participant, $status);
            $weightedSum += $weight;

            if (! isset($weightsApplied[$key])) {
                $weightsApplied[$key] = 0.0;
            }
            $weightsApplied[$key] += $weight;
        }

        // Score as a percentage: (weighted sum / max possible) * 100
        // Max possible = all games attended = gameCount * 1.0
        $score = $gameCount > 0
            ? round(($weightedSum / ($gameCount * 1.0)) * 100, 2)
            : 0.0;

        // Clamp to 0-100 range — negative scores don't make sense as percentages
        $score = max(0.0, min(100.0, $score));

        $tier = $this->getTier($score, $gameCount);

        Log::info('Reliability score computed', [
            'user_id' => $user->id,
            'score' => $score,
            'game_count' => $gameCount,
            'tier' => $tier,
        ]);

        return [
            'score' => $score,
            'game_count' => $gameCount,
            'tier' => $tier,
            'weights_applied' => $weightsApplied,
        ];
    }

    /**
     * Recompute and persist the reliability score after an attendance change.
     *
     * Does a full recomputation (not delta-based) for correctness, then
     * stores the result on the User model.
     */
    public function recomputeAfterAttendance(GameParticipant $participant): void
    {
        $user = $participant->user;

        if (! $user) {
            return;
        }

        $result = $this->computeScore($user);

        $user->forceFill([
            'reliability_score' => [
                'score' => $result['score'],
                'game_count' => $result['game_count'],
                'tier' => $result['tier'],
                'weights_applied' => $result['weights_applied'],
            ],
            'reliability_computed_at' => now(),
        ])->save();

        Log::info('Reliability score persisted', [
            'user_id' => $user->id,
            'score' => $result['score'],
            'game_count' => $result['game_count'],
            'tier' => $result['tier'],
        ]);
    }

    /**
     * Resolve the weight for a participant's attendance status.
     *
     * Uses host-specific weights when the participant was the game owner
     * and committed a no-show or late cancellation, since hosts affect
     * all participants not just themselves.
     *
     * For peer-reported attendance, the grief-resistance-adjusted
     * attendance_weight on the participant record is applied as a
     * multiplier to the base weight. System-generated records (auto-attend,
     * host cancellation offence) use weight 1.0 — their attendance_weight
     * column stores the raw host penalty, not a grief multiplier.
     */
    public function resolveWeight(GameParticipant $participant, AttendanceStatus $status): float
    {
        $key = $status->value;
        $baseWeight = self::WEIGHTS[$key];

        // Host-specific penalty weights — use the raw constant, not grief multiplier
        if ($participant->game && (string) $participant->game->owner_id === (string) $participant->user_id) {
            if ($status === AttendanceStatus::NoShow) {
                return self::HOST_WEIGHTS['host_no_show'];
            }
            if ($status === AttendanceStatus::LateCancel) {
                return self::HOST_WEIGHTS['host_cancel_late'];
            }
        }

        // Determine whether this is a peer-reported record (grief-adjusted)
        // or a system-generated record (auto-attend, self-reported).
        // System records have attendance_reported_by === null OR
        // reporter === reported (self-report/auto-attend).
        $isSystemGenerated = $participant->attendance_reported_by === null
            || (string) $participant->attendance_reported_by === (string) $participant->user_id;

        if ($isSystemGenerated) {
            return $baseWeight;
        }

        // Peer-reported: apply grief-resistance-adjusted attendance_weight as multiplier
        $attendanceWeight = $participant->attendance_weight ?? 1.0;

        return round($baseWeight * $attendanceWeight, 4);
    }

    /**
     * Classify a user into a reliability tier based on score and game count.
     *
     * - Reliable: score >= 95% AND game_count >= 5
     * - Active:   game_count >= 5 AND score < 95%
     * - Newcomer: game_count < 5
     */
    public function getTier(float $score, int $gameCount): string
    {
        if ($gameCount < self::MIN_GAMES) {
            return 'newcomer';
        }

        if ($score >= self::RELIABLE_THRESHOLD) {
            return 'reliable';
        }

        return 'active';
    }
}
