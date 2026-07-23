<?php

use App\Models\Game;
use App\Models\GameReminder;
use App\Models\User;
use App\Notifications\SessionReminder;
use Illuminate\Support\Carbon;

// ── Model + persistence ─────────────────────────────

describe('GameReminder model', function () {
    it('creates with a generated UUID string primary key', function () {
        $reminder = GameReminder::factory()->create();

        expect($reminder->id)->toBeString()
            ->and(strlen($reminder->id))->toBe(36) // UUID v4 canonical form
            ->and($reminder->getKeyType())->toBe('string')
            ->and($reminder->incrementing)->toBeFalse();
    });

    it('casts send_at and sent_at to datetime and offset_minutes to integer', function () {
        $reminder = GameReminder::factory()->create([
            'send_at' => '2026-07-23 18:00:00',
            'sent_at' => '2026-07-23 18:01:00',
            'offset_minutes' => '120',
        ]);

        expect($reminder->send_at)->toBeInstanceOf(Carbon::class)
            ->and($reminder->sent_at)->toBeInstanceOf(Carbon::class)
            ->and($reminder->offset_minutes)->toBeInt()->toBe(120);
    });

    it('accepts mass-assigned fillable attributes', function () {
        $game = Game::factory()->create();

        $reminder = GameReminder::create([
            'game_id' => $game->id,
            'send_at' => now()->addHours(3),
            'message' => 'Bring snacks!',
            'offset_minutes' => 180,
            'sent_at' => null,
        ]);

        expect($reminder->message)->toBe('Bring snacks!')
            ->and($reminder->offset_minutes)->toBe(180)
            ->and($reminder->sent_at)->toBeNull();
    });

    it('allows a nullable custom message and offset_minutes', function () {
        $reminder = GameReminder::factory()->create(['message' => null, 'offset_minutes' => null]);

        expect($reminder->message)->toBeNull()
            ->and($reminder->offset_minutes)->toBeNull();
    });
});

// ── Relationships ────────────────────────────────────

describe('GameReminder relationships', function () {
    it('belongs to a Game', function () {
        $game = Game::factory()->create();
        $reminder = GameReminder::factory()->forGame($game)->create();

        expect($reminder->game->is($game))->toBeTrue();
    });

    it('exposes the reminders() hasMany relation on Game', function () {
        $game = Game::factory()->create();
        $one = GameReminder::factory()->forGame($game)->create();
        $two = GameReminder::factory()->forGame($game)->create();

        // A reminder for a different game must not leak in.
        GameReminder::factory()->create();

        expect($game->reminders)->toHaveCount(2)
            ->and($game->reminders->pluck('id')->toArray())
            ->toContain($one->id, $two->id);
    });

    it('cascades on game deletion (reminders removed with the game)', function () {
        $game = Game::factory()->create();
        $reminder = GameReminder::factory()->forGame($game)->create();

        $game->delete();

        expect(GameReminder::find($reminder->id))->toBeNull();
    });
});

// ── Scopes ───────────────────────────────────────────

describe('GameReminder scopes', function () {
    it('scopeUnsent excludes reminders with a sent_at timestamp', function () {
        $game = Game::factory()->create();
        $pending = GameReminder::factory()->forGame($game)->due()->create();
        $sent = GameReminder::factory()->forGame($game)->sent()->create();

        $results = GameReminder::unsent()->get();

        expect($results->contains($pending->id))->toBeTrue()
            ->and($results->contains($sent->id))->toBeFalse();
    });

    it('scopeDue returns unsent reminders whose send_at has passed', function () {
        $game = Game::factory()->create();
        $due = GameReminder::factory()->forGame($game)->due()->create();         // send_at past, unsent
        $future = GameReminder::factory()->forGame($game)->upcoming()->create(); // send_at future
        $sent = GameReminder::factory()->forGame($game)->sent()->create();       // send_at past but already sent

        $results = GameReminder::due()->get();

        expect($results->contains($due->id))->toBeTrue()
            ->and($results->contains($future->id))->toBeFalse()
            ->and($results->contains($sent->id))->toBeFalse();
    });
});

// ── Accessors + markSent ─────────────────────────────

