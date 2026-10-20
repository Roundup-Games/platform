<?php

use App\Dto\BadgeAwardResult;
use App\Enums\BadgeEventAction;
use App\Enums\BadgeSource;
use App\Models\BadgeDefinition;
use App\Models\BadgeEvent;
use App\Models\BadgeUser;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\Badges\BadgeService;
use App\Services\Badges\Events\BadgeAwarded;
use App\Services\Badges\Events\BadgeRevoked;
use App\Services\Badges\Events\BadgeUpgraded;
use Illuminate\Support\Facades\Event;

/**
 * BadgeService engine tests (M069 / D186, spec §2.2): idempotent award,
 * in-place tier upgrade, reversible revocation — every state change with
 * exactly one badge_events audit row and one after-commit domain event.
 *
 * Integration-shaped on purpose: beforeEach runs the real `badges:sync`
 * against the real badge_definitions table, so registry keys ('debut',
 * 'campaign_compleat') resolve exactly as production would. Tiered
 * definitions come from the factory — the registry has held no
 * dormant definitions since campaign_compleat activated in M071/S05,
 * so dormant fixtures are manufactured through the factory's
 * dormant() state.
 */
beforeEach(function () {
    $this->artisan('badges:sync')->assertSuccessful();
});

it('awards idempotently: the second award is a no-op with exactly one audit row', function () {
    // The slice demo equivalence, proven as a test.
    Event::fake([BadgeAwarded::class, BadgeUpgraded::class, BadgeRevoked::class]);

    $user = User::factory()->create();
    $evidence = GameSystem::factory()->create();
    $service = app(BadgeService::class);

    $first = $service->award($user, 'debut', evidence: $evidence);
    $second = $service->award($user, 'debut', evidence: $evidence);

    expect($first)->toBe(BadgeAwardResult::Awarded)
        ->and($second)->toBe(BadgeAwardResult::AlreadyHeld)
        // One shelf row, one audit row — never two of either.
        ->and(BadgeUser::query()->count())->toBe(1)
        ->and(BadgeEvent::query()->count())->toBe(1);

    $audit = BadgeEvent::query()->first();

    expect($audit->action)->toBe(BadgeEventAction::Awarded)
        ->and($audit->source)->toBe(BadgeSource::Automatic)
        // The evidence pointer: morph alias from the D170 map + key.
        ->and($audit->evidence_type)->toBe('game_system')
        ->and($audit->evidence_id)->toBe($evidence->getKey());

    $hold = BadgeUser::query()->first();

    expect($hold->tier)->toBeNull()
        ->and($hold->revoked_at)->toBeNull()
        ->and($hold->evidence_type)->toBe('game_system')
        ->and($hold->evidence_id)->toBe($evidence->getKey());

    // The no-op dispatched nothing; the award dispatched exactly once.
    Event::assertDispatchedTimes(BadgeAwarded::class, 1);
    Event::assertNotDispatched(BadgeUpgraded::class);
});

it('upgrades a held tier in place and appends an upgraded audit event', function () {
    Event::fake([BadgeAwarded::class, BadgeUpgraded::class, BadgeRevoked::class]);

    $definition = BadgeDefinition::factory()->create([
        'key' => 'test_tiered_badge',
        'tiers' => ['bronze' => 1, 'silver' => 10, 'gold' => 50],
    ]);

    $user = User::factory()->create();
    $service = app(BadgeService::class);

    $bronze = $service->award($user, 'test_tiered_badge', 'bronze');
    $silver = $service->award($user, 'test_tiered_badge', 'silver');

    expect($bronze)->toBe(BadgeAwardResult::Awarded)
        ->and($silver)->toBe(BadgeAwardResult::Upgraded)
        // Upgrade UPDATEs the single row — never a second shelf entry.
        ->and(BadgeUser::query()->count())->toBe(1)
        ->and(BadgeUser::query()->first()->tier)->toBe('silver');

    $actions = BadgeEvent::query()
        ->orderBy('created_at')
        ->get()
        ->map(fn (BadgeEvent $event) => $event->action)
        ->all();

    expect($actions)->toBe([BadgeEventAction::Awarded, BadgeEventAction::Upgraded]);

    Event::assertDispatchedTimes(BadgeAwarded::class, 1);
    Event::assertDispatchedTimes(BadgeUpgraded::class, 1);
    Event::assertNotDispatched(BadgeRevoked::class);
});

