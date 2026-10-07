<?php

namespace App\Livewire\Events;

use App\Enums\ContentLanguage;
use App\Enums\EventType;
use App\Models\Event;
use App\Models\Game;
use App\Models\User;
use App\Services\EventDelegationService;
use App\Services\EventLifecycleService;
use App\Services\ScopedRoleService;
use App\Traits\BuildsTranslatableFormFields;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ManageEvent extends Component
{
    use BuildsTranslatableFormFields;

    public Event $event;

    public ?string $confirmingAction = null;

    // ── Tab tracking ──────────────────────────────────
    public string $activeTab = 'details';

    // ── Content language ──────────────────────────────
    public string $language = 'en';

    // ── Basic Info ────────────────────────────────────
    public string $name = '';

    public string $short_description = '';

    public string $description = '';

    public string $type = 'game_day';

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
    public ?int $max_participants = null;

    public ?int $individual_registration_fee = null;

    public ?int $early_bird_discount = null;

    public string $early_bird_deadline = '';

    /** Paddle Billing price id backing the one-time ticket checkout (metadata.paddle_price_id). */
    public ?string $paddle_price_id = null;

    public string $registration_opens_at = '';

    public string $registration_closes_at = '';

    // ── Team (co-organizer delegation, M063/S04) ──────
    /** Invite input: a username (profile slug) or email address. */
    public string $coOrganizerInvite = '';

    // ── Rules & Settings ──────────────────────────────
    public string $rules = '';

    public string $schedule = '';

    public string $contact_email = '';

    public string $contact_phone = '';

    public bool $is_public = true;

    public bool $is_featured = false;

    public bool $saved = false;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $baseRules = [
            'name' => 'required|string|max:255',
            'short_description' => 'nullable|string|max:500',
            'description' => 'nullable|string',
            'type' => 'required|in:'.implode(',', EventType::values()),
            'status' => 'required|in:draft,published,registration_open,registration_closed,in_progress,completed,cancelled',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'venue_name' => 'nullable|string|max:255',
            'venue_address' => 'nullable|string',
            'city' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:3',
            'postal_code' => 'nullable|string|max:20',
            'max_participants' => 'nullable|integer|min:1',
            'individual_registration_fee' => 'nullable|integer|min:0',
            'early_bird_discount' => 'nullable|integer|min:0',
            'early_bird_deadline' => 'nullable|date',
            'paddle_price_id' => ['nullable', 'string', 'max:255', 'starts_with:pri_'],
            'registration_opens_at' => 'nullable|date',
            'registration_closes_at' => 'nullable|date|after:registration_opens_at',
            'contact_email' => 'nullable|email',
            'contact_phone' => 'nullable|string|max:30',
            'is_public' => 'boolean',
            'is_featured' => 'boolean',
            'language' => 'required|in:'.implode(',', ContentLanguage::values()),
        ];

        return array_merge(
            $baseRules,
            $this->translatableValidationRules(
                ['name' => 'required|string|max:255', 'short_description' => 'nullable|string|max:500', 'description' => 'nullable|string'],
                $this->language,
            ),
        );
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
        $this->type = $e->type->value ?? 'game_day';
        $this->status = $e->status->value ?? 'draft';
        $this->start_date = $e->start_date?->format('Y-m-d') ?? '';
        $this->end_date = $e->end_date?->format('Y-m-d') ?? '';
        $this->venue_name = $e->venue_name ?? '';
        $this->venue_address = $e->venue_address ?? '';
        $this->city = $e->city ?? '';
        $this->country = $e->country ?? '';
        $this->postal_code = $e->postal_code ?? '';
        $this->max_participants = $e->max_participants;
        $this->individual_registration_fee = $e->individual_registration_fee;
        $this->early_bird_discount = $e->early_bird_discount;
        $this->early_bird_deadline = $e->early_bird_deadline ? $e->early_bird_deadline->format('Y-m-d\TH:i') : '';
        $this->paddle_price_id = $e->paddle_price_id;
        $this->registration_opens_at = $e->registration_opens_at ? $e->registration_opens_at->format('Y-m-d\TH:i') : '';
        $this->registration_closes_at = $e->registration_closes_at ? $e->registration_closes_at->format('Y-m-d\TH:i') : '';
        /** @var array<int, string>|string|null $rules */
        $rules = $e->rules;
        $this->rules = is_array($rules) ? implode("\n", $rules) : (string) ($rules ?? '');
        /** @var array<int, string>|string|null $schedule */
        $schedule = $e->schedule;
        $this->schedule = is_array($schedule) ? implode("\n", $schedule) : (string) ($schedule ?? '');
        $this->contact_email = $e->contact_email ?? '';
        $this->contact_phone = $e->contact_phone ?? '';
        $this->is_public = $e->is_public ?? false;
        $this->is_featured = $e->is_featured ?? false;

        // Content language
        $this->language = $e->language ?? 'en';

        // Load secondary locale translations via trait
        $this->loadTranslatableValues($e, ['name', 'description', 'short_description']);
    }

    /**
     * @return array<int, string>
     */
    public function getTranslatableFields(): array
    {
        return ['name', 'description', 'short_description'];
    }

    // ── Tab Navigation ────────────────────────────────

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    /**
     * Normalize the Paddle price id as it is typed: trim whitespace and
     * collapse an emptied field to null so validation and save() see a
     * clean nullable string instead of "".
     */
    public function updatedPaddlePriceId(?string $value): void
    {
        $trimmed = trim((string) $value);
        $this->paddle_price_id = $trimmed !== '' ? $trimmed : null;
    }

    // ── Team (Co-Organizer Delegation) ────────────────

    /**
     * Current co-organizers, each carrying a granted_at attribute
     * (Carbon|null) powering the Team tab's granted-at column.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function coOrganizers(): Collection
    {
        return app(EventDelegationService::class)->coOrganizers($this->event);
    }

    /**
     * Grant co-organizer access to the user resolved from the invite
     * input (username = profile slug, or email address).
     *
     * Validation order mirrors the slice contract: user must exist,
     * not be disabled, not already be delegated, and not be the
     * organizer. The grant itself (and its notification, D156) is
     * owned by EventDelegationService.
     */
    public function inviteCoOrganizer(): void
    {
        $this->authorize('update', $this->event);

        $input = trim($this->coOrganizerInvite);

        if ($input === '') {
            $this->addError('coOrganizerInvite', __('validation.required'));

            return;
        }

        $target = User::query()
            ->whereNull('anonymized_at')
            ->where(function (Builder $query) use ($input) {
                $query->where('email', strtolower($input))
                    ->orWhere('slug', $input);
            })
            ->first();

        if ($target === null) {
            $this->addError('coOrganizerInvite', __('events.error_no_user_found_with_that_username_or_email'));

            return;
        }

        if ($target->is_disabled) {
            $this->addError('coOrganizerInvite', __('events.error_this_account_is_disabled'));

            return;
        }

        if ((string) $target->id === (string) $this->event->organizer_id) {
            $this->addError('coOrganizerInvite', __('events.error_the_organizer_already_manages_this_event'));

            return;
        }

        $delegation = app(EventDelegationService::class);

        if ($delegation->isCoOrganizer($target, $this->event)) {
            $this->addError('coOrganizerInvite', __('events.error_user_is_already_a_co_organizer', ['name' => $target->name]));

            return;
        }

        try {
            // D156: the grant is silent and immediate — the service
            // assigns the scoped role and notifies the target itself.
            $delegation->grantCoOrganizer($this->event, $target, authenticatedUser());
        } catch (AuthorizationException) {
            // Passed EventPolicy::update (a co-organizer) but lacks
            // delegation authority — no cascade.
            $this->addError('coOrganizerInvite', __('events.error_only_the_organizer_or_a_global_admin'));

            return;
        }

        $this->reset('coOrganizerInvite');
        session()->flash('success', __('events.flash_co_organizer_added', ['name' => $target->name]));
    }

    /**
     * Revoke a co-organizer's event-scoped access. The listing never
     * offers the organizer for revocation and the service refuses it,
     * so a stale request targeting them is dropped silently.
     */
    public function revokeCoOrganizer(string $userId): void
    {
        $this->authorize('update', $this->event);

        $target = User::find($userId);

        if ($target === null || (string) $target->id === (string) $this->event->organizer_id) {
            return;
        }

        try {
            app(EventDelegationService::class)->revokeCoOrganizer($this->event, $target, authenticatedUser());
        } catch (AuthorizationException) {
            session()->flash('error', __('events.error_only_the_organizer_or_a_global_admin'));

            return;
        }

        session()->flash('success', __('events.flash_co_organizer_revoked', ['name' => $target->name]));
    }

    // ── Tables (host-a-table, M063/S05) ────────────────

    /**
     * Tables (Games) hosted at this event, with everything the Tables tab
     * rows render (host, system chips, seat state) eager-loaded to keep the
     * tab at one query plus its eager loads.
     *
     * @return Collection<int, Game>
     */
    #[Computed]
    public function tables(): Collection
    {
        return $this->event->tables()
            ->with(['owner', 'gameSystems', 'participants'])
            ->get();
    }

    /**
     * Detach a table from this event: games.event_id is set to null and the
     * game itself — its participants, systems, and status — is untouched
     * (R059 detach-never-destroy semantics).
     */
    public function detachTable(string $gameId): void
    {
        $this->authorize('update', $this->event);

        // Relation-scoped lookup: only a table actually hosted at THIS
        // event can be detached from it — a stale request naming a game
        // under another umbrella (or none) 404s instead of detaching.
        $game = $this->event->tables()->findOrFail($gameId);

        $name = $game->name;
        $game->event()->dissociate();
        $game->save();

        Log::info('Table detached from event', [
            'game_id' => $game->id,
            'event_id' => $this->event->id,
            'detached_by' => Auth::id(),
        ]);

        session()->flash('success', __('events.flash_table_detached', ['name' => $name]));
    }

    // ── Save ──────────────────────────────────────────

    public function save(): void
    {
        $this->authorize('update', $this->event);
        $this->validate($this->rules());

        // Validate status transition if status changed
        $oldStatusValue = $this->resolveStatusString($this->event->getOriginal('status'));
        if ($this->status !== $oldStatusValue && ! Event::isValidStatusTransition($oldStatusValue, $this->status)) {
            Log::warning('Invalid event status transition attempted', [
                'event_id' => $this->event->id,
                'from' => $oldStatusValue,
                'to' => $this->status,
                'user_id' => Auth::id(),
            ]);
            throw ValidationException::withMessages([
                'status' => __('events.error_cannot_change_event_status_from_from_to_to', ['from' => $oldStatusValue, 'to' => $this->status]),
            ]);
        }

        // Only global admins can change is_featured — non-admins keep the current value
        $isFeatured = $this->is_featured;
        if ($isFeatured !== (bool) $this->event->getOriginal('is_featured')) {
            $user = authenticatedUser();
            $isAdmin = app(ScopedRoleService::class)->isGlobalAdmin($user);

            if (! $isAdmin) {
                Log::warning('Non-admin attempted to change is_featured', [
                    'user_id' => $user->id,
                    'event_id' => $this->event->id,
                    'attempted_value' => $isFeatured,
                ]);
                $isFeatured = (bool) $this->event->getOriginal('is_featured');
            }
        }

        $parsedRules = $this->rules ? array_filter(array_map('trim', explode("\n", $this->rules))) : null;
        $parsedSchedule = $this->schedule ? array_filter(array_map('trim', explode("\n", $this->schedule))) : null;

        // Ticket payment: persist the Paddle price id through the Event
        // accessor/mutator (metadata.paddle_price_id). Assigned before
        // update() so it lands in the same save; only touched when the value
        // actually changed so unrelated metadata keys (and a null metadata
        // column) are never rewritten needlessly. Clearing maps to removing
        // the key, which is why this cannot ride through array_filter below.
        $paddlePriceId = filled($this->paddle_price_id) ? $this->paddle_price_id : null;
        if ($this->event->paddle_price_id !== $paddlePriceId) {
            $this->event->paddle_price_id = $paddlePriceId;
        }

        $translatable = $this->buildTranslatableValues(
            ['name', 'description', 'short_description'],
            $this->language,
            ['name' => $this->name, 'description' => $this->description, 'short_description' => $this->short_description],
        );

        $updateData = array_filter([
            'name' => $translatable['name'],
            'short_description' => $translatable['short_description'],
            'description' => $translatable['description'],
            'type' => $this->type,
            'status' => $this->status,
            'language' => $this->language,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'venue_name' => $this->venue_name ?: null,
            'venue_address' => $this->venue_address ?: null,
            'city' => $this->city ?: null,
            'country' => $this->country ?: null,
            'postal_code' => $this->postal_code ?: null,
            'max_participants' => $this->max_participants,
            'individual_registration_fee' => $this->individual_registration_fee,
            'early_bird_discount' => $this->early_bird_discount,
            'early_bird_deadline' => $this->early_bird_deadline ?: null,
            'registration_opens_at' => $this->registration_opens_at ?: null,
            'registration_closes_at' => $this->registration_closes_at ?: null,
            'rules' => $parsedRules,
            'schedule' => $parsedSchedule,
            'contact_email' => $this->contact_email ?: null,
            'contact_phone' => $this->contact_phone ?: null,
            'is_public' => $this->is_public,
            'is_featured' => $isFeatured,
        ], fn ($value) => $value !== null);

        // A cancellation submitted through the status <select> routes through
        // the unified lifecycle service (like the Cancel action below): the
        // service owns the transition and notifies active registrants exactly
        // once. Status is stripped from this write because the service's
        // already-cancelled guard must see the transition still pending.
        $cancellingViaForm = $this->status === 'cancelled' && $oldStatusValue !== 'cancelled';
        if ($cancellingViaForm) {
            unset($updateData['status']);
        }

        $this->event->update($updateData);

        if ($cancellingViaForm) {
            app(EventLifecycleService::class)->cancel($this->event);
        }

        Log::info('Event updated', [
            'event_id' => $this->event->id,
            'event_slug' => $this->event->slug,
            'updated_by' => Auth::id(),
            'status' => $this->status,
            'paddle_price_id' => $paddlePriceId,
        ]);

        $this->saved = true;
    }

    // ── Status Transitions ────────────────────────────

    public function publishEvent(): void
    {
        $this->authorize('update', $this->event);

        $oldStatus = $this->resolveStatusString($this->event->getOriginal('status'));
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

        $oldStatus = $this->resolveStatusString($this->event->getOriginal('status'));
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
        $this->registration_opens_at = $this->event->registration_opens_at?->format('Y-m-d\TH:i') ?? '';

        Log::info('Event registration opened', [
            'event_id' => $this->event->id,
            'opened_by' => Auth::id(),
        ]);

        session()->flash('success', __('events.content_registration_opened'));
    }

    public function closeRegistration(): void
    {
        $this->authorize('update', $this->event);

        $oldStatus = $this->resolveStatusString($this->event->getOriginal('status'));
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

        $oldStatus = $this->resolveStatusString($this->event->getOriginal('status'));
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

        app(EventLifecycleService::class)->cancel($this->event);
        $this->status = 'cancelled';

        Log::info('Event cancelled', [
            'event_id' => $this->event->id,
            'cancelled_by' => Auth::id(),
        ]);

        session()->flash('success', __('events.flash_event_cancelled'));
    }

    /**
     * Resolve a status value (which may be a BackedEnum from getOriginal()
     * or a string from Livewire) to its string value for comparison and logging.
     */
    private function resolveStatusString(mixed $status): string
    {
        return $status instanceof \BackedEnum ? (string) $status->value : (is_string($status) ? $status : '');
    }

    public function render(): View
    {
        return view('livewire.events.manage-event');
    }
}
