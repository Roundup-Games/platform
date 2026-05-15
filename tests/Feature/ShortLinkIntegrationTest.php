<?php

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\JoinSource;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\ShortLink;
use App\Models\User;
use App\Policies\CampaignPolicy;
use App\Policies\GamePolicy;
use App\Services\ShortLinkService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Traits\CreatesUsers;

uses(CreatesUsers::class);

beforeEach(function () {
    $this->gameSystem = GameSystem::factory()->create();
});

// ══════════════════════════════════════════════════════════════
// 1. Auto-generation: GM creates game → ShortLink exists
// ══════════════════════════════════════════════════════════════

describe('short link auto-generation on entity creation', function () {
    it('auto-generates a short link when a GM creates a game via Livewire', function () {
        seedPermissions();
        $gm = $this->createSubscribedGm();
        $gm->givePermissionTo('create game');

        Livewire::actingAs($gm)
            ->test(\App\Livewire\Games\CreateGame::class)
            ->set('game_type', 'board_game')
            ->call('selectType', 'board_game')
            ->set('name', 'Test Game')
            ->set('date_time', now()->addDays(7)->format('Y-m-d\TH:i'))
            ->set('max_players', 6)
            ->call('save')
            ->assertHasNoErrors();

        $game = Game::where('owner_id', $gm->id)->first();
        expect($game)->not->toBeNull();

        $shortLinks = ShortLink::where('linkable_type', Game::class)
            ->where('linkable_id', $game->id)
            ->get();

        expect($shortLinks)->toHaveCount(1);
        expect($shortLinks->first()->label)->toBe('Default');
    });

    it('auto-generates a short link when a GM creates a campaign via Livewire', function () {
        seedPermissions();
        $gm = $this->createSubscribedGm();
        $gm->givePermissionTo('create campaign');

        Livewire::actingAs($gm)
            ->test(\App\Livewire\Campaigns\CreateCampaign::class)
            ->set('name', 'Test Campaign')
            ->set('game_system_id', $this->gameSystem->id)
            ->set('max_players', 6)
            ->call('save')
            ->assertHasNoErrors();

        $campaign = Campaign::where('owner_id', $gm->id)->first();
        expect($campaign)->not->toBeNull();

        $shortLinks = ShortLink::where('linkable_type', Campaign::class)
            ->where('linkable_id', $campaign->id)
            ->get();

        expect($shortLinks)->toHaveCount(1);
        expect($shortLinks->first()->label)->toBe('Default');
    });

    it('does not auto-generate a short link when a non-GM creates a game', function () {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);

        // Create game directly — non-GM path doesn't call ShortLinkService
        $game = Game::factory()->create([
            'owner_id' => $user->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $shortLinks = ShortLink::where('linkable_type', Game::class)
            ->where('linkable_id', $game->id)
            ->get();

        expect($shortLinks)->toHaveCount(0);
    });
});

// ══════════════════════════════════════════════════════════════
// 2. Policy bypass: short link cookie grants access to private entity
// ══════════════════════════════════════════════════════════════

describe('short link policy bypass', function () {
    it('grants view access to a private game via ph_link_id cookie (policy)', function () {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
        ]);

        $shortLink = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $owner->id,
        ]);

        $policy = new GamePolicy;

        // Without cookie: stranger cannot view private game
        expect($policy->view($stranger, $game))->toBeFalse();

        // With ph_link_id cookie: stranger CAN view
        $cookie = cookie('ph_link_id', (string) $shortLink->id, 60);
        request()->cookies->set('ph_link_id', (string) $shortLink->id);

        expect($policy->view($stranger, $game))->toBeTrue();
    });

    it('grants view access to a private campaign via ph_link_id cookie (policy)', function () {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'private',
        ]);

        $shortLink = ShortLink::factory()->create([
            'linkable_type' => Campaign::class,
            'linkable_id' => $campaign->id,
            'user_id' => $owner->id,
        ]);

        $policy = new CampaignPolicy;

        // Without cookie: stranger cannot view
        expect($policy->view($stranger, $campaign))->toBeFalse();

        // With cookie: stranger CAN view
        request()->cookies->set('ph_link_id', (string) $shortLink->id);

        expect($policy->view($stranger, $campaign))->toBeTrue();
    });

    it('does not grant access when short link is expired', function () {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'private',
        ]);

        $shortLink = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $owner->id,
        ]);
        // Expire it
        $shortLink->update(['expires_at' => now()->subDay()]);

        request()->cookies->set('ph_link_id', (string) $shortLink->id);

        $policy = new GamePolicy;
        expect($policy->view($stranger, $game))->toBeFalse();
    });
});

