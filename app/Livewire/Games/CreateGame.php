<?php

namespace App\Livewire\Games;

use App\Enums\ContentLanguage;
use App\Enums\ExperienceLevel;
use App\Enums\GameStatus;
use App\Enums\GameType;
use App\Enums\VibeFlag;
use App\Enums\Visibility;
use App\Models\Game;
use App\Models\GameSystem;
use App\Services\OwnerParticipantService;
use App\Services\ShortLinkService;
use App\Services\VenueTrustService;
use App\Traits\BuildsTranslatableFormFields;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/** @property-read bool $canCreatePublic */
#[Layout('layouts.app')]
class CreateGame extends Component
{
    use BuildsTranslatableFormFields;

    /** Optional query parameter: game ID to clone from */
    #[Url]
    public ?string $clone = null;

    public string $name = '';

    // ── Translatable fields ──
    /**
     * @return array<int, string>
     */
    public function getTranslatableFields(): array
    {
        return ['name', 'description'];
    }

    public ?string $game_type = null;

    public string $step = 'type';

    public ?string $game_system_id = null;

    /** @var array<int, string> Game systems for a Gathering (multi-select; the S01 saving event syncs game_system_id from [0]) */
    public array $game_systems = [];

    public ?string $host_note = null;

    public string $date_time = '';

    public string $description = '';

    public ?string $expected_duration = '';

    public ?string $price = '';

    public string $language = 'en';

    public ?string $location_id = null;

    public string $location_instructions = '';

    public string $visibility = 'protected';

    /** @var array<string, mixed> */
    public array $minimum_requirements = [];

    /** @var array<string, mixed> */
    public array $safety_rules = [];

    public ?int $min_players = null;

    public ?int $max_players = null;

    public ?string $experience_level = null;

    public ?string $complexity = null;

    /** @var array<int|string, mixed> VibeFlag value → null|'favorite'|'avoid', from VibePreferencePicker */
    public array $vibePreferences = [];

    public string $comfort_notes = '';

    public ?string $min_reliability_preference = null;