it('treats a same-or-lower tier re-award as already held with no new audit row', function () {
    Event::fake([BadgeAwarded::class, BadgeUpgraded::class, BadgeRevoked::class]);

    BadgeDefinition::factory()->create([
        'key' => 'test_tiered_badge',
        'tiers' => ['bronze' => 1, 'silver' => 10, 'gold' => 50],
    ]);

    $user = User::factory()->create();
    $service = app(BadgeService::class);

    $service->award($user, 'test_tiered_badge', 'silver');

    $same = $service->award($user, 'test_tiered_badge', 'silver');
    $lower = $service->award($user, 'test_tiered_badge', 'bronze');
    $untiered = $service->award($user, 'test_tiered_badge');

    expect($same)->toBe(BadgeAwardResult::AlreadyHeld)
        ->and($lower)->toBe(BadgeAwardResult::AlreadyHeld)
        // Null = untiered = below every declared tier.
        ->and($untiered)->toBe(BadgeAwardResult::AlreadyHeld)
        // Nothing was written: still one row, one audit entry, tier unchanged.
        ->and(BadgeUser::query()->count())->toBe(1)
        ->and(BadgeUser::query()->first()->tier)->toBe('silver')
        ->and(BadgeEvent::query()->count())->toBe(1);

    Event::assertDispatchedTimes(BadgeAwarded::class, 1);
    Event::assertNotDispatched(BadgeUpgraded::class);
});

it('revokes with a reason and re-awards reversibly while first_awarded_at survives', function () {
    Event::fake([BadgeAwarded::class, BadgeUpgraded::class, BadgeRevoked::class]);

    $user = User::factory()->create();
    $admin = User::factory()->create();
    $service = app(BadgeService::class);

    $service->award($user, 'debut');

    $originalFirstAwardedAt = BadgeUser::query()->first()->first_awarded_at;

    expect($service->revoke($user, 'debut', 'Issued in error (test fixture)', $admin))->toBeTrue();

    $revokedHold = BadgeUser::query()->first();

    expect($revokedHold->revoked_at)->not->toBeNull()
        ->and($revokedHold->revoked_reason)->toBe('Issued in error (test fixture)');

    $revocationAudit = BadgeEvent::query()
        ->where('action', BadgeEventAction::Revoked)
        ->first();

    expect($revocationAudit)->not->toBeNull()
        ->and($revocationAudit->actor_id)->toBe($admin->getKey())
        ->and($revocationAudit->reason)->toBe('Issued in error (test fixture)')
        ->and($revocationAudit->source)->toBe(BadgeSource::Manual);

    // Revocation is reversible; milestones are never re-taken.
    $reAward = $service->award($user, 'debut');

    $renewedHold = BadgeUser::query()->first();

    expect($reAward)->toBe(BadgeAwardResult::Awarded)
        ->and($renewedHold->revoked_at)->toBeNull()
        ->and($renewedHold->revoked_reason)->toBeNull()
        ->and($renewedHold->first_awarded_at->equalTo($originalFirstAwardedAt))->toBeTrue();

    $actions = BadgeEvent::query()
        ->orderBy('created_at')
        ->get()
        ->map(fn (BadgeEvent $event) => $event->action)
        ->all();

    expect($actions)->toBe([
        BadgeEventAction::Awarded,
        BadgeEventAction::Revoked,
        BadgeEventAction::Awarded,
    ]);

    Event::assertDispatchedTimes(BadgeAwarded::class, 2);
    Event::assertDispatchedTimes(BadgeRevoked::class, 1);
});

it('returns false when revoking a badge the user does not hold', function () {
    Event::fake([BadgeRevoked::class]);

    $user = User::factory()->create();
    $admin = User::factory()->create();
    $service = app(BadgeService::class);

    expect($service->revoke($user, 'debut', 'Not held (test fixture)', $admin))->toBeFalse()
        ->and(BadgeUser::query()->count())->toBe(0)
        ->and(BadgeEvent::query()->count())->toBe(0);

    Event::assertNotDispatched(BadgeRevoked::class);
});

it('lapses quietly: stamps lapsed_at with exactly one audit row and no domain event', function () {
    Event::fake([BadgeAwarded::class, BadgeUpgraded::class, BadgeRevoked::class]);

    $user = User::factory()->create();
    $service = app(BadgeService::class);

    $service->award($user, 'debut');

    expect($service->lapse($user, 'debut', 'membership_ended'))->toBeTrue();

    $hold = BadgeUser::query()->first();

    // Lapse stamps only lapsed_at: revocation stamps and the milestone survive.
    expect($hold->lapsed_at)->not->toBeNull()
        ->and($hold->revoked_at)->toBeNull()
        ->and($hold->revoked_reason)->toBeNull();

    $audit = BadgeEvent::query()->where('action', BadgeEventAction::Lapsed)->first();

    expect($audit)->not->toBeNull()
        ->and($audit->source)->toBe(BadgeSource::Automatic)
        ->and($audit->actor_id)->toBeNull()
        ->and($audit->reason)->toBe('membership_ended')
        // Exactly Awarded + Lapsed — nothing else was written.
        ->and(BadgeEvent::query()->count())->toBe(2);

    // Spec §4: lapse is quiet — the original award stays the only
    // dispatched moment; no BadgeRevoked, no new BadgeAwarded.
    Event::assertDispatchedTimes(BadgeAwarded::class, 1);
    Event::assertNotDispatched(BadgeUpgraded::class);
    Event::assertNotDispatched(BadgeRevoked::class);
});

