<?php

namespace App\Livewire\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\ContentLanguage;
use App\Enums\ExperienceLevel;
use App\Enums\GameType;
use App\Enums\VibeFlag;
use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\GameSystem;
use App\Services\OwnerParticipantService;
use App\Services\ShortLinkService;
use App\Services\VenueTrustService;
use App\Traits\BuildsTranslatableFormFields;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/** @property-read bool $canCreatePublic */
#[Layout('layouts.app')]
class CreateCampaign extends Component
{
    use BuildsTranslatableFormFields;

    // Livewire file-upload support for the host-uploaded cover image (S07).
    use WithFileUploads;

    public string $name = '';

    // ── Translatable fields ──
    /**
     * @return array<int, string>
     */
    public function getTranslatableFields(): array
    {
        return ['name', 'description'];
    }

    public ?string $game_system_id = null;

    /**
     * Campaign game type (R050). Defaults to 'ttrpg' for backward compatibility —
     * campaigns were implicitly TTRPG before this field existed. A 'gathering'
     * campaign is a recurring board-game night; AddSessionToCampaign propagates
     * the type onto each spawned session.
     */
    public ?string $game_type = 'ttrpg';

    public ?string $location_id = null;

    public string $location_instructions = '';

    public string $description = '';

    public string $recurrence = 'weekly';

    public string $time_of_day = '19:00';

    public ?string $session_duration = '3';

    public ?string $price_per_session = '';

    public string $language = 'en';

    public string $visibility = 'protected';

    /** @var array<int, string> */
    public array $minimum_requirements = [];

