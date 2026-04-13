<?php

namespace App\Livewire\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Team;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.public-layout')]
class RegisterForEvent extends Component
{
    public Event $event;

    public string $registrationMode = 'individual'; // 'individual' or 'team'

    #[Validate('nullable|string|max:100')]
    public string $division = '';

    #[Validate('nullable|string|max:1000')]
    public string $notes = '';

    // Team registration fields
    #[Validate('nullable|exists:teams,id')]
    public ?string $selectedTeamId = null;

    /** @var array<int> */
    public array $selectedRosterMemberIds = [];

    public function mount(string $slug): void
    {
        $this->event = Event::where('slug', $slug)->firstOrFail();

        if (! $this->event->isRegistrationOpen()) {
            session()->flash('error', 'Registration is not currently open for this event.');
            $this->redirectRoute('events.detail', ['slug' => $this->event->slug]);

            return;
        }

        // Default to whatever the event allows
        if ($this->event->registration_type === 'team') {
            $this->registrationMode = 'team';
        } elseif ($this->event->registration_type === 'individual') {
            $this->registrationMode = 'individual';
        }
        // 'both' defaults to 'individual'
    }

    #[Computed]
    public function userTeams()
    {
        $user = Auth::user();

        if (! $user) {
            return collect();
        }

        return Team::whereHas('members', function ($q) use ($user) {
            $q->where('user_id', $user->id)
                ->where('status', 'active');
        })->get();
    }

    #[Computed]
    public function selectedTeam(): ?Team
    {
        if (! $this->selectedTeamId) {
            return null;
        }

        return Team::with('activeMembers.user')->find($this->selectedTeamId);
    }

    #[Computed]
    public function effectiveFee(): int
    {
        if ($this->registrationMode === 'team') {
            $base = $this->event->team_registration_fee ?? 0;
        } else {
            $base = $this->event->individual_registration_fee ?? 0;
        }

        if ($this->event->early_bird_discount && $this->event->early_bird_deadline && now()->lt($this->earlyBirdDeadline())) {
            return max(0, $base - $this->event->early_bird_discount);
        }

        return $base;
    }

    #[Computed]
    public function isEarlyBird(): bool
    {
        return $this->event->early_bird_discount
            && $this->event->early_bird_deadline
            && now()->lt($this->earlyBirdDeadline());
    }

    private function earlyBirdDeadline(): \Illuminate\Support\Carbon
    {
        return $this->event->early_bird_deadline;
    }

    public function updatedRegistrationMode(string $value): void
    {
        if ($value === 'individual') {
            $this->selectedTeamId = null;
            $this->selectedRosterMemberIds = [];
        }
    }

    public function updatedSelectedTeamId(): void
    {
        $this->selectedRosterMemberIds = [];
        unset($this->selectedTeam);
    }