it('treats a double lapse as a no-op with no second audit row', function () {
    $user = User::factory()->create();
    $service = app(BadgeService::class);

    $service->award($user, 'debut');

    expect($service->lapse($user, 'debut', 'membership_ended'))->toBeTrue()
        ->and($service->lapse($user, 'debut', 'sweep_re-ran'))->toBeFalse()
        ->and(BadgeUser::query()->first()->lapsed_at)->not->toBeNull()
        ->and(BadgeEvent::query()->where('action', BadgeEventAction::Lapsed)->count())->toBe(1);
});

it('returns false when lapsing a badge the user does not hold', function () {
    $user = User::factory()->create();
    $service = app(BadgeService::class);

    expect($service->lapse($user, 'debut', 'membership_ended'))->toBeFalse()
        ->and(BadgeUser::query()->count())->toBe(0)
        ->and(BadgeEvent::query()->count())->toBe(0);
});

it('skips lapse of an admin-revoked row: revocation supersedes lapse', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $service = app(BadgeService::class);

    $service->award($user, 'debut');
    $service->revoke($user, 'debut', 'Issued in error (test fixture)', $admin);

    expect($service->lapse($user, 'debut', 'membership_ended'))->toBeFalse();

    $hold = BadgeUser::query()->first();

    expect($hold->lapsed_at)->toBeNull()
        ->and($hold->revoked_at)->not->toBeNull()
        // Awarded + Revoked only — the lapse wrote nothing.
        ->and(BadgeEvent::query()->count())->toBe(2)
        ->and(BadgeEvent::query()->where('action', BadgeEventAction::Lapsed)->count())->toBe(0);
});

it('re-qualifies a lapsed badge on re-award: clears lapsed_at, keeps first_awarded_at, dispatches BadgeAwarded', function () {
    Event::fake([BadgeAwarded::class, BadgeUpgraded::class, BadgeRevoked::class]);

    $user = User::factory()->create();
    $service = app(BadgeService::class);

    $service->award($user, 'debut');

    $originalFirstAwardedAt = BadgeUser::query()->first()->first_awarded_at;

    // Distinct created_at per mutation: badge_events timestamps are
    // second-precision, so ties would make the sequence below ambiguous.
    $this->travel(1)->seconds();
    $service->lapse($user, 'debut', 'membership_ended');

    $this->travel(1)->seconds();
    $reAward = $service->award($user, 'debut');

    $renewedHold = BadgeUser::query()->first();

    expect($reAward)->toBe(BadgeAwardResult::Awarded)
        ->and($renewedHold->lapsed_at)->toBeNull()
        ->and($renewedHold->revoked_at)->toBeNull()
        // Milestones are never re-taken.
        ->and($renewedHold->first_awarded_at->equalTo($originalFirstAwardedAt))->toBeTrue();

    $actions = BadgeEvent::query()
        ->orderBy('created_at')
        ->get()
        ->map(fn (BadgeEvent $event) => $event->action)
        ->all();

    expect($actions)->toBe([
        BadgeEventAction::Awarded,
        BadgeEventAction::Lapsed,
        BadgeEventAction::Requalified,
    ]);

    // Requalification is an award moment: the pipeline keys on it.
    Event::assertDispatchedTimes(BadgeAwarded::class, 2);
    Event::assertNotDispatched(BadgeUpgraded::class);
    Event::assertNotDispatched(BadgeRevoked::class);
});

