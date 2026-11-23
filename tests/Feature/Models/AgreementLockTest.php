<?php

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\OwnerParticipantService;
use App\Services\ParticipantLifecycle;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ── Helpers ──────────────────────────────────────────────
//
// Every scenario runs against BOTH participant-bearing entities: D314 (games)
// and D317 (campaigns) define ONE lock predicate, and HasAgreementLock is the
// same trait on Game and Campaign. No skip guards — both participant tables
// carry approved_at (games since the capacity work, campaigns since
// M084/S01-T01).

/** @var array<string, class-string<Game|Campaign>> */
$agreementLockEntities = ['game' => Game::class, 'campaign' => Campaign::class];

/**
 * A fresh entity owned by $owner (a new user by default) with NO participant
 * rows — the baseline. Neither the factory nor the model observers create the
 * owner row (only SessionCreationService does, at create time), so the owner
 * row is added explicitly through that same production path by
 * agreementLockAddOwnerRow().
 *
 * @param  class-string<Game|Campaign>  $entityClass
 * @return array{owner: User, entity: Game|Campaign}
 */
function agreementLockEntity(string $entityClass, ?User $owner = null): array
{
    $owner ??= User::factory()->create();

    /** @var Game|Campaign $entity */
    $entity = $entityClass::factory()->create([
        'owner_id' => $owner->id,
        'max_players' => 5,
        'min_players' => 2,
    ]);

    return ['owner' => $owner, 'entity' => $entity];
}

/**
 * The owner's own participant row, created the way production creates it:
 * Approved from creation, stamped by the saving() observer.
 *
 * @param  array{entity: Game|Campaign}  $setup
 */
function agreementLockAddOwnerRow(array $setup): GameParticipant|CampaignParticipant
{
    $entity = $setup['entity'];

    if ($entity instanceof Game) {
        return app(OwnerParticipantService::class)->ensureOwnerParticipant($entity);
    }

    return app(OwnerParticipantService::class)->ensureCampaignOwnerParticipant($entity);
}

/**
 * A non-owner participant on the entity, written through the participant
 * model so the saving() observers stamp approved_at on Approved rows.
 * Approved by default; $attributes overrides any column.
 *
 * @param  array{entity: Game|Campaign}  $setup
 * @param  array<string, mixed>  $attributes
 * @return array{member: User, participant: GameParticipant|CampaignParticipant}
 */
function agreementLockAddMember(array $setup, array $attributes = []): array
{
    $entity = $setup['entity'];
    $member = User::factory()->create();

    if ($entity instanceof Game) {
        $participant = GameParticipant::factory()->create([
            'game_id' => $entity->id,
            'user_id' => $member->id,
            ...$attributes,
        ]);
    } else {
        $participant = CampaignParticipant::factory()->create([
            'campaign_id' => $entity->id,
            'user_id' => $member->id,
            ...$attributes,
        ]);
    }

    return ['member' => $member, 'participant' => $participant];
}

/**
 * The raw participant table for the entity's type — for fixture reads that
 * must bypass the model, so what gets asserted is the stored column.
 */
function agreementLockRawTable(Game|Campaign $entity): Builder
{
    return DB::table($entity instanceof Game ? 'game_participants' : 'campaign_participants');
}

/**
 * An Approved non-owner row with approved_at NULL — the one shape the
 * predicate cannot count. A DB::table() insert skips the Eloquent model
 * events (the saving() observers) that stamp every model-written approval, so
 * it reproduces a pre-stamp legacy row: the shape the legacy backfills exist
 * to retire. Returns the row id.
 *
 * @param  array{entity: Game|Campaign}  $setup
 */
function agreementLockInsertUnstampedApprovedRow(array $setup): string
{
    $entity = $setup['entity'];
    $isGame = $entity instanceof Game;
    $id = (string) Str::uuid();

    agreementLockRawTable($entity)->insert([
        'id' => $id,
        $isGame ? 'game_id' : 'campaign_id' => $entity->id,
        'user_id' => User::factory()->create()->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
        'created_at' => now(),
        'approved_at' => null,
    ]);

    return $id;
}

// ═══════════════════════════════════════════════════════════
// The lock predicate (D314 / D317): "has EVER had a confirmed non-owner
// participant" — the earliest approved_at among non-owner participants
// ═══════════════════════════════════════════════════════════

