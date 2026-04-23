<?php

namespace App\Livewire\Events;

use App\Enums\ContentLanguage;
use App\Models\Event;
use App\Services\ScopedRoleService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ManageEvent extends Component
{
    public Event $event;

    // ── Tab tracking ──────────────────────────────────
    public string $activeTab = 'details';

    // ── Translation fields ────────────────────────────
    public string $content_language = 'en';
    public string $activeLocale = 'en';
    public string $name_de = '';
    public string $short_description_de = '';
    public string $description_de = '';
    public string $rules_de = '';
    public string $schedule_de = '';

    // ── Basic Info ────────────────────────────────────
    public string $name = '';
    public string $short_description = '';
    public string $description = '';
    public string $type = 'tournament';
    public string $status = 'draft';
    public string $start_date = '';
    public string $end_date = '';

    // ── Venue ─────────────────────────────────────────
    public string $venue_name = '';
    public string $venue_address = '';
    public string $city = '';
    public string $country = '';
    public string $postal_code = '';

    // ── Registration & Fees ────────────────────────────
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

    // ── Divisions ─────────────────────────────────────
    /** @var array<int, array{name: string, description: string}> */
    public array $divisions = [];

    public string $newDivisionName = '';
    public string $newDivisionDescription = '';

    // ── Rules & Settings ──────────────────────────────
    public string $rules = '';
    public string $schedule = '';
    public string $contact_email = '';
    public string $contact_phone = '';
    public bool $is_public = true;
    public bool $is_featured = false;

    public bool $saved = false;

    public function rules(): array
    {
        $baseRules = [
            'name' => 'required|string|max:255',
            'short_description' => 'nullable|string|max:500',
            'description' => 'nullable|string',
            'type' => 'required|in:tournament,league,camp,clinic,social,other',
            'status' => 'required|in:draft,published,registration_open,registration_closed,in_progress,completed,cancelled',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'venue_name' => 'nullable|string|max:255',
            'venue_address' => 'nullable|string',
            'city' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:3',
            'postal_code' => 'nullable|string|max:20',
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
            'contact_email' => 'nullable|email',
            'contact_phone' => 'nullable|string|max:30',
            'is_public' => 'boolean',
            'is_featured' => 'boolean',
            'content_language' => 'required|in:' . implode(',', ContentLanguage::values()),
        ];

        return array_merge($baseRules, $this->validatedTranslationRules());
    }

    /**
     * Return translation validation rules based on content_language.
     */
    protected function validatedTranslationRules(): array
    {
        if ($this->content_language !== 'de') {
            return [];
        }

        return [
            'name_de' => 'required|string|max:255',
            'short_description_de' => 'nullable|string|max:500',
            'description_de' => 'nullable|string',
            'rules_de' => 'nullable|string',
            'schedule_de' => 'nullable|string',
        ];
    }

    /**
     * Custom validation messages for DE translation fields.
     */
    public function translationMessages(): array
    {
        if ($this->content_language !== 'de') {
            return [];
        }

        return [
            'name_de.required' => 'The German name is required because this event\'s content language includes German.',
            'name_de.max' => 'The German name must not exceed 255 characters.',
            'short_description_de.max' => 'The German short description must not exceed 500 characters.',
        ];
    }

    public function mount(string $slug): void
    {
        $this->event = Event::where('slug', $slug)->firstOrFail();
        $this->authorize('update', $this->event);

        $this->fillFromEvent();
    }

    private function fillFromEvent(): void
    {
        $e = $this->event;
        $this->name = $e->name;
        $this->short_description = $e->short_description ?? '';
        $this->description = $e->description ?? '';
        $this->type = $e->type;
        $this->status = $e->status;
        $this->start_date = $e->start_date->format('Y-m-d');
        $this->end_date = $e->end_date->format('Y-m-d');
        $this->venue_name = $e->venue_name ?? '';
        $this->venue_address = $e->venue_address ?? '';
        $this->city = $e->city ?? '';
        $this->country = $e->country ?? '';
        $this->postal_code = $e->postal_code ?? '';
        $this->registration_type = $e->registration_type;
        $this->max_teams = $e->max_teams;
        $this->max_participants = $e->max_participants;
        $this->min_players_per_team = $e->min_players_per_team;
        $this->max_players_per_team = $e->max_players_per_team;
        $this->team_registration_fee = $e->team_registration_fee;
        $this->individual_registration_fee = $e->individual_registration_fee;
        $this->early_bird_discount = $e->early_bird_discount;
        $this->early_bird_deadline = $e->early_bird_deadline ? $e->early_bird_deadline->format('Y-m-d\TH:i') : '';
        $this->registration_opens_at = $e->registration_opens_at ? $e->registration_opens_at->format('Y-m-d\TH:i') : '';
        $this->registration_closes_at = $e->registration_closes_at ? $e->registration_closes_at->format('Y-m-d\TH:i') : '';
        $this->divisions = $e->divisions ?? [];
        $this->rules = is_array($e->rules) ? implode("\n", $e->rules) : ($e->rules ?? '');
        $this->schedule = is_array($e->schedule) ? implode("\n", $e->schedule) : ($e->schedule ?? '');
        $this->contact_email = $e->contact_email ?? '';
        $this->contact_phone = $e->contact_phone ?? '';
        $this->is_public = $e->is_public;
        $this->is_featured = $e->is_featured;

        // Load translation fields
        $this->content_language = $e->content_language ?? 'en';
        $this->name_de = $e->getTranslation('de', 'name') ?? '';
        $deShortDesc = $e->getTranslation('de', 'short_description');
        $this->short_description_de = $deShortDesc ?? '';
        $deDesc = $e->getTranslation('de', 'description');
        $this->description_de = $deDesc ?? '';
        $deRules = $e->getTranslation('de', 'rules');
        $this->rules_de = is_array($deRules) ? implode("\n", $deRules) : ($deRules ?? '');
        $deSchedule = $e->getTranslation('de', 'schedule');
        $this->schedule_de = is_array($deSchedule) ? implode("\n", $deSchedule) : ($deSchedule ?? '');
    }

    // ── Tab Navigation ────────────────────────────────

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function setLocaleTab(string $locale): void
    {
        $this->activeLocale = $locale;
    }

    // ── Division Management ───────────────────────────

    public function addDivision(): void
    {
        $this->validate([
            'newDivisionName' => 'required|string|max:100',
            'newDivisionDescription' => 'nullable|string|max:500',
        ]);

        $divisions = $this->divisions;
        $divisions[] = [
            'name' => $this->newDivisionName,
            'description' => $this->newDivisionDescription,
        ];
        $this->divisions = $divisions;

        $this->newDivisionName = '';
        $this->newDivisionDescription = '';
    }

    public function removeDivision(int $index): void
    {
        $divisions = $this->divisions;
        unset($divisions[$index]);
        $this->divisions = array_values($divisions);
    }

    // ── Save ──────────────────────────────────────────

    public function save(): void
    {
        $this->authorize('update', $this->event);
        $this->validate($this->rules(), $this->translationMessages());

        // Validate status transition if status changed
        $oldStatus = $this->event->getOriginal('status');
        if ($this->status !== $oldStatus && ! Event::isValidStatusTransition($oldStatus, $this->status)) {
            Log::warning('Invalid event status transition attempted', [
                'event_id' => $this->event->id,
                'from' => $oldStatus,
                'to' => $this->status,
                'user_id' => Auth::id(),
            ]);
            throw ValidationException::withMessages([
                'status' => __('events.error_cannot_change_event_status_from_from_to_to', ['from' => $oldStatus, 'to' => $this->status]),
            ]);
        }

        // Only global admins can change is_featured — non-admins keep the current value
        $isFeatured = $this->is_featured;
        if ($isFeatured !== (bool) $this->event->getOriginal('is_featured')) {
            $user = Auth::user();
            $isAdmin = $user && app(ScopedRoleService::class)->isGlobalAdmin($user);

            if (! $isAdmin) {
                Log::warning('Non-admin attempted to change is_featured', [
                    'user_id' => $user?->id,
                    'event_id' => $this->event->id,
                    'attempted_value' => $isFeatured,
                ]);
                $isFeatured = (bool) $this->event->getOriginal('is_featured');
            }
        }

        $parsedRules = $this->rules ? array_filter(array_map('trim', explode("\n", $this->rules))) : null;
        $parsedSchedule = $this->schedule ? array_filter(array_map('trim', explode("\n", $this->schedule))) : null;

        $this->event->update(array_filter([
            'name' => $this->name,
            'short_description' => $this->short_description ?: null,
            'description' => $this->description ?: null,
            'type' => $this->type,
            'status' => $this->status,
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
            'is_public' => $this->is_public,
            'is_featured' => $isFeatured,
        ], fn ($value) => $value !== null));

        // Persist German translations if content_language includes DE
        if ($this->content_language === 'de') {
            foreach (['name', 'short_description', 'description', 'rules', 'schedule'] as $field) {
                $deProperty = $field . '_de';
                $deValue = $this->$deProperty;
                if ($deValue !== '' && $deValue !== null) {
                    if (in_array($field, ['rules', 'schedule'])) {
                        $deValue = array_filter(array_map('trim', explode("\n", $deValue)));
                    }
                    $this->event->setTranslation('de', $field, $deValue);
                }
            }
        }

        Log::info('Event updated', [
            'event_id' => $this->event->id,
            'event_slug' => $this->event->slug,
            'updated_by' => Auth::id(),
            'status' => $this->status,
        ]);

        $this->saved = true;
    }

    // ── Status Transitions ────────────────────────────

    public function publishEvent(): void
    {
        $this->authorize('update', $this->event);

        $oldStatus = $this->event->getOriginal('status');
        if (! Event::isValidStatusTransition($oldStatus, 'published')) {
            Log::warning('Invalid event status transition attempted', [
                'event_id' => $this->event->id,
                'from' => $oldStatus,
                'to' => 'published',
                'user_id' => Auth::id(),
            ]);
            throw ValidationException::withMessages([
                'status' => __('events.error_cannot_publish_event_from_status_from', ['from' => $oldStatus]),
            ]);
        }

        $this->event->update(['status' => 'published']);
        $this->status = 'published';

        Log::info('Event published', [
            'event_id' => $this->event->id,
            'published_by' => Auth::id(),
        ]);

        session()->flash('success', __('events.flash_event_published'));
    }

    public function openRegistration(): void
    {
        $this->authorize('update', $this->event);

        $oldStatus = $this->event->getOriginal('status');
        if (! Event::isValidStatusTransition($oldStatus, 'registration_open')) {
            Log::warning('Invalid event status transition attempted', [
                'event_id' => $this->event->id,
                'from' => $oldStatus,
                'to' => 'registration_open',
                'user_id' => Auth::id(),
            ]);
            throw ValidationException::withMessages([
                'status' => __('events.error_cannot_open_registration_from_status_from', ['from' => $oldStatus]),
            ]);
        }

        $this->event->update([
            'status' => 'registration_open',
            'registration_opens_at' => $this->event->registration_opens_at ?? now(),
        ]);
        $this->status = 'registration_open';
        $this->registration_opens_at = $this->event->registration_opens_at->format('Y-m-d\TH:i');

        Log::info('Event registration opened', [
            'event_id' => $this->event->id,
            'opened_by' => Auth::id(),
        ]);

        session()->flash('success', __('events.content_registration_opened'));
    }

    public function closeRegistration(): void
    {
        $this->authorize('update', $this->event);

        $oldStatus = $this->event->getOriginal('status');
        if (! Event::isValidStatusTransition($oldStatus, 'registration_closed')) {
            Log::warning('Invalid event status transition attempted', [
                'event_id' => $this->event->id,
                'from' => $oldStatus,
                'to' => 'registration_closed',
                'user_id' => Auth::id(),
            ]);
            throw ValidationException::withMessages([
                'status' => __('events.error_cannot_close_registration_from_status_from', ['from' => $oldStatus]),
            ]);
        }

        $this->event->update(['status' => 'registration_closed']);
        $this->status = 'registration_closed';

        Log::info('Event registration closed', [
            'event_id' => $this->event->id,
            'closed_by' => Auth::id(),
        ]);

        session()->flash('success', __('events.flash_registration_closed'));
    }

    public function cancelEvent(): void
    {
        $this->authorize('update', $this->event);

        $oldStatus = $this->event->getOriginal('status');
        if (! Event::isValidStatusTransition($oldStatus, 'cancelled')) {
            Log::warning('Invalid event status transition attempted', [
                'event_id' => $this->event->id,
                'from' => $oldStatus,
                'to' => 'cancelled',
                'user_id' => Auth::id(),
            ]);
            throw ValidationException::withMessages([
                'status' => __('events.error_cannot_cancel_event_from_status_from', ['from' => $oldStatus]),
            ]);
        }

        $this->event->update(['status' => 'cancelled']);
        $this->status = 'cancelled';

        Log::info('Event cancelled', [
            'event_id' => $this->event->id,
            'cancelled_by' => Auth::id(),
        ]);

        session()->flash('success', __('events.flash_event_cancelled'));
    }

    public function render()
    {
        return view('livewire.events.manage-event');
    }
}
