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

// ═══════════════════════════════════════════════════════════
// CHECKOUT — ROUTE & COMPONENT
// ═══════════════════════════════════════════════════════════

describe('Checkout Route', function () {
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

// ═══════════════════════════════════════════════════════════
// MEMBERSHIP PAGE
// ═══════════════════════════════════════════════════════════

describe('MembershipPage Route', function () {
    it('renders for authenticated user', function () {
        $user = portalCreateUser();

        actingAs($user)
            ->get(route('membership'))
            ->assertOk()
            ->assertSeeLivewire('billing.membership-page')
            ->assertSee('Membership');
    });
});

describe('MembershipPage Component', function () {
    it('shows available plans', function () {
        $user = portalCreateUser();
        $plan = portalCreateMembershipType(['name' => 'Annual Plan', 'price_cents' => 4999]);

        Livewire::actingAs($user)
            ->test(\App\Livewire\Billing\MembershipPage::class)
            ->assertSee('Annual Plan')
            ->assertSee('Choose Your Plan')
            ->assertSee(format_currency(4999));
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