// ══════════════════════════════════════════════════════════════
// 3. Join flow: authenticated user joins via short link
// ══════════════════════════════════════════════════════════════

describe('join flow via short link', function () {
    it('creates participant with join_source=short_link and short_link_id', function () {
        $owner = User::factory()->create();
        $player = User::factory()->create(['profile_complete' => true]);

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'public',
            'max_players' => 10,
        ]);

        $shortLink = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $owner->id,
        ]);

        // Simulate ProcessShareIntent processing short_link_intent cookie
        $payload = json_encode([
            'entity_type' => 'game',
            'entity_id' => $game->id,
            'short_link_id' => $shortLink->id,
        ]);

        $this->actingAs($player)
            ->withUnencryptedCookie('short_link_intent', $payload)
            ->get('/en/dashboard');

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $player->id)
            ->first();

        expect($participant)->not->toBeNull();
        expect($participant->join_source)->toBe(JoinSource::ShortLink);
        expect($participant->short_link_id)->toBe($shortLink->id);
        expect($participant->status)->toBe(ParticipantStatus::Approved);
    });

    it('creates campaign participant with join_source=short_link', function () {
        $owner = User::factory()->create();
        $player = User::factory()->create(['profile_complete' => true]);

        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
            'max_players' => 10,
        ]);

        $shortLink = ShortLink::factory()->create([
            'linkable_type' => Campaign::class,
            'linkable_id' => $campaign->id,
            'user_id' => $owner->id,
        ]);

        $payload = json_encode([
            'entity_type' => 'campaign',
            'entity_id' => $campaign->id,
            'short_link_id' => $shortLink->id,
        ]);

        $this->actingAs($player)
            ->withUnencryptedCookie('short_link_intent', $payload)
            ->get('/en/dashboard');

        $participant = CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('user_id', $player->id)
            ->first();

        expect($participant)->not->toBeNull();
        expect($participant->join_source)->toBe(JoinSource::ShortLink);
        expect($participant->short_link_id)->toBe($shortLink->id);
    });
});

// ══════════════════════════════════════════════════════════════
// 4. Guest-to-auth short link intent flow
// ══════════════════════════════════════════════════════════════

describe('guest short link intent flow', function () {
    it('sets short_link_intent cookie when guest visits via short link redirect', function () {
        $owner = User::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'public',
        ]);

        $shortLink = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
        ]);

        // Guest hits /link/{code}
        $response = $this->get(route('short-link.redirect', $shortLink->code));

        $response->assertRedirect();
        $response->assertCookie('short_link_intent');

        // Verify cookie payload
        $cookieValue = json_decode($response->getCookie('short_link_intent')->getValue(), true);
        expect($cookieValue['entity_type'])->toBe('game');
        expect($cookieValue['entity_id'])->toBe($game->id);
        expect($cookieValue['short_link_id'])->toBe($shortLink->id);
    });

    it('processes short_link_intent cookie after user completes profile', function () {
        $owner = User::factory()->create();
        $newUser = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'public',
            'max_players' => 10,
        ]);

        $shortLink = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
        ]);

        $payload = json_encode([
            'entity_type' => 'game',
            'entity_id' => $game->id,
            'short_link_id' => $shortLink->id,
        ]);

        // User with complete profile hits dashboard with short_link_intent cookie
        $this->actingAs($newUser)
            ->withUnencryptedCookie('short_link_intent', $payload)
            ->get('/en/dashboard');

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $newUser->id)
            ->first();

        expect($participant)->not->toBeNull();
        expect($participant->join_source)->toBe(JoinSource::ShortLink);
        expect($participant->short_link_id)->toBe($shortLink->id);
    });
});

// ══════════════════════════════════════════════════════════════
// 5. Backward compatibility: old ?share= URLs still work
// ══════════════════════════════════════════════════════════════

describe('backward compatibility with share tokens', function () {
    it('share_link_intent cookie creates participant with join_source=share_link', function () {
        $owner = User::factory()->create();
        $player = User::factory()->create(['profile_complete' => true]);

        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
            'visibility' => 'public',
            'max_players' => 10,
        ]);

        // Simulate share_intent cookie payload
        $payload = json_encode([
            'entity_type' => 'game',
            'entity_id' => $game->id,
            'share_token' => $token,
        ]);

        $this->actingAs($player)
            ->withUnencryptedCookie('share_intent', $payload)
            ->get('/en/dashboard');

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $player->id)
            ->first();

        expect($participant)->not->toBeNull();
        expect($participant->join_source)->toBe(JoinSource::ShareLink);
    });

    it('share token grants policy access to private game', function () {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
            'share_token_expires_at' => now()->addDays(7),
            'visibility' => 'private',
        ]);

        $policy = new GamePolicy;

        // Without share token: stranger cannot view private game
        expect($policy->view($stranger, $game))->toBeFalse();

        // With valid share token in query: can view
        request()->merge(['share' => $token]);
        expect($policy->view($stranger, $game))->toBeTrue();
    });
});

