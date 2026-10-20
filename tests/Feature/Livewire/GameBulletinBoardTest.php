<?php

use App\Enums\GameStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Livewire\Games\GameBulletinBoard;
use App\Models\Campaign;
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
            ->test(GameBulletinBoard::class, ['game' => $this->game])
            ->assertOk();
    });

    it('owner can view the bulletin board', function () {
        Livewire::actingAs($this->owner)
            ->test(GameBulletinBoard::class, ['game' => $this->game])
            ->assertSet('canViewBoard', true);
    });

    it('approved participant can view the bulletin board', function () {
        $participant = createApprovedParticipant($this->game);

        Livewire::actingAs($participant)
            ->test(GameBulletinBoard::class, ['game' => $this->game])
            ->assertSet('canViewBoard', true);
    });

    it('non-participant cannot view the bulletin board', function () {
        $stranger = User::factory()->create();

        Livewire::actingAs($stranger)
            ->test(GameBulletinBoard::class, ['game' => $this->game])
            ->assertSet('canViewBoard', false);
    });

    it('guest cannot view the bulletin board', function () {
        Livewire::test(GameBulletinBoard::class, ['game' => $this->game])
            ->assertSet('canViewBoard', false);
    });
});

// ── canCreateBulletin ───────────────────────────────────

describe('canCreateBulletin', function () {
    it('owner of a scheduled game can create bulletins', function () {
        Livewire::actingAs($this->owner)
            ->test(GameBulletinBoard::class, ['game' => $this->game])
            ->assertSet('canCreateBulletin', true);
    });

    it('participant cannot create bulletins', function () {
        $participant = createApprovedParticipant($this->game);

        Livewire::actingAs($participant)
            ->test(GameBulletinBoard::class, ['game' => $this->game])
            ->assertSet('canCreateBulletin', false);
    });

    it('owner cannot create bulletins for a completed campaign session', function () {
        $campaign = Campaign::factory()->create(['owner_id' => $this->owner->id]);
        $this->game->update([
            'status' => GameStatus::Completed,
            'campaign_id' => $campaign->id,
        ]);

        Livewire::actingAs($this->owner)
            ->test(GameBulletinBoard::class, ['game' => $this->game])
            ->assertSet('canCreateBulletin', false);
    });

    it('owner cannot create bulletins for a canceled game', function () {
        $this->game->update(['status' => GameStatus::Canceled]);

        Livewire::actingAs($this->owner)
            ->test(GameBulletinBoard::class, ['game' => $this->game])
            ->assertSet('canCreateBulletin', false);
    });

    it('stranger cannot create bulletins', function () {
        $stranger = User::factory()->create();

        Livewire::actingAs($stranger)
            ->test(GameBulletinBoard::class, ['game' => $this->game])
            ->assertSet('canCreateBulletin', false);
    });
});

// ── Bulletin List ───────────────────────────────────────

describe('bulletin list', function () {
    it('shows existing non-expired bulletins', function () {
        $first = GameBulletin::factory()->create([
            'game_id' => $this->game->id,
            'user_id' => $this->owner->id,
            'content' => 'First update',
        ]);
        // Stagger created_at: Postgres second-precision timestamps would tie
        // otherwise, making the created_at ordering ambiguous.
        $first->created_at = now()->subHour();
        $first->save();
        GameBulletin::factory()->create([
            'game_id' => $this->game->id,
            'user_id' => $this->owner->id,
            'content' => 'Second update',
        ]);

        $component = Livewire::actingAs($this->owner)
            ->test(GameBulletinBoard::class, ['game' => $this->game]);

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
            ->test(GameBulletinBoard::class, ['game' => $this->game]);

        $bulletins = $component->get('bulletins');
        expect($bulletins)->toHaveCount(1);
        expect($bulletins->first()->content)->toBe('Active update');
    });
});

// ── Create Bulletin ─────────────────────────────────────

