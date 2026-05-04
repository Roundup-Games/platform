<?php

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\NotificationCategory;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Notifications\CampaignCancelled;
use App\Notifications\CampaignCompleted;
use App\Notifications\GameCancelled;
use App\Notifications\GameCompleted;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

// ══════════════════════════════════════════════════════
// Game Cancelled
// ══════════════════════════════════════════════════════

describe('Cancel game → GameCancelled', function () {
    it('dispatches GameCancelled to all approved participants excluding owner', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $player1 = User::factory()->create(['profile_complete' => true]);
        $player2 = User::factory()->create(['profile_complete' => true]);

        $gameSystem = GameSystem::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
        ]);

        GameParticipant::create(['game_id' => $game->id, 'user_id' => $player1->id, 'role' => 'player', 'status' => 'approved']);
        GameParticipant::create(['game_id' => $game->id, 'user_id' => $player2->id, 'role' => 'player', 'status' => 'approved']);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('cancelGame', $game->id);

        foreach ([$player1, $player2] as $player) {
            $notifications = $player->notifications()->where('type', GameCancelled::class)->get();
            expect($notifications)->toHaveCount(1);

            $data = $notifications->first()->data;
            expect($data['type'])->toBe('game_cancelled')
                ->and($data['entity_id'])->toBe($game->id)
                ->and($data['entity_name'])->toBe($game->name)
                ->and($data)->toHaveKey('action_url');
        }

        // Owner should NOT receive notification
        expect($owner->notifications()->where('type', GameCancelled::class)->count())->toBe(0);
    });

    it('does not dispatch to pending or rejected participants', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $pending = User::factory()->create(['profile_complete' => true]);
        $rejected = User::factory()->create(['profile_complete' => true]);

        $gameSystem = GameSystem::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
        ]);

        GameParticipant::create(['game_id' => $game->id, 'user_id' => $pending->id, 'role' => 'player', 'status' => 'pending']);
        GameParticipant::create(['game_id' => $game->id, 'user_id' => $rejected->id, 'role' => 'player', 'status' => 'rejected']);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('cancelGame', $game->id);

        expect($pending->notifications()->where('type', GameCancelled::class)->count())->toBe(0);
        expect($rejected->notifications()->where('type', GameCancelled::class)->count())->toBe(0);
    });

    it('does not dispatch when preferences are off', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $player = User::factory()->create([
            'profile_complete' => true,
            'notification_settings' => array_merge(
                NotificationCategory::defaultSettings(),
                ['game_cancelled' => ['database' => false, 'mail' => false]]
            ),
        ]);

        $gameSystem = GameSystem::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
        ]);

        GameParticipant::create(['game_id' => $game->id, 'user_id' => $player->id, 'role' => 'player', 'status' => 'approved']);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('cancelGame', $game->id);

        expect($player->notifications()->where('type', GameCancelled::class)->count())->toBe(0);
    });


});

// ══════════════════════════════════════════════════════
// Game Completed
// ══════════════════════════════════════════════════════

describe('Complete game → GameCompleted', function () {
    it('dispatches GameCompleted to all approved participants excluding owner', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $player = User::factory()->create(['profile_complete' => true]);

        $gameSystem = GameSystem::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'scheduled',
        ]);

        GameParticipant::create(['game_id' => $game->id, 'user_id' => $player->id, 'role' => 'player', 'status' => 'approved']);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('completeGame', $game->id);

        $notifications = $player->notifications()->where('type', GameCompleted::class)->get();
        expect($notifications)->toHaveCount(1);

        $data = $notifications->first()->data;
        expect($data['type'])->toBe('game_completed')
            ->and($data['entity_id'])->toBe($game->id)
            ->and($data['entity_name'])->toBe($game->name)
            ->and($data)->toHaveKey('action_url');

        expect($owner->notifications()->where('type', GameCompleted::class)->count())->toBe(0);
    });

    it('does not dispatch when game is not scheduled', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $player = User::factory()->create(['profile_complete' => true]);

        $gameSystem = GameSystem::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'completed',
        ]);

        GameParticipant::create(['game_id' => $game->id, 'user_id' => $player->id, 'role' => 'player', 'status' => 'approved']);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('completeGame', $game->id);

        expect($player->notifications()->where('type', GameCompleted::class)->count())->toBe(0);
    });


});

// ══════════════════════════════════════════════════════
// Campaign Cancelled
// ══════════════════════════════════════════════════════

describe('Cancel campaign → CampaignCancelled', function () {
    it('dispatches CampaignCancelled to all approved participants excluding owner', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $player1 = User::factory()->create(['profile_complete' => true]);
        $player2 = User::factory()->create(['profile_complete' => true]);

        $gameSystem = GameSystem::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'active',
        ]);

        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $player1->id, 'role' => 'player', 'status' => 'approved']);
        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $player2->id, 'role' => 'player', 'status' => 'approved']);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('cancelCampaign', $campaign->id);

        foreach ([$player1, $player2] as $player) {
            $notifications = $player->notifications()->where('type', CampaignCancelled::class)->get();
            expect($notifications)->toHaveCount(1);

            $data = $notifications->first()->data;
            expect($data['type'])->toBe('campaign_cancelled')
                ->and($data['entity_id'])->toBe($campaign->id)
                ->and($data['entity_name'])->toBe($campaign->name)
                ->and($data)->toHaveKey('action_url');
        }

        expect($owner->notifications()->where('type', CampaignCancelled::class)->count())->toBe(0);
    });

});

// ══════════════════════════════════════════════════════
// Campaign Completed
// ══════════════════════════════════════════════════════

describe('Complete campaign → CampaignCompleted', function () {
    it('dispatches CampaignCompleted to all approved participants excluding owner', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $player = User::factory()->create(['profile_complete' => true]);

        $gameSystem = GameSystem::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $gameSystem->id,
            'status' => 'active',
        ]);

        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $player->id, 'role' => 'player', 'status' => 'approved']);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('completeCampaign', $campaign->id);

        $notifications = $player->notifications()->where('type', CampaignCompleted::class)->get();
        expect($notifications)->toHaveCount(1);

        $data = $notifications->first()->data;
        expect($data['type'])->toBe('campaign_completed')
            ->and($data['entity_id'])->toBe($campaign->id)
            ->and($data['entity_name'])->toBe($campaign->name)
            ->and($data)->toHaveKey('action_url');

        expect($owner->notifications()->where('type', CampaignCompleted::class)->count())->toBe(0);
    });

});

// ══════════════════════════════════════════════════════
// Edge cases
// ══════════════════════════════════════════════════════

describe('Status change edge cases', function () {
    it('handles game cancel with no participants without error', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => GameSystem::factory()->create()->id,
            'status' => 'scheduled',
        ]);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('cancelGame', $game->id)
            ->assertHasNoErrors();

        expect($game->fresh()->status)->toBe(GameStatus::Canceled);
    });
});
