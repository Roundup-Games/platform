<?php

namespace App\Livewire\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Team;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\QueryException;
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

        $eventId = $this->event->id;
        $registrationMode = $this->registrationMode;
        $selectedTeamId = $this->selectedTeamId;
        $division = $this->division;
        $notes = $this->notes;
        $fee = $this->effectiveFee;
        $isEarlyBird = $this->isEarlyBird;
        $userId = $user->id;

        // Build roster for team registration (outside transaction — read-only)
        $roster = null;
        if ($registrationMode === 'team' && $selectedTeamId) {
            $team = Team::with('activeMembers')->find($selectedTeamId);
            $roster = $team->activeMembers->map(function ($member) {
                return [
                    'user_id' => $member->user_id,
                    'name' => $member->user?->name ?? 'Unknown',
                    'role' => $member->role,
                ];
            })->toArray();
        }

        try {
            $registration = DB::transaction(function () use ($eventId, $userId, $selectedTeamId, $registrationMode, $division, $notes, $fee, $roster) {
                // Pessimistic lock on the event row to serialize capacity checks
                $event = Event::lockForUpdate()->find($eventId);

                if (! $event->hasCapacity()) {
                    throw new \RuntimeException('This event is now full.');
                }

                // Check for duplicate registration (user or team, scoped to this event)
                $existing = EventRegistration::where('event_id', $eventId)
                    ->whereNotIn('status', ['cancelled'])
                    ->where(function ($q) use ($userId, $registrationMode, $selectedTeamId) {
                        $q->where('user_id', $userId);
                        if ($registrationMode === 'team' && $selectedTeamId) {
                            $q->orWhere('team_id', $selectedTeamId);
                        }
                    })
                    ->exists();

                if ($existing) {
                    throw new \RuntimeException('You are already registered for this event.');
                }

                $status = $fee > 0 ? 'pending' : 'confirmed';
                $paymentStatus = $fee > 0 ? 'pending' : 'not_required';

                return EventRegistration::create([
                    'event_id' => $eventId,
                    'user_id' => $userId,
                    'team_id' => $registrationMode === 'team' ? $selectedTeamId : null,
                    'registration_type' => $registrationMode,
                    'division' => $division ?: null,
                    'status' => $status,
                    'payment_status' => $paymentStatus,
                    'roster' => $roster,
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
            session()->flash('error', 'You are already registered for this event.');
            $this->redirectRoute('events.detail', ['slug' => $this->event->slug]);

            return;
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
            $this->redirectRoute('events.detail', ['slug' => $this->event->slug]);

            return;
        }

        Log::info('Event registration created', [
            'registration_id' => $registration->id,
            'event_id' => $eventId,
            'user_id' => $userId,
            'type' => $registrationMode,
            'team_id' => $selectedTeamId,
            'fee' => $fee,
            'status' => $registration->status,
            'payment_status' => $registration->payment_status,
            'early_bird' => $isEarlyBird,
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