it('re-qualifies a lapsed tiered badge directly at the new tier', function () {
    BadgeDefinition::factory()->create([
        'key' => 'test_tiered_badge',
        'tiers' => ['bronze' => 1, 'silver' => 10, 'gold' => 50],
    ]);

    $user = User::factory()->create();
    $service = app(BadgeService::class);

    $service->award($user, 'test_tiered_badge', 'bronze');

    $this->travel(1)->seconds();
    $service->lapse($user, 'test_tiered_badge', 'streak_broken');

    // Re-award at a higher tier: the requalification branch lands the
    // tier directly — sweep tier math flows through award(), never
    // hand-ranked past a lapse.
    $this->travel(1)->seconds();
    $reAward = $service->award($user, 'test_tiered_badge', 'silver');

    $hold = BadgeUser::query()->first();

    expect($reAward)->toBe(BadgeAwardResult::Awarded)
        ->and($hold->tier)->toBe('silver')
        ->and($hold->lapsed_at)->toBeNull()
        ->and(BadgeUser::query()->count())->toBe(1);

    $actions = BadgeEvent::query()
        ->orderBy('created_at')
        ->get()
        ->map(fn (BadgeEvent $event) => $event->action)
        ->all();

    expect($actions)->toBe([
        BadgeEventAction::Awarded,
        BadgeEventAction::Lapsed,
        BadgeEventAction::Requalified,
    ]);
});

it('refuses new awards for inactive and dormant definitions without writing', function () {
    Event::fake([BadgeAwarded::class, BadgeUpgraded::class, BadgeRevoked::class]);

    BadgeDefinition::factory()->inactive()->create(['key' => 'retired_badge']);

    $user = User::factory()->create();
    $service = app(BadgeService::class);

    $inactive = $service->award($user, 'retired_badge');
    // Manufactured dormant fixture: campaign_compleat went live in
    // M071/S05, so the registry no longer holds a dormant definition —
    // the dormancy machinery is pinned through a factory-made row.
    BadgeDefinition::factory()->dormant()->create([
        'key' => 'hibernating_badge',
        'tiers' => ['bronze' => 1],
    ]);
    $dormant = $service->award($user, 'hibernating_badge', 'bronze');

    expect($inactive)->toBe(BadgeAwardResult::RefusedInactive)
        ->and($dormant)->toBe(BadgeAwardResult::RefusedDormant)
        ->and(BadgeUser::query()->count())->toBe(0)
        ->and(BadgeEvent::query()->count())->toBe(0);

    Event::assertNotDispatched(BadgeAwarded::class);
});

it('requires a reason for manual awards and revocations', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $service = app(BadgeService::class);

    expect(fn () => $service->award($user, 'debut', source: BadgeSource::Manual))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->award($user, 'debut', source: BadgeSource::Manual, actor: $admin, reason: ''))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->revoke($user, 'debut', ''))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->lapse($user, 'debut', ''))
        ->toThrow(InvalidArgumentException::class)
        // Nothing written by any refused mutation.
        ->and(BadgeUser::query()->count())->toBe(0)
        ->and(BadgeEvent::query()->count())->toBe(0);
});

it('records actor and reason on a manual award with a reason', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $service = app(BadgeService::class);

    $result = $service->award(
        $user,
        'debut',
        source: BadgeSource::Manual,
        actor: $admin,
        reason: 'Community recognition (test fixture)',
    );

    $audit = BadgeEvent::query()->first();

    expect($result)->toBe(BadgeAwardResult::Awarded)
        ->and($audit->action)->toBe(BadgeEventAction::Awarded)
        ->and($audit->source)->toBe(BadgeSource::Manual)
        ->and($audit->actor_id)->toBe($admin->getKey())
        ->and($audit->reason)->toBe('Community recognition (test fixture)')
        ->and(BadgeUser::query()->first()->source)->toBe(BadgeSource::Manual);
});

it('rejects unknown badge keys and invalid tiers as programming errors', function () {
    $user = User::factory()->create();
    $service = app(BadgeService::class);

    BadgeDefinition::factory()->create([
        'key' => 'test_tiered_badge',
        'tiers' => ['bronze' => 1, 'silver' => 10, 'gold' => 50],
    ]);

    // Unknown key: registry not synced is a programming error, not a refusal.
    expect(fn () => $service->award($user, 'nonexistent_badge'))
        ->toThrow(InvalidArgumentException::class)
        // Unknown tier on a tiered definition.
        ->and(fn () => $service->award($user, 'test_tiered_badge', 'platinum'))
        ->toThrow(InvalidArgumentException::class)
        // Any tier on an untiered definition.
        ->and(fn () => $service->award($user, 'debut', 'bronze'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->revoke($user, 'nonexistent_badge', 'Unknown key (test fixture)'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->lapse($user, 'nonexistent_badge', 'Unknown key (test fixture)'))
        ->toThrow(InvalidArgumentException::class)
        ->and(BadgeUser::query()->count())->toBe(0)
        ->and(BadgeEvent::query()->count())->toBe(0);
});
