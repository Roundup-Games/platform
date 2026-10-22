<?php

use App\Livewire\Billing\BillingPortal;
use App\Livewire\Billing\MembershipPage;
use App\Models\MembershipType;
use App\Models\User;
use App\Services\CompMembershipService;
use App\Services\GmRoleService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Paddle\Cashier;
use Laravel\Paddle\Subscription;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

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
        'billable_type' => $user->getMorphClass(),
        'billable_id' => $user->id,
        'type' => 'default',
        'paddle_id' => 'sub_'.Str::random(12),
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
        'billable_type' => $user->getMorphClass(),
        'billable_id' => $user->id,
        'paddle_id' => $paddleId ?? 'ctm_'.$user->id,
        'name' => $user->name,
        'email' => $user->email,
    ]);
}

/**
 * Seed the local GM plan + Game Master role (unique prefix: Pest
 * compiles every test file into one process — a redeclared helper
 * name is fatal).
 */
function billingPortalSeedGmPlanAndRole(): void
{
    MembershipType::updateOrCreate(
        ['name' => 'Game Master'],
        [
            'description' => 'Test GM plan (billing portal fixtures)',
            'price_cents' => 0,
            'duration_months' => 0,
            'status' => 'active',
            'type' => 'local',
            'paddle_price_id' => null,
            'metadata' => ['gm_plan' => true],
        ],
    );

    Role::firstOrCreate([
        'name' => 'Game Master',
        'guard_name' => 'web',
        'team_id' => null,
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
            ->test(BillingPortal::class)
            ->call('cancelSubscription');

        expect($subscription->fresh()->ends_at)->not->toBeNull();
    });

    it('keeps GM benefits when a Paddle cancel leaves an active local GM membership', function () {
        config(['cashier.api_key' => 'test_api_key']);

        billingPortalSeedGmPlanAndRole();
        $user = portalCreateUser();
        portalCreateSubscription($user, ['status' => 'active']);
        app(GmRoleService::class)->activateGmSubscription($user);
        $user->unsetRelations();

        Http::fake([
            '*/subscriptions/*' => Http::response(['data' => [
                'id' => 'sub_x',
                'status' => 'active',
                'canceled_at' => now()->addMonth()->toIso8601String(),
                'scheduled_change' => [
                    'action' => 'cancel',
                    'effective_at' => now()->addMonth()->toIso8601String(),
                ],
            ]]),
        ]);
        Log::shouldReceive('info')->andReturn(null);

        // Benefits follow the LAST active membership: the self-serve GM
        // plan survives the Paddle supporter cancel, so the GM role,
        // public-entry right, and profile must all stay intact.
        Livewire::actingAs($user)
            ->test(BillingPortal::class)
            ->call('cancelSubscription');

        expect($user->fresh()->isGM())->toBeTrue()
            ->and($user->fresh()->can_create_public_entries)->toBeTrue()
            ->and($user->fresh()->gmProfile->is_active)->toBeTrue()
            ->and($user->fresh()->hasActiveMembership())->toBeTrue();
    });

    it('keeps GM benefits through the paid grace period of a canceled Paddle-only membership', function () {
        config(['cashier.api_key' => 'test_api_key']);

        billingPortalSeedGmPlanAndRole();
        $user = portalCreateUser();
        portalCreateSubscription($user, ['status' => 'active']);
        app(GmRoleService::class)->assignGMRole($user);
        $user->unsetRelations();

        Http::fake([
            '*/subscriptions/*' => Http::response(['data' => [
                'id' => 'sub_x',
                'status' => 'active',
                'canceled_at' => now()->addMonth()->toIso8601String(),
                'scheduled_change' => [
                    'action' => 'cancel',
                    'effective_at' => now()->addMonth()->toIso8601String(),
                ],
            ]]),
        ]);
        Log::shouldReceive('info')->andReturn(null);

        // The paid-out grace period keeps the subscription valid
        // (subscribed() = active OR onGracePeriod), so the portal cancel
        // must not lapse benefits early — the subscription.canceled
        // webhook ends the membership when the period actually does.
        Livewire::actingAs($user)
            ->test(BillingPortal::class)
            ->call('cancelSubscription');

        expect($user->fresh()->isGM())->toBeTrue()
            ->and($user->fresh()->gmProfile->is_active)->toBeTrue();
    });

    it('activates exactly the GM plan the user clicked when several gm_plan rows are active', function () {
        billingPortalSeedGmPlanAndRole();

        // Two live gm_plan rows (a tier variant, say): the button the
        // user clicked must win — the service may not re-resolve "the"
        // GM plan by arbitrary row order.
        $clicked = MembershipType::updateOrCreate(
            ['name' => 'Game Master+'],
            [
                'description' => 'Tier variant',
                'price_cents' => 500,
                'duration_months' => 0,
                'status' => 'active',
                'type' => 'local',
                'paddle_price_id' => null,
                'metadata' => ['gm_plan' => true],
            ],
        );

        $user = portalCreateUser();

        Livewire::actingAs($user)
            ->test(BillingPortal::class)
            ->call('activateLocalPlan', $clicked->id);

        $subscription = $user->localSubscriptions()->first();

        expect($subscription)->not->toBeNull()
            ->and($subscription->membership_type_id)->toBe($clicked->id);
    });

    it('blocks GM-plan self-serve activation for a comp holder (one active GM membership)', function () {
        billingPortalSeedGmPlanAndRole();

        $user = portalCreateUser();
        $admin = portalCreateUser();
        app(CompMembershipService::class)->grant($user, now()->addMonth(), $admin, 'Community comp');

        $plan = MembershipType::where('name', 'Game Master')->first();

        Livewire::actingAs($user)
            ->test(BillingPortal::class)
            ->call('activateLocalPlan', $plan->id);

        // hasGmSubscription() is any-source: the comp-only user gets the
        // already-have refusal, not a second (self-serve) GM row.
        expect($user->localSubscriptions()->count())->toBe(1)
            ->and($user->localSubscriptions()->first()->source->value)->toBe('comp');
    });

    it('shows GM tools Active while a surviving comp keeps benefits beside a canceled self-serve row', function () {
        billingPortalSeedGmPlanAndRole();

        $user = portalCreateUser();
        app(GmRoleService::class)->activateGmSubscription($user);
        app(GmRoleService::class)->deactivateGmSubscription($user);
        app(CompMembershipService::class)->grant($user, now()->addMonth(), portalCreateUser(), 'Community comp');
        $user->unsetRelations();

        // The card's pill reflects live BENEFIT state (the
        // last-active-membership invariant), not the displayed row's
        // canceled status — benefits are alive via the comp.
        Livewire::actingAs($user)
            ->test(BillingPortal::class)
            ->assertSee(__('billing.content_gm_tools_active_description'))
            ->assertDontSee(__('billing.content_gm_tools_inactive_description'));
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
        'non-existent plan' => fn () => [(string) Str::uuid(), 404],
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
            ->assertSee(__('billing.content_membership', [], 'en'));
    });
});

