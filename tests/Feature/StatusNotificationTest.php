<?php

use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Notifications\CampaignCancelled;
use App\Notifications\CampaignCompleted;
use App\Notifications\GameCancelled;
use App\Notifications\GameCompleted;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

// ── Game Cancel ────────────────────────────────────────

describe('Cancel game → GameCancelled', function () {
    it('dispatches GameCancelled to all approved participants (excluding owner)', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $player1 = User::factory()->create(['profile_complete' => true]);
        $player2 = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create(['owner_id' => $owner->id, 'status' => 'scheduled']);

        GameParticipant::create(['game_id' => $game->id, 'user_id' => $player1->id, 'role' => 'player', 'status' => 'approved']);
        GameParticipant::create(['game_id' => $game->id, 'user_id' => $player2->id, 'role' => 'player', 'status' => 'approved']);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('cancelGame', $game->id);

        // Both players should receive GameCancelled
        foreach ([$player1, $player2] as $player) {
            $notifications = $player->notifications()->where('type', GameCancelled::class)->get();
            expect($notifications)->toHaveCount(1);
            expect($notifications->first()->data['entity_id'])->toBe($game->id);
            expect($notifications->first()->data['type'])->toBe('game_cancelled');
        }

        // Owner should NOT receive GameCancelled (they initiated it)
        $ownerNotifications = $owner->notifications()->where('type', GameCancelled::class)->get();
        expect($ownerNotifications)->toHaveCount(0);
    });

    it('does not dispatch GameCancelled to pending or rejected participants', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $pendingPlayer = User::factory()->create(['profile_complete' => true]);
        $rejectedPlayer = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create(['owner_id' => $owner->id, 'status' => 'scheduled']);

        GameParticipant::create(['game_id' => $game->id, 'user_id' => $pendingPlayer->id, 'role' => 'player', 'status' => 'pending']);
        GameParticipant::create(['game_id' => $game->id, 'user_id' => $rejectedPlayer->id, 'role' => 'player', 'status' => 'rejected']);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('cancelGame', $game->id);

        expect($pendingPlayer->notifications()->where('type', GameCancelled::class)->count())->toBe(0);
        expect($rejectedPlayer->notifications()->where('type', GameCancelled::class)->count())->toBe(0);
    });

    it('does not dispatch when game is not scheduled', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $player = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create(['owner_id' => $owner->id, 'status' => 'completed']);

        GameParticipant::create(['game_id' => $game->id, 'user_id' => $player->id, 'role' => 'player', 'status' => 'approved']);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('cancelGame', $game->id);

        expect($player->notifications()->where('type', GameCancelled::class)->count())->toBe(0);
    });
});

// ── Game Complete ──────────────────────────────────────

describe('Complete game → GameCompleted', function () {
    it('dispatches GameCompleted to all approved participants (excluding owner)', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $player1 = User::factory()->create(['profile_complete' => true]);
        $player2 = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create(['owner_id' => $owner->id, 'status' => 'scheduled']);

        GameParticipant::create(['game_id' => $game->id, 'user_id' => $player1->id, 'role' => 'player', 'status' => 'approved']);
        GameParticipant::create(['game_id' => $game->id, 'user_id' => $player2->id, 'role' => 'player', 'status' => 'approved']);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('completeGame', $game->id);

        // Both players should receive GameCompleted
        foreach ([$player1, $player2] as $player) {
            $notifications = $player->notifications()->where('type', GameCompleted::class)->get();
            expect($notifications)->toHaveCount(1);
            expect($notifications->first()->data['entity_id'])->toBe($game->id);
            expect($notifications->first()->data['type'])->toBe('game_completed');
        }

        // Owner should NOT receive GameCompleted
        expect($owner->notifications()->where('type', GameCompleted::class)->count())->toBe(0);
    });

    it('does not dispatch when game is not scheduled', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $player = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create(['owner_id' => $owner->id, 'status' => 'canceled']);

        GameParticipant::create(['game_id' => $game->id, 'user_id' => $player->id, 'role' => 'player', 'status' => 'approved']);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('completeGame', $game->id);

        expect($player->notifications()->where('type', GameCompleted::class)->count())->toBe(0);
    });
});

