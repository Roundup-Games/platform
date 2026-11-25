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
use App\Services\Edit\AgreementEditService;
use App\Support\Edit\FieldTiers;
use App\Traits\BuildsTranslatableFormFields;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * The owner's edit mode on the campaign detail page (M084/S03, decisions D317 +
 * D314) — the campaign twin of Games\EditGame.
 *
 * Mounted by CampaignDetail for a user who passes CampaignPolicy::update. It renders
 * the same field partials as CreateCampaign (one field markup), pre-filled from the
 * model, and saves model columns ONLY through AgreementEditService — the two
 * sanctioned direct writes are the gameSystems pivot sync (the service gates but
 * never writes a pivot) and the cover upload (media library).
 *
 * Game type is deliberately NOT switchable here: the type switcher is create-only
 * chrome, a type change re-shapes the whole form, and a wrong type on an unlocked
 * campaign is fixed by cancel + recreate. This component therefore never sends
 * `game_type`, and save() always restores the stored type before it reads the form.
 *
 * @property-read bool $canCreatePublic
 * @property-read bool $isLocked
 */
class EditCampaign extends Component
{
    use BuildsSessionForm {
        BuildsSessionForm::getTranslatableFields insteadof BuildsTranslatableFormFields;
    }
    use BuildsTranslatableFormFields;
    use WithFileUploads;

    #[Locked]
    public Campaign $campaign;

    /** The campaign's stored type — locked, so no request can switch it (see the class note). */
    #[Locked]
    public ?string $game_type = null;

    public string $recurrence = 'weekly';

    public string $time_of_day = '19:00';

    public ?string $session_duration = '3';

    public ?string $price_per_session = '';

    /**
     * The offered systems the form could show on mount: the first pivot row
     * for a focused type (the single picker cannot represent more), the full
     * set for a gathering. The pivot changes only when the selection differs
     * from this, so an untouched form never rewrites a multi-system pivot.
     *
     * @var list<string>
     */
    #[Locked]
    public array $originalSystemIds = [];

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

    // ── Lifecycle ─────────────────────────────────────────

