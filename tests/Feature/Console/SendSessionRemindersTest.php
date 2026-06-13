<?php

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;

describe('SendSessionReminders command', function () {
    it('finds games starting within 1 hour and marks reminder_sent_at', function () {
        $owner = User::factory()->create();
        $gameSystem = GameSystem::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'date_time' => now()->addMinutes(45),
            'status' => 'scheduled',
        ]);

        // Add a participant with no push subscriptions — should be counted but no push sent
        $participant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $this->artisan('pwa:send-session-reminders')
            ->assertSuccessful()
            ->expectsOutputToContain('Found 1 game(s)');

        // Verify reminder_sent_at was set
        expect($game->fresh()->reminder_sent_at)->not->toBeNull();
    });

    it('shows dry-run output without sending or marking', function () {
        $owner = User::factory()->create();
        $participant = User::factory()->create([
            'notification_settings' => [
                'participant_joined' => ['database' => true, 'mail' => false, 'push' => true],
            ],
        ]);
        $gameSystem = GameSystem::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'date_time' => now()->addMinutes(45),
            'status' => 'scheduled',
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        PushSubscription::factory()->create([
            'user_id' => $participant->id,
        ]);

        $this->artisan('pwa:send-session-reminders --dry-run')
            ->assertSuccessful()
            ->expectsOutputToContain('Would notify');

        // Should NOT be marked in dry-run
        expect($game->fresh()->reminder_sent_at)->toBeNull();
    });

    it('skips participant with push disabled in notification settings', function () {
        $owner = User::factory()->create();
        $gameSystem = GameSystem::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'date_time' => now()->addMinutes(45),
            'status' => 'scheduled',
        ]);

        // Participant has push subscriptions but push disabled in settings
        $participant = User::factory()->create([
            'notification_settings' => [
                'participant_joined' => ['database' => true, 'mail' => false, 'push' => false],
            ],
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
        PushSubscription::factory()->create([
            'user_id' => $participant->id,
        ]);

        Log::spy();

        $this->artisan('pwa:send-session-reminders')
            ->assertSuccessful();

        // Game should be marked since it was processed
        expect($game->fresh()->reminder_sent_at)->not->toBeNull();

        // No push_failed or push dispatch logs should appear for this participant
        Log::shouldNotHaveReceived('info', fn ($msg) => str_contains($msg, 'push_sent'));
    });

    it('processes multiple games in one run', function () {
        $owner = User::factory()->create();
        $gameSystem = GameSystem::factory()->create();

        $game1 = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'date_time' => now()->addMinutes(30),
            'status' => 'scheduled',
        ]);

        $game2 = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'date_time' => now()->addMinutes(45),
            'status' => 'scheduled',
        ]);

        $participant1 = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game1->id,
            'user_id' => $participant1->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $participant2 = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game2->id,
            'user_id' => $participant2->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $this->artisan('pwa:send-session-reminders')
            ->assertSuccessful()
            ->expectsOutputToContain('Found 2 game(s)');

        // Both games should be marked
        expect($game1->fresh()->reminder_sent_at)->not->toBeNull()
            ->and($game2->fresh()->reminder_sent_at)->not->toBeNull();
    });

    it('logs completion with correct counters', function () {
        $owner = User::factory()->create();
        $gameSystem = GameSystem::factory()->create();

        Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'date_time' => now()->addMinutes(45),
            'status' => 'scheduled',
        ]);

        Log::spy();

        $this->artisan('pwa:send-session-reminders')
            ->assertSuccessful();

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) {
                return $message === 'session_reminders.completed'
                    && isset($context['notified_count'])
                    && isset($context['error_count'])
                    && isset($context['duration_ms']);
            });
    });
});

// ── 24-hour reminder window ─────────────────────────

describe('SendSessionReminders 24-hour window', function () {
    it('finds games starting within 24 hours and sets reminder_24h_sent_at', function () {
        $owner = User::factory()->create();
        $gameSystem = GameSystem::factory()->create();

        // 23 hours from now — within the 24h window
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'date_time' => now()->addHours(23),
            'status' => 'scheduled',
        ]);

        $participant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $this->artisan('pwa:send-session-reminders')
            ->assertSuccessful()
            ->expectsOutputToContain('[24-hour] Found 1 game(s)');

        // 24h reminder column should be set
        expect($game->fresh()->reminder_24h_sent_at)->not->toBeNull();
        // 1h reminder column should NOT be set (game is too far away)
        expect($game->fresh()->reminder_sent_at)->toBeNull();
    });

    it('skips games starting beyond 24 hours for 24h window', function () {
        $owner = User::factory()->create();
        $gameSystem = GameSystem::factory()->create();

        // 25 hours from now — beyond the 24h window
        Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'date_time' => now()->addHours(25),
            'status' => 'scheduled',
        ]);

        $this->artisan('pwa:send-session-reminders')
            ->assertSuccessful()
            ->expectsOutputToContain('[24-hour] Found 0 game(s)');
    });

    it('skips games that already have reminder_24h_sent_at set', function () {
        $owner = User::factory()->create();
        $gameSystem = GameSystem::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'date_time' => now()->addHours(23),
            'status' => 'scheduled',
            'reminder_24h_sent_at' => now(),
        ]);

        $this->artisan('pwa:send-session-reminders')
            ->assertSuccessful()
            ->expectsOutputToContain('[24-hour] Found 0 game(s)');

        // timestamp should remain unchanged
        expect($game->fresh()->reminder_24h_sent_at->timestamp)->toBe($game->reminder_24h_sent_at->timestamp);
    });

    it('1-hour and 24-hour windows are independent', function () {
        $owner = User::factory()->create();
        $gameSystem = GameSystem::factory()->create();

        // Game 50 min away — within both windows, triggers 1h AND 24h
        // (any game within 1h is also within 24h by definition)
        $game1h = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'date_time' => now()->addMinutes(50),
            'status' => 'scheduled',
        ]);

        // Game 12 hours away — within 24h window only, NOT 1h window
        $game24h = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'date_time' => now()->addHours(12),
            'status' => 'scheduled',
        ]);

        $participant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game1h->id,
            'user_id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $participant2 = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game24h->id,
            'user_id' => $participant2->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $this->artisan('pwa:send-session-reminders')
            ->assertSuccessful()
            ->expectsOutputToContain('[24-hour] Found 2 game(s)')
            ->expectsOutputToContain('[1-hour] Found 1 game(s)');

        // 1h game: both timestamps set (within both windows)
        expect($game1h->fresh()->reminder_sent_at)->not->toBeNull();
        expect($game1h->fresh()->reminder_24h_sent_at)->not->toBeNull();

        // 24h-only game: reminder_24h_sent_at set, reminder_sent_at NOT set
        expect($game24h->fresh()->reminder_24h_sent_at)->not->toBeNull();
        expect($game24h->fresh()->reminder_sent_at)->toBeNull();
    });

});
