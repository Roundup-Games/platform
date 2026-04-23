<?php

namespace App\Services;

use App\Models\GameSystem;
use Illuminate\Support\Facades\Log;
use Throwable;

class PlatformScoreService
{
    /**
     * Type-specific weights for platform scoring.
     *
     * Board games weight active (scheduled) games highest since they indicate
     * current community engagement. TTRPGs weight campaigns highest since
     * campaign play is the primary activity metric.
     */
    private const WEIGHTS = [
        'boardgame' => [
            'favorites' => 10,
            'games' => 3,
            'campaigns' => 5,
            'active_games' => 20,
        ],
        'ttrpg' => [
            'favorites' => 10,
            'games' => 3,
            'campaigns' => 15,
            'active_games' => 10,
        ],
    ];

    /**
     * Compute the platform score for a single game system.
     *
     * Score = (favorites × w_favorites) + (games × w_games)
     *       + (campaigns × w_campaigns) + (active_games × w_active_games)
     *
     * Active games = games with status 'scheduled' and date_time in the future.
     * Falls back to 'boardgame' weights for unknown types.
     */
    public function computeScore(GameSystem $system): int
    {
        $type = $system->type ?? 'boardgame';
        $weights = self::WEIGHTS[$type] ?? self::WEIGHTS['boardgame'];

        $favorites = $system->favoredByUsers()->count();
        $games = $system->games()->count();
        $campaigns = $system->campaigns()->count();
        $activeGames = $system->games()
            ->where('status', 'scheduled')
            ->where('date_time', '>', now())
            ->count();

        return ($favorites * $weights['favorites'])
            + ($games * $weights['games'])
            + ($campaigns * $weights['campaigns'])
            + ($activeGames * $weights['active_games']);
    }

    /**
     * Compute platform scores for all game systems.
     *
     * Processes in chunks of 100 to avoid memory issues. Uses updateQuietly
     * to skip model events (no timestamps updated, no observers triggered).
     *
     * @return array{scored: int, errors: int, duration_ms: float}
     */
    public function computeAll(): array
    {
        $start = microtime(true);
        $scored = 0;
        $errors = 0;

        GameSystem::query()->select('id')->chunkById(100, function ($systems) use (&$scored, &$errors) {
            foreach ($systems as $system) {
                try {
                    $system->platform_score = $this->computeScore($system);
                    $system->updateQuietly(['platform_score' => $system->platform_score]);
                    $scored++;
                } catch (Throwable $e) {
                    $errors++;
                    Log::error('platform_scores.compute_error', [
                        'game_system_id' => $system->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        $durationMs = round((microtime(true) - $start) * 1000, 2);

        Log::info('platform_scores.computed', [
            'scored' => $scored,
            'errors' => $errors,
            'duration_ms' => $durationMs,
        ]);

        return [
            'scored' => $scored,
            'errors' => $errors,
            'duration_ms' => $durationMs,
        ];
    }
}
