<?php

use App\Models\Campaign;
use App\Models\CampaignApplication;
use App\Models\CampaignParticipant;
use App\Models\EventAnnouncement;
use App\Models\Event;
use App\Models\Game;
use App\Models\GameApplication;
use App\Models\GameParticipant;
use App\Models\User;

// ═══════════════════════════════════════════════════════════
// CAMPAIGN APPLICATION MODEL
// ═══════════════════════════════════════════════════════════

describe('CampaignApplication Model', function () {
    it('has campaign relationship', function () {
        $campaign = Campaign::factory()->create(['name' => 'Test Campaign']);
        $user = User::factory()->create();

        $app = CampaignApplication::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'status' => 'pending',
            'message' => 'Please let me in',
        ]);

        expect($app->campaign->name)->toBe('Test Campaign');
    });

    it('has user relationship', function () {
        $campaign = Campaign::factory()->create();
        $user = User::factory()->create(['name' => 'Applicant']);

        $app = CampaignApplication::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        expect($app->user->name)->toBe('Applicant');
    });
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN PARTICIPANT MODEL
// ═══════════════════════════════════════════════════════════

describe('CampaignParticipant Model', function () {
    it('has campaign relationship', function () {
        $campaign = Campaign::factory()->create(['name' => 'Campaign']);
        $user = User::factory()->create();

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        expect($participant->campaign->name)->toBe('Campaign');
    });

    it('has user relationship', function () {
        $campaign = Campaign::factory()->create();
        $user = User::factory()->create(['name' => 'Player']);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        expect($participant->user->name)->toBe('Player');
    });
});

// ═══════════════════════════════════════════════════════════
// GAME APPLICATION MODEL
// ═══════════════════════════════════════════════════════════

describe('GameApplication Model', function () {
    it('has game relationship', function () {
        $game = Game::factory()->create(['name' => 'Test Game']);
        $user = User::factory()->create();

        $app = GameApplication::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => 'pending',
            'message' => 'I want to play',
        ]);

        expect($app->game->name)->toBe('Test Game');
    });

    it('has user relationship', function () {
        $game = Game::factory()->create();
        $user = User::factory()->create(['name' => 'Gamer']);

        $app = GameApplication::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        expect($app->user->name)->toBe('Gamer');
    });
});

// ═══════════════════════════════════════════════════════════
// GAME PARTICIPANT MODEL
// ═══════════════════════════════════════════════════════════

describe('GameParticipant Model', function () {
    it('has game relationship', function () {
        $game = Game::factory()->create(['name' => 'Session']);
        $user = User::factory()->create();

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        expect($participant->game->name)->toBe('Session');
    });

    it('has user relationship', function () {
        $game = Game::factory()->create();
        $user = User::factory()->create(['name' => 'Player One']);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        expect($participant->user->name)->toBe('Player One');
    });
});

// ═══════════════════════════════════════════════════════════
// EVENT ANNOUNCEMENT MODEL
// ═══════════════════════════════════════════════════════════

describe('EventAnnouncement Model', function () {
    it('has event relationship', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create(['organizer_id' => $user->id]);

        $announcement = EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $user->id,
            'title' => 'Schedule Update',
            'content' => 'Times have changed',
            'is_pinned' => false,
        ]);

        expect($announcement->event->id)->toBe($event->id);
    });

    it('casts is_pinned as boolean', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create(['organizer_id' => $user->id]);

        $announcement = EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $user->id,
            'title' => 'Pinned',
            'content' => 'Read this',
            'is_pinned' => 1,
        ]);

        expect($announcement->is_pinned)->toBeTrue();
    });
});
