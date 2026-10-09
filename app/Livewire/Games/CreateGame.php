<?php

namespace App\Livewire\Games;

use App\Enums\ContentLanguage;
use App\Enums\ExperienceLevel;
use App\Enums\GameStatus;
use App\Enums\GameType;
use App\Enums\Visibility;
use App\Livewire\Concerns\BuildsSessionForm;
use App\Models\Event;
use App\Models\Game;
use App\Services\CreateDefaultsService;
use App\Services\SessionCreationService;
use App\Traits\BuildsTranslatableFormFields;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/** @property-read bool $canCreatePublic */
#[Layout('layouts.app')]
class CreateGame extends Component
{
    use BuildsSessionForm {
        BuildsSessionForm::getTranslatableFields insteadof BuildsTranslatableFormFields;
    }
    use BuildsTranslatableFormFields;

    // Livewire file-upload support for the host-uploaded cover image (S07).
    use WithFileUploads;

    /** Optional query parameter: game ID to clone from */
    #[Url]
    public ?string $clone = null;

    /** Optional query parameter: pre-selected game type (from the unified Plan flow) */
    #[Url]
    public ?string $type = null;

    /**
     * Optional query parameter: slug of the event this table is hosted at
     * (M063/S05 host-a-table flow, from an event's Manage → Tables tab).
     * Nothing on the form is pre-filled from it; event_id is attached on
     * save after re-authorization.
     */
    #[Url]
    public ?string $event = null;

    public ?string $game_type = null;

    public string $date_time = '';

    /**
     * Optional per-game signup cutoff (M057/S05 — decision D124). When this
     * time passes, new signups are blocked at all three participant-write
     * paths. Nullable string from a datetime-local input; cast to datetime on
     * the Game model. Empty = no cutoff (preserves current behavior).
     */
    public ?string $signup_cutoff_at = null;

    public ?string $expected_duration = '';

    public ?string $price = '';

    public string $comfort_notes = '';

