<?php

namespace App\Livewire\Events;

use App\Enums\NotificationCategory;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Notifications\EventRegistrationConfirmed;
use App\Services\NotificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * @property-read int $effectiveFee
 * @property-read bool $isEarlyBird
 */
#[Layout('components.public-layout')]
class RegisterForEvent extends Component
{
    public Event $event;

    #[Validate('nullable|string|max:1000')]
    public string $notes = '';

    public function mount(string $slug): void
    {
        $this->event = Event::where('slug', $slug)->firstOrFail();

        if (! $this->event->isRegistrationOpen()) {
            session()->flash('error', __('events.content_registration_is_not_currently_open_for_this_event'));
            $this->redirectRoute('events.detail', ['slug' => $this->event->slug]);

            return;
        }
    }

    #[Computed]
    public function effectiveFee(): int
    {
        $base = $this->event->individual_registration_fee ?? 0;

        if ($this->event->early_bird_discount && $this->event->early_bird_deadline && ($deadline = $this->earlyBirdDeadline()) !== null && now()->lt($deadline)) {
            return max(0, $base - $this->event->early_bird_discount);
        }

        return $base;
    }

    #[Computed]
    public function isEarlyBird(): bool
    {
        return $this->event->early_bird_discount
            && $this->event->early_bird_deadline
            && ($deadline = $this->earlyBirdDeadline()) !== null
            && now()->lt($deadline);
    }

    private function earlyBirdDeadline(): ?Carbon
    {
        return $this->event->early_bird_deadline;
    }

