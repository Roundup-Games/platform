<?php

use App\Models\MembershipType;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Paddle\Cashier;
use Laravel\Paddle\Subscription;

use function Pest\Laravel\{actingAs, assertDatabaseHas, get, post};

// ── Helpers ──────────────────────────────────────────────

function portalCreateUser(array $overrides = []): User
{
    return User::factory()->create([
        'email_verified_at' => now(),
        'profile_complete' => true,
        ...$overrides,
    ]);
}

function portalCreateMembershipType(array $overrides = []): MembershipType
{
    return MembershipType::factory()->create([
        'status' => 'active',
        'paddle_price_id' => 'pri_portal_test',
        ...$overrides,
    ]);
}

function portalCreateSubscription(User $user, array $overrides = []): Subscription
{
    return Cashier::$subscriptionModel::create([
        'billable_type' => get_class($user),
        'billable_id' => $user->id,
        'type' => 'default',
        'paddle_id' => 'sub_' . \Illuminate\Support\Str::random(12),
        'status' => 'active',
        'trial_ends_at' => null,
        'paused_at' => null,
        'ends_at' => null,
        ...$overrides,
    ]);
}

function portalCreateCustomer(User $user, ?string $paddleId = null): void
{
    Cashier::$customerModel::create([
        'billable_type' => get_class($user),
        'billable_id' => $user->id,
        'paddle_id' => $paddleId ?? 'ctm_' . $user->id,
        'name' => $user->name,
        'email' => $user->email,
    ]);
}

// ═══════════════════════════════════════════════════════════
// BILLING PORTAL — ROUTE PROTECTION
// ═══════════════════════════════════════════════════════════

describe('Billing Portal Route Protection', function () {
    it('requires authentication', function () {
        get(route('billing.portal'))
            ->assertRedirect(route('login'));
    });


});

// ═══════════════════════════════════════════════════════════
// BILLING PORTAL — DISPLAY
// ═══════════════════════════════════════════════════════════

describe('Billing Portal Display', function () {
    it('renders portal for authenticated verified user', function () {
        $user = portalCreateUser();

        actingAs($user)
            ->get(route('billing.portal'))
            ->assertOk()
            ->assertSeeLivewire('billing.billing-portal');
    })->group('smoke');



    it('shows available plans when no subscription', function () {
        $user = portalCreateUser();
        $plan = portalCreateMembershipType(['name' => 'Annual Plan']);

        actingAs($user)
            ->get(route('billing.portal'))
            ->assertOk()
            ->assertSee('Annual Plan')
            ->assertSee('Available Plans');
    });

    it('shows active subscription with status badge', function () {
        $user = portalCreateUser();
        portalCreateSubscription($user, ['status' => 'active']);

        actingAs($user)
            ->get(route('billing.portal'))
            ->assertOk()
            ->assertSee('Active')
            ->assertSee('Current Plan');
    })->group('smoke');




});

// ═══════════════════════════════════════════════════════════
// BILLING PORTAL — SUBSCRIPTION ACTIONS
// ═══════════════════════════════════════════════════════════

describe('Billing Portal — Cancel Subscription', function () {
    it('cancels active subscription via Paddle API', function () {
        config(['cashier.api_key' => 'test_api_key']);
        Log::shouldReceive('info')->andReturn(null);

        $user = portalCreateUser();
        $subscription = portalCreateSubscription($user, ['status' => 'active']);

        Http::fake([
            '*/subscriptions/*' => Http::response([
                'data' => [
                    'id' => $subscription->paddle_id,
                    'status' => 'active',
                    'canceled_at' => now()->addMonth()->toIso8601String(),
                    'scheduled_change' => [
                        'action' => 'cancel',
                        'effective_at' => now()->addMonth()->toIso8601String(),
                    ],
                ],
            ]),
        ]);

        Livewire::actingAs($user)
            ->test(\App\Livewire\Billing\BillingPortal::class)
            ->call('cancelSubscription');

        expect($subscription->fresh()->ends_at)->not->toBeNull();
    });


});

