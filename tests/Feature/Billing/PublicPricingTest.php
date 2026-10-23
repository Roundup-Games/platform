<?php

use App\Models\MembershipType;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

// ── Helpers ──────────────────────────────────────────────

function pricingPageTier(array $overrides = []): MembershipType
{
    return MembershipType::factory()->create(array_merge([
        'status' => 'active',
    ], $overrides));
}

// ═══════════════════════════════════════════════════════════
// M066/S08/T01 — PUBLIC PRICING PAGE
//
// /pricing is guest-visible and lists only purchasable (Paddle)
// MembershipType tiers with name, price, and features; inactive
// tiers never leak and free local tiers (the Game Master plan)
// are not advertised here — they are activated on /membership
// instead; guests route to signup, members to /membership for
// checkout.
// ═══════════════════════════════════════════════════════════

describe('PublicPricing — guest access', function () {
    it('renders for guests without authentication', function () {
        pricingPageTier(['name' => 'Annual Membership', 'price_cents' => 4999, 'duration_months' => 12]);

        get(route('pricing'))
            ->assertOk()
            ->assertSeeLivewire('billing.public-pricing')
            ->assertSee(__('billing.content_pricing', [], 'en'))
            ->assertSee('Annual Membership');
    });

    it('states the honest free/paid boundary up front', function () {
        get(route('pricing'))
            ->assertOk()
            ->assertSee(__('billing.content_pricing_subtitle', [], 'en'))
            ->assertSee(__('billing.content_pricing_free_heading', [], 'en'));
    });

    it('renders the empty state when no plans exist', function () {
        get(route('pricing'))
            ->assertOk()
            ->assertSee(__('billing.content_no_plans_available_yet', [], 'en'));
    });
});

describe('PublicPricing — tier rendering', function () {
    it('renders every active purchasable tier with names, prices, and features', function () {
        pricingPageTier([
            'name' => 'Annual Membership',
            'price_cents' => 4999,
            'duration_months' => 12,
            'metadata' => ['popular' => true, 'features' => ['Unlimited game sessions']],
        ]);

        get(route('pricing'))
            ->assertOk()
            ->assertSee('Annual Membership')
            ->assertSee(format_currency(4999))
            ->assertSee('Unlimited game sessions')
            ->assertSee(__('billing.content_best_value', [], 'en'));
    });

    it('does not advertise free local tiers such as the Game Master plan', function () {
        pricingPageTier([
            'name' => 'Game Master',
            'price_cents' => 0,
            'type' => 'local',
            'paddle_price_id' => null,
            'metadata' => ['gm_plan' => true, 'features' => ['GM workspace dashboard']],
        ]);

        get(route('pricing'))
            ->assertOk()
            // The tier-card heading, not the nav's "Game Masters" directory link.
            ->assertDontSee('Game Master</h3>')
            ->assertDontSee('GM workspace dashboard');
    });

    it('hides paddle plans without a configured price id (pre-launch tiers)', function () {
        pricingPageTier([
            'name' => 'GM Monthly',
            'price_cents' => 950,
            'duration_months' => 1,
            'type' => 'paddle',
            'paddle_price_id' => null,
            'metadata' => ['gm_plan' => true, 'features' => ['Unlimited active campaign charters']],
        ]);
        // The blank-env variant: an empty string is as unconfigured as null.
        pricingPageTier([
            'name' => 'GM Annual',
            'price_cents' => 9500,
            'duration_months' => 12,
            'type' => 'paddle',
            'paddle_price_id' => '',
            'metadata' => ['gm_plan' => true, 'features' => []],
        ]);

        get(route('pricing'))
            ->assertOk()
            ->assertDontSee('GM Monthly</h3>')
            ->assertDontSee('GM Annual</h3>')
            ->assertDontSee('Unlimited active campaign charters');
    });

    it('never renders inactive tiers', function () {
        pricingPageTier(['name' => 'Visible Plan', 'price_cents' => 599, 'duration_months' => 1]);
        MembershipType::factory()->create([
            'name' => 'Retired Plan',
            'status' => 'inactive',
        ]);

        get(route('pricing'))
            ->assertOk()
            ->assertSee('Visible Plan')
            ->assertDontSee('Retired Plan');
    });
});

describe('PublicPricing — CTAs', function () {
    it('sends guests to signup', function () {
        pricingPageTier(['name' => 'Monthly Membership', 'price_cents' => 599, 'duration_months' => 1]);

        get(route('pricing'))
            ->assertOk()
            ->assertSee(__('auth.content_sign_up_free', [], 'en'))
            ->assertSee(route('register'));
    });

    it('links authenticated users to /membership for checkout', function () {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);
        pricingPageTier(['name' => 'Monthly Membership', 'price_cents' => 599, 'duration_months' => 1]);

        actingAs($user)
            ->get(route('pricing'))
            ->assertOk()
            ->assertSee(__('billing.action_choose_your_plan', [], 'en'))
            ->assertSee(route('membership'));
    });
});

describe('PublicPricing — locales', function () {
    it('renders in German with localized copy', function () {
        pricingPageTier(['name' => 'Monats-Mitgliedschaft', 'price_cents' => 599, 'duration_months' => 1]);

        get('/de/pricing')
            ->assertOk()
            ->assertSee(__('billing.content_pricing', [], 'de'))
            ->assertSee(__('billing.content_pricing_free_heading', [], 'de'))
            ->assertSee('Monats-Mitgliedschaft');
    });
});
