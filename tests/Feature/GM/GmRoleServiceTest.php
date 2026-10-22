<?php

namespace Tests\Feature\GM;

use App\Enums\LocalSubscriptionSource;
use App\Models\GMProfile;
use App\Models\LocalSubscription;
use App\Models\MembershipType;
use App\Models\User;
use App\Services\GmRoleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Paddle\Cashier;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\CreatesUsers;

class GmRoleServiceTest extends TestCase
{
    use CreatesUsers;
    use DatabaseTransactions;

    private GmRoleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(GmRoleService::class);

        Role::firstOrCreate([
            'name' => 'Game Master',
            'guard_name' => 'web',
            'team_id' => null,
        ]);
    }

    // ── Helpers ────────────────────────────────────────

    // ── Helpers ────────────────────────────────────────

    private function seedGmPlan(): void
    {
        MembershipType::updateOrCreate(
            ['name' => 'Game Master'],
            [
                'description' => 'GM tools plan',
                'price_cents' => 0,
                'duration_months' => 0,
                'status' => 'active',
                'type' => 'local',
                'paddle_price_id' => null,
                'metadata' => ['gm_plan' => true],
            ],
        );
    }

    // ── Subscription Gate ──────────────────────────────

    public function test_non_subscriber_blocked_from_gm_role(): void
    {
        $user = User::factory()->create();

        $result = $this->service->assignGMRole($user);

        $this->assertFalse($result);
        $this->assertFalse($user->hasRole('Game Master'));
        $this->assertNull($user->gmProfile);
    }

    public function test_subscriber_can_receive_gm_role(): void
    {
        $user = $this->createSubscribedUser();

        $result = $this->service->assignGMRole($user);

        $this->assertTrue($result);
        $this->assertTrue($user->fresh()->hasRole('Game Master'));

        // Verify GM profile was created with correct attributes
        $profile = $user->fresh()->gmProfile;
        $this->assertNotNull($profile);
        $this->assertTrue($profile->is_active);
        $this->assertEquals($user->id, $profile->user_id);
    }

    // ── Logging Events ─────────────────────────────────

    #[Group('smoke')]
    public function test_revoke_gm_role_removes_role_and_deactivates_profile(): void
    {
        // (The previous name claimed to verify logging, but the body tests
        // role revocation + profile deactivation. Logging is not asserted
        // anywhere — renamed to match the actual coverage, which is valuable.)
        $user = $this->createSubscribedUser();
        $this->service->assignGMRole($user);

        // Verify the revoke path runs without error — logging is verified
        // separately in tests/Feature/Services/GmRoleServiceTest.php
        $this->service->revokeGMRole($user);

        $this->assertFalse($user->fresh()->hasRole('Game Master'));
        $this->assertFalse($user->gmProfile->fresh()->is_active);
    }

    public function test_handle_subscription_lapse_removes_role_and_deactivates_profile(): void
    {
        // (The previous name claimed to verify start/complete logging, but the
        // body tests role revocation + profile deactivation. Renamed to match.)
        $user = $this->createSubscribedUser();
        $this->service->assignGMRole($user);

        // Raw lapse mechanics (the guarded seam would skip: this user
        // still holds the active Cashier sub the fixture created). The
        // last-active-membership guard itself is pinned in
        // tests/Feature/Billing.
        $this->service->lapseGmBenefits($user);

        $this->assertFalse($user->fresh()->hasRole('Game Master'));
        $this->assertFalse($user->gmProfile->fresh()->is_active);
        $this->assertNotNull(GMProfile::where('user_id', $user->id)->first());
    }

    // ── can_create_public_entries Flag ─────────────────

    public function test_assign_gm_role_grants_can_create_public_entries(): void
    {
        $user = $this->createSubscribedUser();
        $this->assertFalse((bool) $user->can_create_public_entries);

        $this->service->assignGMRole($user);

        $this->assertTrue($user->fresh()->can_create_public_entries);
    }

    #[Group('smoke')]
    public function test_activate_gm_subscription_grants_can_create_public_entries(): void
    {
        $this->seedGmPlan();
        $user = $this->createSubscribedUser();
        $this->assertFalse((bool) $user->can_create_public_entries);

        $result = $this->service->activateGmSubscription($user);
        $this->assertTrue($result);

        $this->assertTrue($user->fresh()->can_create_public_entries);
    }

    public function test_activate_gm_subscription_never_revives_a_comp_row(): void
    {
        $this->seedGmPlan();
        $user = $this->createSubscribedUser();
        $grantedBy = $this->createSubscribedUser();

        // An expired fixed-period comp: the admin grant ended, the row
        // keeps its provenance (source, granted_by, reason, ends_at).
        $gmPlan = MembershipType::query()->where('name', 'Game Master')->firstOrFail();
        $comp = LocalSubscription::factory()->create([
            'user_id' => $user->id,
            'membership_type_id' => $gmPlan->id,
            'source' => LocalSubscriptionSource::Comp,
            'status' => 'expired',
            'starts_at' => now()->subYear(),
            'ends_at' => now()->subDay(),
            'granted_by' => $grantedBy->id,
            'reason' => 'Community program',
        ]);

        $this->service->activateGmSubscription($user);

        // The self-serve reactivation created its OWN row; the comp row
        // stays untouched (regression: updateOrCreate on the plan alone
        // used to flip the comp row to active with ends_at null while
        // keeping source=comp — provenance that lies).
        $freshComp = $comp->fresh();
        $this->assertSame('expired', $freshComp->status);
        $this->assertSame(LocalSubscriptionSource::Comp, $freshComp->source);
        $this->assertNotNull($freshComp->ends_at);

        $selfServe = $user->localSubscriptions()
            ->where('source', LocalSubscriptionSource::SelfServe)
            ->where('status', 'active')
            ->first();
        $this->assertNotNull($selfServe);
        $this->assertNotSame($comp->id, $selfServe->id);
        $this->assertTrue($user->fresh()->can_create_public_entries);
    }

    public function test_activate_gm_subscription_converges_when_a_concurrent_activation_wins_the_insert(): void
    {
        $this->seedGmPlan();
        $user = $this->createSubscribedUser();
        $gmPlan = MembershipType::query()->where('name', 'Game Master')->firstOrFail();

        // Simulate the concurrent winner: a second activation commits
        // between this one's lookup miss and its INSERT. A one-shot
        // creating hook inserts the winner through the query builder (no
        // model events, no recursion); this activation's INSERT then
        // collides with the REAL partial unique index — no synthetic
        // exception. Before the converge fix this escaped as a 500.
        $userId = $user->id;
        $planId = $gmPlan->id;
        $armed = true;
        LocalSubscription::creating(function (LocalSubscription $subscription) use ($userId, $planId, &$armed): void {
            // Returns void on purpose: model events dispatch with until()
            // semantics — any non-null return would halt the listener
            // chain and skip the model's own UUID-assigning hook.
            if (! $armed
                || $subscription->user_id !== $userId
                || $subscription->membership_type_id !== $planId
                || $subscription->source !== LocalSubscriptionSource::SelfServe) {
                return;
            }

            $armed = false;

            DB::table('local_subscriptions')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'membership_type_id' => $planId,
                'source' => LocalSubscriptionSource::SelfServe->value,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => null,
                'canceled_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $result = $this->service->activateGmSubscription($user);

        $this->assertTrue($result);

        // Converged on a single active self-serve row — benefits intact.
        $this->assertSame(
            1,
            $user->localSubscriptions()
                ->where('source', LocalSubscriptionSource::SelfServe)
                ->where('status', 'active')
                ->count(),
        );
        $this->assertTrue($user->fresh()->can_create_public_entries);
        $this->assertTrue($user->fresh()->hasRole('Game Master'));
    }

    public function test_revoke_gm_role_revokes_can_create_public_entries(): void
    {
        $user = $this->createSubscribedUser();
        $this->service->assignGMRole($user);
        $this->assertTrue($user->fresh()->can_create_public_entries);

        $this->service->revokeGMRole($user);

        $this->assertFalse($user->fresh()->can_create_public_entries);
    }

    public function test_deactivate_gm_subscription_revokes_can_create_public_entries(): void
    {
        $this->seedGmPlan();
        $user = User::factory()->create();

        $result = $this->service->activateGmSubscription($user);
        $this->assertTrue($result);
        $this->assertTrue($user->fresh()->can_create_public_entries);

        $this->service->deactivateGmSubscription($user);

        $this->assertFalse($user->fresh()->can_create_public_entries);
    }

    public function test_deactivate_keeps_benefits_while_a_paddle_membership_remains(): void
    {
        // Benefits follow the last active membership (the withdraw
        // semantics): a user paying through Paddle who also cancels
        // their local self-serve GM row keeps the role and public-entry
        // rights — membership never lapsed.
        $this->seedGmPlan();
        $user = $this->createSubscribedUser();

        $this->service->activateGmSubscription($user);
        $this->assertTrue($user->fresh()->can_create_public_entries);

        $this->assertTrue($this->service->deactivateGmSubscription($user));

        $this->assertTrue($user->fresh()->can_create_public_entries);
        $this->assertTrue($user->fresh()->isGM());
    }

    public function test_subscription_lapse_revokes_can_create_public_entries(): void
    {
        $user = $this->createSubscribedUser();
        $this->service->assignGMRole($user);
        $this->assertTrue($user->fresh()->can_create_public_entries);

        $this->service->lapseGmBenefits($user);

        $this->assertFalse($user->fresh()->can_create_public_entries);
    }

    public function test_resubscribe_regrants_can_create_public_entries(): void
    {
        $user = $this->createSubscribedUser();
        $this->service->assignGMRole($user);
        $this->service->lapseGmBenefits($user);
        $this->assertFalse($user->fresh()->can_create_public_entries);

        // Resubscribe
        Cashier::$subscriptionModel::create([
            'billable_type' => get_class($user),
            'billable_id' => $user->id,
            'type' => 'default',
            'paddle_id' => 'sub_resub_'.Str::random(12),
            'status' => 'active',
            'trial_ends_at' => null,
            'paused_at' => null,
            'ends_at' => null,
        ]);

        $this->service->assignGMRole($user->fresh());
        $this->assertTrue($user->fresh()->can_create_public_entries);
    }

    // ── Role Assignment Transaction Safety ─────────────

    public function test_assign_is_idempotent(): void
    {
        $user = $this->createSubscribedUser();

        $this->service->assignGMRole($user);
        $result = $this->service->assignGMRole($user);

        $this->assertTrue($result);
        $this->assertEquals(1, GMProfile::where('user_id', $user->id)->count());
    }

    public function test_assign_reactivates_existing_inactive_profile(): void
    {
        $user = $this->createSubscribedUser();
        $profile = GMProfile::factory()->create([
            'user_id' => $user->id,
            'is_active' => false,
        ]);

        $this->service->assignGMRole($user);

        $this->assertTrue($profile->fresh()->is_active);
        $this->assertEquals(1, GMProfile::where('user_id', $user->id)->count());
    }

    // ── isGmActive ────────────────────────────────────

    public function test_is_gm_active_with_role_and_subscription(): void
    {
        $user = $this->createSubscribedUser();
        $this->service->assignGMRole($user);

        $this->assertTrue($this->service->isGmActive($user));
    }

    public function test_is_gm_active_false_without_role(): void
    {
        $user = $this->createSubscribedUser();

        $this->assertFalse($this->service->isGmActive($user));
    }

    public function test_is_gm_active_false_without_subscription(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Game Master');

        $this->assertFalse($this->service->isGmActive($user));
    }

    public function test_is_gm_active_false_after_subscription_ends(): void
    {
        $user = $this->createSubscribedUser();
        $this->service->assignGMRole($user);
        $this->assertTrue($this->service->isGmActive($user));

        // Simulate subscription ending
        Cashier::$subscriptionModel::where('billable_id', $user->id)->delete();

        $this->assertFalse($this->service->isGmActive($user->fresh()));
    }

    // ── Full Lifecycle Integration ─────────────────────

    public function test_complete_subscribe_lapse_resubscribe_lifecycle(): void
    {
        // Phase 1: Subscribe and become GM
        $user = $this->createSubscribedUser();
        $this->assertFalse($this->service->isGmActive($user));

        $this->assertTrue($this->service->assignGMRole($user));
        $this->assertTrue($this->service->isGmActive($user));
        $profileId = $user->gmProfile->id;

        // Phase 2: Subscription lapses
        $this->service->lapseGmBenefits($user);
        $this->assertFalse($this->service->isGmActive($user));
        $this->assertFalse($user->gmProfile->fresh()->is_active);

        // Profile preserved
        $this->assertNotNull(GMProfile::find($profileId));

        // Phase 3: User resubscribes
        Cashier::$subscriptionModel::create([
            'billable_type' => get_class($user),
            'billable_id' => $user->id,
            'type' => 'default',
            'paddle_id' => 'sub_resub_'.Str::random(12),
            'status' => 'active',
            'trial_ends_at' => null,
            'paused_at' => null,
            'ends_at' => null,
        ]);

        $this->assertTrue($this->service->assignGMRole($user->fresh()));
        $this->assertTrue($this->service->isGmActive($user->fresh()));

        // Same profile reactivated
        $reactivated = $user->fresh()->gmProfile;
        $this->assertEquals($profileId, $reactivated->id);
        $this->assertTrue($reactivated->is_active);
    }

    public function test_multiple_users_independent_lifecycles(): void
    {
        $user1 = $this->createSubscribedUser(['name' => 'GM One']);
        $user2 = $this->createSubscribedUser(['name' => 'GM Two']);

        // Both become GMs
        $this->service->assignGMRole($user1);
        $this->service->assignGMRole($user2);

        $this->assertTrue($this->service->isGmActive($user1));
        $this->assertTrue($this->service->isGmActive($user2));

        // Only user1's subscription lapses
        $this->service->lapseGmBenefits($user1);

        $this->assertFalse($this->service->isGmActive($user1->fresh()));
        $this->assertTrue($this->service->isGmActive($user2));

        // User2 still has active profile
        $this->assertTrue($user2->gmProfile->is_active);
        $this->assertFalse($user1->gmProfile->fresh()->is_active);
    }
}