    public bool $bench_mode = false;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'name' => 'required|string|max:255',
            'game_type' => 'required|string|in:'.implode(',', GameType::values()),
            'game_system_id' => 'nullable|uuid|exists:game_systems,id',
            'game_systems' => 'nullable|array',
            'game_systems.*' => 'nullable|uuid|exists:game_systems,id',
            'host_note' => 'nullable|string|max:1000',
            'date_time' => 'required|date',
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
        ], $this->translatableValidationRules(
            ['name' => 'required|string|max:255', 'description' => 'nullable|string|max:5000'],
            $this->language,
        ));
    }

    // ── Event Listeners ──────────────────────────────────

    #[On('location-selected')]
    public function onLocationSelected(string $locationId, string $city, ?string $address = null): void
    {
        $this->location_id = $locationId;
    }

    #[On('location-removed')]
    public function onLocationRemoved(): void
    {
        $this->location_id = null;
    }

    #[On('location-instructions-updated')]
    public function onLocationInstructionsUpdated(string $instructions): void
    {
        $this->location_instructions = $instructions;
    }

    /**
     * @param  array<string, mixed>  $preferences
     */
    #[On('vibe-preferences-changed')]
    public function onVibePreferencesChanged(array $preferences): void
    {
        $this->vibePreferences = $preferences;
    }

    /**
     * @param  array<string, mixed>  $safetyRules
     */
    #[On('safety-tools-changed')]
    public function onSafetyToolsChanged(array $safetyRules): void
    {
        $this->safety_rules = $safetyRules;
    }

    #[On('value-updated')]
    public function onGameSystemPicked(mixed $value): void
    {
        $id = is_string($value) && Str::isUuid($value) ? $value : null;
        $this->game_system_id = $id;
        $this->autofillFromGameSystem($id);
    }

    /**
     * Multi-select game systems from the GameSystemPreferencePicker (creation
     * mode) — used by Gatherings. preferenceType is ignored here because the
     * creation picker has no favorites/avoids.
     *
     * @param  array<int, string>  $selectedIds
     */
    #[On('selection-changed')]
    public function onGameSystemsChanged(array $selectedIds): void
    {
        $this->game_systems = array_map('strval', $selectedIds);
    }

    // ── Lifecycle ─────────────────────────────────────────

    public function mount(): void
    {
        if ($this->clone === null || $this->clone === '') {
            return;
        }

        $source = Game::findOrFail($this->clone);

        // Only the owner can clone their own game
        if ($source->owner_id !== Auth::id()) {
            abort(403, __('games.error_clone_own_only'));
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
        $this->game_system_id = $source->game_system_id;
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

        // Pre-fill Gathering fields (multi-system picker + host note)
        $this->game_systems = array_map('strval', $source->game_systems ?? []);
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
    }

    // ── Type Selection Actions ───────────────────────────

    public function selectType(string $type): void
    {
        if (! in_array($type, GameType::values())) {
            return;
        }

        $this->game_type = $type;
        $this->step = 'form';
        $this->applyTypeDefaults($type);
    }

    public function changeType(string $type): void
    {
        if (! in_array($type, GameType::values())) {
            return;
        }

        $this->game_type = $type;
        // Reset type-specific fields when type changes
        $this->game_system_id = null;
        $this->game_systems = [];
        $this->host_note = null;
        $this->vibePreferences = [];
        $this->safety_rules = [];
        $this->comfort_notes = '';
        $this->experience_level = null;
        $this->complexity = null;
        $this->applyTypeDefaults($type);
    }

    // ── Lifecycle Hooks ──────────────────────────────────

    public function updatedGameSystemId(?string $id): void
    {
        $this->autofillFromGameSystem($id);
    }

    public function updatedExpectedDuration(): void
    {
        if ($this->expected_duration === '' || $this->expected_duration === null) {
            return;
        }

        $value = (float) $this->expected_duration;
        $rounded = round($value * 2) / 2;
        $this->expected_duration = (string) max($rounded, 0.5);
    }

    public function updatedMinPlayers(): void
    {
        $this->validatePlayerCounts();
    }

    public function updatedMaxPlayers(): void
    {
        $this->validatePlayerCounts();
    }

    // ── Computed ─────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function languageOptions(): array
    {
        $options = [];
        foreach (ContentLanguage::cases() as $case) {
            $options[$case->value] = __('common.label_language_'.$case->value);
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function gameTypeOptions(): array
    {
        $options = [];
        foreach (GameType::cases() as $case) {
            $options[$case->value] = __('games.type_'.$case->value);
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function experienceLevelOptions(): array
    {
        $options = ['' => __('discovery.content_any')];
        foreach (ExperienceLevel::cases() as $case) {
            $options[$case->value] = __('games.content_experience_'.$case->value);
        }

        return $options;
    }

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

    #[Computed]
    public function canCreatePublic(): bool
    {
        $user = authenticatedUser();

        return app(VenueTrustService::class)->canCreatePublic($user, $this->location_id);
    }

    #[Computed]
    public function publicViaVenue(): bool
    {
        if ($this->canCreatePublic && authenticatedUser()->can_create_public_entries) {
            return false; // GM — doesn't need venue indicator
        }

        return $this->canCreatePublic; // true only via venue bypass
    }

    // ── Actions ──────────────────────────────────────────

    public function save(): void
    {
        $this->authorize('create', Game::class);

        if ($this->game_type === null) {
            $this->addError('game_type', __('games.error_select_game_type'));

            return;
        }

        // Gate public visibility
        if ($this->visibility === 'public' && ! $this->canCreatePublic) {
            $this->visibility = 'private';
        }

        $validated = $this->validate();

        // Gatherings require at least one game system (the host picks what to
        // play). Enforced here rather than via a rule because it is conditional
        // on game_type. game_system_id is intentionally NOT set for gatherings —
        // the Game saving event (S01) derives it from game_systems[0].
        if ($this->game_type === 'gathering' && empty($validated['game_systems'])) {
            $this->addError('game_systems', __('Please select at least one game system.'));

            return;
        }

        // Cross-field validation after individual field validation
        if (
            isset($validated['min_players'], $validated['max_players'])
            && $validated['min_players'] > $validated['max_players']
        ) {
            $this->addError('min_players', __('games.error_min_players_cannot_exceed_max_players'));

            return;
        }

        // Extract favorite vibe flags for storage
        $vibeFlags = $this->selectedVibeFlags();

        // Handle safety data based on game type
        $safetyRules = $validated['safety_rules'] ?? null;
        if ($this->game_type === 'board_game') {
            $safetyRules = ! empty($this->comfort_notes) ? ['comfort_notes' => $this->comfort_notes] : null;
        }

        // Gate bench_mode to GM users only (defense-in-depth; UI disables toggle for non-GMs)
        $benchMode = $this->bench_mode;
        if ($benchMode && ! authenticatedUser()->isGM()) {
            Log::warning('Non-GM user attempted to enable bench_mode on game creation', [
                'user_id' => Auth::id(),
                'attempted_bench_mode' => true,
            ]);
            $benchMode = false;
        }

        // Gatherings are multi-system social sessions: force complexity/bench/
        // reliability clean so the warm form can't persist GM-complexity state.
        // The existing game_system_id line passes null for gatherings (no single
        // picker); the Game saving event (S01) then sets it from game_systems[0].
        $isGathering = $this->game_type === 'gathering';
        $complexity = $isGathering ? null : ($this->complexity ?: null);
        $minReliabilityPreference = $isGathering ? null : ($validated['min_reliability_preference'] ?: null);
        $benchMode = $isGathering ? false : $benchMode;

        // Canonical system set: the game_game_system pivot is the
        // source of truth for which systems this game offers. For a Gathering
        // the host picks a set via the multi-select; for a focused board_book /
        // ttrpg the single picker carries one system. The legacy
        // game_system_id anchor + game_systems JSON columns are still written
        // for the transition (retired in T06); the saving event keeps the
        // anchor synced to the set's first element.
        if ($isGathering) {
            $pivotSystemIds = array_map('strval', $validated['game_systems'] ?? []);
        } else {
            $pivotSystemIds = array_filter(
                [$validated['game_system_id'] ?? null],
                fn (?string $id): bool => $id !== null,
            );
        }

        // Build translatable values for name and description only
        $translatable = $this->buildTranslatableValues(
            ['name', 'description'],
            $validated['language'],
            $validated,
        );

        $game = DB::transaction(function () use ($validated, $translatable, $safetyRules, $vibeFlags, $benchMode, $complexity, $minReliabilityPreference, $pivotSystemIds) {
            $game = Game::create([
                'owner_id' => Auth::id(),
                'game_system_id' => $validated['game_system_id'],
                'game_systems' => $validated['game_systems'] ?? null,
                'host_note' => $validated['host_note'] ?? null,
                'name' => $translatable['name'],
                'game_type' => $validated['game_type'],
                'date_time' => $validated['date_time'],
                'description' => $translatable['description'],
                'expected_duration' => $validated['expected_duration'] ?: 2,
                'price' => $validated['price'] ?: 0,
                'language' => $validated['language'],
                'location_id' => $this->location_id,
                'location' => ['details' => ''],
                'location_instructions' => $validated['location_instructions'] ?? null,
                'status' => GameStatus::Scheduled,
                'visibility' => $validated['visibility'],
                'minimum_requirements' => $validated['minimum_requirements'] ?: null,
                'safety_rules' => $safetyRules,
                'min_players' => $validated['min_players'] ?? 2,
                'max_players' => $validated['max_players'] ?? 6,
                'experience_level' => $validated['experience_level'],
                'complexity' => $complexity,
                'vibe_flags' => ! empty($vibeFlags) ? $vibeFlags : null,
                'min_reliability_preference' => $minReliabilityPreference,
                'bench_mode' => $benchMode,
            ]);

            app(OwnerParticipantService::class)->ensureOwnerParticipant($game);

            // Sync the canonical pivot. Runs inside the create transaction so a
            // failure rolls the whole game back. empty() would detach everything,
            // so guard against an empty set (single-system games always have one).
            if (! empty($pivotSystemIds)) {
                $game->gameSystems()->sync($pivotSystemIds);
            }

            return $game;
        });

        $logContext = [
            'game_id' => $game->id,
            'name' => $game->name,
            'game_type' => $game->game_type?->value,
            'owner_id' => Auth::id(),
        ];

        if ($this->clone !== null && $this->clone !== '') {
            $logContext['source_game_id'] = $this->clone;
        }

        Log::info('Game created', $logContext);

        // Auto-generate short link for GMs
        if (authenticatedUser()->isGM()) {
            try {
                app(ShortLinkService::class)->createLink($game, authenticatedUser(), [
                    'label' => 'Default',
                    'expires_at' => now()->addDays(30),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to auto-generate short link for game', [
                    'game_id' => $game->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        session()->flash('success', __('games.flash_game_name_created_successfully', ['name' => $game->name]));

        $this->redirect(route('games.show', $game->id), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.games.create-game', [
            'isGM' => authenticatedUser()->isGM(),
        ]);
    }

    // ── Private Helpers ──────────────────────────────────

    protected function applyTypeDefaults(string $type): void
    {
        $this->expected_duration = match ($type) {
            'board_game' => '1.5',
            'ttrpg' => '3',
            default => '2',
        };

        // Gatherings are larger, warmer, all-welcome social sessions (R047):
        // a raised venue-size capacity default and an "all welcome" experience
        // level. Autofill never overrides these for gatherings because the
        // single-system picker (which drives autofill) is hidden on this branch.
        if ($type === 'gathering') {
            $this->max_players = 12;
            $this->experience_level = 'all';
        }
    }

    protected function autofillFromGameSystem(?string $id): void
    {
        if ($id === null) {
            return;
        }

        $system = GameSystem::find($id);
        if ($system === null) {
            return;
        }

        // Allow autofill to override type-default durations but not manual input
        $typeDefault = match ($this->game_type) {
            'board_game' => '1.5',
            'ttrpg' => '3',
            default => '',
        };

        if ($system->average_play_time && ($this->expected_duration === '' || $this->expected_duration === $typeDefault)) {
            $hours = $system->average_play_time / 60;
            $rounded = round($hours * 2) / 2;
            $this->expected_duration = (string) max($rounded, 0.5);
        }

        if ($system->min_players && $this->min_players === null) {
            $this->min_players = $system->min_players;
        }
        if ($system->max_players && $this->max_players === null) {
            $this->max_players = $system->max_players;
        }

        if ($system->bgg_average_weight && $this->complexity === null) {
            $this->complexity = (string) round((float) $system->bgg_average_weight, 2);
        }

        if ($this->experience_level === null && $system->bgg_average_weight) {
            $weight = (float) $system->bgg_average_weight;
            if ($weight <= 2.0) {
                $this->experience_level = 'beginner';
            } elseif ($weight <= 3.5) {
                $this->experience_level = 'intermediate';
            } else {
                $this->experience_level = 'advanced';
            }
        }
    }

    protected function validatePlayerCounts(): void
    {
        if (
            $this->min_players !== null
            && $this->max_players !== null
            && $this->min_players > $this->max_players
        ) {
            $this->addError('min_players', __('games.error_min_players_cannot_exceed_max_players'));
        }
    }

    /**
     * Extract favorite flags from the picker as a flat array for DB storage.
     * Validates against the VibeFlag enum to prevent tampering.
     *
     * @return array<int, string>
     */
    protected function selectedVibeFlags(): array
    {
        $validValues = VibeFlag::values();

        return collect($this->vibePreferences)
            ->filter(fn ($value) => $value === 'favorite')
            ->keys()
            ->filter(fn ($key) => in_array($key, $validValues, true))
            ->map(fn (mixed $k): string => (string) $k)
            ->values()
            ->all();
    }
}
