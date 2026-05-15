<?php

namespace App\Livewire\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\ContentLanguage;
use App\Enums\ExperienceLevel;
use App\Enums\Visibility;
use App\Enums\VibeFlag;
use App\Models\Campaign;
use App\Models\GameSystem;
use App\Services\ShortLinkService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.app')]
class CreateCampaign extends Component
{
    public string $name = '';

    public ?string $game_system_id = null;

    public ?string $location_id = null;

    public string $description = '';

    public string $recurrence = 'weekly';

    public string $time_of_day = '19:00';

    public ?string $session_duration = '3';

    public ?string $price_per_session = '';

    public string $language = 'en';

    public string $visibility = 'protected';

    public array $minimum_requirements = [];

    public array $safety_rules = [];

    public ?int $min_players = null;

    public ?int $max_players = null;

    public ?string $experience_level = null;

    public ?string $complexity = null;

    /** @var array<string, string|null> VibeFlag value → null|'favorite'|'avoid', from VibePreferencePicker */
    public array $vibePreferences = [];

    public bool $bench_mode = false;

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'game_system_id' => 'nullable|uuid|exists:game_systems,id',
            'location_id' => 'nullable|uuid|exists:locations,id',
            'description' => 'nullable|string|max:10000',
            'recurrence' => 'required|in:weekly,bi-weekly,monthly,custom',
            'time_of_day' => 'required|date_format:H:i',
            'session_duration' => 'nullable|numeric|min:0.5|max:24',
            'price_per_session' => 'nullable|numeric|min:0',
            'language' => 'required|string|in:' . implode(',', ContentLanguage::values()),
            'visibility' => Visibility::validationRule(),
            'minimum_requirements' => 'nullable|array',
            'safety_rules' => 'nullable|array',
            'min_players' => 'nullable|integer|min:1|max:99',
            'max_players' => 'nullable|integer|min:1|max:99',
            'experience_level' => 'nullable|string|in:' . implode(',', ExperienceLevel::values()),
            'complexity' => 'nullable|numeric|min:1|max:5',
            'bench_mode' => 'boolean',
        ];
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

    #[On('vibe-preferences-changed')]
    public function onVibePreferencesChanged(array $preferences): void
    {
        $this->vibePreferences = $preferences;
    }

    #[On('safety-tools-changed')]
    public function onSafetyToolsChanged(array $safetyRules): void
    {
        $this->safety_rules = $safetyRules;
    }

    #[On('value-updated')]
    public function onGameSystemPicked($value): void
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

    #[Computed]
    public function languageOptions(): array
    {
        $options = [];
        foreach (ContentLanguage::cases() as $case) {
            $options[$case->value] = __('common.label_language_' . $case->value);
        }

        return $options;
    }

    #[Computed]
    public function experienceLevelOptions(): array
    {
        $options = ['' => __('discovery.content_any')];
        foreach (ExperienceLevel::cases() as $case) {
            $options[$case->value] = __('games.content_experience_' . $case->value);
        }

        return $options;
    }

    #[Computed]
    public function canCreatePublic(): bool
    {
        $user = Auth::user();

        return $user && $user->can_create_public_entries;
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
        if ($benchMode && ! Auth::user()->isGM()) {
            Log::warning('Non-GM user attempted to enable bench_mode on campaign creation', [
                'user_id' => Auth::id(),
                'attempted_bench_mode' => true,
            ]);
            $benchMode = false;
        }

        $campaign = Campaign::create([
            'owner_id' => Auth::id(),
            'game_system_id' => $validated['game_system_id'],
            'location_id' => $this->location_id,
            'name' => $validated['name'],
            'description' => $validated['description'],
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

        Log::info('Campaign created', [
            'campaign_id' => $campaign->id,
            'name' => $campaign->name,
            'owner_id' => Auth::id(),
        ]);

        // Auto-generate short link for GMs
        if (Auth::user()->isGM()) {
            try {
                app(ShortLinkService::class)->createLink($campaign, Auth::user(), [
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

    public function render()
    {
        return view('livewire.campaigns.create-campaign', [
            'isGM' => Auth::user()?->isGM() ?? false,
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
