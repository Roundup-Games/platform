<?php

namespace App\Observers;

use App\Enums\NotificationCategory;
use App\Enums\ParticipantStatus;
use App\Jobs\PublishGameBulletinToDiscord;
use App\Models\GameBulletin;
use App\Models\User;
use App\Notifications\BulletinPosted;
use App\Services\DashboardCacheService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

/**
 * The single fan-out hook for bulletin creation (M062/S01).
 *
 * Everything that should happen when a bulletin starts existing happens
 * here, regardless of which path created it (Livewire bulletin board today;
 * Filament or console paths later):
 *
 *   1. Action-center cache invalidation for approved participants.
 *   2. The BulletinPosted notification cascade (in-app, mail, push, Discord
 *      DM) to every approved participant except the author — full content,
 *      participant-only channels.
 *   3. Dispatch of PublishGameBulletinToDiscord, which pushes a TEASER-only
 *      embed (D132) into every live per-session Discord thread for the game.
 */
class GameBulletinObserver
{
    public function __construct(
        private DashboardCacheService $cache,
    ) {}

    public function created(GameBulletin $bulletin): void
    {
        // Invalidate action center for all approved participants
        // so they see the new bulletin in their feed.
        $this->cache->invalidateActionCenterForGameEvent($bulletin->game_id);

        Log::debug('dashboard.bulletin_created', [
            'bulletin_id' => $bulletin->id,
            'game_id' => $bulletin->game_id,
        ]);

        $this->notifyParticipants($bulletin);

        PublishGameBulletinToDiscord::dispatch($bulletin->id);
    }

    public function deleted(GameBulletin $bulletin): void
    {
        $this->cache->invalidateActionCenterForGameEvent($bulletin->game_id);
    }

    /**
     * Send the BulletinPosted notification to every approved participant
     * except the bulletin author (the host, or a platform admin posting on
     * their behalf). Failures are logged and swallowed per participant so one
     * broken channel never blocks the rest of the cascade.
     */
    private function notifyParticipants(GameBulletin $bulletin): void
    {
        $game = $bulletin->game;
        $author = $bulletin->user;

        if ($game === null || $author === null) {
            return;
        }

        $notification = new BulletinPosted($game, $author, $bulletin);

        $participants = $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->where('user_id', '!=', $author->id)
            ->with('user')
            ->get();

        foreach ($participants->pluck('user')->filter() as $participant) {
            if (! ($participant instanceof User)) {
                continue;
            }

            try {
                app(NotificationService::class)->send(
                    $participant,
                    $notification,
                    NotificationCategory::SessionContent,
                );
            } catch (\Throwable $e) {
                Log::error('bulletin.notification_dispatch_failed', [
                    'game_id' => $game->id,
                    'bulletin_id' => $bulletin->id,
                    'participant_id' => $participant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
