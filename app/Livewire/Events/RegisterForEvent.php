<?php

namespace App\Livewire\Events;

use App\Models\Event;
use App\Models\EventRegistration;
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
            // Unique constraint violation from a concurrent insert — treat as duplicate
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
            session()->flash('success', __('events.flash_you_have_been_registered_successfully'));
            $this->redirectRoute('events.detail', ['slug' => $this->event->slug]);
        }
    }

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

            // Store a reference on the registration for reconciliation after webhook
            $registration->update([
                'payment_id' => 'paddle_checkout_'.$registration->id,
            ]);

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