    public function register(): void
    {
        $user = authenticatedUser();

        // Re-validate registration window (may have closed since page load)
        $this->event->refresh();
        if (! $this->event->isRegistrationOpen()) {
            session()->flash('error', __('events.content_registration_has_closed'));
            $this->redirectRoute('events.detail', ['slug' => $this->event->slug]);

            return;
        }

        $this->validate();

        $eventId = $this->event->id;
        $notes = $this->notes;
        $fee = $this->effectiveFee;
        $isEarlyBird = $this->isEarlyBird;
        $userId = $user->id;

        try {
            $registration = DB::transaction(function () use ($eventId, $userId, $notes, $fee) {
                // Pessimistic lock on the event row to serialize capacity checks
                $event = Event::lockForUpdate()->find($eventId);

                if ($event === null) {
                    throw new \RuntimeException(__('events.error_event_not_found'));
                }

                if (! $event->hasCapacity()) {
                    throw new \RuntimeException(__('events.content_this_event_is_now_full'));
                }

                // Check for duplicate registration (user, scoped to this event)
                $existing = EventRegistration::where('event_id', $eventId)
                    ->whereNotIn('status', ['cancelled'])
                    ->where('user_id', $userId)
                    ->exists();

                if ($existing) {
                    throw new \RuntimeException(__('events.content_you_are_already_registered_for_this_event'));
                }

                $status = $fee > 0 ? 'pending' : 'confirmed';
                $paymentStatus = $fee > 0 ? 'pending' : 'not_required';

                return EventRegistration::create([
                    'event_id' => $eventId,
                    'user_id' => $userId,
                    'status' => $status,
                    'payment_status' => $paymentStatus,
                    'notes' => $notes ?: null,
                    'confirmed_at' => $fee === 0 ? now() : null,
                ]);
            });
        } catch (QueryException $e) {
            // Unique constraint violation from a concurrent insert — treat as
            // duplicate. Any other database error (connectivity, deadlock, a
            // future constraint) is rethrown so real failures are not masked
            // by the already-registered flash.
            if (! self::isDuplicateRegistrationViolation($e)) {
                throw $e;
            }

            Log::warning('Event registration race caught by unique constraint', [
                'event_id' => $eventId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            session()->flash('error', __('events.content_you_are_already_registered_for_this_event'));
            $this->redirectRoute('events.detail', ['slug' => $this->event->slug]);

            return;
        } catch (\RuntimeException $e) {
            session()->flash('error', __($e->getMessage()));
            $this->redirectRoute('events.detail', ['slug' => $this->event->slug]);

            return;
        }

        Log::info('Event registration created', [
            'registration_id' => $registration->id,
            'event_id' => $eventId,
            'user_id' => $userId,
            'fee' => $fee,
            'status' => $registration->status,
            'payment_status' => $registration->payment_status,
            'early_bird' => $isEarlyBird,
        ]);

        if ($fee > 0) {
            // Redirect to Paddle checkout for payment
            $this->initPaymentCheckout($registration, $fee);
        } else {
            // Free path: the registration is already confirmed, so the
            // registrant gets their confirmation immediately through the
            // channel stack. Paid registrations are confirmed (and notified)
            // by the Paddle webhook instead.
            app(NotificationService::class)->send(
                $user,
                new EventRegistrationConfirmed($registration),
                NotificationCategory::EventRegistration,
            );

            session()->flash('success', __('events.flash_you_have_been_registered_successfully'));
            $this->redirectRoute('events.detail', ['slug' => $this->event->slug]);
        }
    }

    /**
     * Map a QueryException to the duplicate-registration race for both
     * database drivers the app can run on:
     *
     * - PostgreSQL (production + test harness): SQLSTATE 23505
     *   (unique_violation) with the index name in the message.
     * - SQLite (local file DB): SQLSTATE 23000 with driver code 19 or 2067
     *   (SQLITE_CONSTRAINT / SQLITE_CONSTRAINT_UNIQUE) and a
     *   "UNIQUE constraint failed: <table>.<cols>" message shape.
     *
     * Matching is scoped to the event_registrations active-registration
     * index so unrelated unique violations (uuid pkey, future constraints)
     * are not misreported as "already registered".
     *
     * Static: excluded from Livewire's frontend-callable method surface
     * (Livewire only wires non-static public methods) and directly testable.
     */
    public static function isDuplicateRegistrationViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;
        $driverCode = $e->errorInfo[1] ?? null;
        $message = $e->getMessage();

        $isUniqueViolation = $sqlState === '23505'
            || ($sqlState === '23000' && in_array($driverCode, [19, 2067], true));

        return $isUniqueViolation && (
            str_contains($message, 'event_registrations_event_user_active_unique')
            || str_contains($message, 'UNIQUE constraint failed: event_registrations.event_id')
        );
    }

    /**
     * Initiate a Paddle checkout for a paid event registration.
     *
     * The checkout is tagged with custom_data {event_id, registration_id} so
     * the transaction.completed webhook can resolve and confirm this exact
     * registration. payment_id intentionally stays null here — the real Paddle
     * transaction id is written by the webhook on payment, never a placeholder.
     */
    private function initPaymentCheckout(EventRegistration $registration, int $fee): void
    {
        $user = authenticatedUser();

        // Check if event has a Paddle price ID in metadata
        $priceId = $this->event->metadata['paddle_price_id'] ?? null;

        if ($priceId) {
            Log::info('Initiating Paddle checkout for event registration', [
                'registration_id' => $registration->id,
                'price_id' => $priceId,
                'fee' => $fee,
            ]);

            $checkoutOptions = $user->checkout($priceId)
                ->customData(['event_id' => $this->event->id, 'registration_id' => $registration->id])
                ->returnTo(route('events.detail', [
                    'slug' => $this->event->slug,
                    'registration' => $registration->id,
                ]))
                ->options();

            Log::info('Paddle checkout options prepared for registration', [
                'registration_id' => $registration->id,
                'event_id' => $this->event->id,
            ]);

            $this->dispatch('open-paddle-checkout', options: $checkoutOptions);

            return;
        }

        // No Paddle price ID — mark as pending payment, organizer handles manually
        Log::info('No Paddle price ID configured for event; registration pending manual payment', [
            'registration_id' => $registration->id,
            'event_id' => $this->event->id,
            'fee' => $fee,
        ]);

        session()->flash('success', __('billing.content_registration_submitted_payment_instructions_will'));
        $this->redirectRoute('events.detail', ['slug' => $this->event->slug]);
    }

    public function render(): View
    {
        return view('livewire.events.register-for-event');
    }
}
