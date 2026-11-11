<?php

namespace App\Traits;

/**
 * Spread a queued Mailable's retries over time. Without a backoff, Horizon
 * re-runs a failed send at once, so a sub-second network blip to the mail
 * provider uses up all 3 tries (config/horizon.php) and the mail is lost.
 */
trait BacksOffMailRetries
{
    /**
     * Seconds to wait before each retry.
     *
     * @var list<int>
     */
    public array $backoff = [10, 60];
}
