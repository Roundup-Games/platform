<?php

use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\WebPush;

/**
 * Tests that the session reminder command degrades gracefully when VAPID keys
 * are not configured. Push notifications are skipped but database notifications
 * are still delivered and the command exits successfully.
 */
describe('SendSessionReminders without VAPID keys', function () {
    beforeEach(function () {
        // Swap the WebPush singleton to return null (simulates missing VAPID keys)
        $this->app->instance(WebPush::class, null);
    });

    it('completes without error when VAPID keys are missing', function () {
        $owner = User::factory()->create();
        $gameSystem = GameSystem::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'date_time' => now()->addMinutes(45),
            'status' => 'scheduled',
        ]);

        $participant = User::factory()->create([
            'notification_settings' => [
                'participant_joined' => ['database' => true, 'mail' => false, 'push' => true],
            ],
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
        PushSubscription::factory()->create([
            'user_id' => $participant->id,
        ]);

        $this->artisan('pwa:send-session-reminders')
            ->assertSuccessful();

        // Game should still be marked as processed
        expect($game->fresh()->reminder_sent_at)->not->toBeNull();
    });

    it('completes successfully even when VAPID keys are not configured', function () {
        $owner = User::factory()->create();
        $gameSystem = GameSystem::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'date_time' => now()->addMinutes(45),
            'status' => 'scheduled',
        ]);

        $participant = User::factory()->create([
            'notification_settings' => [
                'participant_joined' => ['database' => true, 'mail' => false, 'push' => true],
            ],
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);
        PushSubscription::factory()->create([
            'user_id' => $participant->id,
        ]);

        $this->artisan('pwa:send-session-reminders')
            ->assertSuccessful();

        // Game should still be marked as processed even though push was silently skipped
        expect($game->fresh()->reminder_sent_at)->not->toBeNull();
    });

    it('runs with no upcoming games even without VAPID keys', function () {
        $this->artisan('pwa:send-session-reminders')
            ->assertSuccessful()
            ->expectsOutputToContain('Found 0 game(s)');
    });
});