    public function mount(Campaign $campaign): void
    {
        $this->authorize('update', $campaign);
        $this->guardActive($campaign);

        $this->campaign = $campaign;
        // A forced reload, not loadMissing(): the pre-fill must not depend on the
        // caller's relation cache (CampaignDetail eager-loads gameSystems with
        // nested relations before it mounts this component).
        $campaign->load('gameSystems');

        $this->game_type = self::storedType($campaign);
        $this->step = 'form';

        $this->language = $campaign->language;
        // NEVER `$this->name = $campaign->name`: that loads the current locale
        // only, renders the other locale's tab empty, and the next save would
        // drop the stored translation.
        $this->loadTranslatableValues($campaign, ['name', 'description']);

        // Schedule. time_of_day is a plain `H:i` varchar on campaigns: a
        // wall-clock time with no timezone conversion, unlike a game's date_time.
        $this->recurrence = $campaign->recurrence;
        $this->time_of_day = $campaign->time_of_day;
        $this->session_duration = (string) $campaign->session_duration;
        $this->price_per_session = $campaign->price_per_session !== null ? (string) $campaign->price_per_session : '';
        $this->visibility = ($campaign->visibility ?? Visibility::Protected)->value;
        $this->min_players = $campaign->min_players;
        $this->max_players = $campaign->max_players;
        $this->bench_mode = $campaign->bench_mode;
        $this->experience_level = $campaign->experience_level;
        $this->complexity = $campaign->complexity !== null ? (string) $campaign->complexity : null;
        $requirements = $campaign->getAttribute('minimum_requirements');
        $this->minimum_requirements = is_array($requirements) ? $requirements : [];
        $safetyRules = $campaign->getAttribute('safety_rules');
        $this->safety_rules = is_array($safetyRules) ? $safetyRules : [];
        $this->host_note = $campaign->host_note;
        $this->location_id = $campaign->location_id;
        $this->location_instructions = $campaign->location_instructions ?? '';

        $pivotIds = $campaign->gameSystems
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id))
            ->map(fn (string $id): string => $id)
            ->values()
            ->all();
        if ($this->game_type === 'gathering') {
            $this->game_systems = $pivotIds;
            $this->originalSystemIds = self::normalizeIds($pivotIds);
        } else {
            $this->game_system_id = $pivotIds[0] ?? null;
            $this->originalSystemIds = self::normalizeIds($pivotIds === [] ? [] : [$pivotIds[0]]);
        }

        // Vibe flags (Tier 3): flat DB array → 'favorite' map, as the edit mode of a game does.
        $storedVibeFlags = $campaign->getAttribute('vibe_flags');
        if (is_array($storedVibeFlags)) {
            foreach ($storedVibeFlags as $flag) {
                if (is_string($flag)) {
                    $this->vibePreferences[$flag] = 'favorite';
                }
            }
        }
    }

    /**
     * A content-language change MOVES the baseline; it never re-labels the
     * text in the fields (M084/S07, decision D324). Without this hook the
     * select already answers the new locale when save() runs, so
     * buildTranslatableValues() would label the English text as German and
     * overwrite the stored German translation. The campaign is passed along so
     * the move re-fills from what the new locale has STORED, not from a fallback copy.
     */
    public function updatedLanguage(): void
    {
        $this->moveBaselineTo($this->language, $this->campaign);
    }

    // ── Lock state (D314) ─────────────────────────────────

    /** When the agreement locked: the earliest confirmed non-owner participant. */
    #[Computed]
    public function lockedAt(): ?Carbon
    {
        return $this->campaign->agreementLockedAt();
    }

    #[Computed]
    public function isLocked(): bool
    {
        return $this->campaign->isAgreementLocked();
    }

    /**
     * Tier of one form field. Fixed-tier fields come straight from
     * FieldTiers; the two direction-dependent fields are classified at their
     * CURRENT value (an unchanged value is Tier 3 by definition — the gate
     * on a decrease / a public→private switch stays with the service).
     *
     * @return int One of FieldTiers::TIER_LOCKED, TIER_NOTIFIED, TIER_FREE
     */
    public function tierOf(string $field): int
    {
        if ($field === 'max_players') {
            $current = (int) $this->campaign->max_players;

            return FieldTiers::maxPlayersTier($current, $current);
        }

        if ($field === 'visibility') {
            $stored = $this->campaign->getRawOriginal('visibility');
            $current = is_string($stored) && Visibility::tryFrom($stored) instanceof Visibility
                ? $stored
                : Visibility::Protected->value;

            return FieldTiers::visibilityTier($current, $current);
        }

        return FieldTiers::tierOf(Campaign::class, $field);
    }

    /**
     * The fields the form renders disabled: Tier-1 fields, once the
     * agreement is locked. `max_players` is never among them (an increase is
     * Tier 2 and stays allowed); `visibility` is disabled only for the
     * private option, which the partial derives itself. The set is exactly
     * the Tier-1 keys of FieldTiers::CAMPAIGN_TIERS — never a hand-written list.
     *
     * @return array<string, true>
     */
    #[Computed]
    public function lockedFields(): array
    {
        if (! $this->isLocked()) {
            return [];
        }

        $locked = [];
        foreach (array_keys(FieldTiers::CAMPAIGN_TIERS) as $field) {
            if ($this->tierOf($field) === FieldTiers::TIER_LOCKED) {
                $locked[$field] = true;
            }
        }

        return $locked;
    }

    // ── Computed ─────────────────────────────────────────

    /**
     * The recurrence options of the `_schedule` partial. The list lives on
     * CreateCampaign itself (not on BuildsSessionForm), so the edit component
     * provides the same name with the same options.
     *
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
        // Permission and lifecycle may have changed between mount and submit.
        $this->authorize('update', $this->campaign);
        $this->guardActive($this->campaign);

        // Game type is not switchable in the edit mode: the STORED type decides
        // which fields this form edits — never a value that came from the request.
        $this->game_type = self::storedType($this->campaign);

        $validated = $this->validate();

        if ($this->guardCrossFieldSessionRules($validated)) {
            return;
        }

        // Public visibility needs venue trust. A campaign that is already public
        // keeps its option; a NEW switch to public is refused rather than silently
        // downgraded as on create — a downgrade would quietly hide the campaign,
        // and on a locked public campaign the service refuses to go private.
        if ($this->visibility === Visibility::Public->value
            && $this->campaign->visibility !== Visibility::Public
            && ! $this->canCreatePublic) {
            $this->addError('visibility', __('common.content_public_visibility_requires_admin_approval'));

            return;
        }

        $changes = $this->buildChanges($validated);

        // Game systems are Tier 1 but live on a pivot the service never
        // writes: refuse a changed selection on a locked campaign BEFORE anything
        // is applied, so the request stays all-or-nothing.
        $systemIds = $this->selectedSystemIds();
        $pivotChanged = $systemIds !== $this->originalSystemIds;

        if ($pivotChanged && $this->campaign->isAgreementLocked()) {
            $this->refuseLockedSystemChange();
        }

        app(AgreementEditService::class)->apply($this->campaign, $changes, authenticatedUser());

        if ($pivotChanged) {
            // Re-read the lock right before the Tier-1 pivot write: an approval
            // that landed while the columns were applied must still win.
            if ($this->campaign->isAgreementLocked()) {
                $this->refuseLockedSystemChange();
            }

            $this->campaign->gameSystems()->sync($systemIds);
            $this->campaign->load('gameSystems');
        }

        $this->storeCover();

        session()->flash('success', __('campaigns.flash_campaign_updated'));
        $this->dispatch('campaign-edit-saved');
    }

    /** Store a picked cover image. Cover is Tier 3 — allowed on a locked campaign too. */
    public function saveCover(): void
    {
        $this->authorize('update', $this->campaign);
        $this->guardActive($this->campaign);

        $this->validateOnly('cover_image');

        if ($this->storeCover()) {
            session()->flash('success', __('campaigns.flash_campaign_updated'));
            $this->dispatch('campaign-edit-saved');
        }
    }

    public function cancel(): void
    {
        $this->dispatch('campaign-edit-cancelled');
    }

    public function render(): View
    {
        return view('livewire.campaigns.edit-campaign', [
            'isGM' => authenticatedUser()->isGM(),
            'lockedAt' => $this->lockedAt(),
            'isLocked' => $this->isLocked(),
            'lockedFields' => $this->lockedFields(),
            // Only public → private is a Tier-1 visibility change (D313).
            'privateVisibilityLocked' => $this->isLocked() && $this->campaign->visibility === Visibility::Public,
        ]);
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

    // ── Private Helpers ──────────────────────────────────

    /**
     * A campaign that is no longer Active cannot be edited. The Edit button is
     * hidden for those — the server must not rely on it.
     */
    private function guardActive(Campaign $campaign): void
    {
        if ($campaign->status !== CampaignStatus::Active) {
            throw ValidationException::withMessages([
                'edit' => __('campaigns.error_campaign_not_active'),
            ]);
        }
    }

    /**
     * The campaign's stored game type. A campaign from before the column
     * existed reads as TTRPG — the documented default of CreateCampaign.
     */
    private static function storedType(Campaign $campaign): string
    {
        $stored = $campaign->getRawOriginal('game_type');

        return is_string($stored) && GameType::tryFrom($stored) instanceof GameType
            ? $stored
            : GameType::Ttrpg->value;
    }

    /**
     * The storage-ready changes for AgreementEditService, keyed by machine
     * column name. The service drops no-ops.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function buildChanges(array $validated): array
    {
        $translatable = $this->buildTranslatableValues(['name', 'description'], $this->language, $validated);

        $vibeFlags = $this->selectedVibeFlags();

        $changes = [
            'name' => $translatable['name'],
            'description' => $translatable['description'],
            'host_note' => $validated['host_note'] ?? null,
            'recurrence' => $validated['recurrence'],
            'time_of_day' => $validated['time_of_day'],
            'language' => $validated['language'],
            'location_id' => $this->location_id,
            'location_instructions' => $validated['location_instructions'] ?? null,
            'visibility' => $validated['visibility'],
            'minimum_requirements' => $validated['minimum_requirements'] !== null && $validated['minimum_requirements'] !== []
                ? $validated['minimum_requirements']
                : null,
            'safety_rules' => $validated['safety_rules'] !== null && $validated['safety_rules'] !== []
                ? $validated['safety_rules']
                : null,
            'min_players' => $validated['min_players'],
            'max_players' => $validated['max_players'],
            'experience_level' => $validated['experience_level'],
            'vibe_flags' => $vibeFlags !== [] ? $vibeFlags : null,
        ];

        // session_duration is NOT NULL on campaigns: an emptied field leaves the
        // stored value standing instead of writing a null the database refuses.
        if ($validated['session_duration'] !== null && $validated['session_duration'] !== '') {
            $changes['session_duration'] = $validated['session_duration'];
        }

        // Same canonicalisation as the create path (CreateCampaign::save): an
        // empty price is stored as 0 — "free".
        $changes['price_per_session'] = $validated['price_per_session'] !== null && $validated['price_per_session'] !== ''
            ? $validated['price_per_session']
            : 0;

        // A gathering's form carries neither complexity nor the bench switch, so an
        // edit of a gathering never writes them (the stored values stand). Bench
        // mode is a GM setting: a non-GM's form never writes it either.
        if ($this->game_type !== 'gathering') {
            $changes['complexity'] = $validated['complexity'] ?? null;

            if (authenticatedUser()->isGM()) {
                $changes['bench_mode'] = $this->bench_mode;
            } elseif ($this->bench_mode !== $this->campaign->bench_mode) {
                Log::warning('Non-GM user attempted to change bench_mode on campaign edit', [
                    'user_id' => authenticatedUser()->id,
                    'campaign_id' => $this->campaign->id,
                    'attempted_bench_mode' => $this->bench_mode,
                ]);
            }
        }

        return $changes;
    }

    /**
     * The submitted system selection as a normalized, sorted id list.
     *
     * @return list<string>
     */
    private function selectedSystemIds(): array
    {
        if ($this->game_type === 'gathering') {
            return self::normalizeIds($this->game_systems);
        }

        return self::normalizeIds($this->game_system_id === null || $this->game_system_id === '' ? [] : [$this->game_system_id]);
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return list<string>
     */
    private static function normalizeIds(array $ids): array
    {
        $set = [];
        foreach ($ids as $id) {
            if (is_string($id) && $id !== '') {
                $set[$id] = true;
            }
        }

        $list = array_keys($set);
        sort($list);

        return $list;
    }

    /** A Tier-1 game-system change on a locked campaign: the service's refusal, logged the same way. */
    private function refuseLockedSystemChange(): never
    {
        Log::warning('edit.rejected_locked', [
            'entity_type' => 'campaign',
            'entity_id' => $this->campaign->id,
            'actor_id' => authenticatedUser()->id,
            'fields' => [$this->game_type === 'gathering' ? 'game_systems' : 'game_system_id'],
        ]);

        throw ValidationException::withMessages([
            'edit' => __('campaigns.error_agreement_locked'),
        ]);
    }

    /**
     * Move a temporary upload into the 'cover' media collection (single file:
     * a new upload replaces the prior cover). Upload failures are non-fatal
     * and surfaced under `cover_image`, exactly like the create path.
     */
    private function storeCover(): bool
    {
        $cover = $this->cover_image;

        if (! $cover instanceof UploadedFile) {
            return true;
        }

        try {
            $this->campaign->addMedia($cover)->toMediaCollection('cover');
        } catch (Throwable $e) {
            Log::warning('Campaign cover image upload failed', [
                'campaign_id' => $this->campaign->id,
                'owner_id' => authenticatedUser()->id,
                'error' => $e->getMessage(),
            ]);

            $this->addError('cover_image', __('games.error_cover_image_upload_failed'));

            return false;
        }

        $this->cover_image = null;

        Log::info('Campaign cover image uploaded', [
            'campaign_id' => $this->campaign->id,
            'owner_id' => authenticatedUser()->id,
            'mime' => $cover->getMimeType(),
            'size' => $cover->getSize(),
        ]);

        return true;
    }
}
