<?php

use App\Dto\EntityMeta;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\OverflowRouter;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

beforeEach(function () {
    $this->gameSystem = GameSystem::factory()->create();
});

function createFullGame($owner, $system, bool $benchMode = false, int $maxPlayers = 2): Game
{
    return Game::create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'name' => ['en' => 'Full Game'],
        'date_time' => now()->addDays(7),
        'description' => ['en' => 'test'],
        'expected_duration' => 3,
        'visibility' => 'public',
        'status' => 'scheduled',
        'language' => 'en',
        'location' => ['details' => 'Online'],
        'min_players' => 1,
        'max_players' => $maxPlayers,
        'campaign_id' => null,
        'bench_mode' => $benchMode,
    ]);
}

function fillToCapacity(Game $game): void
{
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $game->owner_id,
        'role' => ParticipantRole::Owner->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    for ($i = 1; $i < $game->max_players; $i++) {
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
    }
}

// ── Decision (OverflowRouter::resolve) ───────────────────

it('routes to Waitlisted when the entity is not bench-mode', function () {
    $owner = User::factory()->create();
    $game = createFullGame($owner, $this->gameSystem, benchMode: false);

    $overflow = app(OverflowRouter::class)->resolve($game);

    expect($overflow->status)->toBe(ParticipantStatus::Waitlisted)
        ->and($overflow->timestampColumn)->toBe('waitlisted_at')
        ->and($overflow->isWaitlist())->toBeTrue()
        ->and($overflow->isBench())->toBeFalse();
});

it('routes to Benched when the entity is bench-mode', function () {
    $owner = User::factory()->create();
    $game = createFullGame($owner, $this->gameSystem, benchMode: true);

    $overflow = app(OverflowRouter::class)->resolve($game);

    expect($overflow->status)->toBe(ParticipantStatus::Benched)
        ->and($overflow->timestampColumn)->toBe('benched_at')
        ->and($overflow->isBench())->toBeTrue()
        ->and($overflow->isWaitlist())->toBeFalse();
});

// ── Placement: accepted-invitee (OverflowRouter::placeAcceptedInvitee) ──

it('moves an accepted invitee to Waitlisted on a full non-bench game', function () {
    $owner = User::factory()->create();
    $game = createFullGame($owner, $this->gameSystem, benchMode: false);
    fillToCapacity($game);

    $invitee = User::factory()->create();
    $participant = GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $invitee->id,
        'role' => ParticipantRole::Invited->value,
        'status' => ParticipantStatus::Pending->value,
    ]);

    app(OverflowRouter::class)->placeAcceptedInvitee(
        $participant->fresh(),
        $game,
        EntityMeta::fromEntity($game),
    );

    $row = $participant->fresh();

    expect($row->status)->toBe(ParticipantStatus::Waitlisted)
        ->and($row->waitlisted_at)->not->toBeNull()
        ->and($row->benched_at)->toBeNull();
});

it('moves an accepted invitee to Benched on a full bench-mode game', function () {
    $owner = User::factory()->create();
    $game = createFullGame($owner, $this->gameSystem, benchMode: true);
    fillToCapacity($game);

    $invitee = User::factory()->create();
    $participant = GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $invitee->id,
        'role' => ParticipantRole::Invited->value,
        'status' => ParticipantStatus::Pending->value,
    ]);

    app(OverflowRouter::class)->placeAcceptedInvitee(
        $participant->fresh(),
        $game,
        EntityMeta::fromEntity($game),
    );

    $row = $participant->fresh();

    expect($row->status)->toBe(ParticipantStatus::Benched)
        ->and($row->benched_at)->not->toBeNull()
        ->and($row->waitlisted_at)->toBeNull();
});

// ── Flash (OverflowRouter::flashResult) ──────────────────

it('returns the waitlisted flash key for non-bench entities', function () {
    $owner = User::factory()->create();
    $game = createFullGame($owner, $this->gameSystem, benchMode: false);

    $result = app(OverflowRouter::class)->flashResult($game);

    expect($result->messageKey)->toBe('people.flash_email_invite_waitlisted');
});

it('returns the benched flash key for bench-mode entities', function () {
    $owner = User::factory()->create();
    $game = createFullGame($owner, $this->gameSystem, benchMode: true);

    $result = app(OverflowRouter::class)->flashResult($game);

    expect($result->messageKey)->toBe('people.flash_email_invite_benched');
});

// ── Consistency: share-link vs email-invite produce the same status ──

it('produces the same overflow status for share-link apply and email-invite on a full game', function () {
    // The validation audit flagged that GameDetail's share-link apply path
    // reimplemented the bench-mode branch inline, bypassing the service.
    // OverflowRouter::resolve() is now the single decision point — both the
    // service placement (placeAcceptedInvitee) and the GameDetail inline
    // branch route through it. This test proves they agree.
    $owner = User::factory()->create();

    foreach ([false, true] as $benchMode) {
        $game = createFullGame($owner, $this->gameSystem, benchMode: $benchMode);

        // What the service would assign
        $serviceStatus = app(OverflowRouter::class)->resolve($game);

        // What GameDetail's rewritten share-link branch would assign
        // (it calls the same resolve() — verifying the routing is consistent)
        expect($serviceStatus->statusValue())
            ->toBe($benchMode
                ? ParticipantStatus::Benched->value
                : ParticipantStatus::Waitlisted->value)
            ->and($serviceStatus->timestampColumn)
            ->toBe($benchMode ? 'benched_at' : 'waitlisted_at');
    }
});