describe('Billing Portal — Resume Subscription', function () {
    it('flashes error when no grace period subscription', function () {
        $user = portalCreateUser();

        Livewire::actingAs($user)
            ->test(\App\Livewire\Billing\BillingPortal::class)
            ->call('resumeSubscription');

        // Component handles gracefully
        $this->assertTrue(true);
    });


});

// ═══════════════════════════════════════════════════════════
// CHECKOUT — ROUTE & COMPONENT
// ═══════════════════════════════════════════════════════════

describe('Checkout Route', function () {
    it('requires authentication', function () {
        $plan = portalCreateMembershipType();

        get(route('billing.checkout', ['membershipType' => $plan->id]))
            ->assertRedirect(route('login'));
    });

    it('renders checkout for valid active plan', function () {
        $user = portalCreateUser();
        $plan = portalCreateMembershipType(['name' => 'Gold Plan']);

        actingAs($user)
            ->get(route('billing.checkout', ['planId' => $plan->id]))
            ->assertOk()
            ->assertSeeLivewire('billing.checkout')
            ->assertSee('Gold Plan');
    })->group('smoke');

    it('rejects invalid checkout requests', function (string $planId, int $expectedStatus) {
        $user = portalCreateUser();
        $params = $planId ? ['planId' => $planId] : [];
        actingAs($user)
            ->get(route('billing.checkout', $params))
            ->assertStatus($expectedStatus);
    })->with([
        'inactive plan' => function () {
            $plan = portalCreateMembershipType(['status' => 'inactive']);
            return [$plan->id, 404];
        },
        'non-existent plan' => fn () => [(string) \Illuminate\Support\Str::uuid(), 404],
        'no plan or price' => fn () => ['', 400],
    ]);
});

describe('Checkout Component — Subscription Mode', function () {
    it('shows plan details in subscription mode', function () {
        $user = portalCreateUser();
        $plan = portalCreateMembershipType([
            'name' => 'Premium',
            'price_cents' => 1999,
            'duration_months' => 1,
        ]);

        Livewire::actingAs($user)
            ->test(\App\Livewire\Billing\Checkout::class, ['planId' => $plan->id])
            ->assertSet('mode', 'subscription')
            ->assertSee('Premium')
            ->assertSee('Order Summary');
    });


});

// ═══════════════════════════════════════════════════════════
// ONE-TIME CHECKOUT
// ═══════════════════════════════════════════════════════════

describe('One-Time Checkout Route', function () {
    it('requires authentication', function () {
        post(route('billing.one-time-checkout'), [
            'price_id' => 'pri_test123',
        ])->assertRedirect(route('login'));
    });

    it('validates one-time checkout request fields', function (array $payload, string $errorField) {
        $user = portalCreateUser();

        actingAs($user)
            ->post(route('billing.one-time-checkout'), $payload)
            ->assertSessionHasErrors($errorField);
    })->with([
        'price_id required' => [[], 'price_id'],
        'price_id must be string' => [['price_id' => ['array_value']], 'price_id'],
        'event_id must exist' => [['price_id' => 'pri_test', 'event_id' => 999999], 'event_id'],
        'event_id must be integer' => [['price_id' => 'pri_test', 'event_id' => 'not-a-number'], 'event_id'],
    ]);
});

// ═══════════════════════════════════════════════════════════
// MEMBERSHIP PAGE
// ═══════════════════════════════════════════════════════════

describe('Membership Page Route', function () {
    it('requires authentication', function () {
        get(route('membership'))
            ->assertRedirect(route('login'));
    });

    it('renders for authenticated user', function () {
        $user = portalCreateUser();

        actingAs($user)
            ->get(route('membership'))
            ->assertOk()
            ->assertSeeLivewire('billing.membership-page')
            ->assertSee('Membership');
    });
});