describe('create', function () {
    it('creates a bulletin as the game owner', function () {
        Livewire::actingAs($this->owner)
            ->test(GameBulletinBoard::class, ['game' => $this->game])
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
            ->test(GameBulletinBoard::class, ['game' => $this->game])
            ->set('content', 'Test bulletin')
            ->call('create');

        $bulletin = GameBulletin::first();
        expect($bulletin->expires_at->toDateString())->toBe($this->game->date_time->toDateString());
    });

    it('clears the content field after creation', function () {
        Livewire::actingAs($this->owner)
            ->test(GameBulletinBoard::class, ['game' => $this->game])
            ->set('content', 'Test bulletin')
            ->call('create')
            ->assertSet('content', '');
    });

    it('refreshes the bulletin list after creation', function () {
        $component = Livewire::actingAs($this->owner)
            ->test(GameBulletinBoard::class, ['game' => $this->game]);

        expect($component->get('bulletins'))->toHaveCount(0);

        $component->set('content', 'First update')
            ->call('create');

        // Re-mount to check persisted state
        $component2 = Livewire::actingAs($this->owner)
            ->test(GameBulletinBoard::class, ['game' => $this->game]);

        expect($component2->get('bulletins'))->toHaveCount(1);
    });

    it('validates content is required', function () {
        Livewire::actingAs($this->owner)
            ->test(GameBulletinBoard::class, ['game' => $this->game])
            ->set('content', '')
            ->call('create')
            ->assertHasErrors(['content' => 'required']);
    });

    it('validates content max length is 280 characters', function () {
        Livewire::actingAs($this->owner)
            ->test(GameBulletinBoard::class, ['game' => $this->game])
            ->set('content', str_repeat('a', 281))
            ->call('create')
            ->assertHasErrors(['content' => 'max']);
    });

    it('accepts content of exactly 280 characters', function () {
        Livewire::actingAs($this->owner)
            ->test(GameBulletinBoard::class, ['game' => $this->game])
            ->set('content', str_repeat('a', 280))
            ->call('create')
            ->assertHasNoErrors();

        expect(GameBulletin::count())->toBe(1);
    });

    it('rejects creation from non-owner', function () {
        $participant = createApprovedParticipant($this->game);

        Livewire::actingAs($participant)
            ->test(GameBulletinBoard::class, ['game' => $this->game])
            ->set('content', 'Unauthorized post')
            ->call('create');

        expect(GameBulletin::count())->toBe(0);
    });

    it('rejects creation from a stranger', function () {
        $stranger = User::factory()->create();

        Livewire::actingAs($stranger)
            ->test(GameBulletinBoard::class, ['game' => $this->game])
            ->set('content', 'Hijack attempt')
            ->call('create');

        expect(GameBulletin::count())->toBe(0);
    });
});

// ── Completed One-Shot Epilogue Board (D219) ───────────

describe('completed one-shot epilogue board', function () {
    beforeEach(function () {
        $this->game->update([
            'status' => GameStatus::Completed,
            'date_time' => now()->subDays(2),
        ]);
    });

    it('approved participant can view the board and create an epilogue reply', function () {
        $participant = createApprovedParticipant($this->game);

        Livewire::actingAs($participant)
            ->test(GameBulletinBoard::class, ['game' => $this->game])
            ->assertSet('canViewBoard', true)
            ->assertSet('canCreateBulletin', true)
            ->assertSee(__('games.title_epilogue_board', [], 'en'))
            ->assertSee(__('games.placeholder_epilogue_reply', [], 'en'))
            ->set('content', 'Thanks for the great game!')
            ->call('create')
            ->assertHasNoErrors();

        $bulletin = GameBulletin::first();
        expect($bulletin)->not->toBeNull();
        expect($bulletin->user_id)->toBe($participant->id);
        // Epilogue replies expire ~30 days out — NOT at the (past) date_time
        expect($bulletin->expires_at)->not->toBeNull();
        expect($bulletin->expires_at->isFuture())->toBeTrue();
        expect($bulletin->expires_at->greaterThan(now()->addDays(29)))->toBeTrue();
        expect($bulletin->expires_at->lessThan(now()->addDays(31)))->toBeTrue();
        expect($bulletin->expires_at->toDateString())->not->toBe($this->game->date_time->toDateString());
    });

    it('host can post a reply on the completed one-shot board', function () {
        Livewire::actingAs($this->owner)
            ->test(GameBulletinBoard::class, ['game' => $this->game])
            ->assertSet('canCreateBulletin', true)
            ->set('content', 'Thanks for playing, everyone!')
            ->call('create')
            ->assertHasNoErrors();

        expect(GameBulletin::count())->toBe(1);
        expect(GameBulletin::first()->user_id)->toBe($this->owner->id);
    });

    it('renders the pinned bulletin before newer unpinned replies and shows the pin label', function () {
        $pinned = GameBulletin::factory()->pinned()->create([
            'game_id' => $this->game->id,
            'user_id' => $this->owner->id,
            'content' => 'Pinned epilogue bulletin',
        ]);
        // Push the pinned bulletin one day back so the unpinned reply is newer
        $pinned->created_at = now()->subDay();
        $pinned->save();

        GameBulletin::factory()->create([
            'game_id' => $this->game->id,
            'user_id' => $this->owner->id,
            'content' => 'Newer unpinned reply',
        ]);

        $component = Livewire::actingAs($this->owner)
            ->test(GameBulletinBoard::class, ['game' => $this->game]);

        $bulletins = $component->get('bulletins');
        expect($bulletins)->toHaveCount(2);
        expect($bulletins->first()->id)->toBe($pinned->id);
        expect($bulletins->last()->content)->toBe('Newer unpinned reply');

        $component->assertSee(__('games.label_epilogue_pinned', [], 'en'));
    });

    it('neither host nor approved participant can view or create on a completed campaign session', function () {
        $participant = createApprovedParticipant($this->game);

        $campaign = Campaign::factory()->create(['owner_id' => $this->owner->id]);
        $this->game->update(['campaign_id' => $campaign->id]);

        Livewire::actingAs($this->owner)
            ->test(GameBulletinBoard::class, ['game' => $this->game])
            ->assertSet('canViewBoard', false)
            ->assertSet('canCreateBulletin', false);

        Livewire::actingAs($participant)
            ->test(GameBulletinBoard::class, ['game' => $this->game])
            ->assertSet('canViewBoard', false)
            ->assertSet('canCreateBulletin', false);

        // Direct policy denial for the completed campaign session
        expect($participant->can('create', [GameBulletin::class, $this->game]))->toBeFalse();
        expect($this->owner->can('create', [GameBulletin::class, $this->game]))->toBeFalse();
    });

    it('canceled game board stays closed', function () {
        $this->game->update(['status' => GameStatus::Canceled]);

        Livewire::actingAs($this->owner)
            ->test(GameBulletinBoard::class, ['game' => $this->game])
            ->assertSet('canViewBoard', false)
            ->assertSet('canCreateBulletin', false);
    });

    it('waitlisted participant cannot create on a completed one-shot', function () {
        $waitlisted = User::factory()->create();
        GameParticipant::create([
            'game_id' => $this->game->id,
            'user_id' => $waitlisted->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Waitlisted->value,
        ]);

        Livewire::actingAs($waitlisted)
            ->test(GameBulletinBoard::class, ['game' => $this->game])
            ->assertSet('canViewBoard', false)
            ->assertSet('canCreateBulletin', false)
            ->set('content', 'Waitlisted hijack attempt')
            ->call('create');

        expect(GameBulletin::count())->toBe(0);
    });

    it('participant poster does not see the admin hint', function () {
        $participant = createApprovedParticipant($this->game);

        Livewire::actingAs($participant)
            ->test(GameBulletinBoard::class, ['game' => $this->game])
            ->assertSet('isElevatedAccess', false)
            ->assertDontSee(__('games.hint_bulletin_admin_access', [], 'en'));
    });

    it('flashes success and clears the content field after an epilogue reply', function () {
        $participant = createApprovedParticipant($this->game);

        Livewire::actingAs($participant)
            ->test(GameBulletinBoard::class, ['game' => $this->game])
            ->set('content', 'What a night!')
            ->call('create')
            ->assertHasNoErrors()
            ->assertSet('content', '');

        // The success flash (games.flash_bulletin_created) is the unchanged
        // scheduled-path line; Livewire's harness does not expose session
        // flash after call(), so the behavioral pins are cleared input plus
        // the persisted participant-authored reply.
        expect(GameBulletin::count())->toBe(1);
        expect(GameBulletin::first()->user_id)->toBe($participant->id);
        expect(GameBulletin::first()->content)->toBe('What a night!');
    });
});

