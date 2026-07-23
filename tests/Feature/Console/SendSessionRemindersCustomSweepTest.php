<?php

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameReminder;
use App\Models\User;
use App\Notifications\SessionReminder;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

// ── Dispatch + dedup ────────────────────────────────

describe('SendSessionReminders custom sweep', function () {
    it('dispatches a due custom reminder to approved participants and marks sent_at', function () {
        Notification::fake();

        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'status' => 'scheduled',
        ]);
        $participant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $reminder = GameReminder::factory()
            ->forGame($game)
            ->due()
            ->withMessage('Bring your character sheets!')
            ->create();

        $this->artisan('pwa:send-session-reminders')
            ->assertSuccessful()
            ->expectsOutputToContain('[custom] Found 1 due custom reminder(s)');

        // Custom reminder dispatched through SessionReminder with the custom copy.
        Notification::assertSentTo($participant, SessionReminder::class,
            fn (SessionReminder $n) => $n->customMessage === 'Bring your character sheets!'
        );

        // sent_at stamped for dedup.
        expect($reminder->fresh()->sent_at)->not->toBeNull();
    });

    it('marks sent_at even when a participant dispatch throws', function () {
        // NotificationService::send() swallows exceptions internally, so to
        // exercise the sweep's per-recipient try/catch + dedup-on-failure
        // invariant we inject a mock whose send() throws. handle() resolves the
        // service at the top, so the mock is honoured by all three windows —
        // but only the custom window finds work here (the game is days away,
        // outside the built-in windows).
        $this->mock(NotificationService::class)
            ->shouldReceive('send')
            ->andThrow(new RuntimeException('boom'));

        $owner = User::factory()->create();
        // date_time far in the future → no built-in window hit; only the
        // custom reminder is due.
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(7),
        ]);
        $participant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $reminder = GameReminder::factory()->forGame($game)->due()->create();

        Log::spy();

        $this->artisan('pwa:send-session-reminders')
            ->assertFailed(); // errorCount > 0 ⇒ FAILURE

        // Reminder still stamped — dedup invariant holds on failure.
        expect($reminder->fresh()->sent_at)->not->toBeNull();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => $message === 'session_reminders.notification_failed');
    });

    it('shows dry-run output without sending or marking sent_at', function () {
        Notification::fake();

        $owner = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $owner->id, 'status' => 'scheduled']);
        $participant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $reminder = GameReminder::factory()->forGame($game)->due()->create();

        $this->artisan('pwa:send-session-reminders --dry-run')
            ->assertSuccessful()
            ->expectsOutputToContain('[custom] Found 1 due custom reminder(s)')
            ->expectsOutputToContain('Would notify user');

        // Nothing dispatched, nothing marked.
        Notification::assertNothingSent();
        expect($reminder->fresh()->sent_at)->toBeNull();
    });

    it('skips future (not-yet-due) reminders', function () {
        Notification::fake();

        $owner = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $owner->id, 'status' => 'scheduled']);
        $participant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $future = GameReminder::factory()->forGame($game)->upcoming()->create();

        $this->artisan('pwa:send-session-reminders')
            ->assertSuccessful()
            ->expectsOutputToContain('[custom] Found 0 due custom reminder(s)');

        Notification::assertNothingSent();
        expect($future->fresh()->sent_at)->toBeNull();
    });

    it('skips already-sent reminders', function () {
        Notification::fake();

        $owner = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $owner->id, 'status' => 'scheduled']);
        $participant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $sent = GameReminder::factory()->forGame($game)->sent()->create();

        $this->artisan('pwa:send-session-reminders')
            ->assertSuccessful()
            ->expectsOutputToContain('[custom] Found 0 due custom reminder(s)');

        Notification::assertNothingSent();
        // sent_at unchanged.
        expect($sent->fresh()->sent_at)->not->toBeNull();
    });
});

// ── Recipient selection ─────────────────────────────

describe('SendSessionReminders custom sweep recipients', function () {
    it('fans a reminder out to all approved participants', function () {
        Notification::fake();

        $owner = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $owner->id, 'status' => 'scheduled']);
        $p1 = User::factory()->create();
        $p2 = User::factory()->create();
        $p3 = User::factory()->create();
        foreach ([$p1, $p2, $p3] as $p) {
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $p->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
            ]);
        }

        GameReminder::factory()->forGame($game)->due()->create();

        $this->artisan('pwa:send-session-reminders')->assertSuccessful();

        foreach ([$p1, $p2, $p3] as $p) {
            Notification::assertSentTo($p, SessionReminder::class);
        }
    });

    it('excludes the owner from the recipients', function () {
        Notification::fake();

        $owner = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $owner->id, 'status' => 'scheduled']);
        $player = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        GameReminder::factory()->forGame($game)->due()->create();

        $this->artisan('pwa:send-session-reminders')->assertSuccessful();

        Notification::assertSentTo($player, SessionReminder::class);
        Notification::assertNotSentTo($owner, SessionReminder::class);
    });

    it('excludes non-approved participants', function () {
        Notification::fake();

        $owner = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $owner->id, 'status' => 'scheduled']);
        $approved = User::factory()->create();
        $pending = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $approved->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $pending->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        GameReminder::factory()->forGame($game)->due()->create();

        $this->artisan('pwa:send-session-reminders')->assertSuccessful();

        Notification::assertSentTo($approved, SessionReminder::class);
        Notification::assertNotSentTo($pending, SessionReminder::class);
    });
});

