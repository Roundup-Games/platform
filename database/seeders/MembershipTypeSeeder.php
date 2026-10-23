<?php

namespace Database\Seeders;

use App\Models\MembershipType;
use Illuminate\Database\Seeder;

class MembershipTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Creates membership plans with Paddle price IDs sourced from config/env.
     * Price IDs should be set in config/billing.php or via env vars:
     *   PADDLE_ANNUAL_PRICE_ID, PADDLE_MONTHLY_PRICE_ID,
     *   PADDLE_GM_MONTHLY_PRICE_ID, PADDLE_GM_ANNUAL_PRICE_ID.
     *
     * Pricing flip (D184/D241): the Game Master tier is paid — €9.50/month
     * or €95/year via Paddle. The legacy free local GM plan stays in the
     * table (comp grants resolve it, and existing self-serve subscriptions
     * keep working until canceled — grandfather-until-cancel), and once a
     * paid GM price is configured it is marked legacy_free: the self-serve
     * surfaces hide it and refuse new activations. Until then it stays
     * open — closing free activation while the paid tier is unpurchasable
     * (no price id configured) would leave GM onboarding dead, so the
     * closure is atomic with purchasability. GM plans without a configured
     * price id stay hidden from the public listing for the same reason.
     */
    public function run(): void
    {
        $gmFlipArmed = (bool) (config('billing.gm_monthly_price_id') || config('billing.gm_annual_price_id'));

        $plans = [
            [
                'name' => 'Annual Membership',
                'description' => 'Full access to all games, campaigns, and events for a full year. Best value for dedicated players.',
                'price_cents' => 4999,
                'duration_months' => 12,
                'status' => 'active',
                'type' => 'paddle',
                'paddle_price_id' => config('billing.annual_price_id'),
                'metadata' => ['popular' => true, 'features' => [
                    'Unlimited game sessions',
                    'Campaign participation',
                    'Event registration',
                    'Community access',
                ]],
            ],
            [
                'name' => 'Monthly Membership',
                'description' => 'Flexible month-to-month access. Cancel anytime.',
                'price_cents' => 599,
                'duration_months' => 1,
                'status' => 'active',
                'type' => 'paddle',
                'paddle_price_id' => config('billing.monthly_price_id'),
                'metadata' => ['features' => [
                    'Unlimited game sessions',
                    'Campaign participation',
                    'Event registration',
                    'Community access',
                ]],
            ],
            [
                'name' => 'GM Monthly',
                'description' => 'Everything for the working Game Master, month to month. Cancel anytime.',
                'price_cents' => 950,
                'duration_months' => 1,
                'status' => 'active',
                'type' => 'paddle',
                'paddle_price_id' => config('billing.gm_monthly_price_id'),
                'metadata' => ['gm_plan' => true, 'features' => self::gmFeatures()],
            ],
            [
                'name' => 'GM Annual',
                'description' => 'Everything for the working Game Master, for a full year — two months free.',
                'price_cents' => 9500,
                'duration_months' => 12,
                'status' => 'active',
                'type' => 'paddle',
                'paddle_price_id' => config('billing.gm_annual_price_id'),
                'metadata' => ['gm_plan' => true, 'features' => self::gmFeatures()],
            ],
            [
                'name' => 'Game Master',
                'description' => 'The legacy free GM tier — closed to new activations. Existing subscriptions keep their benefits until canceled.',
                'price_cents' => 0,
                'duration_months' => 0,
                'status' => 'active',
                'type' => 'local',
                'paddle_price_id' => null,
                'metadata' => array_merge(
                    ['gm_plan' => true, 'features' => [
                        'GM workspace dashboard',
                        'Professional GM profile',
                        'Review and rating system',
                    ]],
                    $gmFlipArmed ? ['legacy_free' => true] : [],
                ),
            ],
        ];

        foreach ($plans as $plan) {
            MembershipType::updateOrCreate(
                ['name' => $plan['name']],
                $plan,
            );
        }

        $this->command->info('Seeded '.count($plans).' membership plans.');
    }

    /**
     * The paid GM tier's feature list (D241): every member gate the
     * platform already enforces plus the GM surfaces. Single source so
     * monthly and annual stay in lockstep.
     *
     * @return string[]
     */
    private static function gmFeatures(): array
    {
        return [
            'Unlimited active campaign charters',
            'Charter templates & audit export',
            'Previously On catch-up digests',
            'GM Workspace',
            'Professional GM profile & reviews',
        ];
    }
}
