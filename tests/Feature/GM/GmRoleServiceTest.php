<?php

namespace Tests\Feature\GM;

use App\Enums\GameType;
use App\Models\GMProfile;
use App\Models\User;
use App\Services\GmRoleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Paddle\Cashier;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\CreatesUsers;
use Tests\Traits\SetsUpLocale;

class GmRoleServiceTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesUsers;
    use SetsUpLocale {
        SetsUpLocale::setUp as setUpLocale;
    }

    private GmRoleService $service;

    protected function setUp(): void
    {
        $this->setUpLocale();
        $this->service = app(GmRoleService::class);

        Role::firstOrCreate([
            'name' => 'Game Master',
            'guard_name' => 'web',
            'team_id' => null,
        ]);
    }

    // ── Helpers ────────────────────────────────────────

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

    public function test_revoke_gm_role_logs_info(): void
    {
        $user = $this->createSubscribedUser();
        $this->service->assignGMRole($user);

        // Verify the revoke path runs without error — logging is verified
        // separately in tests/Feature/Services/GmRoleServiceTest.php
        $this->service->revokeGMRole($user);

        $this->assertFalse($user->fresh()->hasRole('Game Master'));
        $this->assertFalse($user->gmProfile->fresh()->is_active);
    }

    public function test_subscription_lapse_logs_start_and_complete(): void
    {
        $user = $this->createSubscribedUser();
        $this->service->assignGMRole($user);

        // Verify the lapse path handles user correctly — logging verified
        // in tests/Feature/Services/GmRoleServiceTest.php
        $this->service->handleSubscriptionLapse($user);

        $this->assertFalse($user->fresh()->hasRole('Game Master'));
        $this->assertFalse($user->gmProfile->fresh()->is_active);
        $this->assertNotNull(GMProfile::where('user_id', $user->id)->first());
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

    // ── canCreateAsGm ──────────────────────────────────

    public function test_can_create_as_gm_when_active_ttrpg(): void
    {
        $user = $this->createSubscribedUser();
        $this->service->assignGMRole($user);

        $this->assertTrue($this->service->canCreateAsGm($user, GameType::Ttrpg->value));
    }

    public function test_cannot_create_as_gm_for_board_game_even_when_active(): void
    {
        $user = $this->createSubscribedUser();
        $this->service->assignGMRole($user);

        $this->assertFalse($this->service->canCreateAsGm($user, GameType::BoardGame->value));
    }

    public function test_cannot_create_as_gm_without_role(): void
    {
        $user = $this->createSubscribedUser();

        $this->assertFalse($this->service->canCreateAsGm($user, GameType::Ttrpg->value));
    }

    public function test_cannot_create_as_gm_without_subscription(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Game Master');

        $this->assertFalse($this->service->canCreateAsGm($user, GameType::Ttrpg->value));
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
        $this->service->handleSubscriptionLapse($user);
        $this->assertFalse($this->service->isGmActive($user));
        $this->assertFalse($user->gmProfile->fresh()->is_active);

        // Profile preserved
        $this->assertNotNull(GMProfile::find($profileId));

        // Phase 3: User resubscribes
        Cashier::$subscriptionModel::create([
            'billable_type' => get_class($user),
            'billable_id' => $user->id,
            'type' => 'default',
            'paddle_id' => 'sub_resub_' . Str::random(12),
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
        $this->service->handleSubscriptionLapse($user1);

        $this->assertFalse($this->service->isGmActive($user1->fresh()));
        $this->assertTrue($this->service->isGmActive($user2));

        // User2 still has active profile
        $this->assertTrue($user2->gmProfile->is_active);
        $this->assertFalse($user1->gmProfile->fresh()->is_active);
    }
}