// ── Copy resolution ─────────────────────────────────

describe('SendSessionReminders custom sweep copy', function () {
    it('passes null customMessage when the reminder has no custom copy (lang-key fallback)', function () {
        Notification::fake();

        $owner = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $owner->id, 'status' => 'scheduled']);
        $participant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        GameReminder::factory()->forGame($game)->due()->create(['message' => null]);

        $this->artisan('pwa:send-session-reminders')->assertSuccessful();

        Notification::assertSentTo($participant, SessionReminder::class,
            fn (SessionReminder $n) => $n->customMessage === null
        );
    });
});

// ── Observability ───────────────────────────────────

describe('SendSessionReminders custom sweep observability', function () {
    it('logs window_custom_completed with game_count, notified_count, error_count', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $owner->id, 'status' => 'scheduled']);
        $participant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        GameReminder::factory()->forGame($game)->due()->create();

        Log::spy();

        $this->artisan('pwa:send-session-reminders')->assertSuccessful();

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) {
                return $message === 'session_reminders.window_custom_completed'
                    && isset($context['game_count'])
                    && isset($context['notified_count'])
                    && isset($context['error_count'])
                    && $context['game_count'] === 1
                    && $context['notified_count'] === 1
                    && $context['error_count'] === 0;
            });
    });

    it('counts game_count as distinct games across multiple reminders', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $owner->id, 'status' => 'scheduled']);
        $participant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        // Two due reminders for the SAME game — game_count should be 1.
        GameReminder::factory()->forGame($game)->due()->create();
        GameReminder::factory()->forGame($game)->due()->create();

        Log::spy();

        $this->artisan('pwa:send-session-reminders')->assertSuccessful();

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) {
                return $message === 'session_reminders.window_custom_completed'
                    && $context['game_count'] === 1
                    && $context['notified_count'] === 2;
            });
    });

    it('increments error_count on a dispatch failure', function () {
        // Mock whose send() throws — see the dedup-on-failure test above for
        // why a mock (rather than the real service) is required.
        $this->mock(NotificationService::class)
            ->shouldReceive('send')
            ->andThrow(new RuntimeException('boom'));

        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(7),
        ]);
        $participant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        GameReminder::factory()->forGame($game)->due()->create();

        Log::spy();

        $this->artisan('pwa:send-session-reminders')
            ->assertFailed(); // errorCount > 0 ⇒ FAILURE

        // error_count === 1 surfaced in the window log.
        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) {
                return $message === 'session_reminders.window_custom_completed'
                    && $context['error_count'] === 1;
            });
    });
});

// ── Additivity: built-in windows unchanged ──────────

describe('SendSessionReminders custom sweep additivity', function () {
    it('runs alongside the built-in 24h/1h windows without interference', function () {
        Notification::fake();

        $owner = User::factory()->create();

        // A scheduled game within 1h — qualifies for BOTH built-in windows.
        // (GameFactory attaches a game system via afterCreating pivot; the
        // reminder sweep never filters on game_system, so none is set here.)
        $builtinGame = Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => now()->addMinutes(45),
            'status' => 'scheduled',
        ]);
        $builtinParticipant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $builtinGame->id,
            'user_id' => $builtinParticipant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        // A separate game with a due custom reminder but no built-in window hit.
        $customGame = Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => now()->addDays(3),
            'status' => 'scheduled',
        ]);
        $customParticipant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $customGame->id,
            'user_id' => $customParticipant->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
        GameReminder::factory()->forGame($customGame)->due()->create();

        $this->artisan('pwa:send-session-reminders')
            ->assertSuccessful()
            ->expectsOutputToContain('[1-hour] Found 1 game(s)')
            ->expectsOutputToContain('[24-hour] Found 1 game(s)')
            ->expectsOutputToContain('[custom] Found 1 due custom reminder(s)');

        // Built-in participant got the built-in notification (no custom message).
        Notification::assertSentTo($builtinParticipant, SessionReminder::class,
            fn (SessionReminder $n) => $n->customMessage === null
        );
        // Custom participant got the custom-window notification.
        Notification::assertSentTo($customParticipant, SessionReminder::class);

        // Built-in timestamps still set.
        expect($builtinGame->fresh()->reminder_sent_at)->not->toBeNull();
        expect($builtinGame->fresh()->reminder_24h_sent_at)->not->toBeNull();
    });
});
