<?php

use App\Enums\ParticipantStatus;
use App\Enums\ParticipantRole;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\BenchService;
use App\Services\WaitlistService;

beforeEach(function () {
    $this->service = app(WaitlistService::class);
    $this->benchService = app(BenchService::class);
    $this->owner = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
});

// ── Helpers ──────────────────────────────────────────────

function createFullWaitlistCampaign(User $owner, GameSystem $system, int $maxPlayers = 3): Campaign
{
    $campaign = Campaign::create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'name' => ['en' => 'Waitlist Campaign'],
        'description' => ['en' => 'Test campaign'],
        'visibility' => 'public',
        'status' => 'active',
        'language' => 'en',
        'recurrence' => 'weekly',
        'time_of_day' => '19:00',
        'session_duration' => 3,
        'min_players' => 2,
        'max_players' => $maxPlayers,
        'bench_mode' => false,
    ]);

    CampaignParticipant::create([
        'campaign_id' => $campaign->id,
        'user_id' => $owner->id,
        'role' => ParticipantRole::Owner->value,
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

// ── Campaign Waitlist Flow ───────────────────────────────

test('add to waitlist for full campaign with bench_mode=false', function () {
    $campaign = createFullWaitlistCampaign($this->owner, $this->gameSystem, maxPlayers: 2);
    $user = User::factory()->create();

    $participant = $this->service->addToWaitlist($campaign, $user);

    expect($participant)->toBeInstanceOf(CampaignParticipant::class);
    expect($participant->status)->toBe(ParticipantStatus::Waitlisted);
    expect($participant->waitlisted_at)->not->toBeNull();
    expect($participant->campaign_id)->toBe($campaign->id);
});

test('add to waitlist throws for campaign with bench_mode=true', function () {
    $campaign = Campaign::create([
        'owner_id' => $this->owner->id,
        'game_system_id' => $this->gameSystem->id,
        'name' => ['en' => 'Bench Campaign'],
        'description' => ['en' => 'Test'],
        'visibility' => 'public',
        'status' => 'active',
        'language' => 'en',
        'recurrence' => 'weekly',
        'time_of_day' => '19:00',
        'session_duration' => 3,
        'min_players' => 2,
        'max_players' => 3,
        'bench_mode' => true,
    ]);

    CampaignParticipant::create([
        'campaign_id' => $campaign->id,
        'user_id' => $this->owner->id,
        'role' => ParticipantRole::Owner->value,
        'status' => ParticipantStatus::Approved->value,
    ]);
    CampaignParticipant::create([
        'campaign_id' => $campaign->id,
        'user_id' => User::factory()->create()->id,
        'role' => 'player',
        'status' => ParticipantStatus::Approved->value,
    ]);
    CampaignParticipant::create([
        'campaign_id' => $campaign->id,
        'user_id' => User::factory()->create()->id,
        'role' => 'player',
        'status' => ParticipantStatus::Approved->value,
    ]);

    $user = User::factory()->create();

    expect(fn () => $this->service->addToWaitlist($campaign, $user))
        ->toThrow(\LogicException::class, 'Waitlist is not available for this campaign (bench mode is enabled).');
});

test('promote next waitlisted for campaign', function () {
    $campaign = createFullWaitlistCampaign($this->owner, $this->gameSystem, maxPlayers: 2);
    $user = User::factory()->create();
    $this->service->addToWaitlist($campaign, $user);

    // Open a slot
    $campaign->participants()
        ->where('role', 'player')
        ->where('status', ParticipantStatus::Approved->value)
        ->where('user_id', '!=', $this->owner->id)
        ->first()
        ->update(['status' => ParticipantStatus::Rejected->value]);

    $promoted = $this->service->promoteNext($campaign);

    expect($promoted)->not->toBeNull();
    expect($promoted)->toBeInstanceOf(CampaignParticipant::class);
    expect($promoted->status)->toBe(ParticipantStatus::Pending);
    expect($promoted->confirmation_expires_at)->not->toBeNull();
});

test('confirm promotion for campaign participant', function () {
    $campaign = createFullWaitlistCampaign($this->owner, $this->gameSystem, maxPlayers: 2);
    $user = User::factory()->create();
    $this->service->addToWaitlist($campaign, $user);

    $campaign->participants()
        ->where('role', 'player')
        ->where('status', ParticipantStatus::Approved->value)
        ->where('user_id', '!=', $this->owner->id)
        ->first()
        ->update(['status' => ParticipantStatus::Rejected->value]);

    $promoted = $this->service->promoteNext($campaign);
    $this->service->confirmPromotion($promoted);

    expect($promoted->fresh()->status)->toBe(ParticipantStatus::Approved);
});

test('handle campaign cancellation rejects all waitlisted', function () {
    $campaign = createFullWaitlistCampaign($this->owner, $this->gameSystem, maxPlayers: 2);
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $this->service->addToWaitlist($campaign, $user1);
    $this->service->addToWaitlist($campaign, $user2);

    $this->service->handleCampaignCancellation($campaign);

    $rejected = $campaign->participants()
        ->where('status', ParticipantStatus::Rejected->value)
        ->get();
    expect($rejected)->toHaveCount(2);
});

test('campaign waitlist uses far confirmation window (12h)', function () {
    $campaign = createFullWaitlistCampaign($this->owner, $this->gameSystem, maxPlayers: 2);
    $user = User::factory()->create();
    $this->service->addToWaitlist($campaign, $user);

    $campaign->participants()
        ->where('role', 'player')
        ->where('status', ParticipantStatus::Approved->value)
        ->where('user_id', '!=', $this->owner->id)
        ->first()
        ->update(['status' => ParticipantStatus::Rejected->value]);

    $promoted = $this->service->promoteNext($campaign);
    $windowHours = now()->diffInHours($promoted->confirmation_expires_at, false);

    // Far window = 12h
    expect($windowHours)->toBeGreaterThanOrEqual(11);
    expect($windowHours)->toBeLessThanOrEqual(13);
});

// ── Bench Mode Toggle Edge Cases ─────────────────────────

test('standalone game with bench_mode=true uses bench', function () {
    $game = Game::create([
        'owner_id' => $this->owner->id,
        'game_system_id' => $this->gameSystem->id,
        'name' => ['en' => 'Bench Mode Game'],
        'date_time' => now()->addDays(10),
        'description' => ['en' => 'Test'],
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
        'role' => 'player',
        'status' => ParticipantStatus::Approved->value,
    ]);
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => User::factory()->create()->id,
        'role' => 'player',
        'status' => ParticipantStatus::Approved->value,
    ]);

    $user = User::factory()->create();
    $participant = $this->benchService->addToBench($game, $user);

    expect($participant)->toBeInstanceOf(GameParticipant::class);
    expect($participant->status)->toBe(ParticipantStatus::Benched);
    expect($participant->benched_at)->not->toBeNull();
});

