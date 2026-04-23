<?php

namespace Tests\Feature\Services;

use App\Enums\GameType;
use App\Models\GMProfile;
use App\Models\User;
use App\Services\GmRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Paddle\Cashier;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GmRoleServiceTest extends TestCase
{
    use RefreshDatabase;

    private GmRoleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(GmRoleService::class);

        // Ensure the Game Master role exists for all tests
        Role::firstOrCreate([
            'name' => 'Game Master',
            'guard_name' => 'web',
            'team_id' => null,
        ]);
    }

    // ── Helper ────────────────────────────────────────

    private function createSubscribedUser(array $overrides = []): User
    {
        $user = User::factory()->create($overrides);

        Cashier::$subscriptionModel::create([
            'billable_type' => get_class($user),
            'billable_id' => $user->id,
            'type' => 'default',
            'paddle_id' => 'sub_' . \Illuminate\Support\Str::random(12),
            'status' => 'active',
            'trial_ends_at' => null,
            'paused_at' => null,
            'ends_at' => null,
        ]);

        return $user;
    }

    // ── assignGMRole ──────────────────────────────────

    public function test_assign_gm_role_to_subscribed_user(): void
    {
        $user = $this->createSubscribedUser();

        $result = $this->service->assignGMRole($user);

        $this->assertTrue($result);
        $this->assertTrue($user->hasRole('Game Master'));
        $this->assertTrue($user->fresh()->isGM());
    }

    public function test_assign_gm_role_creates_gm_profile(): void
    {
        $user = $this->createSubscribedUser();

        $this->assertNull($user->gmProfile);

        $this->service->assignGMRole($user);

        $profile = $user->fresh()->gmProfile;
        $this->assertNotNull($profile);
        $this->assertTrue($profile->is_active);
        $this->assertEquals($user->id, $profile->user_id);
    }

    public function test_assign_gm_role_reactivates_existing_inactive_profile(): void
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

    public function test_assign_gm_role_idempotent(): void
    {
        $user = $this->createSubscribedUser();

        $this->service->assignGMRole($user);
        $result = $this->service->assignGMRole($user);

        $this->assertTrue($result);
        $this->assertTrue($user->fresh()->hasRole('Game Master'));
        $this->assertEquals(1, GMProfile::where('user_id', $user->id)->count());
    }

    public function test_assign_gm_role_denied_without_subscription(): void
    {
        $user = User::factory()->create();

        $result = $this->service->assignGMRole($user);

        $this->assertFalse($result);
        $this->assertFalse($user->hasRole('Game Master'));
        $this->assertNull($user->gmProfile);
    }

    public function test_assign_gm_role_denied_logs_warning(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, 'denied')
                    && ($context['user_id'] ?? null) !== null
                    && ($context['action'] ?? null) === 'assignGMRole';
            });

        $user = User::factory()->create();
        $this->service->assignGMRole($user);
    }

    public function test_assign_gm_role_creates_role_if_missing(): void
    {
        // Delete the role created in setUp to test auto-creation
        Role::where('name', 'Game Master')->delete();

        $user = $this->createSubscribedUser();

        $result = $this->service->assignGMRole($user);

        $this->assertTrue($result);
        $this->assertDatabaseHas('roles', ['name' => 'Game Master', 'team_id' => null]);
    }

    // ── revokeGMRole ──────────────────────────────────

    public function test_revoke_gm_role(): void
    {
        $user = $this->createSubscribedUser();
        $this->service->assignGMRole($user);

        $this->service->revokeGMRole($user);

        $this->assertFalse($user->fresh()->hasRole('Game Master'));
    }

    public function test_revoke_gm_role_deactivates_profile(): void
    {
        $user = $this->createSubscribedUser();
        $this->service->assignGMRole($user);

        $this->assertTrue($user->gmProfile->is_active);

        $this->service->revokeGMRole($user);

        $this->assertFalse($user->gmProfile->fresh()->is_active);
    }

    public function test_revoke_gm_role_preserves_profile(): void
    {
        $user = $this->createSubscribedUser();
        $this->service->assignGMRole($user);
        $profileId = $user->gmProfile->id;

        $this->service->revokeGMRole($user);

        $this->assertNotNull(GMProfile::find($profileId));
    }

    public function test_revoke_gm_role_idempotent(): void
    {
        $user = User::factory()->create();

        // Revoking on user without GM role should not throw
        $this->service->revokeGMRole($user);

        $this->assertFalse($user->hasRole('Game Master'));
    }

    public function test_revoke_gm_role_without_profile_does_not_throw(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Game Master');

        // Should not throw even though no GMProfile exists
        $this->service->revokeGMRole($user);

        $this->assertFalse($user->fresh()->hasRole('Game Master'));
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

    public function test_is_gm_active_false_after_subscription_lapse(): void
    {
        $user = $this->createSubscribedUser();
        $this->service->assignGMRole($user);

        // Simulate subscription ending — remove subscription
        Cashier::$subscriptionModel::where('billable_id', $user->id)->delete();

        $this->assertFalse($this->service->isGmActive($user->fresh()));
    }

    // ── handleSubscriptionLapse ────────────────────────

    public function test_handle_subscription_lapse_revokes_role(): void
    {
        $user = $this->createSubscribedUser();
        $this->service->assignGMRole($user);

        $this->service->handleSubscriptionLapse($user);

        $this->assertFalse($user->fresh()->hasRole('Game Master'));
    }

    public function test_handle_subscription_lapse_deactivates_profile(): void
    {
        $user = $this->createSubscribedUser();
        $this->service->assignGMRole($user);

        $this->service->handleSubscriptionLapse($user);

        $this->assertFalse($user->gmProfile->fresh()->is_active);
    }

    public function test_handle_subscription_lapse_preserves_profile(): void
    {
        $user = $this->createSubscribedUser();
        $this->service->assignGMRole($user);
        $profileId = $user->gmProfile->id;

        $this->service->handleSubscriptionLapse($user);

        $this->assertNotNull(GMProfile::find($profileId));
    }

    public function test_handle_subscription_lapse_idempotent(): void
    {
        $user = User::factory()->create();

        // Should not throw when called on non-GM user
        $this->service->handleSubscriptionLapse($user);

        $this->assertFalse($user->hasRole('Game Master'));
    }

    // ── canCreateAsGm ─────────────────────────────────

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

    // ── Full lifecycle ────────────────────────────────

    public function test_full_gm_lifecycle(): void
    {
        // 1. Non-subscribed user cannot become GM
        $user = User::factory()->create();
        $this->assertFalse($this->service->assignGMRole($user));
        $this->assertFalse($this->service->isGmActive($user));

        // 2. User subscribes — now can become GM
        Cashier::$subscriptionModel::create([
            'billable_type' => get_class($user),
            'billable_id' => $user->id,
            'type' => 'default',
            'paddle_id' => 'sub_lifecycle_test',
            'status' => 'active',
            'trial_ends_at' => null,
            'paused_at' => null,
            'ends_at' => null,
        ]);
        $user = $user->fresh();

        $this->assertTrue($this->service->assignGMRole($user));
        $this->assertTrue($this->service->isGmActive($user));
        $this->assertTrue($user->gmProfile->is_active);

        // 3. Subscription lapses
        $this->service->handleSubscriptionLapse($user);
        $this->assertFalse($this->service->isGmActive($user));
        $this->assertFalse($user->gmProfile->fresh()->is_active);

        // 4. Profile preserved for history
        $this->assertNotNull($user->gmProfile);
    }
}
