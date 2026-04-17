<?php

namespace App\Livewire\Campaigns;

use App\Enums\ContentLanguage;
use App\Enums\ExperienceLevel;
use App\Enums\VibeFlag;
use App\Models\Campaign;
use App\Models\GameSystem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.app')]
class CreateCampaign extends Component
{
    public string $name = '';

    public ?int $game_system_id = null;

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

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'game_system_id' => 'nullable|exists:game_systems,id',
            'description' => 'nullable|string|max:10000',
            'recurrence' => 'required|in:weekly,bi-weekly,monthly,custom',
            'time_of_day' => 'required|date_format:H:i',
            'session_duration' => 'nullable|numeric|min:0.5|max:24',
            'price_per_session' => 'nullable|numeric|min:0',
            'language' => 'required|string|in:' . implode(',', ContentLanguage::values()),
            'visibility' => 'required|in:public,protected,private',
            'minimum_requirements' => 'nullable|array',
            'safety_rules' => 'nullable|array',
            'min_players' => 'nullable|integer|min:1|max:99',
            'max_players' => 'nullable|integer|min:1|max:99',
            'experience_level' => 'nullable|string|in:' . implode(',', ExperienceLevel::values()),
            'complexity' => 'nullable|numeric|min:1|max:5',
        ];
    }

    // ── Event Listeners ──────────────────────────────────

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
        $id = is_numeric($value) ? (int) $value : null;
        $this->game_system_id = $id;
        $this->autofillFromGameSystem($id);
    }

    // ── Lifecycle Hooks ──────────────────────────────────

    public function updatedGameSystemId(?int $id): void
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
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    #[Computed]
    public function experienceLevelOptions(): array
    {
        $options = ['' => __('Any')];
        foreach (ExperienceLevel::cases() as $case) {
            $options[$case->value] = $case->label();
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
            $this->addError('min_players', __('Min players cannot exceed max players.'));

            return;
        }

        // Extract favorite vibe flags for storage
        $vibeFlags = $this->selectedVibeFlags();

        $campaign = Campaign::create([
            'owner_id' => Auth::id(),
            'game_system_id' => $validated['game_system_id'],
            'name' => $validated['name'],
            'description' => $validated['description'],
            'recurrence' => $validated['recurrence'],
            'time_of_day' => $validated['time_of_day'],
            'session_duration' => $validated['session_duration'] ?: null,
            'price_per_session' => $validated['price_per_session'] ?: 0,
            'language' => $validated['language'],
            'status' => 'active',
            'visibility' => $validated['visibility'],
            'minimum_requirements' => $validated['minimum_requirements'] ?: null,
            'safety_rules' => $validated['safety_rules'] ?: null,
            'min_players' => $validated['min_players'],
            'max_players' => $validated['max_players'],
            'experience_level' => $validated['experience_level'],
            'complexity' => $validated['complexity'] ?: null,
            'vibe_flags' => ! empty($vibeFlags) ? $vibeFlags : null,
        ]);

        Log::info('Campaign created', [
            'campaign_id' => $campaign->id,
            'name' => $campaign->name,
            'owner_id' => Auth::id(),
        ]);

        session()->flash('success', __('Campaign ":name" created successfully!', ['name' => $campaign->name]));

        $this->redirect(route('campaigns.detail', $campaign->id), navigate: true);
    }

    public function render()
    {
        return view('livewire.campaigns.create-campaign');
    }

    // ── Private Helpers ──────────────────────────────────

    protected function autofillFromGameSystem(?int $id): void
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
            $this->addError('min_players', __('Min players cannot exceed max players.'));
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
