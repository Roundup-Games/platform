<?php

use App\Enums\JoinSource;
use App\Livewire\Campaigns\CampaignDetail;
use App\Livewire\Games\GameDetail;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\ShortLink;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
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
        ]);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertSee(__('common.title_share_link'));
    });

    it('does not show share link section to non-owner', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        Livewire::actingAs($this->nonOwner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertDontSee(__('common.title_share_link'));
    });

    it('shows generate button when no short links exist', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertSee(__('common.action_generate_link'));
    });

    it('shows active short link when one exists', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $link = ShortLink::create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $this->owner->id,
            'code' => 'abc123',
            'url' => url('/link/abc123'),
            'purpose' => 'share',
        ]);

        $fullUrl = url('/link/abc123');

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertSee($fullUrl)
            ->assertSee(__('common.action_copy_link'));
    });

    it('creates a short link via createShortLink action', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('createShortLink', 'Test link');

        expect(ShortLink::where('linkable_id', $game->id)->count())->toBe(1);
    });

    it('revokes a short link via revokeShortLink action', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $link = ShortLink::create([
            'linkable_type' => Game::class,
            'linkable_id' => $game->id,
            'user_id' => $this->owner->id,
            'code' => 'revoke1',
            'url' => url('/link/revoke1'),
            'purpose' => 'share',
        ]);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call("revokeShortLink", $link->id);

        expect(ShortLink::find($link->id))->toBeNull();
    });
});

// ── Campaign share link UI tests ─────────────────────────────

describe('Campaign share link UI', function () {
    it('shows share link section to owner', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        Livewire::actingAs($this->owner)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertSee(__('common.title_share_link'));
    });

    it('does not show share link section to non-owner', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        Livewire::actingAs($this->nonOwner)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertDontSee(__('common.title_share_link'));
    });

    it('shows active short link when one exists', function () {
        $campaign = Campaign::factory()->create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
        ]);

        $link = ShortLink::create([
            'linkable_type' => Campaign::class,
            'linkable_id' => $campaign->id,
            'user_id' => $this->owner->id,
            'code' => 'camp12',
            'url' => url('/link/camp12'),
            'purpose' => 'share',
        ]);

        $fullUrl = url('/link/camp12');

        Livewire::actingAs($this->owner)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertSee($fullUrl)
            ->assertSee(__('common.action_copy_link'));
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

        $html = $component->html();
        expect(preg_match('/person_add.*Friend Invite|link.*Share Link|edit_note.*Application/s', $html))->toBe(0);
    });
});
