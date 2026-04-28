<?php

namespace App\Jobs;

use App\Models\GameParticipant;
use App\Services\WaitlistService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Queued job that checks if a promoted participant's confirmation window
 * has expired and, if so, moves them to the back of the waitlist and
 * promotes the next player.
 *
 * Dispatched with a delay matching the confirmation deadline so it
 * auto-fires as a fallback when no response comes in time. The sweep
 * command (SweepExpiredConfirmations) serves as an additional safety net.
 */
class HandleExpiredConfirmation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum retry attempts before marking as failed.
     */
    public int $tries = 3;

    /**
     * Discard the job if the participant was deleted between dispatch and execution.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * @param  string  $participantId  The game_participant ID to check.
     */
    public function __construct(
        public string $participantId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(WaitlistService $waitlistService): void
    {
        $participant = GameParticipant::find($this->participantId);

        if ($participant === null) {
            Log::info('waitlist.expired_confirmation_job.participant_not_found', [
                'participant_id' => $this->participantId,
            ]);

            return;
        }

        // If the participant is no longer pending, they already responded — skip.
        if ($participant->status->value !== 'pending') {
            Log::debug('waitlist.expired_confirmation_job.already_resolved', [
                'participant_id' => $participant->id,
                'status' => $participant->status->value,
            ]);

            return;
        }

        // If confirmation has not actually expired yet, skip (the sweep will catch it).
        if ($participant->confirmation_expires_at !== null
            && now()->isBefore($participant->confirmation_expires_at)) {
            Log::debug('waitlist.expired_confirmation_job.not_yet_expired', [
                'participant_id' => $participant->id,
                'expires_at' => $participant->confirmation_expires_at->toIso8601String(),
            ]);

            return;
        }

        Log::info('waitlist.expired_confirmation_job.processing', [
            'participant_id' => $participant->id,
            'game_id' => $participant->game_id,
            'expired_at' => $participant->confirmation_expires_at?->toIso8601String(),
        ]);

        $waitlistService->handleExpiredConfirmation($participant);
    }

    /**
     * Handle a job failure after all retries exhausted.
     */
    public function failed(?\Throwable $exception = null): void
    {
        Log::error('waitlist.expired_confirmation_job.failed', [
            'participant_id' => $this->participantId,
            'exception' => $exception?->getMessage(),
            'exception_class' => $exception ? get_class($exception) : null,
        ]);
    }
}
