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
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
    $this->waitlistService = app(WaitlistService::class);
    $this->benchService = app(BenchService::class);
});

// ── Helpers ──────────────────────────────────────────────

function createFullGameForLeave(User $owner, GameSystem $system, int $maxPlayers = 2): Game
{
    $game = Game::create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'name' => 'Leave Test Game',
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

function createFullBenchGameForLeave(User $owner, GameSystem $system, int $maxPlayers = 2): Game
{
    $campaign = Campaign::create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'name' => 'Bench Campaign',
        'description' => 'Test',
        'visibility' => 'public',
        'status' => 'active',
        'language' => 'en',
        'recurrence' => 'weekly',
        'time_of_day' => '19:00',
        'session_duration' => 3,
        'min_players' => 2,
        'max_players' => $maxPlayers,
        'bench_mode' => true,
    ]);

    $game = Game::create([
        'owner_id' => $owner->id,
        'campaign_id' => $campaign->id,
        'game_system_id' => $system->id,
        'name' => 'Bench Leave Test Session',
        'date_time' => now()->addDays(10),
        'description' => 'Test',
        'expected_duration' => 3,
        'visibility' => 'public',
        'status' => 'scheduled',
        'language' => 'en',
        'location' => ['details' => 'Online'],
        'min_players' => 2,
        'max_players' => $maxPlayers,
        'bench_mode' => true,
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

function createFullCampaignForLeave(User $owner, GameSystem $system, int $maxPlayers = 2, bool $benchMode = false): Campaign
{
    $campaign = Campaign::create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'name' => 'Leave Test Campaign',
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
// LEAVE WAITLIST — GAME DETAIL
// ═══════════════════════════════════════════════════════════

describe('WaitlistUI leave waitlist', function () {
    test('waitlisted user can leave waitlist on game detail', function () {
        $game = createFullGameForLeave($this->owner, $this->gameSystem, maxPlayers: 2);
        $user = User::factory()->create();
        $participant = $this->waitlistService->addToWaitlist($game, $user);

        Log::shouldReceive('info')->with('waitlist.participant_left', \Mockery::on(fn ($ctx) =>
            $ctx['entity_id'] === $game->id && $ctx['user_id'] === $user->id
        ))->once();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('leaveWaitlist', $participant->id)
            ->assertHasNoErrors()
            ->assertSee(__('games.flash_left_waitlist'));

        // Status changed to rejected
        expect($participant->fresh()->status)->toBe(ParticipantStatus::Rejected);
    });

    test('leaving waitlist on standalone game promotes next waitlisted player', function () {
        $game = createFullGameForLeave($this->owner, $this->gameSystem, maxPlayers: 2);
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $this->travelTo(now()->addSecond());
        $p1 = $this->waitlistService->addToWaitlist($game, $user1);
        $this->travelTo(now()->addSecond());
        $p2 = $this->waitlistService->addToWaitlist($game, $user2);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        // user1 leaves — user2 should NOT be promoted because the game is still full (no slot opened)
        Livewire::actingAs($user1)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('leaveWaitlist', $p1->id);

        expect($p1->fresh()->status)->toBe(ParticipantStatus::Rejected);
        // user2 still waitlisted (game is full, no slot to fill)
        expect($p2->fresh()->status)->toBe(ParticipantStatus::Waitlisted);
    });

    test('leaving waitlist when game has open slot promotes next player', function () {
        $game = createFullGameForLeave($this->owner, $this->gameSystem, maxPlayers: 3);
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $this->travelTo(now()->addSecond());
        $p1 = $this->waitlistService->addToWaitlist($game, $user1);
        $this->travelTo(now()->addSecond());
        $p2 = $this->waitlistService->addToWaitlist($game, $user2);

        // Open a slot by rejecting a player
        $game->participants()
            ->where('role', 'player')
            ->where('status', ParticipantStatus::Approved->value)
            ->where('user_id', '!=', $this->owner->id)
            ->first()
            ->update(['status' => ParticipantStatus::Rejected->value]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        // user1 leaves — slot is open, so promoteAllOnCancel promotes user2
        Livewire::actingAs($user1)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('leaveWaitlist', $p1->id);

        expect($p1->fresh()->status)->toBe(ParticipantStatus::Rejected);
        expect($p2->fresh()->status)->toBe(ParticipantStatus::Pending);
    });

    test('non-owner cannot leave someone else waitlist spot', function () {
        $game = createFullGameForLeave($this->owner, $this->gameSystem, maxPlayers: 2);
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $p1 = $this->waitlistService->addToWaitlist($game, $user1);

        Livewire::actingAs($user2)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('leaveWaitlist', $p1->id);

        // Participant unchanged
        expect($p1->fresh()->status)->toBe(ParticipantStatus::Waitlisted);
    });

    test('cannot leave waitlist with non-waitlisted status', function () {
        $game = createFullGameForLeave($this->owner, $this->gameSystem, maxPlayers: 2);
        $user = User::factory()->create();

        // Create participant as approved (not waitlisted)
        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('leaveWaitlist', $participant->id);

        // Status unchanged
        expect($participant->fresh()->status)->toBe(ParticipantStatus::Approved);
    });
});

// ═══════════════════════════════════════════════════════════
// LEAVE WAITLIST — CAMPAIGN DETAIL
// ═══════════════════════════════════════════════════════════

describe('WaitlistUI campaign leave waitlist', function () {
    test('waitlisted user can leave waitlist on campaign detail', function () {
        $campaign = createFullCampaignForLeave($this->owner, $this->gameSystem, maxPlayers: 2, benchMode: false);
        $user = User::factory()->create();
        $participant = $this->waitlistService->addToWaitlist($campaign, $user);

        Log::shouldReceive('info')->with('waitlist.participant_left', \Mockery::on(fn ($ctx) =>
            $ctx['entity_id'] === $campaign->id && $ctx['user_id'] === $user->id
        ))->once();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->call('leaveWaitlist', $participant->id)
            ->assertHasNoErrors();

        expect($participant->fresh()->status)->toBe(ParticipantStatus::Rejected);
    });

    test('campaign detail shows waitlist banner for waitlisted user with bench_mode=false', function () {
        $campaign = createFullCampaignForLeave($this->owner, $this->gameSystem, maxPlayers: 2, benchMode: false);
        $user = User::factory()->create();
        $this->waitlistService->addToWaitlist($campaign, $user);

        Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->assertSee(__('campaigns.action_join_waitlist'))
            ->assertSee(__('campaigns.content_waitlist_position', ['position' => 1]))
            ->assertSee(__('campaigns.action_leave_waitlist'));
    });

    test('campaign detail does not show waitlist banner when bench_mode=true', function () {
        $campaign = createFullCampaignForLeave($this->owner, $this->gameSystem, maxPlayers: 2, benchMode: true);
        $user = User::factory()->create();

        // In bench mode, users go to bench, not waitlist
        $participant = $this->benchService->addToBench($campaign, $user);

        Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->assertDontSee(__('campaigns.action_join_waitlist'))
            ->assertSee(__('campaigns.content_you_are_on_the_bench'));
    });
});

// ═══════════════════════════════════════════════════════════
// LEAVE BENCH — GAME DETAIL (CAMPAIGN SESSION)
// ═══════════════════════════════════════════════════════════

describe('BenchUI leave bench', function () {
    test('benched user can leave bench on campaign session detail', function () {
        $game = createFullBenchGameForLeave($this->owner, $this->gameSystem, maxPlayers: 2);
        $benchedUser = User::factory()->create();
        $benchedParticipant = $this->benchService->addToBench($game, $benchedUser);

        Log::shouldReceive('info')->with('bench.participant_left', \Mockery::on(fn ($ctx) =>
            $ctx['entity_id'] === $game->id && $ctx['user_id'] === $benchedUser->id
        ))->once();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        Livewire::actingAs($benchedUser)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('leaveBench', $benchedParticipant->id)
            ->assertHasNoErrors()
            ->assertSee(__('games.flash_left_bench'));

        expect($benchedParticipant->fresh()->status)->toBe(ParticipantStatus::Rejected);
    });

    test('benched user can leave bench on campaign detail', function () {
        $campaign = createFullCampaignForLeave($this->owner, $this->gameSystem, maxPlayers: 2, benchMode: true);
        $user = User::factory()->create();
        $participant = $this->benchService->addToBench($campaign, $user);

        Log::shouldReceive('info')->with('bench.participant_left', \Mockery::on(fn ($ctx) =>
            $ctx['entity_id'] === $campaign->id && $ctx['user_id'] === $user->id
        ))->once();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->call('leaveBench', $participant->id)
            ->assertHasNoErrors();

        expect($participant->fresh()->status)->toBe(ParticipantStatus::Rejected);
    });

    test('non-owner cannot leave someone else bench spot', function () {
        $game = createFullBenchGameForLeave($this->owner, $this->gameSystem, maxPlayers: 2);
        $benchedParticipant = $this->benchService->addToBench($game, User::factory()->create());
        $otherUser = User::factory()->create();

        Livewire::actingAs($otherUser)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('leaveBench', $benchedParticipant->id);

        // Status unchanged
        expect($benchedParticipant->fresh()->status)->toBe(ParticipantStatus::Benched);
    });

    test('cannot leave bench with non-benched status', function () {
        $game = createFullBenchGameForLeave($this->owner, $this->gameSystem, maxPlayers: 2);
        $user = User::factory()->create();

        // Create participant as approved (not benched)
        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        Livewire::actingAs($user)
            ->test(\App\Livewire\Games\GameDetail::class, ['id' => $game->id])
            ->call('leaveBench', $participant->id);

        // Status unchanged
        expect($participant->fresh()->status)->toBe(ParticipantStatus::Approved);
    });

    test('campaign detail shows bench section when bench_mode=true', function () {
        $campaign = createFullCampaignForLeave($this->owner, $this->gameSystem, maxPlayers: 2, benchMode: true);
        $user = User::factory()->create();
        $this->benchService->addToBench($campaign, $user);

        Livewire::actingAs($user)
            ->test(\App\Livewire\Campaigns\CampaignDetail::class, ['id' => $campaign->id])
            ->assertSee(__('campaigns.content_you_are_on_the_bench'))
            ->assertSee(__('campaigns.content_you_have_been_placed_on_the_bench'))
            ->assertSee(__('games.action_leave_bench'));
    });
});
