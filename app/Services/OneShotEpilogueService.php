<?php

namespace App\Services;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameBulletin;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

/**
 * Creates the sticky Epilogue bulletin when a one-shot completes (M071 S03, D219).
 *
 * Called from AttendanceService::handleGameCompletion — the single seam both
 * completion paths (host GamesPage::completeGame and AutoCompleteGames) flow
 * through after the status save. The Game::updated expiry sweep has already
 * run by then, so the pinned bulletin is inserted after the sweep and survives
 * it by the is_pinned guard on the sweep itself.
 *
 * Fan-out (action-center invalidation, BulletinPosted cascade, Discord teaser
 * job) is NOT triggered here — GameBulletinObserver::created centralizes it
 * for every creation path.
 */
class OneShotEpilogueService
{
    /**
     * Post the pinned Epilogue bulletin for a completed one-shot.
     *
     * Returns null (no-op) when the game belongs to a campaign — campaigns
     * keep their journal/recap surface — when the game is not completed,
     * or when an epilogue is already pinned (idempotency: both completion
     * paths and retries converge on this guard).
     */
    public function createFor(Game $game): ?GameBulletin
    {
        if ($game->campaign_id !== null) {
            return null;
        }

        if ($game->status !== GameStatus::Completed) {
            return null;
        }

        if ($game->bulletins()->where('is_pinned', true)->exists()) {
            return null;
        }

        // The prompt is player-facing copy pinned atop the table's board
        // for 30 days: resolve it under the GAME's language (the language
        // the table plays in), never the completing actor's ambient locale
        // — the scheduler and an English-UI host must both produce the
        // same prompt as a German table's own host (PreviouslyOnService
        // composition contract; app locale restored in a finally).
        $fallback = config('app.fallback_locale', 'en');
        $locale = $game->language !== ''
            ? $game->language
            : (is_string($fallback) && $fallback !== '' ? $fallback : 'en');

        $previous = App::getLocale();

        App::setLocale($locale);

        try {
            $bulletin = GameBulletin::postBy(
                gameId: (string) $game->id,
                userId: (string) $game->owner_id,
                content: __('games.content_epilogue_prompt'),
                expiresAt: now()->addDays(30)->toDateTimeString(),
                isPinned: true,
            );
        } finally {
            App::setLocale($previous);
        }

        Log::info('Epilogue bulletin created', [
            'game_id' => $game->id,
            'bulletin_id' => $bulletin->id,
        ]);

        return $bulletin;
    }
}
