<?php

use App\Enums\GameStatus;
use App\Enums\NotificationCategory;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameBulletin;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Notifications\BulletinPosted;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->create(['name' => 'Host User']);
    $this->gameSystem = GameSystem::factory()->create();
    $this->game = Game::create([
        'owner_id' => $this->owner->id,
        'game_system_id' => $this->gameSystem->id,
        'name' => ['en' => 'Test Game'],
        'date_time' => now()->addDays(7),
        'description' => ['en' => 'A test game'],
        'expected_duration' => 3,
        'visibility' => 'public',
        'status' => 'scheduled',
        'language' => 'en',
        'location' => ['details' => 'Online'],
        'min_players' => 2,
        'max_players' => 6,
    ]);

    // Explicit owner participant (matches production state after M048)
    GameParticipant::create([
        'game_id' => $this->game->id,
        'user_id' => $this->owner->id,
        'role' => ParticipantRole::Owner->value,
        'status' => ParticipantStatus::Approved->value,
    ]);
});

function createApprovedParticipant(Game $game): User
{
    $user = User::factory()->create();
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $user->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    return $user;
}

// ── Mount & Rendering ────────────────────────────────────

describe('mount and rendering', function () {
    it('mounts with a valid game', function () {
        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameBulletinBoard::class, ['game' => $this->game])
            ->assertOk();
    });

    it('owner can view the bulletin board', function () {
        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameBulletinBoard::class, ['game' => $this->game])
            ->assertSet('canViewBoard', true);
    });

    it('approved participant can view the bulletin board', function () {
        $participant = createApprovedParticipant($this->game);

        Livewire::actingAs($participant)
            ->test(\App\Livewire\Games\GameBulletinBoard::class, ['game' => $this->game])
            ->assertSet('canViewBoard', true);
    });

    it('non-participant cannot view the bulletin board', function () {
        $stranger = User::factory()->create();

        Livewire::actingAs($stranger)
            ->test(\App\Livewire\Games\GameBulletinBoard::class, ['game' => $this->game])
            ->assertSet('canViewBoard', false);
    });

    it('guest cannot view the bulletin board', function () {
        Livewire::test(\App\Livewire\Games\GameBulletinBoard::class, ['game' => $this->game])
            ->assertSet('canViewBoard', false);
    });
});

// ── canCreateBulletin ───────────────────────────────────

describe('canCreateBulletin', function () {
    it('owner of a scheduled game can create bulletins', function () {
        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameBulletinBoard::class, ['game' => $this->game])
            ->assertSet('canCreateBulletin', true);
    });

    it('participant cannot create bulletins', function () {
        $participant = createApprovedParticipant($this->game);

        Livewire::actingAs($participant)
            ->test(\App\Livewire\Games\GameBulletinBoard::class, ['game' => $this->game])
            ->assertSet('canCreateBulletin', false);
    });

    it('owner cannot create bulletins for a completed game', function () {
        $this->game->update(['status' => GameStatus::Completed]);

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameBulletinBoard::class, ['game' => $this->game])
            ->assertSet('canCreateBulletin', false);
    });

    it('owner cannot create bulletins for a canceled game', function () {
        $this->game->update(['status' => GameStatus::Canceled]);

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameBulletinBoard::class, ['game' => $this->game])
            ->assertSet('canCreateBulletin', false);
    });

    it('stranger cannot create bulletins', function () {
        $stranger = User::factory()->create();

        Livewire::actingAs($stranger)
            ->test(\App\Livewire\Games\GameBulletinBoard::class, ['game' => $this->game])
            ->assertSet('canCreateBulletin', false);
    });
});

// ── Bulletin List ───────────────────────────────────────

describe('bulletin list', function () {
    it('shows existing non-expired bulletins', function () {
        GameBulletin::factory()->create([
            'game_id' => $this->game->id,
            'user_id' => $this->owner->id,
            'content' => 'First update',
        ]);
        GameBulletin::factory()->create([
            'game_id' => $this->game->id,
            'user_id' => $this->owner->id,
            'content' => 'Second update',
        ]);

        $component = Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameBulletinBoard::class, ['game' => $this->game]);

        $bulletins = $component->get('bulletins');
        expect($bulletins)->toHaveCount(2);
        // Ordered by created_at desc
        expect($bulletins->first()->content)->toBe('Second update');
    });

    it('excludes expired bulletins', function () {
        GameBulletin::factory()->create([
            'game_id' => $this->game->id,
            'user_id' => $this->owner->id,
            'content' => 'Active update',
            'expires_at' => now()->addDays(7),
        ]);
        GameBulletin::factory()->create([
            'game_id' => $this->game->id,
            'user_id' => $this->owner->id,
            'content' => 'Expired update',
            'expires_at' => now()->subHour(),
        ]);

        $component = Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameBulletinBoard::class, ['game' => $this->game]);

        $bulletins = $component->get('bulletins');
        expect($bulletins)->toHaveCount(1);
        expect($bulletins->first()->content)->toBe('Active update');
    });
});

// ── Create Bulletin ─────────────────────────────────────