    public ?string $min_reliability_preference = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'name' => 'required|string|max:255',
            'game_type' => 'required|string|in:'.implode(',', GameType::values()),
            'game_system_id' => 'nullable|uuid|exists:game_systems,id',
            'game_systems' => 'nullable|array|max:6',
            'game_systems.*' => 'required|uuid|exists:game_systems,id',
            'host_note' => 'nullable|string|max:1000',
            'date_time' => 'required|date',
            // The cutoff must be a date no later than the session start — a
            // cutoff after the session makes no semantic sense, and an
            // accidental past date would silently close signups at creation.
            'signup_cutoff_at' => 'nullable|date|before_or_equal:date_time',
            'description' => 'nullable|string|max:5000',
            'expected_duration' => 'nullable|numeric|min:0.5|max:24',
            'price' => 'nullable|numeric|min:0',
            'language' => 'required|string|in:'.implode(',', ContentLanguage::values()),
            'location_id' => 'nullable|uuid|exists:locations,id',
            'location_instructions' => 'nullable|string|max:1000',
            'visibility' => Visibility::validationRule(),
            'minimum_requirements' => 'nullable|array',
            'safety_rules' => 'nullable|array',
            'safety_rules.tools' => 'nullable|array',
            'safety_rules.tools.*' => 'nullable|string',
            'safety_rules.lines_and_veils_text' => 'nullable|string|max:2000',
            'safety_rules.custom_note' => 'nullable|string|max:1000',
            'min_players' => 'nullable|integer|min:1|max:99',
            'max_players' => 'required|integer|min:2|max:30',
            'experience_level' => 'nullable|string|in:'.implode(',', ExperienceLevel::values()),
            'comfort_notes' => 'nullable|string|max:1000',
            'min_reliability_preference' => 'nullable|numeric|min:0|max:100',
            'complexity' => 'nullable|numeric|min:0|max:5',
            'bench_mode' => 'boolean',
            // Host-uploaded cover (S07): the model's registerMediaCollections()
            // also enforces the jpeg/png/webp mime allow-list at storage time.
            // Max dimensions guard against decompression-bomb spikes during
            // Spatie's og/thumb conversions (defense-in-depth).
            'cover_image' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:5120', Rule::dimensions()->maxWidth(4096)->maxHeight(4096)],
        ], $this->translatableValidationRules(
            ['name' => 'required|string|max:255', 'description' => 'nullable|string|max:5000'],
            $this->language,
        ));
    }

    // ── Lifecycle ─────────────────────────────────────────

    public function mount(): void
    {
        // Host-a-table context (?event={slug}): the host must pass
        // EventPolicy::update to even see the form. Checked again in save() —
        // permission may have been revoked between load and submit.
        $hostingEvent = null;
        if ($this->event !== null && $this->event !== '') {
            $hostingEvent = $this->resolveHostingEvent();
            $this->authorize('update', $hostingEvent);
        }

        // If a type was pre-selected via ?type= (e.g. from the Plan flow),
        // auto-advance to the form with smart defaults applied.
        if (($this->clone === null || $this->clone === '') && $this->type !== null) {
            if (in_array($this->type, GameType::values(), true)) {
                $this->selectType($this->type);
            }

            $this->applyHostingEventVisibilityDefault($hostingEvent);

            return;
        }

        if ($this->clone === null || $this->clone === '') {
            $this->applyHostingEventVisibilityDefault($hostingEvent);

            return;
        }

        $source = Game::findOrFail($this->clone);

        // Only the owner can clone their own game — the rule lives in
        // GamePolicy::clone; the localized message stays at the surface.
        if (! authenticatedUser()->can('clone', $source)) {
            Gate::allowIf(false, __('games.error_clone_own_only'));
        }

        // Verify the user can still create games (permission may have been revoked)
        $this->authorize('create', Game::class);

        // Set game type and apply defaults first
        $this->game_type = $source->game_type->value ?? 'board_game';
        $this->step = 'form';
        $this->applyTypeDefaults($this->game_type);

        // Pre-fill all shared fields (NOT date_time — leave empty for user)
        $this->name = $source->name;
        $this->description = $source->description ?? '';
        // Clone the offered-system set from the canonical pivot (the legacy
        // game_system_id / game_systems columns were retired in S06/T06).
        $this->game_system_id = $source->gameSystems->first()?->id;
        $this->location_id = $source->location_id;
        $this->location_instructions = $source->location_instructions ?? '';
        $this->price = $source->price !== null ? (string) $source->price : '';
        $this->language = $source->language ?? 'en';
        $this->visibility = $source->visibility->value ?? 'protected';
        $this->min_players = $source->min_players;
        $this->max_players = $source->max_players;
        $this->experience_level = $source->experience_level;
        $this->complexity = $source->complexity !== null ? (string) $source->complexity : null;
        $this->expected_duration = (string) ($source->expected_duration ?? '');
        $this->min_reliability_preference = $source->min_reliability_preference !== null
            ? (string) $source->min_reliability_preference
            : null;

        // Pre-fill Gathering fields (multi-system picker + host note).
        // Read the offered set from the belongsToMany pivot.
        $this->game_systems = $source->gameSystems
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id))
            ->map(fn (string $id): string => $id)
            ->values()
            ->all();
        $this->host_note = $source->host_note;

        // Load vibe_flags into vibePreferences array (flat DB array → favorite map)
        if (! empty($source->vibe_flags)) {
            foreach ((array) $source->vibe_flags as $flag) {
                $this->vibePreferences[$flag] = 'favorite';
            }
        }

        // Load safety_rules based on game type
        if (! empty($source->safety_rules)) {
            if (($source->game_type->value ?? 'board_game') === 'board_game') {
                // Board games store comfort_notes in safety_rules JSON
                $rawNotes = $source->safety_rules['comfort_notes'] ?? '';
                $this->comfort_notes = is_string($rawNotes) ? $rawNotes : '';
            } else {
                // TTRPG uses safety_rules directly
                $this->safety_rules = $source->safety_rules;
            }
        }

        Log::info('Game clone initiated', [
            'source_game_id' => $source->id,
            'user_id' => Auth::id(),
        ]);

        // Cloning into a host-a-table context follows the same public-event
        // visibility rule as a fresh table.
        if ($this->event !== null && $this->event !== '') {
            $this->applyHostingEventVisibilityDefault($this->resolveHostingEvent());
        }
    }

    // ── Lifecycle Hooks ──────────────────────────────────

    public function updatedExpectedDuration(): void
    {
        if ($this->expected_duration === '' || $this->expected_duration === null) {
            return;
        }

        $value = (float) $this->expected_duration;
        $rounded = round($value * 2) / 2;
        $this->expected_duration = (string) max($rounded, 0.5);
    }

    // ── Computed ─────────────────────────────────────────

    /**
     * @return array<int|string, string>
     */
    #[Computed]
    public function attendanceToleranceOptions(): array
    {
        return [
            '' => (string) __('games.content_attendance_any'),
            '70' => (string) __('games.content_attendance_relaxed'),
            '85' => (string) __('games.content_attendance_moderate'),
            '95' => (string) __('games.content_attendance_strict'),
        ];
    }

    // ── Actions ──────────────────────────────────────────

    /**
     * The event this table will be hosted at, when the ?event={slug}
     * context is present. Null on the regular creation path.
     */
    #[Computed]
    public function hostingEvent(): ?Event
    {
        if ($this->event === null || $this->event === '') {
            return null;
        }

        return Event::where('slug', $this->event)->first();
    }

    /**
     * Resolve the host-a-table context (?event={slug}) to its Event.
     *
     * Events keep their default `id` route key (see Location's
     * getRouteKeyName note) and are resolved manually by slug.
     */
    protected function resolveHostingEvent(): ?Event
    {
        if ($this->event === null || $this->event === '') {
            return null;
        }

        return Event::where('slug', $this->event)->firstOrFail();
    }

    /**
     * Tables hosted at a public get-together default to public visibility:
     * the event page is a public surface and its join CTA routes through the
     * game's detail page, so the old 'protected' default produced tables most
     * event guests (and the event's own organizer) could not open. save()
     * still guards the case where the host re-selects a restricted option.
     */
    protected function applyHostingEventVisibilityDefault(?Event $hostingEvent): void
    {
        if ($hostingEvent !== null && $hostingEvent->is_public) {
            $this->visibility = Visibility::Public->value;
        }
    }

    public function save(): void
    {
        $this->authorize('create', Game::class);

        // Re-resolve and re-authorize the host-a-table context on save:
        // permission may have been revoked since form load, and the #[Url]
        // param can change between load and submit. An event deleted mid-form
        // 404s here (fail-closed) rather than silently creating a standalone
        // table under a vanished umbrella.
        $hostingEvent = $this->resolveHostingEvent();
        if ($hostingEvent !== null) {
            $this->authorize('update', $hostingEvent);

            // Lifecycle gate (M063/S05/T03): tables attach only while the
            // umbrella is published / registration_open (Event::canHostTables).
            // A cancelled, completed, closed, or still-draft event rejects
            // the attach with a clear validation error instead of silently
            // creating a standalone table or linking under a dead umbrella.
            if (! $hostingEvent->canHostTables()) {
                throw ValidationException::withMessages([
                    'event' => __('events.error_event_not_accepting_tables'),
                ]);
            }

            // Visibility guard (M063 follow-up): a table at a PUBLIC event must
            // stay publicly visible — the event page advertises it and links
            // here, so a restricted table would 403 for the guests it invites.
            // Non-public (unlisted) events may keep restricted tables.
            if ($hostingEvent->is_public && $this->visibility !== Visibility::Public->value) {
                throw ValidationException::withMessages([
                    'visibility' => __('events.error_event_table_visibility_public'),
                ]);
            }
        }

        if ($this->guardGameTypeSelected()) {
            return;
        }

        // Gate public visibility. Standalone games need venue trust; a table at
        // a public get-together is exempt — the umbrella event is itself
        // public, and its page routes guests to this table's detail page.
        if ($this->visibility === 'public'
            && ! $this->canCreatePublic
            && ! ($hostingEvent !== null && $hostingEvent->is_public)) {
            $this->visibility = 'private';
        }

        $validated = $this->validate();

        if ($this->guardCrossFieldSessionRules($validated)) {
            return;
        }

        // Handle safety data based on game type
        $safetyRules = $validated['safety_rules'] ?? null;
        if ($this->game_type === 'board_game') {
            $safetyRules = ! empty($this->comfort_notes) ? ['comfort_notes' => $this->comfort_notes] : null;
        }

        $game = app(SessionCreationService::class)->create(
            authenticatedUser(),
            'game',
            [
                'validated' => $validated,
                'translatable' => $this->buildTranslatableValues(
                    ['name', 'description'],
                    $validated['language'],
                    $validated,
                ),
                'safety_rules' => $safetyRules,
                'vibe_flags' => $this->selectedVibeFlags(),
                'bench_mode' => $this->bench_mode,
                'complexity' => $this->complexity ?: null,
                'min_reliability_preference' => $validated['min_reliability_preference'] ?: null,
                'cover_image' => $this->cover_image,
            ],
            makeModel: function (array $context) use ($hostingEvent): Game {
                $game = Game::create([
                    'owner_id' => $context['owner_id'],
                    'host_note' => $context['validated']['host_note'] ?? null,
                    'name' => $context['translatable']['name'],
                    'game_type' => $context['validated']['game_type'],
                    'date_time' => $context['validated']['date_time'],
                    'signup_cutoff_at' => $context['validated']['signup_cutoff_at'] ?: null,
                    'description' => $context['translatable']['description'],
                    'expected_duration' => $context['validated']['expected_duration'] ?: 2,
                    'price' => $context['validated']['price'] ?: 0,
                    'language' => $context['validated']['language'],
                    'location_id' => $this->location_id,
                    'location' => ['details' => ''],
                    'location_instructions' => $context['validated']['location_instructions'] ?? null,
                    'status' => GameStatus::Scheduled,
                    'visibility' => $context['validated']['visibility'],
                    'minimum_requirements' => $context['validated']['minimum_requirements'] ?: null,
                    'safety_rules' => $context['safety_rules'],
                    'min_players' => $context['validated']['min_players'] ?? 2,
                    'max_players' => $context['validated']['max_players'] ?? 6,
                    'experience_level' => $context['validated']['experience_level'],
                    'complexity' => $context['complexity'],
                    'vibe_flags' => ! empty($context['vibe_flags']) ? $context['vibe_flags'] : null,
                    'min_reliability_preference' => $context['min_reliability_preference'],
                    'bench_mode' => $context['bench_mode'],
                ]);

                // Host-a-table context: link the new table to its umbrella
                // event via the relation API (associate(), not a raw event_id
                // write — Eloquent baseline R2). Detach-never-destroy semantics
                // live on the FK (nullOnDelete); attaching here only ever sets
                // the link.
                if ($hostingEvent !== null) {
                    $game->event()->associate($hostingEvent);
                    $game->save();
                }

                return $game;
            },
            afterSync: function (Game $game) use ($hostingEvent): void {
                // M063/S06/T02: the pivot sync fires no model events, so the
                // umbrella's derived offered-systems cache (aggregated across
                // tables) is flushed here. GameObserver::saved already flushed on
                // the associate+save above, but THIS sync is what changes the
                // union — a concurrent read between the two would otherwise pin
                // a stale offering for the cache TTL.
                if ($hostingEvent !== null) {
                    $hostingEvent->flushOfferedSystemsCache();
                }
            },
            onCreated: function (Game $game) use ($hostingEvent): void {
                $logContext = [
                    'game_id' => $game->id,
                    'name' => $game->name,
                    'game_type' => $game->game_type?->value,
                    'owner_id' => Auth::id(),
                    'event_id' => $hostingEvent?->id,
                ];

                if ($this->clone !== null && $this->clone !== '') {
                    $logContext['source_game_id'] = $this->clone;
                }

                Log::info('Game created', $logContext);
            },
        );

        session()->flash('success', __('games.flash_game_name_created_successfully', ['name' => $game->name]));

        $this->redirect(route('games.show', $game), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.games.create-game', [
            'isGM' => authenticatedUser()->isGM(),
        ]);
    }

    // ── Private Helpers ──────────────────────────────────

    /**
     * Layer smart defaults from the user's last session of the same type
     * and profile preferences on top of the bare type defaults.
     *
     * Only fills fields that are still at their type-default value; clone
     * data (which runs after mount via a separate path) is never affected.
     */
    protected function applySmartDefaults(string $type): void
    {
        $user = Auth::user();
        if ($user === null) {
            return;
        }

        $gameType = GameType::from($type);
        $defaults = app(CreateDefaultsService::class)->forGameType($user, $gameType);

        // Language: prefer last session, then profile preference, then 'en'.
        $this->language = $defaults->language ?? 'en';

        // Location + instructions travel forward.
        $this->location_id = $defaults->locationId;
        $this->location_instructions = $defaults->locationInstructions ?? '';

        // Visibility from last session (default 'protected' if none).
        $this->visibility = $defaults->visibility ?? 'protected';

        // Experience level — but gatherings keep their 'all' default.
        if ($type !== 'gathering' && $defaults->experienceLevel !== null) {
            $this->experience_level = $defaults->experienceLevel;
        }

        // Duration: override the type default only if last session had one.
        if ($defaults->expectedDuration !== null) {
            $this->expected_duration = $defaults->expectedDuration;
        }

        // Seat counts — but gatherings keep their 12-player default.
        if ($type !== 'gathering') {
            if ($defaults->maxPlayers !== null) {
                $this->max_players = $defaults->maxPlayers;
            }
            if ($defaults->minPlayers !== null) {
                $this->min_players = $defaults->minPlayers;
            }
        }

        // Vibe flags from last session.
        if ($defaults->vibeFlags !== null) {
            foreach ($defaults->vibeFlags as $flag) {
                $this->vibePreferences[$flag] = 'favorite';
            }
        }

        // Offered-system set: gatherings get the multi-select set carried
        // forward; focused types get the single system.
        if ($type === 'gathering' && ! empty($defaults->gameSystems)) {
            $this->game_systems = $defaults->gameSystems;
        } elseif ($defaults->gameSystemId !== null) {
            $this->game_system_id = $defaults->gameSystemId;
        }
    }

    // ── BuildsSessionForm hooks ──────────────────────────

    protected function sessionDuration(): ?string
    {
        return $this->expected_duration;
    }

    protected function setSessionDuration(string $value): void
    {
        $this->expected_duration = $value;
    }

    protected function afterTypeSelected(string $type): void
    {
        $this->applySmartDefaults($type);
    }

    protected function resetTypeSpecificState(): void
    {
        $this->comfort_notes = '';
    }
}
