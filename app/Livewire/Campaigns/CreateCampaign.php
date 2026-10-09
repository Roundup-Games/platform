<?php

namespace App\Livewire\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\ContentLanguage;
use App\Enums\ExperienceLevel;
use App\Enums\GameType;
use App\Enums\Recurrence;
use App\Enums\Visibility;
use App\Livewire\Concerns\BuildsSessionForm;
use App\Models\Campaign;
use App\Services\CreateDefaultsService;
use App\Services\SessionCreationService;
use App\Traits\BuildsTranslatableFormFields;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/** @property-read bool $canCreatePublic */
#[Layout('layouts.app')]
class CreateCampaign extends Component
{
    use BuildsSessionForm {
        BuildsSessionForm::getTranslatableFields insteadof BuildsTranslatableFormFields;
    }
    use BuildsTranslatableFormFields;

    // Livewire file-upload support for the host-uploaded cover image (S07).
    use WithFileUploads;

    /** Optional query parameter: pre-selected game type (mirrors CreateGame). */
    #[Url]
    public ?string $type = null;

    /**
     * Campaign game type (R050). Defaults to 'ttrpg' for backward compatibility —
     * campaigns were implicitly TTRPG before this field existed. A 'gathering'
     * campaign is a recurring board-game night; AddSessionToCampaign propagates
     * the type onto each spawned session.
     */
    public ?string $game_type = 'ttrpg';

    public string $recurrence = 'weekly';

    public string $time_of_day = '19:00';

    public ?string $session_duration = '3';

    public ?string $price_per_session = '';

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
            'location_id' => 'nullable|uuid|exists:locations,id',
            'location_instructions' => 'nullable|string|max:1000',
            'description' => 'nullable|string|max:10000',
            'recurrence' => 'required|in:'.implode(',', Recurrence::values()),
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
            // Max dimensions guard against decompression-bomb spikes during
            // Spatie's og/thumb conversions (defense-in-depth).
            'cover_image' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:5120', Rule::dimensions()->maxWidth(4096)->maxHeight(4096)],
        ], $this->translatableValidationRules(
            ['name' => 'required|string|max:255', 'description' => 'nullable|string|max:10000'],
            $this->language,
        ));
    }

    // ── Computed ─────────────────────────────────────────

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function recurrenceOptions(): array
    {
        $options = [];
        foreach (Recurrence::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    // ── Actions ──────────────────────────────────────────

    public function save(): void
    {
        $this->authorize('create', Campaign::class);

        if ($this->guardGameTypeSelected()) {
            return;
        }

        // Gate public visibility
        if ($this->visibility === 'public' && ! $this->canCreatePublic) {
            $this->visibility = 'protected';
        }

        $validated = $this->validate();

        if ($this->guardCrossFieldSessionRules($validated)) {
            return;
        }

        $campaign = app(SessionCreationService::class)->create(
            authenticatedUser(),
            'campaign',
            [
                'validated' => $validated,
                'translatable' => $this->buildTranslatableValues(
                    ['name', 'description'],
                    $validated['language'],
                    $validated,
                ),
                'safety_rules' => $validated['safety_rules'] ?: null,
                'vibe_flags' => $this->selectedVibeFlags(),
                'bench_mode' => $this->bench_mode,
                'complexity' => $validated['complexity'] ?? null,
                'cover_image' => $this->cover_image,
            ],
            makeModel: fn (array $context): Campaign => Campaign::create([
                'owner_id' => $context['owner_id'],
                'game_type' => $context['validated']['game_type'],
                'location_id' => $this->location_id,
                'location_instructions' => $context['validated']['location_instructions'] ?? null,
                'name' => $context['translatable']['name'],
                'description' => $context['translatable']['description'],
                'host_note' => $context['validated']['host_note'] ?? null,
                'recurrence' => $context['validated']['recurrence'],
                'time_of_day' => $context['validated']['time_of_day'],
                'session_duration' => $context['validated']['session_duration'] ?: null,
                'price_per_session' => $context['validated']['price_per_session'] ?: 0,
                'language' => $context['validated']['language'],
                'status' => CampaignStatus::Active,
                'visibility' => $context['validated']['visibility'],
                'minimum_requirements' => $context['validated']['minimum_requirements'] ?: null,
                'safety_rules' => $context['safety_rules'],
                'min_players' => $context['validated']['min_players'],
                'max_players' => $context['validated']['max_players'],
                'experience_level' => $context['validated']['experience_level'],
                'complexity' => $context['complexity'],
                'vibe_flags' => ! empty($context['vibe_flags']) ? $context['vibe_flags'] : null,
                'bench_mode' => $context['bench_mode'],
            ]),
            onCreated: function (Campaign $campaign): void {
                Log::info('Campaign created', [
                    'campaign_id' => $campaign->id,
                    'name' => $campaign->name,
                    'game_type' => $campaign->game_type?->value,
                    'owner_id' => Auth::id(),
                ]);
            },
        );

        session()->flash('success', __('campaigns.flash_campaign_name_created_successfully', ['name' => $campaign->name]));

        $this->redirect(route('campaigns.show', $campaign), navigate: true);
    }

    public function mount(): void
    {
        // Smart defaults: carry forward language, location, visibility, type,
        // and recurrence from the user's prior campaign or standalone sessions.
        // Layered BEFORE type defaults so a ?type= deep-link can override below.
        $this->applySmartDefaults();

        // If a type was pre-selected via ?type= (e.g. deep-linked from the
        // unified Plan flow), advance straight to the form with that type's
        // defaults applied on top of the smart defaults.
        if ($this->type !== null && $this->type !== '' && in_array($this->type, GameType::values(), true)) {
            $this->game_type = $this->type;
            $this->step = 'form';
            $this->applyTypeDefaults($this->type);
        }
    }

    public function render(): View
    {
        return view('livewire.campaigns.create-campaign', [
            'isGM' => authenticatedUser()->isGM(),
        ]);
    }

    // ── Private Helpers ──────────────────────────────────

    /**
     * Layer smart defaults from the user's prior campaign/session history and
     * profile preferences on top of property defaults.
     */
    protected function applySmartDefaults(): void
    {
        $user = Auth::user();
        if ($user === null) {
            return;
        }

        $defaults = app(CreateDefaultsService::class)->forCampaign($user);

        $this->language = $defaults->language ?? 'en';
        $this->location_id = $defaults->locationId;
        $this->visibility = $defaults->visibility ?? 'protected';
        $this->recurrence = $defaults->recurrence ?? 'weekly';
        $this->game_type = $defaults->gameType ?? 'ttrpg';
        $this->game_system_id = $defaults->gameSystemId;

        // Gatherings keep their warm 'all welcome' defaults (seat count + level
        // come from applyTypeDefaults, not history). For focused types, carry
        // forward the last session's experience level and seat count.
        if ($this->game_type !== 'gathering') {
            if ($defaults->experienceLevel !== null) {
                $this->experience_level = $defaults->experienceLevel;
            }
            if ($defaults->maxPlayers !== null) {
                $this->max_players = $defaults->maxPlayers;
            }
        }
    }

    // ── BuildsSessionForm hooks ──────────────────────────

    protected function sessionDuration(): ?string
    {
        return $this->session_duration;
    }

    protected function setSessionDuration(string $value): void
    {
        $this->session_duration = $value;
    }
}
