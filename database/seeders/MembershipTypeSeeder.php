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
     *   PADDLE_ANNUAL_PRICE_ID, PADDLE_MONTHLY_PRICE_ID, etc.
     */
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Annual Membership',
                'description' => 'Full access to all games, campaigns, and events for a full year. Best value for dedicated players.',
                'price_cents' => 4999,
                'duration_months' => 12,
                'status' => 'active',
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
                'paddle_price_id' => config('billing.monthly_price_id'),
                'metadata' => ['features' => [
                    'Unlimited game sessions',
                    'Campaign participation',
                    'Event registration',
                    'Community access',
                ]],
            ],
        ];

        foreach ($plans as $plan) {
            MembershipType::updateOrCreate(
                ['name' => $plan['name']],
                $plan,
            );
        }

        $this->command->info('Seeded ' . count($plans) . ' membership plans.');
    }
}