// ── Campaign Cancel ────────────────────────────────────

describe('Cancel campaign → CampaignCancelled', function () {
    it('dispatches CampaignCancelled to all approved participants (excluding owner)', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $player1 = User::factory()->create(['profile_complete' => true]);
        $player2 = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id, 'status' => 'active']);

        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $player1->id, 'role' => 'player', 'status' => 'approved']);
        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $player2->id, 'role' => 'player', 'status' => 'approved']);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('cancelCampaign', $campaign->id);

        // Both players should receive CampaignCancelled
        foreach ([$player1, $player2] as $player) {
            $notifications = $player->notifications()->where('type', CampaignCancelled::class)->get();
            expect($notifications)->toHaveCount(1);
            expect($notifications->first()->data['entity_id'])->toBe($campaign->id);
            expect($notifications->first()->data['type'])->toBe('campaign_cancelled');
        }

        // Owner should NOT receive CampaignCancelled
        expect($owner->notifications()->where('type', CampaignCancelled::class)->count())->toBe(0);
    });

    it('does not dispatch CampaignCancelled to pending participants', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $pendingPlayer = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id, 'status' => 'active']);

        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $pendingPlayer->id, 'role' => 'invited', 'status' => 'pending']);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('cancelCampaign', $campaign->id);

        expect($pendingPlayer->notifications()->where('type', CampaignCancelled::class)->count())->toBe(0);
    });

    it('does not dispatch when campaign is not active', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $player = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id, 'status' => 'completed']);

        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $player->id, 'role' => 'player', 'status' => 'approved']);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('cancelCampaign', $campaign->id);

        expect($player->notifications()->where('type', CampaignCancelled::class)->count())->toBe(0);
    });
});

// ── Campaign Complete ──────────────────────────────────

describe('Complete campaign → CampaignCompleted', function () {
    it('dispatches CampaignCompleted to all approved participants (excluding owner)', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $player1 = User::factory()->create(['profile_complete' => true]);
        $player2 = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id, 'status' => 'active']);

        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $player1->id, 'role' => 'player', 'status' => 'approved']);
        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $player2->id, 'role' => 'player', 'status' => 'approved']);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('completeCampaign', $campaign->id);

        // Both players should receive CampaignCompleted
        foreach ([$player1, $player2] as $player) {
            $notifications = $player->notifications()->where('type', CampaignCompleted::class)->get();
            expect($notifications)->toHaveCount(1);
            expect($notifications->first()->data['entity_id'])->toBe($campaign->id);
            expect($notifications->first()->data['type'])->toBe('campaign_completed');
        }

        // Owner should NOT receive CampaignCompleted
        expect($owner->notifications()->where('type', CampaignCompleted::class)->count())->toBe(0);
    });

    it('does not dispatch when campaign is not active', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $player = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id, 'status' => 'cancelled']);

        CampaignParticipant::create(['campaign_id' => $campaign->id, 'user_id' => $player->id, 'role' => 'player', 'status' => 'approved']);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('completeCampaign', $campaign->id);

        expect($player->notifications()->where('type', CampaignCompleted::class)->count())->toBe(0);
    });
});

// ── No participants edge case ──────────────────────────

describe('Status change with no approved participants', function () {
    it('handles game cancel with no participants without error', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create(['owner_id' => $owner->id, 'status' => 'scheduled']);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Games\GamesPage::class)
            ->call('cancelGame', $game->id)
            ->assertHasNoErrors();

        expect($game->fresh()->status)->toBe('canceled');
    });

    it('handles campaign complete with no participants without error', function () {
        $owner = User::factory()->create(['profile_complete' => true]);
        $campaign = Campaign::factory()->create(['owner_id' => $owner->id, 'status' => 'active']);

        \Livewire\Livewire::actingAs($owner)
            ->test(\App\Livewire\Campaigns\CampaignsPage::class)
            ->call('completeCampaign', $campaign->id)
            ->assertHasNoErrors();

        expect($campaign->fresh()->status)->toBe('completed');
    });
});
