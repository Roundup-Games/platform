<?php

use App\Enums\JoinSource;
use App\Livewire\Campaigns\CampaignDetail;
use App\Livewire\Games\GameDetail;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->nonOwner = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
});

// ── Game share link UI tests ─────────────────────────────────

describe('Game share link UI', function () {
    it('shows share link section to owner', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => null,
        ]);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertSee(__('common.share_link_title'))
            ->assertSee(__('common.share_link_generate'));
    });

    it('does not show share link section to non-owner', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => null,
        ]);

        Livewire::actingAs($this->nonOwner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertDontSee(__('common.share_link_title'));
    });

    it('shows active share link with URL when token exists', function () {
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
        ]);

        $expectedUrl = route('games.detail', $game->id) . '?share=' . $token;

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertSee($expectedUrl)
            ->assertSee(__('common.share_link_copy'))
            ->assertSee(__('common.share_link_regenerate'))
            ->assertSee(__('common.share_link_revoke'))
            ->assertDontSee(__('common.share_link_generate'));
    });

    it('does not show generate button when link already exists', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => (string) Str::uuid(),
        ]);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertDontSee(__('common.share_link_generate'));
    });

    it('shows generate button when no link exists', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => null,
        ]);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertSee(__('common.share_link_generate'))
            ->assertDontSee(__('common.share_link_copy'))
            ->assertDontSee(__('common.share_link_regenerate'))
            ->assertDontSee(__('common.share_link_revoke'));
    });

    it('copyable URL contains correct share token after generation', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => null,
        ]);

        $component = Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('generateShareLink');

        $freshToken = $game->fresh()->share_token;
        expect($freshToken)->not->toBeNull();

        $component->assertSee($freshToken);
    });

    it('switches to active state after generating a link', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => null,
        ]);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('generateShareLink')
            ->assertDontSee(__('common.share_link_generate'))
            ->assertSee(__('common.share_link_copy'))
            ->assertSee(__('common.share_link_regenerate'))
            ->assertSee(__('common.share_link_revoke'));
    });

    it('switches to generate state after revoking a link', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => (string) Str::uuid(),
        ]);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('revokeShareLink')
            ->assertSee(__('common.share_link_generate'))
            ->assertDontSee(__('common.share_link_copy'))
            ->assertDontSee(__('common.share_link_regenerate'))
            ->assertDontSee(__('common.share_link_revoke'));
    });
});

// ── Campaign share link UI tests ─────────────────────────────

describe('Campaign share link UI', function () {
    it('shows share link section to owner', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => null,
        ]);

        Livewire::actingAs($this->owner)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertSee(__('common.share_link_title'))
            ->assertSee(__('common.share_link_generate'));
    });

    it('does not show share link section to non-owner', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => null,
        ]);

        Livewire::actingAs($this->nonOwner)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertDontSee(__('common.share_link_title'));
    });

    it('shows active share link with URL when token exists', function () {
        $token = (string) Str::uuid();
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => $token,
        ]);

        $expectedUrl = route('campaigns.detail', $campaign->id) . '?share=' . $token;

        Livewire::actingAs($this->owner)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertSee($expectedUrl)
            ->assertSee(__('common.share_link_copy'))
            ->assertDontSee(__('common.share_link_generate'));
    });

    it('copyable URL contains correct share token after generation', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => null,
        ]);

        $component = Livewire::actingAs($this->owner)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->call('generateShareLink');

        $freshToken = $campaign->fresh()->share_token;
        expect($freshToken)->not->toBeNull();

        $component->assertSee($freshToken);
    });

    it('switches to generate state after revoking a link', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'share_token' => (string) Str::uuid(),
        ]);

        Livewire::actingAs($this->owner)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->call('revokeShareLink')
            ->assertSee(__('common.share_link_generate'))
            ->assertDontSee(__('common.share_link_copy'));
    });
});

// ── Join source badge display tests ──────────────────────────

describe('Join source badge display in manage-participants', function () {
    it('shows join_source badge for participant with share_link source', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);
        $participant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
            'join_source' => JoinSource::ShareLink->value,
        ]);

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->assertSee('Share Link');
    });

    it('shows join_source badge for participant with friend_invite source', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);
        $participant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
            'join_source' => JoinSource::FriendInvite->value,
        ]);

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->assertSee('Friend Invite');
    });

    it('shows join_source badge for participant with application source', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);
        $participant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
            'join_source' => JoinSource::Application->value,
        ]);

        Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id])
            ->assertSee('Application');
    });

    it('does not show join_source badge when source is null', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);
        $participant = User::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $participant->id,
            'role' => 'player',
            'status' => 'approved',
            'join_source' => null,
        ]);

        $component = Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Games\ManageParticipants::class, ['id' => $game->id]);

        // The join_source enum labels should not appear in the output for null participants
        $html = $component->html();
        // Check that no join source badge element is rendered — look for the badge icons that only appear with join_source
        expect(preg_match('/person_add.*Friend Invite|link.*Share Link|edit_note.*Application/s', $html))->toBe(0);
    });
});