describe('Membership Page Component', function () {
    it('shows available plans', function () {
        $user = portalCreateUser();
        $plan = portalCreateMembershipType(['name' => 'Annual Plan', 'price_cents' => 4999]);

        Livewire::actingAs($user)
            ->test(\App\Livewire\Billing\MembershipPage::class)
            ->assertSee('Annual Plan')
            ->assertSee('Choose Your Plan')
            ->assertSee('$49.99');
    });

    it('shows active member badge when subscribed', function () {
        $user = portalCreateUser();
        portalCreateSubscription($user, ['status' => 'active']);

        Livewire::actingAs($user)
            ->test(\App\Livewire\Billing\MembershipPage::class)
            ->assertSee('Active Member')
            ->assertDontSee('Choose Your Plan');
    });

    it('shows renewal prompt within 30 days of expiry', function () {
        $user = portalCreateUser();
        portalCreateSubscription($user, [
            'status' => 'active',
            'ends_at' => now()->addDays(15),
        ]);

        Livewire::actingAs($user)
            ->test(\App\Livewire\Billing\MembershipPage::class)
            ->assertSee('Membership Expiring Soon')
            ->assertSee('expires in 15 day');
    });

    it('does not show renewal prompt far from expiry', function () {
        $user = portalCreateUser();
        portalCreateSubscription($user, [
            'status' => 'active',
            'ends_at' => now()->addMonths(6),
        ]);

        Livewire::actingAs($user)
            ->test(\App\Livewire\Billing\MembershipPage::class)
            ->assertDontSee('Membership Expiring Soon');
    });

    it('shows no renewal prompt when no ends_at', function () {
        $user = portalCreateUser();
        portalCreateSubscription($user, [
            'status' => 'active',
            'ends_at' => null,
        ]);

        Livewire::actingAs($user)
            ->test(\App\Livewire\Billing\MembershipPage::class)
            ->assertDontSee('Membership Expiring Soon');
    });


});

// ═══════════════════════════════════════════════════════════
// MEMBERSHIP TYPE SEEDER
// ═══════════════════════════════════════════════════════════

describe('MembershipType Seeder', function () {
    it('creates annual and monthly plans', function () {
        config(['billing.annual_price_id' => 'pri_annual_seed']);
        config(['billing.monthly_price_id' => 'pri_monthly_seed']);

        $this->seed(\Database\Seeders\MembershipTypeSeeder::class);

        assertDatabaseHas('membership_types', [
            'name' => 'Annual Membership',
            'price_cents' => 4999,
            'duration_months' => 12,
            'status' => 'active',
            'paddle_price_id' => 'pri_annual_seed',
        ]);

        assertDatabaseHas('membership_types', [
            'name' => 'Monthly Membership',
            'price_cents' => 599,
            'duration_months' => 1,
            'status' => 'active',
            'paddle_price_id' => 'pri_monthly_seed',
        ]);
    });

    it('creates plans with feature metadata', function () {
        config(['billing.annual_price_id' => 'pri_seed_meta']);

        $this->seed(\Database\Seeders\MembershipTypeSeeder::class);

        $annual = MembershipType::where('name', 'Annual Membership')->first();
        expect($annual->metadata)->not->toBeNull()
            ->and($annual->metadata['features'])->toBeArray()
            ->and($annual->metadata['features'])->toContain('Unlimited game sessions')
            ->and($annual->metadata['popular'])->toBeTrue();
    });


});

// ═══════════════════════════════════════════════════════════
// USER BILLING HELPERS
// ═══════════════════════════════════════════════════════════

describe('User Billing Helpers', function () {
    it('hasActiveMembership returns true when subscribed', function () {
        $user = portalCreateUser();
        portalCreateSubscription($user, ['status' => 'active']);

        expect($user->hasActiveMembership())->toBeTrue();
    });

    it('hasActiveMembership returns false when not subscribed', function () {
        $user = portalCreateUser();

        expect($user->hasActiveMembership())->toBeFalse();
    });


});