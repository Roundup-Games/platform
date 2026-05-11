<?php

use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\BenchService;
use App\Services\WaitlistService;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
    $this->waitlistService = app(WaitlistService::class);
    $this->benchService = app(BenchService::class);
});

// ── Helpers ──────────────────────────────────────────────

function createPublicGameWithCounts(User $owner, GameSystem $system, int $maxPlayers = 2): Game
{
    $game = Game::create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'name' => 'Card Test Game',
        'date_time' => now()->addDays(7),
        'description' => 'Test',
        'expected_duration' => 3,
        'visibility' => 'public',
        'status' => 'scheduled',
        'language' => 'en',
        'location' => ['details' => 'Online'],
        'min_players' => 2,
        'max_players' => $maxPlayers,
        'campaign_id' => null,
    ]);

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $owner->id,
        'role' => 'owner',
        'status' => ParticipantStatus::Approved->value,
    ]);

    for ($i = 1; $i < $maxPlayers; $i++) {
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);
    }

    return $game;
}

function createPublicCampaignWithCounts(User $owner, GameSystem $system, int $maxPlayers = 2, bool $benchMode = false): Campaign
{
    $campaign = Campaign::create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'name' => 'Card Test Campaign',
        'description' => 'Test',
        'visibility' => 'public',
        'status' => 'active',
        'language' => 'en',
        'recurrence' => 'weekly',
        'time_of_day' => '19:00',
        'session_duration' => 3,
        'min_players' => 2,
        'max_players' => $maxPlayers,
        'bench_mode' => $benchMode,
    ]);

    CampaignParticipant::create([
        'campaign_id' => $campaign->id,
        'user_id' => $owner->id,
        'role' => 'owner',
        'status' => ParticipantStatus::Approved->value,
    ]);

    for ($i = 1; $i < $maxPlayers; $i++) {
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => User::factory()->create()->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);
    }

    return $campaign;
}

// ═══════════════════════════════════════════════════════════
// GAME CARD OVERFLOW INDICATORS
// ═══════════════════════════════════════════════════════════

describe('GameCard overflow indicators', function () {
    test('game card shows waitlisted count when full with waitlisted players', function () {
        $game = createPublicGameWithCounts($this->owner, $this->gameSystem, maxPlayers: 2);
        $user = User::factory()->create();
        $this->waitlistService->addToWaitlist($game, $user);

        // Reload with counts
        $gameWithCounts = Game::withCount([
            'participants as waitlisted_count' => fn ($q) => $q->where('status', 'waitlisted'),
            'participants as benched_count' => fn ($q) => $q->where('status', 'benched'),
        ])->find($game->id);

        $rendered = view('livewire.discovery.partials.game-card', ['game' => $gameWithCounts])->render();

        expect($rendered)->toContain(trans_choice('common.content_n_waitlisted', 1));
    });

    test('game card shows benched count for bench-mode campaign session', function () {
        $campaign = Campaign::create([
            'owner_id' => $this->owner->id,
            'game_system_id' => $this->gameSystem->id,
            'name' => 'Bench Campaign',
            'description' => 'Test',
            'visibility' => 'public',
            'status' => 'active',
            'language' => 'en',
            'recurrence' => 'weekly',
            'time_of_day' => '19:00',
            'session_duration' => 3,
            'min_players' => 2,
            'max_players' => 2,
        ]);

        $game = Game::create([
            'owner_id' => $this->owner->id,
            'campaign_id' => $campaign->id,
            'game_system_id' => $this->gameSystem->id,
            'name' => 'Bench Session',
            'date_time' => now()->addDays(7),
            'description' => 'Test',
            'expected_duration' => 3,
            'visibility' => 'public',
            'status' => 'scheduled',
            'language' => 'en',
            'location' => ['details' => 'Online'],
            'min_players' => 2,
            'max_players' => 2,
            'bench_mode' => true,
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'status' => ParticipantStatus::Approved->value,
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        $this->benchService->addToBench($game, User::factory()->create());

        $gameWithCounts = Game::withCount([
            'participants as waitlisted_count' => fn ($q) => $q->where('status', 'waitlisted'),
            'participants as benched_count' => fn ($q) => $q->where('status', 'benched'),
        ])->find($game->id);

        $rendered = view('livewire.discovery.partials.game-card', ['game' => $gameWithCounts])->render();

        expect($rendered)->toContain(trans_choice('common.content_n_on_bench', 1));
    });

    test('game card hides overflow indicator when no waitlisted or benched players', function () {
        $game = createPublicGameWithCounts($this->owner, $this->gameSystem, maxPlayers: 4);

        $gameWithCounts = Game::withCount([
            'participants as waitlisted_count' => fn ($q) => $q->where('status', 'waitlisted'),
            'participants as benched_count' => fn ($q) => $q->where('status', 'benched'),
        ])->find($game->id);

        $rendered = view('livewire.discovery.partials.game-card', ['game' => $gameWithCounts])->render();

        expect($rendered)->not->toContain('waitlisted')
            ->and($rendered)->not->toContain('on bench');
    });
});

// ═══════════════════════════════════════════════════════════
// CAMPAIGN CARD OVERFLOW INDICATORS
// ═══════════════════════════════════════════════════════════

describe('CampaignCard overflow indicators', function () {
    test('campaign card shows waitlisted count when full with waitlisted players', function () {
        $campaign = createPublicCampaignWithCounts($this->owner, $this->gameSystem, maxPlayers: 2, benchMode: false);
        $user = User::factory()->create();
        $this->waitlistService->addToWaitlist($campaign, $user);

        $campaignWithCounts = Campaign::withCount([
            'participants as waitlisted_count' => fn ($q) => $q->where('status', 'waitlisted'),
            'participants as benched_count' => fn ($q) => $q->where('status', 'benched'),
        ])->find($campaign->id);

        $rendered = view('livewire.discovery.partials.campaign-card', ['campaign' => $campaignWithCounts])->render();

        expect($rendered)->toContain(trans_choice('common.content_n_waitlisted', 1));
    });

    test('campaign card shows benched count for bench-mode campaign', function () {
        $campaign = createPublicCampaignWithCounts($this->owner, $this->gameSystem, maxPlayers: 2, benchMode: true);
        $user = User::factory()->create();
        $this->benchService->addToBench($campaign, $user);

        $campaignWithCounts = Campaign::withCount([
            'participants as waitlisted_count' => fn ($q) => $q->where('status', 'waitlisted'),
            'participants as benched_count' => fn ($q) => $q->where('status', 'benched'),
        ])->find($campaign->id);

        $rendered = view('livewire.discovery.partials.campaign-card', ['campaign' => $campaignWithCounts])->render();

        expect($rendered)->toContain(trans_choice('common.content_n_on_bench', 1));
    });

    test('campaign card hides overflow indicator when no waitlisted or benched players', function () {
        $campaign = createPublicCampaignWithCounts($this->owner, $this->gameSystem, maxPlayers: 4);

        $campaignWithCounts = Campaign::withCount([
            'participants as waitlisted_count' => fn ($q) => $q->where('status', 'waitlisted'),
            'participants as benched_count' => fn ($q) => $q->where('status', 'benched'),
        ])->find($campaign->id);

        $rendered = view('livewire.discovery.partials.campaign-card', ['campaign' => $campaignWithCounts])->render();

        expect($rendered)->not->toContain('waitlisted')
            ->and($rendered)->not->toContain('on bench');
    });
});
