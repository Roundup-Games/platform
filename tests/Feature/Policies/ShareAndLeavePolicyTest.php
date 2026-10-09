<?php

use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

//
// Direct coverage for the entity action abilities extracted from the detail
// components' hand-rolled owner_id comparisons (see .ai/rules/livewire.md):
// manageShareToken, leave (Game + Campaign) and clone (Game only). The
// component flows exercise these abilities indirectly; these tests pin the
// policy rules themselves — including the before() global-admin bypass,
// which is documented behavior for the share-token mutations.
//

/**
 * Create an owner plus one session entity of the given type.
 *
 * @param  'game'|'campaign'  $type
 * @return array{0: User, 1: Game|Campaign}
 */
function actionPolicyEntity(string $type): array
{
    $owner = User::factory()->create();
    $system = GameSystem::factory()->create();

    $attributes = [
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
    ];

    $entity = $type === 'game'
        ? Game::factory()->create($attributes)
        : Campaign::factory()->create($attributes);

    return [$owner, $entity];
}

/**
 * Seat a user on the entity as a player with the given status.
 *
 * @param  'game'|'campaign'  $type
 */
function actionPolicyParticipant(string $type, Game|Campaign $entity, User $user, ParticipantStatus $status): void
{
    $attributes = [
        'user_id' => $user->id,
        'role' => 'player',
        'status' => $status->value,
    ];

    if ($type === 'game') {
        GameParticipant::factory()->create([
            ...$attributes,
            'game_id' => $entity->id,
        ]);

        return;
    }

    CampaignParticipant::factory()->create([
        ...$attributes,
        'campaign_id' => $entity->id,
    ]);
}

/** Player statuses under which a participant may still leave the entity. */
function leavableParticipantStatuses(): array
{
    return [
        'approved' => ParticipantStatus::Approved,
        'waitlisted' => ParticipantStatus::Waitlisted,
        'benched' => ParticipantStatus::Benched,
        'pending' => ParticipantStatus::Pending,
    ];
}

/** Player statuses that are no longer active — leaving is denied. */
function departedParticipantStatuses(): array
{
    return [
        'rejected' => ParticipantStatus::Rejected,
        'removed' => ParticipantStatus::Removed,
    ];
}

// ── manageShareToken ─────────────────────────────────────────────────────

describe('manageShareToken', function () {
    it('grants the owner share-token control of their entity', function (string $type) {
        [$owner, $entity] = actionPolicyEntity($type);

        expect(Gate::forUser($owner)->allows('manageShareToken', $entity))->toBeTrue();
    })->with(['game', 'campaign']);

    it('denies share-token control to everyone but the owner', function (string $type) {
        [, $entity] = actionPolicyEntity($type);

        $participant = User::factory()->create();
        actionPolicyParticipant($type, $entity, $participant, ParticipantStatus::Approved);

        $stranger = User::factory()->create();

        expect(Gate::forUser($participant)->allows('manageShareToken', $entity))->toBeFalse()
            ->and(Gate::forUser($stranger)->allows('manageShareToken', $entity))->toBeFalse();
    })->with(['game', 'campaign']);

    it('grants a global admin share-token control via the before() bypass', function (string $type) {
        seedRoles();

        setPermissionsTeamId(null);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $admin = User::factory()->create();
        $admin->assignRole('Platform Admin');
        $admin->unsetRelations();

        [, $entity] = actionPolicyEntity($type);

        expect(Gate::forUser($admin)->allows('manageShareToken', $entity))->toBeTrue();
    })->with(['game', 'campaign']);
});

// ── leave ────────────────────────────────────────────────────────────────

describe('leave', function () {
    it('denies the owner leaving their own entity', function (string $type) {
        [$owner, $entity] = actionPolicyEntity($type);

        // Owners exit via cancel/delete instead, even though they usually
        // also carry an owner participant row.
        expect(Gate::forUser($owner)->allows('leave', $entity))->toBeFalse();
    })->with(['game', 'campaign']);

    it('lets an active participant leave', function (string $type, ParticipantStatus $status) {
        [, $entity] = actionPolicyEntity($type);

        $participant = User::factory()->create();
        actionPolicyParticipant($type, $entity, $participant, $status);

        expect(Gate::forUser($participant)->allows('leave', $entity))->toBeTrue();
    })->with(['game', 'campaign'])->with(leavableParticipantStatuses());

    it('denies a departed participant leaving again', function (string $type, ParticipantStatus $status) {
        [, $entity] = actionPolicyEntity($type);

        $participant = User::factory()->create();
        actionPolicyParticipant($type, $entity, $participant, $status);

        expect(Gate::forUser($participant)->allows('leave', $entity))->toBeFalse();
    })->with(['game', 'campaign'])->with(departedParticipantStatuses());

    it('denies a stranger leaving an entity they are not in', function (string $type) {
        [, $entity] = actionPolicyEntity($type);

        $stranger = User::factory()->create();

        expect(Gate::forUser($stranger)->allows('leave', $entity))->toBeFalse();
    })->with(['game', 'campaign']);
});

// ── clone (game only) ────────────────────────────────────────────────────

describe('clone', function () {
    it('grants the owner cloning their own game', function () {
        [$owner, $game] = actionPolicyEntity('game');

        expect(Gate::forUser($owner)->allows('clone', $game))->toBeTrue();
    });

    it('denies cloning a game the user does not own', function () {
        [, $game] = actionPolicyEntity('game');

        $participant = User::factory()->create();
        actionPolicyParticipant('game', $game, $participant, ParticipantStatus::Approved);

        expect(Gate::forUser($participant)->allows('clone', $game))->toBeFalse();
    });
});