describe('GameReminder accessors + markSent', function () {
    it('isSent reflects the sent_at marker', function () {
        $unsent = GameReminder::factory()->due()->create();
        $sent = GameReminder::factory()->sent()->create();

        expect($unsent->isSent)->toBeFalse()
            ->and($sent->isSent)->toBeTrue();
    });

    it('isDue is true only when send_at passed and not yet sent', function () {
        $due = GameReminder::factory()->due()->create();
        $future = GameReminder::factory()->upcoming()->create();
        $sent = GameReminder::factory()->sent()->create();

        expect($due->isDue)->toBeTrue()
            ->and($future->isDue)->toBeFalse()
            ->and($sent->isDue)->toBeFalse();
    });

    it('markSent stamps sent_at and removes the row from the due sweep', function () {
        $reminder = GameReminder::factory()->due()->create();

        expect($reminder->sent_at)->toBeNull()
            ->and(GameReminder::due()->where('id', $reminder->id)->exists())->toBeTrue();

        $reminder->markSent();

        expect($reminder->fresh()->sent_at)->not->toBeNull()
            ->and(GameReminder::due()->where('id', $reminder->id)->exists())->toBeFalse();
    });
});

// ── Factory states ───────────────────────────────────

describe('GameReminderFactory states', function () {
    it('due() sets a past send_at with no sent_at', function () {
        $reminder = GameReminder::factory()->due()->create();

        expect($reminder->send_at->isPast())->toBeTrue()
            ->and($reminder->sent_at)->toBeNull();
    });

    it('sent() stamps both a past send_at and sent_at', function () {
        $reminder = GameReminder::factory()->sent()->create();

        expect($reminder->send_at->isPast())->toBeTrue()
            ->and($reminder->sent_at)->not->toBeNull();
    });

    it('withMessage() sets custom copy (defaulting when none given)', function () {
        $defaulted = GameReminder::factory()->withMessage()->create();
        $explicit = GameReminder::factory()->withMessage('Custom body')->create();

        expect($defaulted->message)->toBeString()->not->toBeEmpty()
            ->and($explicit->message)->toBe('Custom body');
    });
});

// ── SessionReminder customMessage extension (D125) ──

describe('SessionReminder customMessage extension', function () {
    it('uses the lang-key push body when customMessage is null (built-in reminders unchanged)', function () {
        $game = Game::factory()->create(['date_time' => now()->addHour()]);
        $notifiable = User::factory()->create();

        $payload = (new SessionReminder($game, '1h'))->toPush($notifiable);

        // Lang-key body interpolates the game name — proves the fallback path.
        expect($payload->body)->toContain($game->name);
    });

    it('uses the custom message as the push body when customMessage is provided', function () {
        $game = Game::factory()->create(['date_time' => now()->addHour()]);
        $notifiable = User::factory()->create();

        $payload = (new SessionReminder($game, 'custom', 'Bring your dice and snacks!'))
            ->toPush($notifiable);

        expect($payload->body)->toBe('Bring your dice and snacks!');
    });

    it('keeps the lang-key title even with a custom message', function () {
        $game = Game::factory()->create(['date_time' => now()->addHour()]);
        $notifiable = User::factory()->create();

        $payload = (new SessionReminder($game, 'custom', 'Custom body'))->toPush($notifiable);

        expect($payload->title)->toBe(__('notifications.push_title_session_reminder'));
    });

    it('carries the custom message into the database payload when provided', function () {
        $game = Game::factory()->create(['date_time' => now()->addHour()]);
        $notifiable = User::factory()->create();

        $data = (new SessionReminder($game, 'custom', 'See you at the table!'))
            ->toDatabase($notifiable);

        expect($data['custom_message'])->toBe('See you at the table!')
            ->and($data['type'])->toBe('session_reminder')
            ->and($data['entity_id'])->toBe($game->id);
    });

    it('omits custom_message from the database payload for built-in reminders', function () {
        $game = Game::factory()->create(['date_time' => now()->addHour()]);
        $notifiable = User::factory()->create();

        $data = (new SessionReminder($game, '24h'))->toDatabase($notifiable);

        expect($data)->not->toHaveKey('custom_message')
            ->and($data['window'])->toBe('24h');
    });
});