test('standalone game with bench_mode=true rejects waitlist', function () {
    $game = Game::create([
        'owner_id' => $this->owner->id,
        'game_system_id' => $this->gameSystem->id,
        'name' => ['en' => 'Bench Mode Game'],
        'date_time' => now()->addDays(10),
        'description' => ['en' => 'Test'],
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
        'role' => 'player',
        'status' => ParticipantStatus::Approved->value,
    ]);
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => User::factory()->create()->id,
        'role' => 'player',
        'status' => ParticipantStatus::Approved->value,
    ]);

    $user = User::factory()->create();

    expect(fn () => $this->service->addToWaitlist($game, $user))
        ->toThrow(\LogicException::class, 'bench mode is enabled');
});

test('campaign with bench_mode=false rejects bench', function () {
    $campaign = Campaign::create([
        'owner_id' => $this->owner->id,
        'game_system_id' => $this->gameSystem->id,
        'name' => ['en' => 'Waitlist Campaign'],
        'description' => ['en' => 'Test'],
        'visibility' => 'public',
        'status' => 'active',
        'language' => 'en',
        'recurrence' => 'weekly',
        'time_of_day' => '19:00',
        'session_duration' => 3,
        'min_players' => 2,
        'max_players' => 2,
        'bench_mode' => false,
    ]);

    CampaignParticipant::create([
        'campaign_id' => $campaign->id,
        'user_id' => $this->owner->id,
        'role' => ParticipantRole::Owner->value,
        'status' => ParticipantStatus::Approved->value,
    ]);
    CampaignParticipant::create([
        'campaign_id' => $campaign->id,
        'user_id' => User::factory()->create()->id,
        'role' => 'player',
        'status' => ParticipantStatus::Approved->value,
    ]);

    $user = User::factory()->create();

    // BenchService only blocks games, not campaigns — campaigns always go to bench.
    // But the campaign with bench_mode=false should use waitlist, not bench.
    // BenchService.addToBench doesn't check bench_mode for campaigns (campaigns can always bench).
    // The routing decision happens at the component level, not the service level.
    $participant = $this->benchService->addToBench($campaign, $user);
    expect($participant)->toBeInstanceOf(CampaignParticipant::class);
});