describe('agreement lock', function () use ($agreementLockEntities) {
    it('is not locked while the entity has no participants', function (string $entityClass) {
        $entity = agreementLockEntity($entityClass)['entity'];
        expect($entity->participants()->count())->toBe(0); // precondition

        expect($entity->agreementLockedAt())->toBeNull()
            ->and($entity->isAgreementLocked())->toBeFalse();
    })->with($agreementLockEntities);

    it('is not locked when only the owner has been approved', function (string $entityClass) {
        $setup = agreementLockEntity($entityClass);
        $entity = $setup['entity'];

        $ownerRow = agreementLockAddOwnerRow($setup);

        // Precondition: the owner row IS a stamped Approved row, so it is the
        // owner exclusion — not a missing stamp — that keeps the entity unlocked.
        expect($ownerRow->status)->toBe(ParticipantStatus::Approved)
            ->and($ownerRow->approved_at)->not->toBeNull();

        expect($entity->agreementLockedAt())->toBeNull()
            ->and($entity->isAgreementLocked())->toBeFalse();
    })->with($agreementLockEntities);

    it('locks at the first non-owner approval and reports that approval time', function (string $entityClass) {
        $setup = agreementLockEntity($entityClass);
        agreementLockAddOwnerRow($setup);
        $entity = $setup['entity'];

        // The observer stamps now(). Clock frozen on a whole second (approved_at
        // is a timestamp(0) column) so the stamp comes back out exactly.
        $approvedAt = now()->startOfSecond()->subHours(2);
        $this->travelTo($approvedAt, fn () => agreementLockAddMember($setup));

        $lockedAt = $entity->agreementLockedAt();
        expect($lockedAt)->toBeInstanceOf(Carbon::class) // read through the model: the datetime cast applies
            ->and($lockedAt?->equalTo($approvedAt))->toBeTrue()
            ->and($entity->isAgreementLocked())->toBeTrue();
    })->with($agreementLockEntities);

    it('stays locked after that participant departs — "ever had", not "currently has"', function (string $entityClass) {
        $setup = agreementLockEntity($entityClass);
        agreementLockAddOwnerRow($setup);
        $entity = $setup['entity'];

        ['member' => $member, 'participant' => $row] = agreementLockAddMember($setup);
        $approvedAt = $row->fresh()->approved_at;
        expect($approvedAt)->not->toBeNull();

        app(ParticipantLifecycle::class)->depart($row->fresh(), $member);

        // Precondition: the row is no longer an Approved seat, and its stamp
        // outlives the seat (a departure never clears approved_at).
        $departed = $row->fresh();
        expect($departed->status)->not->toBe(ParticipantStatus::Approved)
            ->and($departed->approved_at?->equalTo($approvedAt))->toBeTrue();

        expect($entity->isAgreementLocked())->toBeTrue()
            ->and($entity->agreementLockedAt()?->equalTo($approvedAt))->toBeTrue();
    })->with($agreementLockEntities);

    it('ignores the current status of a member who was approved once', function (string $entityClass, ParticipantStatus $status) {
        $setup = agreementLockEntity($entityClass);
        agreementLockAddOwnerRow($setup);
        $entity = $setup['entity'];

        ['participant' => $row] = agreementLockAddMember($setup);
        $row->update(['status' => $status->value]);

        expect($row->fresh()->status)->toBe($status) // precondition
            ->and($entity->isAgreementLocked())->toBeTrue();
    })->with($agreementLockEntities)->with([
        'removed by the host' => ParticipantStatus::Removed,
        'benched by a capacity demotion' => ParticipantStatus::Benched,
    ]);

    it('reports the EARLIER of two non-owner approvals, whatever order they arrived in', function (string $entityClass) {
        $setup = agreementLockEntity($entityClass);
        agreementLockAddOwnerRow($setup);
        $entity = $setup['entity'];

        $earlier = now()->startOfSecond()->subDays(10);
        $later = now()->startOfSecond()->subDays(3);

        // The later approval is written FIRST, so this refutes both a
        // "first row written wins" and a "latest approval wins" reading.
        agreementLockAddMember($setup, ['approved_at' => $later]);
        agreementLockAddMember($setup, ['approved_at' => $earlier]);

        expect($entity->agreementLockedAt()?->equalTo($earlier))->toBeTrue()
            ->and($entity->isAgreementLocked())->toBeTrue();
    })->with($agreementLockEntities);

    it('is not locked by a member who was never approved', function (string $entityClass, ParticipantStatus $status, array $extra) {
        $setup = agreementLockEntity($entityClass);
        agreementLockAddOwnerRow($setup);
        $entity = $setup['entity'];

        ['participant' => $row] = agreementLockAddMember($setup, ['status' => $status->value, ...$extra]);
        expect($row->fresh()->approved_at)->toBeNull(); // precondition: never stamped

        expect($entity->agreementLockedAt())->toBeNull()
            ->and($entity->isAgreementLocked())->toBeFalse();
    })->with($agreementLockEntities)->with([
        'pending (invited)' => [ParticipantStatus::Pending, []],
        'waitlisted' => [ParticipantStatus::Waitlisted, ['waitlisted_at' => now()]],
        'benched' => [ParticipantStatus::Benched, ['benched_at' => now()]],
    ]);

    it('flips to locked the moment a participant is approved — read live on the already-loaded model', function (string $entityClass) {
        $setup = agreementLockEntity($entityClass);
        agreementLockAddOwnerRow($setup);
        $entity = $setup['entity'];

        ['participant' => $row] = agreementLockAddMember($setup, ['status' => ParticipantStatus::Pending->value]);
        $attributesBefore = $entity->getAttributes();

        expect($entity->isAgreementLocked())->toBeFalse();

        // The approval write (the observer stamps approved_at). No fresh() on
        // the entity afterwards: the SAME instance must see the lock.
        $row->update(['status' => ParticipantStatus::Approved->value]);

        expect($entity->isAgreementLocked())->toBeTrue()
            ->and($entity->getAttributes())->toBe($attributesBefore); // nothing cached or stored on the model
    })->with($agreementLockEntities);

    it('does not count an Approved non-owner row that was never stamped', function (string $entityClass) {
        $setup = agreementLockEntity($entityClass);
        agreementLockAddOwnerRow($setup);
        $entity = $setup['entity'];

        $rawId = agreementLockInsertUnstampedApprovedRow($setup);
        $rawRow = agreementLockRawTable($entity)->where('id', $rawId)->first();

        // Precondition: an Approved non-owner row with no stamp...
        expect($rawRow->status)->toBe(ParticipantStatus::Approved->value)
            ->and($rawRow->user_id)->not->toBe($entity->owner_id)
            ->and($rawRow->approved_at)->toBeNull();

        // ...so the predicate has nothing to read. It keys on approved_at
        // alone — which is why the stamping + legacy backfill (S01-T01) are
        // load-bearing for this guard.
        expect($entity->agreementLockedAt())->toBeNull()
            ->and($entity->isAgreementLocked())->toBeFalse();

        // Control: stamp that very row and the lock engages — the negative
        // above is about the missing stamp, not the row being invisible.
        agreementLockRawTable($entity)->where('id', $rawId)->update(['approved_at' => now()]);

        expect($entity->isAgreementLocked())->toBeTrue();
    })->with($agreementLockEntities);

    it('only counts this entity\'s own participants', function (string $entityClass) {
        $setup = agreementLockEntity($entityClass);
        agreementLockAddOwnerRow($setup);
        $entity = $setup['entity'];

        // A confirmed member of ANOTHER entity — owned by the same user, so
        // what keeps $entity unlocked is entity scoping, not the owner exclusion.
        $elsewhere = agreementLockEntity($entityClass, $setup['owner']);
        agreementLockAddOwnerRow($elsewhere);
        agreementLockAddMember($elsewhere);

        expect($elsewhere['entity']->isAgreementLocked())->toBeTrue() // control
            ->and($entity->agreementLockedAt())->toBeNull()
            ->and($entity->isAgreementLocked())->toBeFalse();
    })->with($agreementLockEntities);

    it('issues exactly one participant query per call — no relation loading, no N+1', function (string $entityClass) {
        $setup = agreementLockEntity($entityClass);
        agreementLockAddOwnerRow($setup);
        agreementLockAddMember($setup);
        $entity = $setup['entity'];

        DB::flushQueryLog();
        DB::enableQueryLog();

        $lockedAt = $entity->agreementLockedAt();
        $isLocked = $entity->isAgreementLocked();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // The slice contract: a single query over the entity's own participant
        // relation. The caller owns not turning that into an N+1 across a list.
        $table = $entity instanceof Game ? 'game_participants' : 'campaign_participants';

        expect($lockedAt)->toBeInstanceOf(Carbon::class)
            ->and($isLocked)->toBeTrue()
            ->and($queries)->toHaveCount(2) // one per call
            ->and($queries[0]['query'])->toContain($table)
            ->and($queries[1]['query'])->toContain($table);
    })->with($agreementLockEntities);
});
