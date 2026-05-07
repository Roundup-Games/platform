<?php

use App\Enums\GameStatus;
use App\Enums\JoinSource;
use App\Enums\ParticipantStatus;
use App\Livewire\Campaigns\CampaignDetail;
use App\Livewire\Games\GameDetail;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->player = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
});

// ── Cookie setting on guest visit ──────────────────────────

describe('share_intent cookie on guest visit', function () {
    it('sets share_intent cookie when guest visits game via valid share link', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
            'visibility' => 'public',
        ]);

        Livewire::withQueryParams(['share' => $token])
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertHasNoErrors();

        // Cookie should have been queued
        $cookie = collect(cookie()->getQueuedCookies())->last();
        expect($cookie)->not->toBeNull();
        expect($cookie->getName())->toBe('share_intent');
        // Cookie was queued with 24-hour TTL
        expect($cookie->getExpiresTime())->toBeGreaterThan(time() + 23 * 60 * 60);
    });

    it('sets share_intent cookie when guest visits campaign via valid share link', function () {
        $token = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'share_token' => $token,
            'visibility' => 'public',
        ]);

        Livewire::withQueryParams(['share' => $token])
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertHasNoErrors();

        $cookie = collect(cookie()->getQueuedCookies())->last();
        expect($cookie)->not->toBeNull();
        expect($cookie->getName())->toBe('share_intent');
    });

    it('does not set cookie when authenticated user visits via share link', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
            'visibility' => 'public',
        ]);

        cookie()->flushQueuedCookies();

        Livewire::actingAs($this->player)
            ->withQueryParams(['share' => $token])
            ->test(GameDetail::class, ['id' => $game->id]);

        $shareCookies = collect(cookie()->getQueuedCookies())->filter(fn ($c) => $c->getName() === 'share_intent');
        expect($shareCookies)->toHaveCount(0);
    });

    it('does not set cookie when guest visits without share token', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'visibility' => 'public',
        ]);

        cookie()->flushQueuedCookies();

        Livewire::test(GameDetail::class, ['id' => $game->id]);

        $shareCookies = collect(cookie()->getQueuedCookies())->filter(fn ($c) => $c->getName() === 'share_intent');
        expect($shareCookies)->toHaveCount(0);
    });

    it('does not set cookie when share token is invalid', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => (string) Str::uuid(),
            'visibility' => 'public',
        ]);

        cookie()->flushQueuedCookies();

        Livewire::withQueryParams(['share' => 'wrong-token'])
            ->test(GameDetail::class, ['id' => $game->id]);

        $shareCookies = collect(cookie()->getQueuedCookies())->filter(fn ($c) => $c->getName() === 'share_intent');
        expect($shareCookies)->toHaveCount(0);
    });
});

// ── canJoinViaShareLink computed ───────────────────────────

describe('canJoinViaShareLink', function () {
    it('returns true for authenticated user with valid share token who is not participant', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
            'visibility' => 'protected',
        ]);

        $component = Livewire::actingAs($this->player)
            ->withQueryParams(['share' => $token])
            ->test(GameDetail::class, ['id' => $game->id]);

        expect($component->get('canJoinViaShareLink'))->toBeTrue();
    });

    it('returns false for guest', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
            'visibility' => 'public',
        ]);

        $component = Livewire::withQueryParams(['share' => $token])
            ->test(GameDetail::class, ['id' => $game->id]);

        expect($component->get('canJoinViaShareLink'))->toBeFalse();
    });

    it('returns false for owner', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
            'visibility' => 'public',
        ]);

        $component = Livewire::actingAs($this->owner)
            ->withQueryParams(['share' => $token])
            ->test(GameDetail::class, ['id' => $game->id]);

        expect($component->get('canJoinViaShareLink'))->toBeFalse();
    });

    it('returns false for existing participant', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
            'visibility' => 'public',
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->player->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        $component = Livewire::actingAs($this->player)
            ->withQueryParams(['share' => $token])
            ->test(GameDetail::class, ['id' => $game->id]);

        expect($component->get('canJoinViaShareLink'))->toBeFalse();
    });

    it('returns false without share token in URL', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => (string) Str::uuid(),
            'visibility' => 'public',
        ]);

        $component = Livewire::actingAs($this->player)
            ->test(GameDetail::class, ['id' => $game->id]);

        expect($component->get('canJoinViaShareLink'))->toBeFalse();
    });

    it('returns false for completed game', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
            'visibility' => 'public',
            'status' => GameStatus::Completed->value,
        ]);

        $component = Livewire::actingAs($this->player)
            ->withQueryParams(['share' => $token])
            ->test(GameDetail::class, ['id' => $game->id]);

        expect($component->get('canJoinViaShareLink'))->toBeFalse();
    });

    it('returns false for cancelled game', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
            'visibility' => 'public',
            'status' => GameStatus::Canceled->value,
        ]);

        $component = Livewire::actingAs($this->player)
            ->withQueryParams(['share' => $token])
            ->test(GameDetail::class, ['id' => $game->id]);

        expect($component->get('canJoinViaShareLink'))->toBeFalse();
    });
});

// ── joinViaShareLink for Games ─────────────────────────────

