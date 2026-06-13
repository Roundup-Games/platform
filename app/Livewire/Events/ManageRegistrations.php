<?php

namespace App\Livewire\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Traits\EscapesLikeWildcards;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class ManageRegistrations extends Component
{
    use EscapesLikeWildcards;
    use WithPagination;

    public Event $event;

    public string $search = '';

    public string $filterStatus = '';

    public string $filterType = '';

    public string $filterPaymentStatus = '';

    #[Validate('nullable|string|max:1000')]
    public string $internalNotes = '';

    public ?string $editingRegistrationId = null;

    public ?string $confirmingAction = null;

    public function mount(string $slug): void
    {
        $this->event = Event::where('slug', $slug)->firstOrFail();
        $this->authorize('update', $this->event);
    }

    /**
     * @return LengthAwarePaginator<int, EventRegistration>
     */
    #[Computed]
    public function registrations()
    {
        $query = EventRegistration::where('event_id', $this->event->id)
            ->with(['user', 'team']);

        if ($this->search) {
            $escaped = $this->escapeLikeWildcards($this->search);
            $query->where(function ($q) use ($escaped) {
                $q->whereHas('user', function ($uq) use ($escaped) {
                    $uq->where('name', $this->likeOperator(), "%{$escaped}%")
                        ->orWhere('email', $this->likeOperator(), "%{$escaped}%");
                })->orWhereHas('team', function ($tq) use ($escaped) {
                    $tq->where('name', $this->likeOperator(), "%{$escaped}%");
                });
            });
        }

        if ($this->filterStatus) {
            $query->where('status', $this->filterStatus);
        }

        if ($this->filterType) {
            $query->where('registration_type', $this->filterType);
        }

        if ($this->filterPaymentStatus) {
            $query->where('payment_status', $this->filterPaymentStatus);
        }

        return $query->orderByDesc('created_at')->paginate(15);
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function statusCounts(): array
    {
        $base = EventRegistration::where('event_id', $this->event->id);

        return [
            'total' => $base->count(),
            'pending' => (clone $base)->where('status', 'pending')->count(),
            'confirmed' => (clone $base)->where('status', 'confirmed')->count(),
            'cancelled' => (clone $base)->where('status', 'cancelled')->count(),
            'waitlisted' => (clone $base)->where('status', 'waitlisted')->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function paymentCounts(): array
    {
        $base = EventRegistration::where('event_id', $this->event->id);

        return [
            'paid' => (clone $base)->where('payment_status', 'paid')->count(),
            'pending' => (clone $base)->where('payment_status', 'pending')->count(),
            'not_required' => (clone $base)->where('payment_status', 'not_required')->count(),
            'refunded' => (clone $base)->where('payment_status', 'refunded')->count(),
        ];
    }

    public function approve(string $registrationId): void
    {
        $registration = $this->findRegistration($registrationId);

        $oldStatus = $registration->status;
        $registration->update([
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);

        Log::info('Event registration approved', [
            'registration_id' => $registration->id,
            'event_id' => $this->event->id,
            'old_status' => $oldStatus,
            'new_status' => 'confirmed',
            'approved_by' => Auth::id(),
        ]);

        unset($this->registrations, $this->statusCounts);
        session()->flash('success', __('events.flash_registration_approved'));
    }

    public function reject(string $registrationId): void
    {
        $registration = $this->findRegistration($registrationId);

        $oldStatus = $registration->status;
        $registration->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        Log::info('Event registration rejected', [
            'registration_id' => $registration->id,
            'event_id' => $this->event->id,
            'old_status' => $oldStatus,
            'new_status' => 'cancelled',
            'rejected_by' => Auth::id(),
        ]);

        unset($this->registrations, $this->statusCounts);
        session()->flash('success', __('events.flash_registration_rejected'));
    }

    public function confirmPayment(string $registrationId): void
    {
        $registration = $this->findRegistration($registrationId);

        $oldPaymentStatus = $registration->payment_status;
        $registration->update([
            'payment_status' => 'paid',
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);

        Log::info('Event registration payment confirmed', [
            'registration_id' => $registration->id,
            'event_id' => $this->event->id,
            'old_payment_status' => $oldPaymentStatus,
            'new_payment_status' => 'paid',
            'confirmed_by' => Auth::id(),
        ]);

        unset($this->registrations, $this->statusCounts, $this->paymentCounts);
        session()->flash('success', __('billing.flash_payment_confirmed'));
    }

    public function markRefunded(string $registrationId): void
    {
        $registration = $this->findRegistration($registrationId);

        $oldPaymentStatus = $registration->payment_status;
        $registration->update([
            'payment_status' => 'refunded',
        ]);

        Log::info('Event registration payment refunded', [
            'registration_id' => $registration->id,
            'event_id' => $this->event->id,
            'old_payment_status' => $oldPaymentStatus,
            'new_payment_status' => 'refunded',
            'refunded_by' => Auth::id(),
        ]);

        unset($this->registrations, $this->paymentCounts);
        session()->flash('success', __('billing.content_payment_marked_as_refunded'));
    }

    public function cancelRegistration(string $registrationId): void
    {
        $registration = $this->findRegistration($registrationId);

        $oldStatus = $registration->status;
        $registration->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        Log::info('Event registration cancelled by organizer', [
            'registration_id' => $registration->id,
            'event_id' => $this->event->id,
            'old_status' => $oldStatus,
            'cancelled_by' => Auth::id(),
        ]);

        unset($this->registrations, $this->statusCounts);
        session()->flash('success', __('events.flash_registration_cancelled'));
    }

    public function saveInternalNotes(string $registrationId): void
    {
        $this->validate();

        $registration = $this->findRegistration($registrationId);
        $registration->update(['internal_notes' => $this->internalNotes ?: null]);

        $this->editingRegistrationId = null;
        $this->internalNotes = '';

        session()->flash('success', __('common.flash_notes_saved'));
    }

    public function editInternalNotes(string $registrationId): void
    {
        $registration = $this->findRegistration($registrationId);
        $this->editingRegistrationId = $registrationId;
        $this->internalNotes = $registration->internal_notes ?? '';
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->filterStatus = '';
        $this->filterType = '';
        $this->filterPaymentStatus = '';
    }

    private function findRegistration(string $id): EventRegistration
    {
        return EventRegistration::where('id', $id)
            ->where('event_id', $this->event->id)
            ->firstOrFail();
    }

    public function render(): View
    {
        return view('livewire.events.manage-registrations');
    }
}
