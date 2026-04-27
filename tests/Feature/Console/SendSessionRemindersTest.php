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
            ->expectsOutput('Found 0 upcoming game(s) needing reminders.');
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
            ->expectsOutput('Found 0 upcoming game(s) needing reminders.');
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
            ->expectsOutput('Found 0 upcoming game(s) needing reminders.');
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
            ->expectsOutput('Found 0 upcoming game(s) needing reminders.');
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
            ->expectsOutput('Found 0 upcoming game(s) needing reminders.');
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
            ->expectsOutputToContain('Found 1 upcoming game(s)');

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
            ->expectsOutputToContain('Would send');

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
            ->expectsOutputToContain('Found 2 upcoming game(s)');

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
                    && isset($context['game_count'])
                    && isset($context['push_count'])
                    && isset($context['error_count'])
                    && isset($context['duration_ms']);
            });
    });
});