// ══════════════════════════════════════════════════════════════
// 6. Multi-link: entity can have multiple short links
// ══════════════════════════════════════════════════════════════

describe('multi-link support', function () {
    it('entity can have multiple short links, each independently revocable', function () {
        $owner = $this->createSubscribedGm();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $link1 = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $owner->id,
            'code' => 'abc1234',
        ]);

        $link2 = ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $owner->id,
            'code' => 'xyz5678',
        ]);

        expect(
            ShortLink::where('linkable_type', Game::class)
                ->where('linkable_id', $game->id)
                ->count()
        )->toBe(2);

        // Revoke link1 via ShortLinkService
        app(ShortLinkService::class)->revokeLink($link1);

        // link1 is soft-deleted, link2 still active
        expect(
            ShortLink::where('linkable_type', Game::class)
                ->where('linkable_id', $game->id)
                ->count()
        )->toBe(1);

        expect(
            ShortLink::withTrashed()
                ->where('linkable_type', Game::class)
                ->where('linkable_id', $game->id)
                ->count()
        )->toBe(2);

        // link2 still resolves
        $resolved = app(ShortLinkService::class)->resolveLink('xyz5678');
        expect($resolved)->not->toBeNull();
        expect($resolved->id)->toBe($link2->id);
    });
});

// ══════════════════════════════════════════════════════════════
// 7. Migration command
// ══════════════════════════════════════════════════════════════

describe('migrate:share-tokens command', function () {
    it('creates ShortLink records from existing share tokens', function () {
        $owner = User::factory()->create();
        $expiresAt = now()->addDays(14);

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => (string) Str::uuid(),
            'share_token_expires_at' => $expiresAt,
        ]);

        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'share_token' => (string) Str::uuid(),
            'share_token_expires_at' => null,
        ]);

        $this->artisan('migrate:share-tokens')
            ->assertSuccessful()
            ->expectsOutputToContain('Created 2 short link(s)');

        // Verify game short link
        $gameLink = ShortLink::where('linkable_type', Game::class)
            ->where('linkable_id', $game->id)
            ->where('purpose', 'share_token_migration')
            ->first();

        expect($gameLink)->not->toBeNull();
        expect($gameLink->label)->toBe('Migrated share token');
        expect($gameLink->expires_at->toDateString())->toBe($expiresAt->toDateString());

        // Verify campaign short link
        $campaignLink = ShortLink::where('linkable_type', Campaign::class)
            ->where('linkable_id', $campaign->id)
            ->where('purpose', 'share_token_migration')
            ->first();

        expect($campaignLink)->not->toBeNull();
        expect($campaignLink->label)->toBe('Migrated share token');
        expect($campaignLink->expires_at)->toBeNull();
    });

    it('dry-run does not create any records', function () {
        $owner = User::factory()->create();

        Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => (string) Str::uuid(),
        ]);

        $this->artisan('migrate:share-tokens --dry-run')
            ->assertSuccessful()
            ->expectsOutputToContain('Would create 1 short link(s)');

        // No ShortLink records created
        expect(ShortLink::where('purpose', 'share_token_migration')->count())->toBe(0);
    });

    it('skips entities that already have migrated short links', function () {
        $owner = User::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => (string) Str::uuid(),
        ]);

        // Pre-create a migration link
        ShortLink::factory()->create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $owner->id,
            'purpose' => 'share_token_migration',
            'label' => 'Migrated share token',
        ]);

        $this->artisan('migrate:share-tokens')
            ->assertSuccessful()
            ->expectsOutputToContain('Created 0 short link(s)');

        // Still only 1 short link (the pre-existing one)
        expect(
            ShortLink::where('linkable_type', Game::class)
                ->where('linkable_id', $game->id)
                ->count()
        )->toBe(1);
    });

    it('skips entities with null share_token', function () {
        $owner = User::factory()->create();

        // Game without share_token
        Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => null,
        ]);

        $this->artisan('migrate:share-tokens')
            ->assertSuccessful()
            ->expectsOutputToContain('Created 0 short link(s)');
    });
});
