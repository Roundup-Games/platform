<?php

namespace App\Livewire\Concerns;

use App\Enums\ContentLanguage;
use App\Enums\ExperienceLevel;
use App\Enums\GameType;
use App\Enums\VibeFlag;
use App\Models\GameSystem;
use App\Services\VenueTrustService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;

/**
 * Shared form machinery for the twin session-creation Livewire forms
 * (Games\CreateGame and Campaigns\CreateCampaign): the offered-system
 * pickers, location/vibe/safety listeners, type-selection flow, computed
 * option lists, and per-type defaults.
 *
 * The few members that genuinely differ between the twins are exposed as
 * small overridable hooks instead of duplicated switch logic:
 *
 * - sessionDuration()/setSessionDuration(): the duration field is named
 *   expected_duration on games and session_duration on campaigns.
 * - afterTypeSelected(): games layer smart defaults after type selection;
 *   campaigns apply theirs in mount() instead.
 * - resetTypeSpecificState(): games additionally clear comfort_notes on a
 *   type switch (campaigns have no such field).
 */
trait BuildsSessionForm
{
    // ── Shared form state ────────────────────────────────
    // These public properties are identical on both twins (same name, type,
    // and default). Livewire synthesizes trait-level public properties the
    // same as class-level ones (see Livewire's own WithPagination::$page).

    /** @var array<int, string> Game systems for a Gathering (multi-select; save() syncs them to the gameSystems pivot directly) */
    public array $game_systems = [];

    public ?string $game_system_id = null;

    public string $step = 'type';

    public ?string $host_note = null;

    public ?string $location_id = null;

    public string $location_instructions = '';

    public string $description = '';

    public string $language = 'en';

    public string $visibility = 'protected';

    /** @var array<string, mixed> */
    public array $minimum_requirements = [];

    /** @var array<string, mixed> */
    public array $safety_rules = [];

    public ?int $min_players = null;

    public ?int $max_players = null;

    public ?string $experience_level = null;

    public ?string $complexity = null;

    /** @var array<string, string|null> VibeFlag value → null|'favorite'|'avoid', from VibePreferencePicker */
    public array $vibePreferences = [];

    public bool $bench_mode = false;

    /**
     * Optional host-uploaded cover image (S07). Stored to the Spatie 'cover'
     * media collection after create via addMedia()->toMediaCollection('cover').
     */
    public ?UploadedFile $cover_image = null;

    public string $name = '';

    // ── Translatable fields ──

    /**
     * @return array<int, string>
     */
    public function getTranslatableFields(): array
    {
        return ['name', 'description'];
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
     * @param  array<string, string|null>  $preferences
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

    // ── Type Selection Actions ───────────────────────────

    public function selectType(string $type): void
    {
        if (! in_array($type, GameType::values(), true)) {
            return;
        }

        $this->game_type = $type;
        $this->step = 'form';
        $this->applyTypeDefaults($type);
        $this->afterTypeSelected($type);
    }

    public function changeType(string $type): void
    {
        if (! in_array($type, GameType::values(), true)) {
            return;
        }

        $this->game_type = $type;
        // Reset type-specific fields when the type changes
        $this->game_system_id = null;
        $this->game_systems = [];
        $this->host_note = null;
        $this->vibePreferences = [];
        $this->safety_rules = [];
        $this->resetTypeSpecificState();
        $this->experience_level = null;
        $this->complexity = null;
        $this->applyTypeDefaults($type);
    }

    // ── Lifecycle Hooks ──────────────────────────────────

    public function updatedGameSystemId(?string $id): void
    {
        $this->autofillFromGameSystem($id);
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
            $options[$case->value] = $case->label();
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
            $options[$case->value] = $case->label();
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
            $options[$case->value] = $case->label();
        }

        return $options;
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

    // ── Save guards (Livewire-owned validation feedback) ──

    /**
     * Both twins refuse to save without a selected game type (the type step
     * can be skipped via deep links). Returns true when save() must abort.
     */
    protected function guardGameTypeSelected(): bool
    {
        if ($this->game_type === null) {
            $this->addError('game_type', __('games.error_select_game_type'));

            return true;
        }

        return false;
    }

    /**
     * Cross-field guards shared by both twins, evaluated in the original
     * order: min>max, then the gathering/focused offered-system requirements.
     * Returns true when save() must abort. Uses addError() so the feedback
     * stays in the component's error bag exactly as before.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function guardCrossFieldSessionRules(array $validated): bool
    {
        if (
            isset($validated['min_players'], $validated['max_players'])
            && $validated['min_players'] > $validated['max_players']
        ) {
            $this->addError('min_players', __('games.error_min_players_cannot_exceed_max_players'));

            return true;
        }

        // Gatherings require at least one game system (the host picks what to
        // play). Enforced here rather than via a rule because it is conditional
        // on game_type. game_system_id is intentionally NOT set for gatherings.
        if ($this->game_type === 'gathering' && empty($validated['game_systems'])) {
            $this->addError('game_systems', __('games.error_gathering_requires_system'));

            return true;
        }

        // Focused sessions (board_game / ttrpg) require exactly one game system
        // via the single-select picker. Without this guard, a host can submit
        // the form without picking a system and a systemless session is
        // persisted — which breaks discovery ranking, the activity feed,
        // profile listings, and cover-image resolution, all of which assume at
        // least one offered system.
        if ($this->game_type !== 'gathering' && empty($validated['game_system_id'])) {
            $this->addError('game_system_id', __('games.error_system_required'));

            return true;
        }

        return false;
    }

    // ── Private Helpers ──────────────────────────────────

    /**
     * Bare per-type defaults: a sensible duration for each type, plus the warm
     * all-welcome seat/experience defaults for Gatherings (R047).
     */
    protected function applyTypeDefaults(string $type): void
    {
        $this->setSessionDuration(match ($type) {
            'board_game' => '1.5',
            'ttrpg' => '3',
            default => '2',
        });

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

        $duration = $this->sessionDuration();
        if ($system->average_play_time && ($duration === '' || $duration === $typeDefault)) {
            $hours = $system->average_play_time / 60;
            $rounded = round($hours * 2) / 2;
            $this->setSessionDuration((string) max($rounded, 0.5));
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
     * @return list<string>
     */
    protected function selectedVibeFlags(): array
    {
        $validValues = VibeFlag::values();

        return array_values(collect($this->vibePreferences)
            ->filter(fn ($value) => $value === 'favorite')
            ->keys()
            ->filter(fn ($key) => in_array($key, $validValues, true))
            ->map(fn (mixed $k): string => (string) $k)
            ->all());
    }

    // ── Hooks (the spots where the twins differ) ─────────

    /**
     * The duration property this form autofills: expected_duration on games,
     * session_duration on campaigns.
     */
    abstract protected function sessionDuration(): ?string;

    abstract protected function setSessionDuration(string $value): void;

    /**
     * Called after a type selection lands. Games layer smart defaults from the
     * user's history here; campaigns apply theirs in mount() and do nothing.
     */
    protected function afterTypeSelected(string $type): void {}

    /**
     * Extra per-component state to clear on a type switch. Games clear
     * comfort_notes; campaigns have no additional type-specific fields.
     */
    protected function resetTypeSpecificState(): void {}
}
