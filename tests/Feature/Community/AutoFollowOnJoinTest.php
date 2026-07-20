<?php

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Models\UserRelationship;
use App\Notifications\NewFollower;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
});

// ═══════════════════════════════════════════════════════════════════════
// S03′ — auto-follow host on game/campaign join
// ═══════════════════════════════════════════════════════════════════════
//
// When a player joins a host's game or campaign, the player auto-follows
// the host so the host's future events surface in the player's activity
// feed and discovery ('friends are going' tag). A follow is reversible —
// one tap on the host's profile undoes it — so the implicit opt-in is
// light-touch and does not warrant a confirmation surface.
//
// The auto-follow suppresses the NewFollower notification so a popular
// host with many new players per week is not spammed for follows they
// did not explicitly receive. Manual follows via the profile button
// still notify.

describe('auto-follow on game join', function () {
    it('creates a Follow edge from the player to the host when a game participant is created', function () {
        $host = User::factory()->create();
        $player = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $host->id]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved,
        ]);

        expect($player->fresh()->isFollowing($host))->toBeTrue();
    });

    it('does NOT dispatch the NewFollower notification to the host (silent auto-follow)', function () {
        $host = User::factory()->create();
        $player = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $host->id]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved,
        ]);

        Notification::assertNotSentTo($host, NewFollower::class);
    });

    it('skips the auto-follow when the player is the host (no self-follow)', function () {
        $host = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $host->id]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $host->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved,
        ]);

        expect($host->fresh()->isFollowing($host))->toBeFalse();
    });

    it('is idempotent — re-joining a game does not duplicate the follow edge', function () {
        $host = User::factory()->create();
        $player = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $host->id]);

        UserRelationship::follow($player, $host);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved,
        ]);

        expect(UserRelationship::where('user_id', $player->id)
            ->where('related_user_id', $host->id)
            ->where('type', 'follow')
            ->count()
        )->toBe(1);
    });

    it('skips the auto-follow when the player has blocked the host (respects blocks)', function () {
        $host = User::factory()->create();
        $player = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $host->id]);

        UserRelationship::block($player, $host);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved,
        ]);

        expect($player->fresh()->isFollowing($host))->toBeFalse();
    });

    it('skips the auto-follow when the host has blocked the player', function () {
        $host = User::factory()->create();
        $player = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $host->id]);

        UserRelationship::block($host, $player);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved,
        ]);

        expect($player->fresh()->isFollowing($host))->toBeFalse();
    });
});

describe('auto-follow on campaign join', function () {
    it('creates a Follow edge from the player to the host when a campaign participant is created', function () {
        $host = User::factory()->create();
        $player = User::factory()->create();
        $campaign = Campaign::factory()->create(['owner_id' => $host->id]);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved,
        ]);

        expect($player->fresh()->isFollowing($host))->toBeTrue();
    });

    it('does NOT dispatch the NewFollower notification to the host (silent auto-follow)', function () {
        $host = User::factory()->create();
        $player = User::factory()->create();
        $campaign = Campaign::factory()->create(['owner_id' => $host->id]);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $player->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved,
        ]);

        Notification::assertNotSentTo($host, NewFollower::class);
    });

    it('skips the auto-follow when the player is the campaign host', function () {
        $host = User::factory()->create();
        $campaign = Campaign::factory()->create(['owner_id' => $host->id]);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $host->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved,
        ]);

        expect($host->fresh()->isFollowing($host))->toBeFalse();
    });
});

describe('manual follow still notifies (no regression)', function () {
    it('dispatches NewFollower when UserRelationship::follow is called with default notify=true', function () {
        $host = User::factory()->create();
        $player = User::factory()->create();

        UserRelationship::follow($player, $host);

        Notification::assertSentTo($host, NewFollower::class);
    });
});