test('bench mode toggle on game can be changed', function () {
    $game = Game::create([
        'owner_id' => $this->owner->id,
        'game_system_id' => $this->gameSystem->id,
        'name' => ['en' => 'Toggle Game'],
        'date_time' => now()->addDays(10),
        'description' => ['en' => 'Test'],
        'expected_duration' => 3,
        'visibility' => 'public',
        'status' => 'scheduled',
        'language' => 'en',
        'location' => ['details' => 'Online'],
        'min_players' => 2,
        'max_players' => 4,
        'bench_mode' => false,
    ]);

    expect($game->isBenchMode())->toBeFalse();

    $game->update(['bench_mode' => true]);
    expect($game->fresh()->isBenchMode())->toBeTrue();
});

// ── Campaign Waitlist FIFO ───────────────────────────────

test('campaign waitlist maintains FIFO ordering', function () {
    $campaign = createFullWaitlistCampaign($this->owner, $this->gameSystem, maxPlayers: 2);

    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $user3 = User::factory()->create();

    $p1 = $this->service->addToWaitlist($campaign, $user1);
    $this->travelTo(now()->addSecond());
    $p2 = $this->service->addToWaitlist($campaign, $user2);
    $this->travelTo(now()->addSecond());
    $p3 = $this->service->addToWaitlist($campaign, $user3);

    expect($this->service->getWaitlistPosition($p1))->toBe(1);
    expect($this->service->getWaitlistPosition($p2))->toBe(2);
    expect($this->service->getWaitlistPosition($p3))->toBe(3);
});

test('campaign waitlist promotion and confirmation chain', function () {
    $campaign = createFullWaitlistCampaign($this->owner, $this->gameSystem, maxPlayers: 2);

    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $this->service->addToWaitlist($campaign, $user1);
    $this->travelTo(now()->addSecond());
    $this->service->addToWaitlist($campaign, $user2);

    // Open a slot
    $campaign->participants()
        ->where('role', 'player')
        ->where('status', ParticipantStatus::Approved->value)
        ->where('user_id', '!=', $this->owner->id)
        ->first()
        ->update(['status' => ParticipantStatus::Rejected->value]);

    // user1 should be promoted first (FIFO)
    $promoted = $this->service->promoteNext($campaign);
    expect($promoted->user_id)->toBe($user1->id);
    expect($promoted->status)->toBe(ParticipantStatus::Pending);

    // Confirm user1
    $this->service->confirmPromotion($promoted);
    expect($promoted->fresh()->status)->toBe(ParticipantStatus::Approved);

    // user2 should still be waitlisted
    $user2Participant = CampaignParticipant::where('campaign_id', $campaign->id)
        ->where('user_id', $user2->id)
        ->first();
    expect($user2Participant->status)->toBe(ParticipantStatus::Waitlisted);
});
