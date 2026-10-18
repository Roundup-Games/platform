<?php

namespace App\Console\Commands;

use App\Services\SubscriptionExpiryService;
use Illuminate\Console\Command;

/**
 * Nightly local-subscription expiry entry point (M069 S03 T3, R083):
 * flips past-ends_at active rows to expired and lapses GM benefits when
 * no active membership remains — fixed-period comps actually end.
 *
 * Thin by design after the BadgesSweep precedent: the orchestration,
 * per-row transaction isolation and structured logging live in the
 * SubscriptionExpiryService twin, so tests and future admin surfaces
 * share one code path. Always SUCCESS — rows that cannot be processed
 * are logged and skipped, not failed (a due row retries tomorrow).
 */
class SubscriptionsExpire extends Command
{
    protected $signature = 'subscriptions:expire';

    protected $description = 'Expire local subscriptions past their ends_at and lapse GM benefits when no active membership remains';

    public function handle(SubscriptionExpiryService $expirer): int
    {
        $result = $expirer->expireDue();

        $this->info(sprintf(
            'Subscription expiry finished: %d expired, %d GM benefit lapse(s).',
            $result['expired'],
            $result['lapsed'],
        ));

        return self::SUCCESS;
    }
}
