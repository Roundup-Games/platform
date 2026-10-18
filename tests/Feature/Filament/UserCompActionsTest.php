<?php

use App\Enums\LocalSubscriptionSource;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\BadgeUser;
use App\Models\MembershipType;
use App\Models\User;
use App\Services\GmRoleService;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

/**
 * Comp membership record actions on UserResource (R083, spec §2.5):
 * grantCompMembership / withdrawCompMembership drive the same
 * CompMembershipService path as the service suite — a flagged local
 * subscription carrying full GM benefits and the Supporting Member
 * badge (queued BadgePipeline runs inline: QUEUE_CONNECTION=sync in
 * phpunit.xml). These tests pin the admin surface wiring: the period
 * select, the required reason, and the visibility of withdraw on an
 * active comp.
 */

/**
 * Seed/fetch the GM plan (unique prefix: Pest compiles every test file
 * into one process — a redeclared helper name is fatal).
 */
function userCompActionsGmPlan(): MembershipType
{
    return MembershipType::updateOrCreate(
        ['name' => 'Game Master'],
        [
            'description' => 'Test GM plan (UserResource comp action fixtures)',
            'price_cents' => 0,
            'duration_months' => 0,
            'status' => 'active',
            'type' => 'local',
            'paddle_price_id' => null,
            'metadata' => ['gm_plan' => true, 'features' => ['GM workspace']],
        ],
    );
}

beforeEach(function () {
    seedRoles();

    setPermissionsTeamId(null);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('Platform Admin');
    $this->platformAdmin->unsetRelations();

    $this->member = User::factory()->create();

    Filament::setCurrentPanel('admin');

    $this->artisan('badges:sync')->assertSuccessful();

    Role::firstOrCreate([
        'name' => 'Game Master',
        'guard_name' => 'web',
        'team_id' => null,
    ]);

    userCompActionsGmPlan();
});

it('grants a 12-month comp through the record action with full GM benefits and the badge', function () {
    actingAs($this->platformAdmin);

    Livewire\Livewire::test(ListUsers::class)
        ->callTableAction('grantCompMembership', $this->member, [
            'period' => '12',
            'reason' => 'Community recognition comp',
        ])
        ->assertHasNoTableActionErrors();

    $subscription = $this->member->localSubscriptions()->firstOrFail();

    expect($subscription->source)->toBe(LocalSubscriptionSource::Comp)
        ->and($subscription->status)->toBe('active')
        ->and($subscription->granted_by)->toBe($this->platformAdmin->id)
        ->and($subscription->reason)->toBe('Community recognition comp')
        ->and($subscription->ends_at)->not->toBeNull()
        ->and(now()->diffInMonths($subscription->ends_at))->toBeGreaterThanOrEqual(11)
        ->and(app(GmRoleService::class)->isGmActive($this->member))->toBeTrue();

    // The Supporting Member badge rode the S02 pipeline — automatic
    // award with the comp row as evidence.
    $hold = BadgeUser::query()
        ->whereBelongsTo($this->member)
        ->whereHas('badgeDefinition', fn ($query) => $query->where('key', 'supporting_member'))
        ->firstOrFail();

    expect($hold->evidence_type)->toBe('local_subscription')
        ->and($hold->evidence_id)->toBe($subscription->id);
});

it('refuses a comp grant without a reason', function () {
    actingAs($this->platformAdmin);

    Livewire\Livewire::test(ListUsers::class)
        ->callTableAction('grantCompMembership', $this->member, [
            'period' => 'permanent',
        ])
        ->assertHasTableActionErrors(['reason']);

    expect($this->member->localSubscriptions()->count())->toBe(0);
});

it('withdraws the comp through the record action and downgrades benefits (single-membership case)', function () {
    actingAs($this->platformAdmin);

    Livewire\Livewire::test(ListUsers::class)
        ->callTableAction('grantCompMembership', $this->member, [
            'period' => 'permanent',
            'reason' => 'Community recognition comp',
        ])
        ->assertHasNoTableActionErrors();

    // A fresh table instance sees the committed comp — withdraw is
    // visible only while an active comp exists.
    Livewire\Livewire::test(ListUsers::class)
        ->callTableAction('withdrawCompMembership', $this->member->fresh(), [
            'reason' => 'Conduct issue',
        ])
        ->assertHasNoTableActionErrors();

    expect($this->member->localSubscriptions()->first()->status)->toBe('canceled')
        ->and(app(GmRoleService::class)->isGmActive($this->member))->toBeFalse();
});
