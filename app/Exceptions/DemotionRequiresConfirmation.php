<?php

namespace App\Exceptions;

use App\Services\CapacityService;
use RuntimeException;

/**
 * Thrown by {@see CapacityService::decrease()} when the requested
 * max_players falls below the current approved-participant count — i.e. one or
 * more approved players would have to be demoted to the waitlist.
 *
 * The host must explicitly confirm the demotion (naming the exact displaced
 * players) before it proceeds. The Livewire layer (T04) catches this exception
 * to surface the confirm modal; T03 wires the previewDemotion()/demote() flow
 * that performs the LIFO demotion after confirmation.
 *
 * Carries the approved count and the requested max so the caller can derive the
 * displaced count without re-querying.
 */
class DemotionRequiresConfirmation extends RuntimeException
{
    public function __construct(
        public readonly int $approvedCount,
        public readonly int $newMax,
        string $message = '',
    ) {
        $displaced = max(0, $approvedCount - $newMax);

        parent::__construct(
            $message !== ''
                ? $message
                : "Reducing max_players to {$newMax} would displace {$displaced} approved participant(s); explicit confirmation is required."
        );
    }

    /**
     * The number of approved players that would be displaced by the decrease.
     */
    public function displacedCount(): int
    {
        return max(0, $this->approvedCount - $this->newMax);
    }
}