describe('create', function () {
    it('creates a bulletin as the game owner', function () {
        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameBulletinBoard::class, ['game' => $this->game])
            ->set('content', 'Running 10 minutes late!')
            ->call('create')
            ->assertHasNoErrors();

        $bulletin = GameBulletin::first();
        expect($bulletin)->not->toBeNull();
        expect($bulletin->content)->toBe('Running 10 minutes late!');
        expect($bulletin->user_id)->toBe($this->owner->id);
        expect($bulletin->game_id)->toBe($this->game->id);
        expect($bulletin->expires_at)->not->toBeNull();
    });

    it('sets expires_at to the game date_time', function () {
        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameBulletinBoard::class, ['game' => $this->game])
            ->set('content', 'Test bulletin')
            ->call('create');

        $bulletin = GameBulletin::first();
        expect($bulletin->expires_at->toDateString())->toBe($this->game->date_time->toDateString());
    });

    it('clears the content field after creation', function () {
        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameBulletinBoard::class, ['game' => $this->game])
            ->set('content', 'Test bulletin')
            ->call('create')
            ->assertSet('content', '');
    });

    it('refreshes the bulletin list after creation', function () {
        $component = Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameBulletinBoard::class, ['game' => $this->game]);

        expect($component->get('bulletins'))->toHaveCount(0);

        $component->set('content', 'First update')
            ->call('create');

        // Re-mount to check persisted state
        $component2 = Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameBulletinBoard::class, ['game' => $this->game]);

        expect($component2->get('bulletins'))->toHaveCount(1);
    });

    it('validates content is required', function () {
        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameBulletinBoard::class, ['game' => $this->game])
            ->set('content', '')
            ->call('create')
            ->assertHasErrors(['content' => 'required']);
    });

    it('validates content max length is 280 characters', function () {
        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameBulletinBoard::class, ['game' => $this->game])
            ->set('content', str_repeat('a', 281))
            ->call('create')
            ->assertHasErrors(['content' => 'max']);
    });

    it('accepts content of exactly 280 characters', function () {
        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameBulletinBoard::class, ['game' => $this->game])
            ->set('content', str_repeat('a', 280))
            ->call('create')
            ->assertHasNoErrors();

        expect(GameBulletin::count())->toBe(1);
    });

    it('rejects creation from non-owner', function () {
        $participant = createApprovedParticipant($this->game);

        Livewire::actingAs($participant)
            ->test(\App\Livewire\Games\GameBulletinBoard::class, ['game' => $this->game])
            ->set('content', 'Unauthorized post')
            ->call('create');

        expect(GameBulletin::count())->toBe(0);
    });

    it('rejects creation from a stranger', function () {
        $stranger = User::factory()->create();

        Livewire::actingAs($stranger)
            ->test(\App\Livewire\Games\GameBulletinBoard::class, ['game' => $this->game])
            ->set('content', 'Hijack attempt')
            ->call('create');

        expect(GameBulletin::count())->toBe(0);
    });
});

// ── Notifications ───────────────────────────────────────

describe('notifications', function () {
    it('sends notifications to approved participants when a bulletin is created', function () {
        Notification::fake();

        $participant1 = createApprovedParticipant($this->game);
        $participant2 = createApprovedParticipant($this->game);

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameBulletinBoard::class, ['game' => $this->game])
            ->set('content', 'Game starts in 30 minutes!')
            ->call('create');

        Notification::assertSentTo(
            [$participant1, $participant2],
            BulletinPosted::class
        );
    });

    it('does not send notification to the host', function () {
        Notification::fake();

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameBulletinBoard::class, ['game' => $this->game])
            ->set('content', 'Host update')
            ->call('create');

        Notification::assertNotSentTo(
            [$this->owner],
            BulletinPosted::class
        );
    });

    it('does not send notifications to waitlisted participants', function () {
        Notification::fake();

        $waitlisted = User::factory()->create();
        GameParticipant::create([
            'game_id' => $this->game->id,
            'user_id' => $waitlisted->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Waitlisted->value,
        ]);

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameBulletinBoard::class, ['game' => $this->game])
            ->set('content', 'Update')
            ->call('create');

        Notification::assertNotSentTo(
            [$waitlisted],
            BulletinPosted::class
        );
    });

    it('notification contains correct data', function () {
        Notification::fake();

        $participant = createApprovedParticipant($this->game);

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameBulletinBoard::class, ['game' => $this->game])
            ->set('content', 'Important update!')
            ->call('create');

        $bulletin = GameBulletin::first();

        Notification::assertSentTo(
            $participant,
            function (BulletinPosted $notification) use ($bulletin, $participant) {
                $db = $notification->toDatabase($participant);
                expect($db['type'])->toBe('bulletin_posted');
                expect($db['entity_type'])->toBe('game');
                expect($db['entity_id'])->toBe($this->game->id);
                expect($db['actor_id'])->toBe($this->owner->id);
                expect($db['bulletin_id'])->toBe($bulletin->id);

                return true;
            }
        );
    });

    it('notification has push payload', function () {
        Notification::fake();

        $participant = createApprovedParticipant($this->game);

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameBulletinBoard::class, ['game' => $this->game])
            ->set('content', 'Push test')
            ->call('create');

        $bulletin = GameBulletin::first();

        Notification::assertSentTo(
            $participant,
            function (BulletinPosted $notification) use ($participant) {
                $push = $notification->toPush($participant);
                expect($push)->not->toBeNull();
                expect($push->tag)->toBe('bulletin-' . $this->game->bulletins()->first()->id);
                expect($push->url)->toContain($this->game->id);

                return true;
            }
        );
    });
});

// ── Logging ─────────────────────────────────────────────

describe('logging', function () {
    it('logs bulletin creation', function () {
        Log::shouldReceive('info')
            ->once()
            ->with('Game bulletin created', \Mockery::on(function ($context) {
                return $context['game_id'] === $this->game->id
                    && $context['user_id'] === $this->owner->id
                    && isset($context['bulletin_id'])
                    && $context['content_length'] === 24;
            }));

        // Cache invalidation and notification dispatch may produce additional log calls
        Log::shouldReceive('debug')->byDefault();
        Log::shouldReceive('error')->byDefault();
        Log::shouldReceive('info')->byDefault();

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\GameBulletinBoard::class, ['game' => $this->game])
            ->set('content', 'This is a test bulletin!')
            ->call('create');
    });
});