describe('GameDetail joinViaShareLink', function () {
    it('creates approved participant with share_link join source', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
            'visibility' => 'protected',
            'max_players' => 10,
        ]);

        Livewire::actingAs($this->player)
            ->withQueryParams(['share' => $token])
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('joinViaShareLink')
            ->assertHasNoErrors()
            ->assertSee(__('games.flash_joined_via_share_link'));

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $this->player->id)
            ->first();

        expect($participant)->not->toBeNull();
        expect($participant->status)->toBe(ParticipantStatus::Approved);
        expect($participant->role)->toBe('player');
        expect($participant->join_source)->toBe(JoinSource::ShareLink);
    });

    it('waitlists when game is full (standalone game)', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
            'visibility' => 'protected',
            'max_players' => 1,
            'campaign_id' => null,
        ]);

        // Fill the game
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        Livewire::actingAs($this->player)
            ->withQueryParams(['share' => $token])
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('joinViaShareLink')
            ->assertHasNoErrors();

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $this->player->id)
            ->first();

        expect($participant)->not->toBeNull();
        expect($participant->status)->toBe(ParticipantStatus::Waitlisted);
        expect($participant->join_source)->toBe(JoinSource::ShareLink);
        expect($participant->waitlisted_at)->not->toBeNull();
    });

    it('benches when campaign session is full', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
        ]);

        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
            'visibility' => 'protected',
            'max_players' => 1,
            'campaign_id' => $campaign->id,
        ]);

        // Fill the game
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        Livewire::actingAs($this->player)
            ->withQueryParams(['share' => $token])
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('joinViaShareLink')
            ->assertHasNoErrors();

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $this->player->id)
            ->first();

        expect($participant)->not->toBeNull();
        expect($participant->status)->toBe(ParticipantStatus::Benched);
        expect($participant->join_source)->toBe(JoinSource::ShareLink);
        expect($participant->benched_at)->not->toBeNull();
    });

    it('prevents owner from joining their own game via share link', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
            'visibility' => 'public',
        ]);

        Livewire::actingAs($this->owner)
            ->withQueryParams(['share' => $token])
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('joinViaShareLink');

        expect(GameParticipant::where('game_id', $game->id)->count())->toBe(0);
    });

    it('clears share_intent cookie on successful join', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
            'visibility' => 'protected',
        ]);

        $component = Livewire::actingAs($this->player)
            ->withQueryParams(['share' => $token])
            ->test(GameDetail::class, ['id' => $game->id]);

        cookie()->flushQueuedCookies();

        $component->call('joinViaShareLink');

        $forgetCookies = collect(cookie()->getQueuedCookies())->filter(fn ($c) => $c->getName() === 'share_intent');
        expect($forgetCookies)->toHaveCount(1);
        expect($forgetCookies->first()->getValue())->toBeNull();
    });
});

// ── joinViaShareLink for Campaigns ─────────────────────────

describe('CampaignDetail joinViaShareLink', function () {
    it('creates approved participant with share_link join source', function () {
        $token = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'share_token' => $token,
            'visibility' => 'protected',
            'max_players' => 10,
        ]);

        Livewire::actingAs($this->player)
            ->withQueryParams(['share' => $token])
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->call('joinViaShareLink')
            ->assertHasNoErrors()
            ->assertSee(__('campaigns.flash_joined_via_share_link'));

        $participant = CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('user_id', $this->player->id)
            ->first();

        expect($participant)->not->toBeNull();
        expect($participant->status)->toBe(ParticipantStatus::Approved);
        expect($participant->role)->toBe('player');
        expect($participant->join_source)->toBe(JoinSource::ShareLink);
    });

    it('benches when campaign is full', function () {
        $token = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'share_token' => $token,
            'visibility' => 'protected',
            'max_players' => 1,
        ]);

        // Fill the campaign
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => User::factory()->create()->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        Livewire::actingAs($this->player)
            ->withQueryParams(['share' => $token])
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->call('joinViaShareLink')
            ->assertHasNoErrors();

        $participant = CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('user_id', $this->player->id)
            ->first();

        expect($participant)->not->toBeNull();
        expect($participant->status)->toBe(ParticipantStatus::Benched);
        expect($participant->join_source)->toBe(JoinSource::ShareLink);
        expect($participant->benched_at)->not->toBeNull();
    });

    it('prevents owner from joining their own campaign via share link', function () {
        $token = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'share_token' => $token,
            'visibility' => 'public',
        ]);

        Livewire::actingAs($this->owner)
            ->withQueryParams(['share' => $token])
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->call('joinViaShareLink');

        expect(CampaignParticipant::where('campaign_id', $campaign->id)->count())->toBe(0);
    });

    it('prevents existing participant from rejoining', function () {
        $token = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'share_token' => $token,
            'visibility' => 'protected',
        ]);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $this->player->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        Livewire::actingAs($this->player)
            ->withQueryParams(['share' => $token])
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->call('joinViaShareLink');

        // Should still only have 1 participant record
        expect(CampaignParticipant::where('campaign_id', $campaign->id)
            ->where('user_id', $this->player->id)->count())->toBe(1);
    });

    it('canJoinViaShareLink returns correct state for campaign', function () {
        $token = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'share_token' => $token,
            'visibility' => 'protected',
        ]);

        $component = Livewire::actingAs($this->player)
            ->withQueryParams(['share' => $token])
            ->test(CampaignDetail::class, ['id' => $campaign->id]);

        expect($component->get('canJoinViaShareLink'))->toBeTrue();
    });
});
