<?php

namespace App\Traits;

use App\Enums\JoinSource;
use App\Enums\NotificationCategory;
use App\Enums\Visibility;
use App\Models\User;
use App\Notifications\NewApplication;
use App\Services\NotificationService;
use App\Services\ParticipantService;
use Illuminate\Database\Eloquent\Model;
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
    abstract protected function getEntity(): Model;

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
        $status = $entity->status->value;
        if (in_array($status, ['canceled', 'cancelled', 'completed'])) {
            session()->flash('error', __('common.error_entity_no_longer_available'));
            $this->redirect(route($config['show_route'], $entity->id), navigate: true);

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
        $txDecisions = (object) ['isPublic' => null, 'isFull' => null, 'benchMode' => null];

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
                $participantRole = 'applicant';
                $benchedAt = null;
                $waitlistedAt = null;

                if ($isPublic) {
                    if ($isFull && $freshEntity->isBenchMode()) {
                        $participantStatus = 'benched';
                        $participantRole = 'player';
                        $benchedAt = now();
                    } elseif ($isFull) {
                        $participantStatus = 'waitlisted';
                        $participantRole = 'player';
                        $waitlistedAt = now();
                    } else {
                        $participantStatus = 'approved';
                        $participantRole = 'player';
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
            $this->redirect(route($config['show_route'], $entity->id), navigate: true);

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

        // Notify owner of new application (protected entities only)
        if (! $isPublic) {
            try {
                $owner = User::find($entity->owner_id);
                if ($owner) {
                    app(NotificationService::class)->send(
                        $owner,
                        new NewApplication(Auth::user(), $entity, $config['entity_type']),
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
        } elseif ($isPublic && $isFull) {
            session()->flash('success', __($config['translations']['waitlist_success']));
        } elseif ($isPublic) {
            session()->flash('success', __($config['translations']['join_success']));
        } else {
            session()->flash('success', __($config['translations']['application_submitted']));
        }

        $this->redirect(route($config['show_route'], $entity->id), navigate: true);
    }
}
