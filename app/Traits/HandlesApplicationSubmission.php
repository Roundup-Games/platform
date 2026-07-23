<?php

namespace App\Traits;

use App\Dto\TransactionDecisions;
use App\Enums\JoinSource;
use App\Enums\NotificationCategory;
use App\Enums\ParticipantRole;
use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Notifications\NewApplication;
use App\Notifications\PlayerBenched;
use App\Notifications\WaitlistPlaced;
use App\Services\NotificationService;
use App\Services\ParticipantService;
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
     *   foreign_key: string,
     *   application_class: class-string,
     *   participant_class: class-string,
     *   entity_class: class-string,
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
        // blocks NEW signups at all three participant-write entry points. Checked
        // here on the web-apply path before the heavy transaction, mirroring the
        // status guard above. Campaigns have no cutoff column, so the instanceof
        // Game guard skips them. Waitlist auto-promotion is intentionally NOT
        // gated — a promotion is not a new signup (it flows through
        // CapacityService::increase, not this path).
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

        $entityId = $entity->id;
        $userId = Auth::id();
        $message = $this->message;
        $foreignKey = $config['foreign_key'];
        $applicationClass = $config['application_class'];
        $participantClass = $config['participant_class'];

        $this->validate();

        // Capture transaction decisions for post-transaction flash messages
        $txDecisions = new TransactionDecisions;

        try {
            DB::transaction(function () use ($entityId, $userId, $message, $foreignKey, $applicationClass, $participantClass, $config, $txDecisions) {
                // Pessimistic lock on existing participant/application rows for this entity+user
                $participantClass::lockForUpdate()
                    ->where($foreignKey, $entityId)
                    ->where('user_id', $userId)
                    ->exists();

                $applicationClass::lockForUpdate()
                    ->where($foreignKey, $entityId)
                    ->where('user_id', $userId)
                    ->exists();

                // Double-check no existing participant record
                if ($participantClass::where($foreignKey, $entityId)->where('user_id', $userId)->exists()) {
                    throw new \RuntimeException(__($config['translations']['already_participant']));
                }

                // Double-check no active application (pending or approved).
                // Rejected applications from old removal flow are cleaned up,
                // but guard against stale data just in case.
                if ($applicationClass::where($foreignKey, $entityId)
                    ->where('user_id', $userId)
                    ->whereIn('status', ['pending', 'approved'])
                    ->exists()
                ) {
                    throw new \RuntimeException(__($config['translations']['already_applied']));
                }

                // For public entities, auto-approve; for protected, require approval
                $entityClass = $config['entity_class'];
                $freshEntity = $entityClass::find($entityId);
                $isPublic = $freshEntity->visibility === Visibility::Public;

                // Check if entity is full (includes owner in count via ParticipantService)
                $isFull = app(ParticipantService::class)->isAtCapacity($freshEntity);

                // Store for post-transaction flash message and logging
                $txDecisions->isPublic = $isPublic;
                $txDecisions->isFull = $isFull;
                $txDecisions->benchMode = $freshEntity->isBenchMode();

                // Determine participant status
                $participantStatus = 'pending';
                $participantRole = ParticipantRole::Applicant->value;
                $benchedAt = null;
                $waitlistedAt = null;

                if ($isPublic) {
                    if ($isFull && $freshEntity->isBenchMode()) {
                        $participantStatus = 'benched';
                        $participantRole = ParticipantRole::Player->value;
                        $benchedAt = now();
                    } elseif ($isFull) {
                        $participantStatus = 'waitlisted';
                        $participantRole = ParticipantRole::Player->value;
                        $waitlistedAt = now();
                    } else {
                        $participantStatus = 'approved';
                        $participantRole = ParticipantRole::Player->value;
                    }
                }

                // Create application record
                // Game: always 'pending'. Campaign: 'approved' for public, 'pending' for protected.
                $applicationClass::create([
                    $foreignKey => $entityId,
                    'user_id' => $userId,
                    'status' => $isPublic ? $config['application_status_public'] : 'pending',
                    'message' => $message ?: null,
                ]);

                // Create participant record
                $participantClass::create([
                    $foreignKey => $entityId,
                    'user_id' => $userId,
                    'role' => $participantRole,
                    'status' => $participantStatus,
                    'benched_at' => $benchedAt,
                    'waitlisted_at' => $waitlistedAt,
                    'join_source' => JoinSource::Application,
                ]);
            });
        } catch (QueryException $e) {
            // Unique constraint violation — concurrent duplicate
            Log::warning(ucfirst($config['entity_type']).' application race caught by unique constraint', [
                $config['log_key'] => $entityId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            session()->flash('info', __($config['translations']['race_applied']));
            $this->redirect(route($config['show_route'], $entity), navigate: true);

            return;
        } catch (\RuntimeException $e) {
            $this->addError('message', $e->getMessage());

            return;
        }

        // Post-transaction: use the authoritative values from inside the transaction
        $isPublic = $txDecisions->isPublic;
        $isFull = $txDecisions->isFull;

        Log::info(ucfirst($config['entity_type']).' application submitted', [
            $config['log_key'] => $entity->id,
            'user_id' => Auth::id(),
            'auto_approved' => $isPublic && ! $isFull,
            'benched' => $isPublic && $isFull && $txDecisions->benchMode,
            'waitlisted' => $isPublic && $isFull && ! $txDecisions->benchMode,
        ]);

        // Matching-quality funnel: capture the application outcome. This is the
        // top of the funnel — intent — which the approve/reject events below
        // close out. Knowing the split between auto-approved (public) and
        // host-gated (protected) is essential for acceptance-rate analysis.
        $outcome = match (true) {
            $isPublic && ! $isFull => 'approved',
            $isPublic && $txDecisions->benchMode => 'benched',
            $isPublic => 'waitlisted',
            default => 'pending',
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

        if ($isPublic && $isFull && $txDecisions->benchMode) {
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
        } elseif ($isPublic && $isFull) {
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
                Log::error('notification.waitlist_placed_dispatch_failed', [
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
