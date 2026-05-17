<?php

use App\Livewire\Campaigns\CampaignDetail;
use App\Livewire\Games\GameDetail;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->nonOwner = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
});

// ── Game share link tests ─────────────────────────────────

describe('GameDetail share links', function () {
    it('allows owner to generate a share link', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => null,
        ]);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('generateShareLink')
            ->assertHasNoErrors();

        expect($game->fresh()->share_token)->not->toBeNull();
    });

    it('prevents non-owner from generating a share link', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => null,
        ]);

        Livewire::actingAs($this->nonOwner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('generateShareLink');

        expect($game->fresh()->share_token)->toBeNull();
    });

    it('allows owner to revoke a share link', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => (string) Str::uuid(),
        ]);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('revokeShareLink')
            ->assertHasNoErrors();

        $fresh = $game->fresh();
        expect($fresh->share_token)->toBeNull();
        expect($fresh->share_token_expires_at)->toBeNull();
    });

    it('prevents non-owner from revoking a share link', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
        ]);

        Livewire::actingAs($this->nonOwner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('revokeShareLink');

        expect($game->fresh()->share_token)->toBe($token);
    });

    it('allows owner to regenerate a share link', function () {
        $oldToken = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $oldToken,
        ]);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('regenerateShareLink')
            ->assertHasNoErrors();

        $fresh = $game->fresh();
        expect($fresh->share_token)->not->toBeNull();
        expect($fresh->share_token)->not->toBe($oldToken);
    });

    it('prevents non-owner from regenerating a share link', function () {
        $oldToken = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $oldToken,
        ]);

        Livewire::actingAs($this->nonOwner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('regenerateShareLink');

        expect($game->fresh()->share_token)->toBe($oldToken);
    });

    it('returns null URL when no share token exists', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => null,
        ]);

        $component = Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id]);

        expect($component->get('shareLinkUrl'))->toBeNull();
    });

    it('returns full URL when share token exists', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
        ]);

        $component = Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id]);

        $url = $component->get('shareLinkUrl');
        expect($url)->toContain($game->id);
        expect($url)->toContain('share=' . $token);
    });

    it('reports hasShareLink correctly', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => null,
        ]);

        $component = Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id]);

        expect($component->get('hasShareLink'))->toBeFalse();

        $game->update(['share_token' => (string) Str::uuid()]);
        $component->call('$refresh');
        expect($component->get('hasShareLink'))->toBeTrue();
    });

    it('logs share link generation', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => null,
        ]);

        Log::shouldReceive('info')
            ->with('Share link generated', \Mockery::on(fn ($ctx) => $ctx['entity_type'] === 'game' && $ctx['entity_id'] === $game->id))
            ->once();

        Log::shouldReceive('debug')->andReturn(null);
        Log::shouldReceive('warning')->andReturn(null);
        Log::shouldReceive('error')->andReturn(null);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('generateShareLink');
    });
});

// ── Campaign share link tests ──────────────────────────────

describe('CampaignDetail share links', function () {
    it('allows owner to generate a share link', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'share_token' => null,
        ]);

        Livewire::actingAs($this->owner)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->call('generateShareLink')
            ->assertHasNoErrors();

        expect($campaign->fresh()->share_token)->not->toBeNull();
    });

    it('prevents non-owner from generating a share link', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'share_token' => null,
        ]);

        Livewire::actingAs($this->nonOwner)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->call('generateShareLink');

        expect($campaign->fresh()->share_token)->toBeNull();
    });

    it('allows owner to revoke a share link', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'share_token' => (string) Str::uuid(),
        ]);

        Livewire::actingAs($this->owner)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->call('revokeShareLink')
            ->assertHasNoErrors();

        $fresh = $campaign->fresh();
        expect($fresh->share_token)->toBeNull();
        expect($fresh->share_token_expires_at)->toBeNull();
    });

    it('prevents non-owner from revoking a share link', function () {
        $token = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'share_token' => $token,
        ]);

        Livewire::actingAs($this->nonOwner)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->call('revokeShareLink');

        expect($campaign->fresh()->share_token)->toBe($token);
    });

    it('allows owner to regenerate a share link', function () {
        $oldToken = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'share_token' => $oldToken,
        ]);

        Livewire::actingAs($this->owner)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->call('regenerateShareLink')
            ->assertHasNoErrors();

        $fresh = $campaign->fresh();
        expect($fresh->share_token)->not->toBeNull();
        expect($fresh->share_token)->not->toBe($oldToken);
    });

    it('prevents non-owner from regenerating a share link', function () {
        $oldToken = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'share_token' => $oldToken,
        ]);

        Livewire::actingAs($this->nonOwner)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->call('regenerateShareLink');

        expect($campaign->fresh()->share_token)->toBe($oldToken);
    });

    it('returns null URL when no share token exists', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'share_token' => null,
        ]);

        $component = Livewire::actingAs($this->owner)
            ->test(CampaignDetail::class, ['id' => $campaign->id]);

        expect($component->get('shareLinkUrl'))->toBeNull();
    });

    it('returns full URL when share token exists', function () {
        $token = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'share_token' => $token,
        ]);

        $component = Livewire::actingAs($this->owner)
            ->test(CampaignDetail::class, ['id' => $campaign->id]);

        $url = $component->get('shareLinkUrl');
        expect($url)->toContain($campaign->id);
        expect($url)->toContain('share=' . $token);
    });

    it('logs share link generation', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'share_token' => null,
        ]);

        Log::shouldReceive('info')
            ->with('Share link generated', \Mockery::on(fn ($ctx) => $ctx['entity_type'] === 'campaign' && $ctx['entity_id'] === $campaign->id))
            ->once();

        Log::shouldReceive('debug')->andReturn(null);
        Log::shouldReceive('warning')->andReturn(null);
        Log::shouldReceive('error')->andReturn(null);

        Livewire::actingAs($this->owner)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->call('generateShareLink');
    });
});
