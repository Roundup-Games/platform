<?php

use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;

describe('SendSessionReminders command', function () {
    it('runs successfully with no upcoming games', function () {
        $this->artisan('pwa:send-session-reminders')
            ->assertSuccessful()
            ->expectsOutputToContain('Found 0 game(s)');
    });

    it('skips games with reminder already sent', function () {
        $owner = User::factory()->create();
        $gameSystem = GameSystem::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'date_time' => now()->addMinutes(30),
            'status' => 'scheduled',
            'reminder_sent_at' => now(),
        ]);

        $this->artisan('pwa:send-session-reminders')
            ->assertSuccessful()
            ->expectsOutputToContain('Found 0 game(s)');
    });

    it('skips cancelled and completed games', function () {
        $owner = User::factory()->create();
        $gameSystem = GameSystem::factory()->create();

        Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'date_time' => now()->addMinutes(30),
            'status' => 'canceled',
        ]);

        Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'date_time' => now()->addMinutes(30),
            'status' => 'completed',
        ]);

        $this->artisan('pwa:send-session-reminders')
            ->assertSuccessful()
            ->expectsOutputToContain('Found 0 game(s)');
    });

    it('skips games starting beyond 1 hour', function () {
        $owner = User::factory()->create();
        $gameSystem = GameSystem::factory()->create();

        Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'date_time' => now()->addHours(2),
            'status' => 'scheduled',
        ]);

        $this->artisan('pwa:send-session-reminders')
            ->assertSuccessful()
            ->expectsOutputToContain('Found 0 game(s)');
    });

    it('skips games starting in the past', function () {
        $owner = User::factory()->create();
        $gameSystem = GameSystem::factory()->create();

        Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'date_time' => now()->subMinutes(30),
            'status' => 'scheduled',
        ]);

        $this->artisan('pwa:send-session-reminders')
            ->assertSuccessful()
            ->expectsOutputToContain('Found 0 game(s)');
    });

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
            'role' => 'player',
            'status' => 'approved',
        ]);

        $this->artisan('pwa:send-session-reminders')
            ->assertSuccessful()
            ->expectsOutputToContain('Found 1 game(s)');

        // Verify reminder_sent_at was set
        expect($game->fresh()->reminder_sent_at)->not->toBeNull();
    });

    it('excludes game owner from participants', function () {
        $owner = User::factory()->create();
        $gameSystem = GameSystem::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'date_time' => now()->addMinutes(45),
            'status' => 'scheduled',
        ]);

        // Owner is also in participants — should be excluded
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'approved',
        ]);

        $this->artisan('pwa:send-session-reminders')
            ->assertSuccessful();

        // No push should be sent to owner (no push subscriptions anyway)
        // But the game should still be marked
        expect($game->fresh()->reminder_sent_at)->not->toBeNull();
    });

    it('skips pending and rejected participants', function () {
        $owner = User::factory()->create();
        $gameSystem = GameSystem::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'date_time' => now()->addMinutes(45),
            'status' => 'scheduled',
        ]);

        $pendingUser = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $pendingUser->id,
            'role' => 'player',
            'status' => 'pending',
        ]);

        $rejectedUser = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $rejectedUser->id,
            'role' => 'player',
            'status' => 'rejected',
        ]);

        $this->artisan('pwa:send-session-reminders')
            ->assertSuccessful();

        // Game should be marked even though no participants qualified
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
            'role' => 'player',
            'status' => 'approved',
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

    it('logs structured start and completion events', function () {
        Log::spy();

        $this->artisan('pwa:send-session-reminders')
            ->assertSuccessful();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message) => $message === 'session_reminders.started');

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message) => $message === 'session_reminders.completed');
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
            'role' => 'player',
            'status' => 'approved',
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

    it('skips participant with no push subscriptions without errors', function () {
        $owner = User::factory()->create();
        $gameSystem = GameSystem::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'date_time' => now()->addMinutes(45),
            'status' => 'scheduled',
        ]);

        // Participant with push enabled in settings but no subscriptions
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

        $this->artisan('pwa:send-session-reminders')
            ->assertSuccessful();

        // Game should be marked
        expect($game->fresh()->reminder_sent_at)->not->toBeNull();
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
            'role' => 'player',
            'status' => 'approved',
        ]);

        $participant2 = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game2->id,
            'user_id' => $participant2->id,
            'role' => 'player',
            'status' => 'approved',
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
            'role' => 'player',
            'status' => 'approved',
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
            'role' => 'player',
            'status' => 'approved',
        ]);

        $participant2 = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game24h->id,
            'user_id' => $participant2->id,
            'role' => 'player',
            'status' => 'approved',
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

    it('game within both windows gets both reminders in single run', function () {
        $owner = User::factory()->create();
        $gameSystem = GameSystem::factory()->create();

        // Game 30 min away — within BOTH windows (under 1h and under 24h)
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'date_time' => now()->addMinutes(30),
            'status' => 'scheduled',
        ]);

        $participant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        $this->artisan('pwa:send-session-reminders')
            ->assertSuccessful()
            ->expectsOutputToContain('[24-hour] Found 1 game(s)')
            ->expectsOutputToContain('[1-hour] Found 1 game(s)');

        // Both timestamps should be set
        expect($game->fresh()->reminder_24h_sent_at)->not->toBeNull();
        expect($game->fresh()->reminder_sent_at)->not->toBeNull();
    });
});