    /** @var array<int|string, mixed> */
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

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'name' => 'required|string|max:255',
            'game_type' => 'required|string|in:'.implode(',', GameType::values()),
            'game_system_id' => 'nullable|uuid|exists:game_systems,id',
            'location_id' => 'nullable|uuid|exists:locations,id',
            'location_instructions' => 'nullable|string|max:1000',
            'description' => 'nullable|string|max:10000',
            'recurrence' => 'required|in:weekly,bi-weekly,monthly,custom',
            'time_of_day' => 'required|date_format:H:i',
            'session_duration' => 'nullable|numeric|min:0.5|max:24',
            'price_per_session' => 'nullable|numeric|min:0',
            'language' => 'required|string|in:'.implode(',', ContentLanguage::values()),
            'visibility' => Visibility::validationRule(),
            'minimum_requirements' => 'nullable|array',
            'safety_rules' => 'nullable|array',
            'min_players' => 'nullable|integer|min:1|max:99',
            'max_players' => 'nullable|integer|min:1|max:99',
            'experience_level' => 'nullable|string|in:'.implode(',', ExperienceLevel::values()),
            'complexity' => 'nullable|numeric|min:1|max:5',
            'bench_mode' => 'boolean',
            // Host-uploaded cover (S07): the model's registerMediaCollections()
            // also enforces the jpeg/png/webp mime allow-list at storage time.
            'cover_image' => 'nullable|image|mimes:jpeg,png,webp|max:5120',
        ], $this->translatableValidationRules(
            ['name' => 'required|string|max:255', 'description' => 'nullable|string|max:10000'],
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
     * @param  array<string, string|null>  $preferences
     */
    #[On('vibe-preferences-changed')]
    public function onVibePreferencesChanged(array $preferences): void
    {
        $this->vibePreferences = $preferences;
    }

    /**
     * @param  array<int|string, mixed>  $safetyRules
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
        $this->authorize('create', Campaign::class);

        // Gate public visibility
        if ($this->visibility === 'public' && ! $this->canCreatePublic) {
            $this->visibility = 'protected';
        }

        $validated = $this->validate();

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

        // Gate bench_mode to GM users only (defense-in-depth; UI disables toggle for non-GMs)
        $benchMode = $this->bench_mode;
        if ($benchMode && ! authenticatedUser()->isGM()) {
            Log::warning('Non-GM user attempted to enable bench_mode on campaign creation', [
                'user_id' => Auth::id(),
                'attempted_bench_mode' => true,
            ]);
            $benchMode = false;
        }

        // Build translatable values for name and description only
        $translatable = $this->buildTranslatableValues(
            ['name', 'description'],
            $validated['language'],
            $validated,
        );

        $campaign = DB::transaction(function () use ($validated, $translatable, $vibeFlags, $benchMode) {
            $campaign = Campaign::create([
                'owner_id' => Auth::id(),
                'game_type' => $validated['game_type'],
                'location_id' => $this->location_id,
                'location_instructions' => $validated['location_instructions'] ?? null,
                'name' => $translatable['name'],
                'description' => $translatable['description'],
                'recurrence' => $validated['recurrence'],
                'time_of_day' => $validated['time_of_day'],
                'session_duration' => $validated['session_duration'] ?: null,
                'price_per_session' => $validated['price_per_session'] ?: 0,
                'language' => $validated['language'],
                'status' => CampaignStatus::Active,
                'visibility' => $validated['visibility'],
                'minimum_requirements' => $validated['minimum_requirements'] ?: null,
                'safety_rules' => $validated['safety_rules'] ?: null,
                'min_players' => $validated['min_players'],
                'max_players' => $validated['max_players'],
                'experience_level' => $validated['experience_level'],
                'complexity' => $validated['complexity'] ?: null,
                'vibe_flags' => ! empty($vibeFlags) ? $vibeFlags : null,
                'bench_mode' => $benchMode,
            ]);

            app(OwnerParticipantService::class)->ensureCampaignOwnerParticipant($campaign);

            // Sync the canonical campaign_game_system pivot: the
            // campaign's system set is the recurring DEFAULT offering (the
            // template). AddSessionToCampaign copy-on-writes this set into each
            // spawned session's game_game_system rows at creation time.
            // single-system campaigns (the only kind today)
            // produce a one-element pivot set.
            $campaignSystemIds = array_filter(
                [$validated['game_system_id'] ?? null],
                fn (?string $id): bool => $id !== null,
            );
            if (! empty($campaignSystemIds)) {
                $campaign->gameSystems()->sync($campaignSystemIds);
            }

            return $campaign;
        });

        // Persist the host-uploaded cover to the Spatie 'cover' collection.
        // singleFile() on the collection means a fresh upload replaces any
        // prior cover. Runs OUTSIDE the create transaction: media storage
        // writes files and a media row, neither of which the campaign row
        // depends on, and Spatie's medialibrary does not participate in the
        // caller's DB transaction safely.
        if ($this->cover_image instanceof UploadedFile) {
            try {
                $campaign->addMedia($this->cover_image)->toMediaCollection('cover');

                Log::info('Campaign cover image uploaded', [
                    'campaign_id' => $campaign->id,
                    'owner_id' => Auth::id(),
                    'mime' => $this->cover_image->getMimeType(),
                    'size' => $this->cover_image->getSize(),
                ]);
            } catch (\Throwable $e) {
                // Upload failures are non-fatal: the campaign is already created
                // and resolveCoverUrl() falls back to the representative
                // system cover. Surface the failure for follow-up.
                Log::warning('Campaign cover image upload failed', [
                    'campaign_id' => $campaign->id,
                    'owner_id' => Auth::id(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Campaign created', [
            'campaign_id' => $campaign->id,
            'name' => $campaign->name,
            'owner_id' => Auth::id(),
        ]);

        // Auto-generate short link for GMs
        if (authenticatedUser()->isGM()) {
            try {
                app(ShortLinkService::class)->createLink($campaign, authenticatedUser(), [
                    'label' => 'Default',
                    'expires_at' => now()->addDays(30),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to auto-generate short link for campaign', [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        session()->flash('success', __('campaigns.flash_campaign_name_created_successfully', ['name' => $campaign->name]));

        $this->redirect(route('campaigns.show', $campaign->id), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.campaigns.create-campaign', [
            'isGM' => authenticatedUser()->isGM(),
        ]);
    }

    // ── Private Helpers ──────────────────────────────────

    protected function autofillFromGameSystem(?string $id): void
    {
        if ($id === null) {
            return;
        }

        $system = GameSystem::find($id);
        if ($system === null) {
            return;
        }

        if ($system->average_play_time && $this->session_duration === '3') {
            $hours = $system->average_play_time / 60;
            $rounded = round($hours * 2) / 2;
            $this->session_duration = (string) max($rounded, 0.5);
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
     * @return string[]
     */
    protected function selectedVibeFlags(): array
    {
        $validValues = VibeFlag::values();

        return collect($this->vibePreferences)
            ->filter(fn ($value) => $value === 'favorite')
            ->keys()
            ->filter(fn ($key) => in_array($key, $validValues))
            ->values()
            ->all();
    }
}