describe('MembershipPage Component', function () {
    it('shows available plans', function () {
        $user = portalCreateUser();
        $plan = portalCreateMembershipType(['name' => 'Annual Plan', 'price_cents' => 4999]);

        Livewire::actingAs($user)
            ->test(MembershipPage::class)
            ->assertSee('Annual Plan')
            ->assertSee(__('billing.action_choose_your_plan', [], 'en'))
            ->assertSee(format_currency(4999));
    });

    it('shows active member badge when subscribed', function () {
        $user = portalCreateUser();
        portalCreateSubscription($user, ['status' => 'active']);

        Livewire::actingAs($user)
            ->test(MembershipPage::class)
            ->assertSee(__('teams.content_active_member', [], 'en'))
            ->assertDontSee(__('billing.action_choose_your_plan', [], 'en'));
    });

    it('shows renewal prompt within 30 days of expiry', function () {
        $user = portalCreateUser();
        portalCreateSubscription($user, [
            'status' => 'active',
            'ends_at' => now()->addDays(15),
        ]);

        Livewire::actingAs($user)
            ->test(MembershipPage::class)
            ->assertSee(__('emails.content_membership_expiring_soon', [], 'en'))
            ->assertSee('expires in 15 day');
    });

    it('does not show renewal prompt far from expiry', function () {
        $user = portalCreateUser();
        portalCreateSubscription($user, [
            'status' => 'active',
            'ends_at' => now()->addMonths(6),
        ]);

        Livewire::actingAs($user)
            ->test(MembershipPage::class)
            ->assertDontSee(__('emails.content_membership_expiring_soon', [], 'en'));
    });

    it('shows no renewal prompt when no ends_at', function () {
        $user = portalCreateUser();
        portalCreateSubscription($user, [
            'status' => 'active',
            'ends_at' => null,
        ]);

        Livewire::actingAs($user)
            ->test(MembershipPage::class)
            ->assertDontSee(__('emails.content_membership_expiring_soon', [], 'en'));
    });
});

// ═════════════════════════════════════════════════════════
// PLAN TYPE LABEL (M066/S02/T07) — labeled value, not ucfirst(type)
// Cashier's internal subscription type ('default') is never rendered raw;
// the subscription is the platform membership, so it gets a localized label.
// ═════════════════════════════════════════════════════════

describe('Billing Plan Type Label', function () {
    it('labels the plan as a membership in the billing portal', function () {
        $user = portalCreateUser();
        portalCreateSubscription($user, ['type' => 'default', 'status' => 'active']);

        Livewire::actingAs($user)
            ->test(BillingPortal::class)
            ->assertSee(__('billing.content_membership_plan', [], 'en'))
            ->assertDontSee('Default Plan');
    });

    it('renders the German membership-plan label in German locale', function () {
        app()->setLocale('de');

        $user = portalCreateUser();
        portalCreateSubscription($user, ['type' => 'default', 'status' => 'active']);

        Livewire::actingAs($user)
            ->test(BillingPortal::class)
            ->assertSee(__('billing.content_membership_plan', [], 'de'))
            ->assertDontSee('Default-Plan');
    });

    it('labels the plan on the membership page in both locales', function (string $locale, string $oldRendering) {
        app()->setLocale($locale);

        // K004: the visible label resolves through the translator; only the
        // legacy pre-localization rendering is asserted literally.
        $label = __('billing.content_membership_plan', [], $locale);

        $user = portalCreateUser();
        portalCreateSubscription($user, ['type' => 'default', 'status' => 'active']);

        Livewire::actingAs($user)
            ->test(MembershipPage::class)
            ->assertSee($label)
            ->assertDontSee($oldRendering);
    })->with([
        'en' => ['en', 'Default Plan'],
        'de' => ['de', 'Default-Plan'],
    ]);
});
