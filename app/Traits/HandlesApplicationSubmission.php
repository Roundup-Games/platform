<?php

namespace App\Traits;

use App\Dto\JoinIntent;
use App\Dto\TransactionDecisions;
use App\Enums\NotificationCategory;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Exceptions\SignupCutoffReached;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Notifications\NewApplication;
use App\Notifications\PlayerBenched;
use App\Notifications\WaitlistPlaced;
use App\Services\NotificationService;
use App\Services\ParticipantLifecycle;
use App\Services\PostHogAnalytics;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Shared application-submission logic for Game and Campaign Livewire components.
 *
 * Each component keeps its own mount(), render(), and entity property ($game / $campaign).
 * The trait provides submitApplication() with all transaction handling, notification
 * dispatch, and flash messaging. Entity-specific details come from two abstract methods.
 *
 * The participant write routes through the join-intake seam —
 * {@see ParticipantLifecycle::createOrReactivate()} with a
 * {@see JoinIntent::application()} intent. Overflow routing (bench vs waitlist),
 * approved_at stamping, the Player/Applicant role decision, and the Application
 * join-source attribution all resolve behind that single interface; this trait
 * reads the outcome back from the resolved participant status instead of
 * duplicating those decisions inline.
 */
trait HandlesApplicationSubmission
{
    /**
     * The entity being applied to (Game or Campaign).
     */
    abstract protected function getEntity(): Game|Campaign;

    /**
     * Entity-specific configuration and translation keys.
     *
     * @return array{
     *   show_route: string,
     *   entity_type: string,
     *   log_key: string,
     *   application_status_public: string,
     *   translations: array{
     *     own_entity_error: string,
     *     race_applied: string,
     *     already_participant: string,
     *     already_applied: string,
     *     bench_success: string,
     *     waitlist_success: string,
     *     join_success: string,
     *     application_submitted: string,
     *   },
     * }
     */
    abstract protected function getApplicationConfig(): array;

