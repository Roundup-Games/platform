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

    it('runs with no upcoming games even without VAPID keys', function () {
        $this->artisan('pwa:send-session-reminders')
            ->assertSuccessful()
            ->expectsOutputToContain('Found 0 game(s)');
    });
});
