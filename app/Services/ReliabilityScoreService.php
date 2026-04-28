<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class ReliabilityScoreService
{
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
        $participants = GameParticipant::where('user_id', $user->id)
            ->whereNotNull('attendance_status')
            ->get();

        $gameCount = $participants->count();
        $weightsApplied = [];
        $weightedSum = 0.0;

        foreach ($participants as $participant) {
            /** @var AttendanceStatus $status */
            $status = $participant->attendance_status;
            $key = $status->value;
            $weight = self::WEIGHTS[$key] ?? 0.0;
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
