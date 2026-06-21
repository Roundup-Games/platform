<?php

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Livewire\Campaigns\CampaignDetail;
use App\Livewire\Games\GameDetail;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\BenchService;
use App\Services\WaitlistService;
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
        'name' => ['en' => 'Leave Test Game'],
        'date_time' => now()->addDays(7),
        'description' => ['en' => 'Test'],
        'expected_duration' => 3,
        'visibility' => 'public',
        'status' => 'scheduled',
        'language' => 'en',
        'location' => ['details' => 'Online'],
        'min_players' => 2,
        'max_players' => $maxPlayers,
        'campaign_id' => null,
    ]);

    // Owner participant (explicit owner model)
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $owner->id,
        'role' => ParticipantRole::Owner->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    // Fill remaining non-owner slots
    for ($i = 1; $i < $maxPlayers; $i++) {
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
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
        'name' => ['en' => 'Bench Campaign'],
        'description' => ['en' => 'Test'],
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
        'name' => ['en' => 'Bench Leave Test Session'],
        'date_time' => now()->addDays(10),
        'description' => ['en' => 'Test'],
        'expected_duration' => 3,
        'visibility' => 'public',
        'status' => 'scheduled',
        'language' => 'en',
        'location' => ['details' => 'Online'],
        'min_players' => 2,
        'max_players' => $maxPlayers,
        'bench_mode' => true,
    ]);

    // Owner participant (explicit owner model)
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $owner->id,
        'role' => ParticipantRole::Owner->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    // Fill remaining non-owner slots
    for ($i = 1; $i < $maxPlayers; $i++) {
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
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
        'name' => ['en' => 'Leave Test Campaign'],
        'description' => ['en' => 'Test'],
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

    // Owner participant (explicit owner model)
    CampaignParticipant::create([
        'campaign_id' => $campaign->id,
        'user_id' => $owner->id,
        'role' => ParticipantRole::Owner->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    // Fill remaining non-owner slots
    for ($i = 1; $i < $maxPlayers; $i++) {
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
    }

    return $campaign;
}

/**
 * Build a full entity (game / bench-game / campaign) suitable for the given
 * participant status, returning the entity + the Livewire detail component
 * class used to exercise the leave flow on it.
 */
function buildFullEntityForLeave(User $owner, GameSystem $system, ParticipantStatus $status): array
{
    if ($status === ParticipantStatus::Benched) {
        // Bench needs bench_mode=true; use a campaign session game (mimics
        // original test for game detail) and a bench-mode campaign.
        return [
            createFullBenchGameForLeave($owner, $system, maxPlayers: 2),
            GameDetail::class,
        ];
    }

    return [
        createFullGameForLeave($owner, $system, maxPlayers: 2),
        GameDetail::class,
    ];
}

// ═══════════════════════════════════════════════════════════
// LEAVE FLOW — parameterized over [entity, participant_status]
// Same leave contract (status → Rejected + flash) previously duplicated
// as 4 separate tests: game×waitlist, game×bench, campaign×waitlist,
// campaign×bench.
// ═══════════════════════════════════════════════════════════

describe('leave flow', function () {
    it('honors the leave contract across entity and participant status', function (
        string $entityType,
        ParticipantStatus $status,
        string $leaveMethod,
        ?string $expectedFlash,
    ) {
        if ($entityType === 'game') {
            [$entity, $componentClass] = buildFullEntityForLeave($this->owner, $this->gameSystem, $status);
        } else {
            $entity = createFullCampaignForLeave($this->owner, $this->gameSystem, maxPlayers: 2, benchMode: $status === ParticipantStatus::Benched);
            $componentClass = CampaignDetail::class;
        }

        $user = User::factory()->create();
        $participant = $status === ParticipantStatus::Benched
            ? $this->benchService->addToBench($entity, $user)
            : $this->waitlistService->addToWaitlist($entity, $user);

        $request = Livewire::actingAs($user)
            ->test($componentClass, ['id' => $entity->id])
            ->call($leaveMethod, $participant->id)
            ->assertHasNoErrors();

        if ($expectedFlash !== null) {
            $request->assertSee(__($expectedFlash));
        }

        expect($participant->fresh()->status)->toBe(ParticipantStatus::Rejected);
    })->with([
        'waitlisted on game detail' => ['game',     ParticipantStatus::Waitlisted, 'leaveWaitlist', 'games.flash_left_waitlist'],
        'benched on campaign session detail' => ['game',     ParticipantStatus::Benched,    'leaveBench',    'games.flash_left_bench'],
        'waitlisted on campaign detail' => ['campaign', ParticipantStatus::Waitlisted, 'leaveWaitlist', null],
        'benched on campaign detail' => ['campaign', ParticipantStatus::Benched,    'leaveBench',    null],
    ]);

    it('prevents leaving someone else\'s spot or with wrong status', function (
        string $scenario,
        ParticipantStatus $status,
        string $leaveMethod,
    ) {
        // game-side entity — waitlist uses standalone game, bench uses campaign session
        [$entity, $componentClass] = buildFullEntityForLeave($this->owner, $this->gameSystem, $status);

        if ($scenario === 'non-owner') {
            // Add a real waitlisted/benched participant owned by someone else
            $owner = User::factory()->create();
            $participant = $status === ParticipantStatus::Benched
                ? $this->benchService->addToBench($entity, $owner)
                : $this->waitlistService->addToWaitlist($entity, $owner);
            $actingUser = User::factory()->create();
        } else { // 'wrong-status'
            $actingUser = User::factory()->create();
            $participant = GameParticipant::create([
                'game_id' => $entity->id,
                'user_id' => $actingUser->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
            ]);
        }

        Livewire::actingAs($actingUser)
            ->test($componentClass, ['id' => $entity->id])
            ->call($leaveMethod, $participant->id);

        // Status unchanged
        expect($participant->fresh()->status)->toBe($scenario === 'non-owner' ? $status : ParticipantStatus::Approved);
    })->with([
        'non-owner cannot leave waitlist' => ['non-owner',   ParticipantStatus::Waitlisted, 'leaveWaitlist'],
        'non-owner cannot leave bench' => ['non-owner',   ParticipantStatus::Benched,    'leaveBench'],
        'wrong-status cannot leave waitlist' => ['wrong-status', ParticipantStatus::Waitlisted, 'leaveWaitlist'],
        'wrong-status cannot leave bench' => ['wrong-status', ParticipantStatus::Benched,    'leaveBench'],
    ]);
});

// ═══════════════════════════════════════════════════════════
// PROMOTION-ON-LEAVE — waitlist-specific (no bench equivalent)
// ═══════════════════════════════════════════════════════════

describe('waitlist promotion on leave', function () {
    test('leaving waitlist on full game does not promote next (no slot opens)', function () {
        $game = createFullGameForLeave($this->owner, $this->gameSystem, maxPlayers: 2);
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $this->travelTo(now()->addSecond());
        $p1 = $this->waitlistService->addToWaitlist($game, $user1);
        $this->travelTo(now()->addSecond());
        $p2 = $this->waitlistService->addToWaitlist($game, $user2);

        // user1 leaves — user2 should NOT be promoted because the game is still full (no slot opened)
        Livewire::actingAs($user1)
            ->test(GameDetail::class, ['id' => $game->id])
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

        // user1 leaves — slot is open, so promoteAllOnCancel promotes user2
        Livewire::actingAs($user1)
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('leaveWaitlist', $p1->id);

        expect($p1->fresh()->status)->toBe(ParticipantStatus::Rejected);
        expect($p2->fresh()->status)->toBe(ParticipantStatus::Pending);
    });
});

// ═══════════════════════════════════════════════════════════
// UI BANNER VISIBILITY — waitlist vs bench surface on detail pages
// ═══════════════════════════════════════════════════════════

describe('campaign detail banner visibility', function () {
    test('campaign detail shows waitlist banner for waitlisted user with bench_mode=false', function () {
        $campaign = createFullCampaignForLeave($this->owner, $this->gameSystem, maxPlayers: 2, benchMode: false);
        $user = User::factory()->create();
        $this->waitlistService->addToWaitlist($campaign, $user);

        Livewire::actingAs($user)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertSee(__('campaigns.action_join_waitlist'))
            ->assertSee(__('campaigns.content_waitlist_position', ['position' => 1]))
            ->assertSee(__('campaigns.action_leave_waitlist'));
    });

    test('campaign detail hides waitlist banner and shows bench surface when bench_mode=true', function () {
        $campaign = createFullCampaignForLeave($this->owner, $this->gameSystem, maxPlayers: 2, benchMode: true);
        $user = User::factory()->create();

        // In bench mode, users go to bench, not waitlist
        $this->benchService->addToBench($campaign, $user);

        Livewire::actingAs($user)
            ->test(CampaignDetail::class, ['id' => $campaign->id])
            ->assertDontSee(__('campaigns.action_join_waitlist'))
            ->assertSee(__('campaigns.content_you_are_on_the_bench'))
            ->assertSee(__('campaigns.content_you_have_been_placed_on_the_bench'))
            ->assertSee(__('games.action_leave_bench'));
    });
});