    public function submitApplication(): void
    {
        $entity = $this->getEntity();
        $config = $this->getApplicationConfig();

        // Cannot apply to a canceled or completed entity
        $status = $entity->status?->value;
        if (in_array($status, ['canceled', 'cancelled', 'completed'])) {
            session()->flash('error', __('common.error_entity_no_longer_available'));
            $this->redirect(route($config['show_route'], $entity), navigate: true);

            return;
        }

        // Signup cutoff (Game only — decision D124). A past organizer-set cutoff
        // blocks NEW signups. Checked here on the web-apply path before the heavy
        // transaction, mirroring the status guard above; the join intake
        // re-enforces the same gate as a race backstop. Campaigns have no cutoff
        // column, so the instanceof Game guard skips them. Waitlist
        // auto-promotion is intentionally NOT gated — a promotion is not a new
        // signup (it flows through CapacityService::increase, not this path).
        if ($entity instanceof Game && $entity->signupHasClosed()) {
            session()->flash('error', __('games.error_signup_closed'));
            $this->redirect(route($config['show_route'], $entity), navigate: true);

            return;
        }

        // Owner cannot apply to their own entity
        if ($entity->owner_id === Auth::id()) {
            $this->addError('message', __($config['translations']['own_entity_error']));

            return;
        }

        $userId = Auth::id();
        $message = $this->message;

        $this->validate();

        // Capture transaction decisions for post-transaction flash messages
        $txDecisions = new TransactionDecisions;

        try {
            DB::transaction(function () use ($entity, $userId, $message, $config, $txDecisions) {
                // Pessimistic lock on existing participant/application rows for this entity+user
                $entity->participants()->lockForUpdate()->where('user_id', $userId)->exists();

                $entity->applications()->lockForUpdate()->where('user_id', $userId)->exists();

                // Double-check no existing participant record
                if ($entity->participants()->where('user_id', $userId)->exists()) {
                    throw new \RuntimeException(__($config['translations']['already_participant']));
                }

                // Double-check no active application (pending or approved).
                // Rejected applications from old removal flow are cleaned up,
                // but guard against stale data just in case.
                if ($entity->applications()->where('user_id', $userId)
                    ->whereIn('status', ['pending', 'approved'])
                    ->exists()
                ) {
                    throw new \RuntimeException(__($config['translations']['already_applied']));
                }

                // Locked fresh re-read inside the transaction: the visibility and
                // capacity decisions use post-lock state, not the mount()-time
                // snapshot (the entity may have been deleted concurrently —
                // surfaced here instead of failing later on a null relation).
                // The FOR UPDATE row lock serializes concurrent applications
                // against every other entity-row locker (the share-link join,
                // waitlist promotion, invite acceptance): two users racing for
                // the last seat both re-read capacity under the lock, so the
                // second sees the first's committed approval and routes to
                // overflow instead of both landing Approved over capacity.
                $freshEntity = $entity->newQuery()
                    ->lockForUpdate()
                    ->whereKey($entity)
                    ->first();
                if ($freshEntity === null) {
                    throw new \RuntimeException(__('common.error_entity_no_longer_available'));
                }

                // For public entities, auto-approve; for protected, require approval
                $isPublic = $freshEntity->visibility === Visibility::Public;

                // Check if entity is full — the same canonical HasCapacity check
                // the join intake runs; captured here for post-transaction
                // logging and analytics only.
                $isFull = $freshEntity->isAtCapacity();

                // Store for post-transaction flash message and logging
                $txDecisions->isPublic = $isPublic;
                $txDecisions->isFull = $isFull;

                // Create application record
                // Game: always 'pending'. Campaign: 'approved' for public, 'pending' for protected.
                $freshEntity->applications()->create([
                    'user_id' => $userId,
                    'status' => $isPublic ? $config['application_status_public'] : 'pending',
                    'message' => $message ?: null,
                ]);

                // Create/reactivate the participant through the join intake.
                // The intent carries the apply semantics; ParticipantLifecycle
                // resolves the role (Player/Applicant), status (Approved,
                // Pending, Benched/Waitlisted overflow), the overflow
                // timestamps, approved_at stamping, and Application source
                // attribution. The resolved status is the authoritative
                // outcome for the post-transaction UX below.
                $participant = app(ParticipantLifecycle::class)->createOrReactivate(
                    JoinIntent::application($freshEntity, authenticatedUser()),
                );
                $txDecisions->participantStatus = $participant->getStatus();
            });
        } catch (QueryException $e) {
            // Unique constraint violation — concurrent duplicate
            Log::warning(ucfirst($config['entity_type']).' application race caught by unique constraint', [
                $config['log_key'] => $entity->id,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            session()->flash('info', __($config['translations']['race_applied']));
            $this->redirect(route($config['show_route'], $entity), navigate: true);

            return;
        } catch (SignupCutoffReached) {
            // The join intake's backstop for the razor-thin race where the
            // organizer cutoff lands between the pre-transaction guard above
            // and the participant write. Same UX as the pre-guard.
            session()->flash('error', __('games.error_signup_closed'));
            $this->redirect(route($config['show_route'], $entity), navigate: true);

            return;
        } catch (\RuntimeException $e) {
            $this->addError('message', $e->getMessage());

            return;
        }

        // Post-transaction: use the authoritative values from inside the transaction
        $isPublic = $txDecisions->isPublic;
        $isFull = $txDecisions->isFull;
        $participantStatus = $txDecisions->participantStatus;
        assert($participantStatus instanceof ParticipantStatus, 'The join intake must resolve a participant status.');

        $benched = $participantStatus === ParticipantStatus::Benched;
        $waitlisted = $participantStatus === ParticipantStatus::Waitlisted;

        Log::info(ucfirst($config['entity_type']).' application submitted', [
            $config['log_key'] => $entity->id,
            'user_id' => Auth::id(),
            'auto_approved' => $isPublic && ! $isFull,
            'benched' => $benched,
            'waitlisted' => $waitlisted,
        ]);

        // Matching-quality funnel: capture the application outcome. This is the
        // top of the funnel — intent — which the approve/reject events below
        // close out. Knowing the split between auto-approved (public) and
        // host-gated (protected) is essential for acceptance-rate analysis.
        $outcome = match ($participantStatus) {
            ParticipantStatus::Pending => 'pending',
            ParticipantStatus::Benched => 'benched',
            ParticipantStatus::Waitlisted => 'waitlisted',
            default => 'approved',
        };
        app(PostHogAnalytics::class)->capture(
            authenticatedUser(),
            'application.submitted',
            [
                'entity_type' => $config['entity_type'],
                'entity_id' => $entity->id,
                'outcome' => $outcome,
                'visibility' => $entity->visibility?->value,
                'is_full' => $isFull,
            ],
        );

        // Notify owner of new application (protected entities only)
        if (! $isPublic) {
            try {
                $owner = User::find($entity->owner_id);
                if ($owner) {
                    app(NotificationService::class)->send(
                        $owner,
                        new NewApplication(authenticatedUser(), $entity, $config['entity_type']),
                        NotificationCategory::NewApplication
                    );
                }
            } catch (\Throwable $e) {
                Log::error('notification.new_application_dispatch_failed', [
                    $config['log_key'] => $entity->id,
                    'applicant_id' => Auth::id(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($benched) {
            session()->flash('success', __($config['translations']['bench_success']));

            // Notify the applicant they were placed on the bench (host-curated
            // alternate pool, distinct from the FIFO waitlist). Dispatched after
            // the transaction commits so a failure never rolls back the apply.
            try {
                $applicant = User::find(Auth::id());
                if ($applicant) {
                    app(NotificationService::class)->send(
                        $applicant,
                        new PlayerBenched($entity, $config['entity_type']),
                        NotificationCategory::BenchUpdates,
                    );
                }
            } catch (\Throwable $e) {
                Log::error('notification.player_benched_dispatch_failed', [
                    $config['log_key'] => $entity->id,
                    'applicant_id' => Auth::id(),
                    'error' => $e->getMessage(),
                ]);
            }
        } elseif ($waitlisted) {
            session()->flash('success', __($config['translations']['waitlist_success']));

            // Notify the applicant they were placed on the waitlist (FIFO
            // queue). Dispatched after the transaction commits so a failure
            // never rolls back the apply. Parallel to the bench notification
            // above; mail-off by default since there is no action to take.
            try {
                $applicant = User::find(Auth::id());
                if ($applicant) {
                    app(NotificationService::class)->send(
                        $applicant,
                        new WaitlistPlaced($entity),
                        NotificationCategory::WaitlistPlacement,
                    );
                }
            } catch (\Throwable $e) {
                Log::error('notification.waitlist_placement_dispatch_failed', [
                    $config['log_key'] => $entity->id,
                    'applicant_id' => Auth::id(),
                    'error' => $e->getMessage(),
                ]);
            }
        } elseif ($isPublic) {
            session()->flash('success', __($config['translations']['join_success']));
        } else {
            session()->flash('success', __($config['translations']['application_submitted']));
        }

        $this->redirect(route($config['show_route'], $entity), navigate: true);
    }
}
