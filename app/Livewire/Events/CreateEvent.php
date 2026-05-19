<?php

namespace App\Livewire\Events;

use App\Enums\ContentLanguage;
use App\Models\Event;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CreateEvent extends Component
{
    public int $step = 1;
    public ?string $confirmingAction = null;
    public const MAX_STEPS = 5;

    public string $name = '';
    public string $short_description = '';
    public string $description = '';
    public string $type = 'tournament';
    public string $start_date = '';
    public string $end_date = '';

    public string $content_language = 'en';

    public string $venue_name = '';
    public string $venue_address = '';
    public string $city = '';
    public string $country = '';
    public string $postal_code = '';

    public string $registration_type = 'team';
    public ?int $max_teams = null;
    public ?int $max_participants = null;
    public ?int $min_players_per_team = null;
    public ?int $max_players_per_team = null;
    public ?int $team_registration_fee = null;
    public ?int $individual_registration_fee = null;
    public ?int $early_bird_discount = null;
    public string $early_bird_deadline = '';
    public string $registration_opens_at = '';
    public string $registration_closes_at = '';

    /** @var array<int, array{name: string, description: string}> */
    public array $divisions = [];
    public string $newDivisionName = '';
    public string $newDivisionDescription = '';

    public string $rules = '';
    public string $schedule = '';
    public string $contact_email = '';
    public string $contact_phone = '';
    public bool $is_public = true;

    public function mount(): void
    {
        $this->authorize('create', Event::class);
        $this->start_date = now()->addDays(14)->format('Y-m-d');
        $this->end_date = now()->addDays(16)->format('Y-m-d');
    }

    public function nextStep(): void
    {
        $this->validateStep($this->step);
        if ($this->step < self::MAX_STEPS) {
            $this->step++;
        }
    }

    public function previousStep(): void
    {
        if ($this->step > 1) {
            $this->step--;
        }
    }

    public function goToStep(int $step): void
    {
        if ($step < $this->step) {
            $this->step = $step;
        } elseif ($step > $this->step) {
            for ($s = $this->step; $s < $step; $s++) {
                $this->validateStep($s);
            }
            $this->step = $step;
        }
    }

    public function addDivision(): void
    {
        $this->validate([
            'newDivisionName' => 'required|string|max:100',
            'newDivisionDescription' => 'nullable|string|max:500',
        ]);
        $this->divisions[] = [
            'name' => $this->newDivisionName,
            'description' => $this->newDivisionDescription,
        ];
        $this->newDivisionName = '';
        $this->newDivisionDescription = '';
    }

    public function removeDivision(int $index): void
    {
        unset($this->divisions[$index]);
        $this->divisions = array_values($this->divisions);
    }

    public function create(): void
    {
        $this->validateStep($this->step);
        $this->authorize('create', Event::class);

        $parsedRules = $this->rules ? array_filter(array_map('trim', explode("\n", $this->rules))) : null;
        $parsedSchedule = $this->schedule ? array_filter(array_map('trim', explode("\n", $this->schedule))) : null;

        $event = Event::create(array_filter([
            'name' => $this->name,
            'short_description' => $this->short_description ?: null,
            'description' => $this->description ?: null,
            'type' => $this->type,
            'status' => 'draft',
            'content_language' => $this->content_language,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'venue_name' => $this->venue_name ?: null,
            'venue_address' => $this->venue_address ?: null,
            'city' => $this->city ?: null,
            'country' => $this->country ?: null,
            'postal_code' => $this->postal_code ?: null,
            'registration_type' => $this->registration_type,
            'max_teams' => $this->max_teams,
            'max_participants' => $this->max_participants,
            'min_players_per_team' => $this->min_players_per_team,
            'max_players_per_team' => $this->max_players_per_team,
            'team_registration_fee' => $this->team_registration_fee,
            'individual_registration_fee' => $this->individual_registration_fee,
            'early_bird_discount' => $this->early_bird_discount,
            'early_bird_deadline' => $this->early_bird_deadline ?: null,
            'registration_opens_at' => $this->registration_opens_at ?: null,
            'registration_closes_at' => $this->registration_closes_at ?: null,
            'divisions' => ! empty($this->divisions) ? $this->divisions : null,
            'rules' => $parsedRules,
            'schedule' => $parsedSchedule,
            'contact_email' => $this->contact_email ?: null,
            'contact_phone' => $this->contact_phone ?: null,
            'organizer_id' => Auth::id(),
            'is_public' => $this->is_public,
        ], fn ($value) => $value !== null));

        Log::info('Event created', [
            'event_id' => $event->id,
            'event_slug' => $event->slug,
            'name' => $event->name,
            'type' => $event->type,
            'organizer_id' => Auth::id(),
        ]);

        session()->flash('success', __('events.flash_event_name_created_successfully', ['name' => $event->name]));
        $this->redirect(route('events.manage', ['slug' => $event->slug]), navigate: true);
    }

    private function validateStep(int $step): void
    {
        $rules = match ($step) {
            1 => [
                'name' => 'required|string|max:255',
                'short_description' => 'nullable|string|max:500',
                'description' => 'nullable|string',
                'type' => 'required|in:tournament,league,camp,clinic,social,other',
                'start_date' => 'required|date|after:today',
                'end_date' => 'required|date|after_or_equal:start_date',
                'content_language' => 'required|in:' . implode(',', ContentLanguage::values()),
            ],
            2 => [
                'venue_name' => 'nullable|string|max:255',
                'venue_address' => 'nullable|string',
                'city' => 'nullable|string|max:255',
                'country' => 'nullable|string|max:3',
                'postal_code' => 'nullable|string|max:20',
            ],
            3 => [
                'registration_type' => 'required|in:team,individual,both',
                'max_teams' => 'nullable|integer|min:1',
                'max_participants' => 'nullable|integer|min:1',
                'min_players_per_team' => 'nullable|integer|min:1',
                'max_players_per_team' => 'nullable|integer|min:1',
                'team_registration_fee' => 'nullable|integer|min:0',
                'individual_registration_fee' => 'nullable|integer|min:0',
                'early_bird_discount' => 'nullable|integer|min:0',
                'early_bird_deadline' => 'nullable|date',
                'registration_opens_at' => 'nullable|date',
                'registration_closes_at' => 'nullable|date|after:registration_opens_at',
            ],
            4 => null,
            5 => [
                'rules' => 'nullable|string',
                'schedule' => 'nullable|string',
                'contact_email' => 'nullable|email',
                'contact_phone' => 'nullable|string|max:30',
                'is_public' => 'boolean',
            ],

            default => null,
        };

        if ($rules !== null) {
            $this->validate($rules);
        }
    }

    public function render()
    {
        return view('livewire.events.create-event');
    }
}