    public function register(): void
    {
        $user = Auth::user();

        if (! $user) {
            $this->redirectRoute('login');

            return;
        }

        // Re-validate registration window (may have closed since page load)
        $this->event->refresh();
        if (! $this->event->isRegistrationOpen()) {
            session()->flash('error', 'Registration has closed.');
            $this->redirectRoute('events.detail', ['slug' => $this->event->slug]);

            return;
        }

        // Re-check capacity
        if (! $this->event->hasCapacity()) {
            session()->flash('error', 'This event is now full.');
            $this->redirectRoute('events.detail', ['slug' => $this->event->slug]);

            return;
        }

        if ($this->registrationMode === 'team') {
            $this->validateTeamRegistration($user);
        } else {
            $this->validateIndividualRegistration($user);
        }

        // If mode-specific validation added errors, stop here
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $this->validate();

        // Check for duplicate registration (user or team, scoped to this event)
        $existing = EventRegistration::where('event_id', $this->event->id)
            ->whereNotIn('status', ['cancelled'])
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id);
                if ($this->registrationMode === 'team' && $this->selectedTeamId) {
                    $q->orWhere('team_id', $this->selectedTeamId);
                }
            })
            ->exists();

        if ($existing) {
            session()->flash('error', 'You are already registered for this event.');
            $this->redirectRoute('events.detail', ['slug' => $this->event->slug]);

            return;
        }

        // Build roster for team registration
        $roster = null;
        if ($this->registrationMode === 'team' && $this->selectedTeamId) {
            $team = Team::with('activeMembers')->find($this->selectedTeamId);
            $roster = $team->activeMembers->map(function ($member) {
                return [
                    'user_id' => $member->user_id,
                    'name' => $member->user?->name ?? 'Unknown',
                    'role' => $member->role,
                ];
            })->toArray();
        }

        $fee = $this->effectiveFee;

        $status = $fee > 0 ? 'pending' : 'confirmed';
        $paymentStatus = $fee > 0 ? 'pending' : 'not_required';

        $registration = EventRegistration::create([
            'event_id' => $this->event->id,
            'user_id' => $user->id,
            'team_id' => $this->registrationMode === 'team' ? $this->selectedTeamId : null,
            'registration_type' => $this->registrationMode,
            'division' => $this->division ?: null,
            'status' => $status,
            'payment_status' => $paymentStatus,
            'roster' => $roster,
            'notes' => $this->notes ?: null,
            'confirmed_at' => $fee === 0 ? now() : null,
        ]);

        Log::info('Event registration created', [
            'registration_id' => $registration->id,
            'event_id' => $this->event->id,
            'user_id' => $user->id,
            'type' => $this->registrationMode,
            'team_id' => $this->selectedTeamId,
            'fee' => $fee,
            'status' => $status,
            'payment_status' => $paymentStatus,
            'early_bird' => $this->isEarlyBird,
        ]);

        if ($fee > 0) {
            // Redirect to Paddle checkout for payment
            $this->initPaymentCheckout($registration, $fee);
        } else {
            session()->flash('success', 'You have been registered successfully!');
            $this->redirectRoute('events.detail', ['slug' => $this->event->slug]);
        }
    }

    private function validateTeamRegistration($user): void
    {
        if (! in_array($this->event->registration_type, ['team', 'both'])) {
            $this->addError('registrationMode', 'This event does not support team registration.');
        }

        if (! $this->selectedTeamId) {
            $this->addError('selectedTeamId', 'Please select a team.');
        }

        $team = Team::find($this->selectedTeamId);
        if ($team && ! $team->isCaptain($user)) {
            $this->addError('selectedTeamId', 'Only the team captain can register a team.');
        }
    }

    private function validateIndividualRegistration($user): void
    {
        if (! in_array($this->event->registration_type, ['individual', 'both'])) {
            $this->addError('registrationMode', 'This event does not support individual registration.');
        }
    }

    private function initPaymentCheckout(EventRegistration $registration, int $fee): void
    {
        $user = Auth::user();

        // Check if event has a Paddle price ID in metadata
        $priceId = $this->event->metadata['paddle_price_id'] ?? null;

        if ($priceId) {
            Log::info('Initiating Paddle checkout for event registration', [
                'registration_id' => $registration->id,
                'price_id' => $priceId,
                'fee' => $fee,
            ]);

            $checkout = $user->pay($priceId)
                ->returnTo(route('events.detail', ['slug' => $this->event->slug]))
                ->create();

            $this->redirect($checkout);

            return;
        }

        // No Paddle price ID — mark as pending payment, organizer handles manually
        Log::info('No Paddle price ID configured for event; registration pending manual payment', [
            'registration_id' => $registration->id,
            'event_id' => $this->event->id,
            'fee' => $fee,
        ]);

        session()->flash('success', 'Registration submitted! Payment instructions will follow.');
        $this->redirectRoute('events.detail', ['slug' => $this->event->slug]);
    }

    public function render()
    {
        return view('livewire.events.register-for-event');
    }
}