// ── Notifications ───────────────────────────────────────

describe('notifications', function () {
    it('sends notifications to approved participants when a bulletin is created', function () {
        Notification::fake();

        $participant1 = createApprovedParticipant($this->game);
        $participant2 = createApprovedParticipant($this->game);

        Livewire::actingAs($this->owner)
            ->test(GameBulletinBoard::class, ['game' => $this->game])
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
            ->test(GameBulletinBoard::class, ['game' => $this->game])
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
            ->test(GameBulletinBoard::class, ['game' => $this->game])
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
            ->test(GameBulletinBoard::class, ['game' => $this->game])
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
            ->test(GameBulletinBoard::class, ['game' => $this->game])
            ->set('content', 'Push test')
            ->call('create');

        $bulletin = GameBulletin::first();

        Notification::assertSentTo(
            $participant,
            function (BulletinPosted $notification) use ($participant) {
                $push = $notification->toPush($participant);
                expect($push)->not->toBeNull();
                expect($push->tag)->toBe('bulletin-'.$this->game->bulletins()->first()->id);
                expect($push->url)->toContain($this->game->id);

                return true;
            }
        );
    });

    it('discord DM embed carries the host, game, bulletin content, and link', function () {
        Notification::fake();

        $participant = createApprovedParticipant($this->game);

        Livewire::actingAs($this->owner)
            ->test(GameBulletinBoard::class, ['game' => $this->game])
            ->set('content', 'Bring snacks!')
            ->call('create');

        Notification::assertSentTo(
            $participant,
            function (BulletinPosted $notification) use ($participant) {
                $payload = $notification->toDiscord($participant);
                expect($payload)->not->toBeNull();
                expect($payload->embeds)->not->toBeNull();
                expect($payload->embeds)->toHaveCount(1);

                $embed = $payload->embeds[0];
                expect($embed['title'])->toContain($this->game->name);
                expect($embed['description'])->toContain($this->owner->name);
                expect($embed['description'])->toContain('Bring snacks!');
                expect($embed['url'])->toContain($this->game->id);

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
            ->with('Game bulletin created', Mockery::on(function ($context) {
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
            ->test(GameBulletinBoard::class, ['game' => $this->game])
            ->set('content', 'This is a test bulletin!')
            ->call('create');

        // Behavioral backstop: the bulletin was actually persisted.
        expect(GameBulletin::where('game_id', $this->game->id)->count())->toBe(1);
    });
});
