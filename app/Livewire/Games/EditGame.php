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
 * The owner's edit mode on the game detail page (M084/S03, decisions D315 +
 * D314).
 *
 * Mounted by GameDetail for a user who passes GamePolicy::update. It renders
 * the same field partials as CreateGame (D315: one field markup), pre-filled
 * from the model, and saves model columns ONLY through AgreementEditService —
 * the two sanctioned direct writes are the gameSystems pivot sync (the
 * service gates but never writes a pivot) and the cover upload (media
 * library).
 *
 * Game type is deliberately NOT switchable here: the type switcher is
 * create-only chrome, a type change re-shapes the whole form, and a wrong
 * type on an unlocked game is fixed by cancel + recreate (no offence on an
 * empty roster). This component therefore never sends `game_type`, and
 * save() always restores the stored type before it reads the form.
 *
 * @property-read bool $canCreatePublic
 * @property-read bool $isLocked
 */
class EditGame extends Component
{
    use BuildsSessionForm {
        BuildsSessionForm::getTranslatableFields insteadof BuildsTranslatableFormFields;
    }
    use BuildsTranslatableFormFields;
    use WithFileUploads;

    /** The datetime-local format the form inputs carry (wall clock, minute precision). */
    private const string DATETIME_LOCAL_FORMAT = 'Y-m-d\TH:i';

    #[Locked]
    public Game $game;

    /** The game's stored type — locked, so no request can switch it (see the class note). */
    #[Locked]
    public ?string $game_type = null;

    public string $date_time = '';

    public ?string $signup_cutoff_at = null;

    public ?string $expected_duration = '';

    public ?string $price = '';

    public string $comfort_notes = '';

    public ?string $min_reliability_preference = null;

    /**
     * The date_time string that was pre-filled on mount. A submitted value is
     * "changed" only when it differs from THIS string — never by converting
     * and comparing instants: storage is second-precise and the input is
     * minute-precise, so a re-converted untouched date would read as a
     * change (and on a locked game as a Tier-1 violation).
     */
    #[Locked]
    public string $originalDateTime = '';

    /** The signup_cutoff_at string that was pre-filled on mount ('' = no cutoff). Same rule as $originalDateTime. */
    #[Locked]
    public string $originalSignupCutoffAt = '';

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
            'date_time' => 'required|date',
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
            'cover_image' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:5120', Rule::dimensions()->maxWidth(4096)->maxHeight(4096)],
        ], $this->translatableValidationRules(
            ['name' => 'required|string|max:255', 'description' => 'nullable|string|max:5000'],
            $this->language,
        ));
    }

    // ── Lifecycle ─────────────────────────────────────────

    public function mount(Game $game): void
    {
        $this->authorize('update', $game);
        $this->guardScheduled($game);

        $this->game = $game;
        // A forced reload, not loadMissing(): the pre-fill must not depend on
        // the caller's relation cache (a model instance whose gameSystems
        // relation was loaded before a pivot sync would pre-fill an empty
        // system and block every save on "select a game system").
        $game->load('gameSystems');

        $this->game_type = self::storedType($game);
        $this->step = 'form';

        $this->language = $game->language;
        // NEVER `$this->name = $game->name`: that loads the current locale
        // only, renders the other locale's tab empty, and the next save would
        // drop the stored translation.
        $this->loadTranslatableValues($game, ['name', 'description']);
        // loadTranslatableValues() fills a locale the game has no text for with a
        // fallback copy of the primary text, which an untouched save would then
        // store under that locale and report as a change (D326): prune it.
        $this->pruneUnstoredTranslations($game, ['name', 'description']);

        $this->date_time = self::toWallClock($game->date_time) ?? '';
        $this->originalDateTime = $this->date_time;
        $this->signup_cutoff_at = self::toWallClock($game->signup_cutoff_at);
        $this->originalSignupCutoffAt = $this->signup_cutoff_at ?? '';

        $this->expected_duration = (string) ($game->expected_duration ?? '');
        $this->price = $game->price !== null ? (string) $game->price : '';
        $this->visibility = ($game->visibility ?? Visibility::Protected)->value;
        $this->min_players = $game->min_players;
        $this->max_players = $game->max_players;
        $this->bench_mode = $game->bench_mode;
        $this->experience_level = $game->experience_level;
        $this->complexity = $game->complexity !== null ? (string) $game->complexity : null;
        $requirements = $game->getAttribute('minimum_requirements');
        $this->minimum_requirements = is_array($requirements) ? $requirements : [];
        $this->host_note = $game->host_note;
        // Normalized to the option values the select offers ('70' | '85' | '95'): the
        // decimal cast reads 85.00 back as "85.00", which matches no option.
        $reliability = $game->getAttribute('min_reliability_preference');
        $this->min_reliability_preference = is_numeric($reliability) ? (string) (float) $reliability : null;
        $this->location_id = $game->location_id;
        $this->location_instructions = $game->location_instructions ?? '';

        $pivotIds = $game->gameSystems
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

        // Vibe flags (Tier 3): flat DB array → 'favorite' map, as the clone branch of CreateGame::mount().
        foreach ($game->vibe_flags ?? [] as $flag) {
            $this->vibePreferences[$flag] = 'favorite';
        }

        if ($this->game_type === 'board_game') {
            $rawNotes = $game->safety_rules['comfort_notes'] ?? '';
            $this->comfort_notes = is_string($rawNotes) ? $rawNotes : '';
        } else {
            $this->safety_rules = $game->safety_rules ?? [];
        }
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

    /**
     * A content-language change MOVES the baseline; it never re-labels the
     * text in the fields (M084/S07, decision D324). Without this hook the
     * select already answers the new locale when save() runs, so
     * buildTranslatableValues() would label the English text as German and
     * overwrite the stored German translation. The game is passed along so the
     * move re-fills from what the new locale has STORED, not from a fallback copy.
     */
    public function updatedLanguage(): void
    {
        $this->moveBaselineTo($this->language, $this->game);
    }

    // ── Lock state (D314) ─────────────────────────────────

    /** When the agreement locked: the earliest confirmed non-owner participant. */
    #[Computed]
    public function lockedAt(): ?Carbon
    {
        return $this->game->agreementLockedAt();
    }

    #[Computed]
    public function isLocked(): bool
    {
        return $this->game->isAgreementLocked();
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
            $current = (int) $this->game->max_players;

            return FieldTiers::maxPlayersTier($current, $current);
        }

        if ($field === 'visibility') {
            $stored = $this->game->getRawOriginal('visibility');
            $current = is_string($stored) && Visibility::tryFrom($stored) instanceof Visibility
                ? $stored
                : Visibility::Protected->value;

            return FieldTiers::visibilityTier($current, $current);
        }

        return FieldTiers::tierOf(Game::class, $field);
    }

    /**
     * The fields the form renders disabled: Tier-1 fields, once the
     * agreement is locked. `max_players` is never among them (an increase is
     * Tier 2 and stays allowed); `visibility` is disabled only for the
     * private option, which the partial derives itself.
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
        foreach (array_keys(FieldTiers::GAME_TIERS) as $field) {
            if ($this->tierOf($field) === FieldTiers::TIER_LOCKED) {
                $locked[$field] = true;
            }
        }

        return $locked;
    }

    // ── Computed ─────────────────────────────────────────

    /**
     * The attendance-tolerance options of the `_hosting` partial. The list
     * lives on CreateGame itself (not on BuildsSessionForm), so the edit
     * component provides the same name with the same options.
     *
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

    /**
     * The public event this table is hosted at, when it is one. An edit
     * never attaches or detaches an event; the value only drives the
     * visibility hints and the public-event visibility guard.
     */
    #[Computed]
    public function hostingEvent(): ?Event
    {
        return $this->game->event;
    }

    // ── Actions ──────────────────────────────────────────

    public function save(): void
    {
        // Permission and lifecycle may have changed between mount and submit.
        $this->authorize('update', $this->game);
        $this->guardScheduled($this->game);

        // Game type is not switchable in the edit mode: the STORED type decides
        // which fields this form edits — never a value that came from the request.
        // #[Locked] refuses a forged property update; the trait's selectType /
        // changeType actions are still callable, so the type is restored here too.
        $this->game_type = self::storedType($this->game);

        $validated = $this->validate();

        if ($this->guardCrossFieldSessionRules($validated)) {
            return;
        }

        $hostingEvent = $this->hostingEvent();

        // Same rule as CreateGame::save(): a table at a PUBLIC event stays
        // publicly visible — the event page advertises it and links here.
        if ($hostingEvent !== null && $hostingEvent->is_public && $this->visibility !== Visibility::Public->value) {
            throw ValidationException::withMessages([
                'visibility' => __('events.error_event_table_visibility_public'),
            ]);
        }

        // Public visibility needs venue trust (or a public umbrella). A game
        // that is already public keeps its option; a NEW switch to public is
        // refused rather than silently downgraded — on a locked game a
        // downgrade would be a Tier-1 change and block every save.
        if ($this->visibility === Visibility::Public->value
            && $this->game->visibility !== Visibility::Public
            && ! $this->canCreatePublic
            && ! ($hostingEvent !== null && $hostingEvent->is_public)) {
            $this->addError('visibility', __('common.content_public_visibility_requires_admin_approval'));

            return;
        }

        $changes = $this->buildChanges($validated);

        // Game systems are Tier 1 but live on a pivot the service never
        // writes: refuse a changed selection on a locked game BEFORE anything
        // is applied, so the request stays all-or-nothing.
        $systemIds = $this->selectedSystemIds();
        $pivotChanged = $systemIds !== $this->originalSystemIds;

        if ($pivotChanged && $this->game->isAgreementLocked()) {
            $this->refuseLockedSystemChange();
        }

        app(AgreementEditService::class)->apply($this->game, $changes, authenticatedUser());

        if ($pivotChanged) {
            // Re-read the lock right before the Tier-1 pivot write: an
            // approval that landed while the columns were applied must still
            // win. (The columns are applied at this point; the system change
            // — the only refused part — is reported under `edit`.)
            if ($this->game->isAgreementLocked()) {
                $this->refuseLockedSystemChange();
            }

            $this->game->gameSystems()->sync($systemIds);
            $this->game->load('gameSystems');

            // The pivot sync fires no model events — flush the umbrella's
            // derived offered-systems cache when this is a hosted table.
            if ($this->game->event_id !== null) {
                Event::flushOfferedSystemsCacheFor($this->game->event_id);
            }
        }

        // A failed cover upload must stay visible: the columns are saved at this
        // point, but the form stays open on the `cover_image` error with the picked
        // upload kept for a retry — the same contract as saveCover(). Closing the
        // form on a success flash would drop an error the owner never sees.
        if (! $this->storeCover()) {
            return;
        }

        $this->dispatch('game-edit-saved');
    }

    /** Store a picked cover image. Cover is Tier 3 — allowed on a locked game too. */
    public function saveCover(): void
    {
        $this->authorize('update', $this->game);
        $this->guardScheduled($this->game);

        $this->validateOnly('cover_image');

        if ($this->storeCover()) {
            $this->dispatch('game-edit-saved');
        }
    }

    public function cancel(): void
    {
        $this->dispatch('game-edit-cancelled');
    }

    public function render(): View
    {
        return view('livewire.games.edit-game', [
            'isGM' => authenticatedUser()->isGM(),
            'lockedAt' => $this->lockedAt(),
            'isLocked' => $this->isLocked(),
            'lockedFields' => $this->lockedFields(),
            // Only public → private is a Tier-1 visibility change (D313).
            'privateVisibilityLocked' => $this->isLocked() && $this->game->visibility === Visibility::Public,
        ]);
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

    // ── Private Helpers ──────────────────────────────────

    /**
     * A game that is no longer Scheduled cannot be edited. The Edit button is
     * hidden for those — the server must not rely on it.
     */
    private function guardScheduled(Game $game): void
    {
        if ($game->status !== GameStatus::Scheduled) {
            throw ValidationException::withMessages([
                'edit' => __('games.error_game_not_scheduled'),
            ]);
        }
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
        $isGathering = $this->game_type === 'gathering';

        $translatable = $this->buildTranslatableValues(['name', 'description'], $this->language, $validated);

        $safetyRules = null;

        if ($this->game_type === 'board_game') {
            // comfort_notes has no column: it lives inside the safety_rules
            // JSON, exactly as CreateGame::save() composes it. Any other key
            // of a stored document is kept, not dropped.
            $stored = is_array($this->game->safety_rules) ? $this->game->safety_rules : [];
            unset($stored['comfort_notes']);

            if ($this->comfort_notes !== '') {
                $stored['comfort_notes'] = $this->comfort_notes;
            }

            $safetyRules = $stored === [] ? null : $stored;
        } elseif (! $isGathering) {
            // TTRPG: the safety-tool picker's document, as the create path stores it.
            $safetyRules = $this->safety_rules === [] ? null : $this->safety_rules;
        }

        $vibeFlags = $this->selectedVibeFlags();

        $changes = [
            'name' => $translatable['name'],
            'description' => $translatable['description'],
            'host_note' => $validated['host_note'] ?? null,
            'expected_duration' => $validated['expected_duration'] !== null && $validated['expected_duration'] !== ''
                ? $validated['expected_duration']
                : 2,
            'price' => $validated['price'] !== null && $validated['price'] !== '' ? $validated['price'] : 0,
            'language' => $validated['language'],
            'location_id' => $this->location_id,
            'location_instructions' => $validated['location_instructions'] ?? null,
            'visibility' => $validated['visibility'],
            'minimum_requirements' => $this->minimum_requirements !== [] ? $this->minimum_requirements : null,
            'safety_rules' => $safetyRules,
            'min_players' => $validated['min_players'] ?? 2,
            'max_players' => $validated['max_players'],
            'experience_level' => $validated['experience_level'],
            // A descriptor for EVERY game type (D325): the form shows it for gatherings too.
            'complexity' => $this->complexity !== null && $this->complexity !== '' && $this->complexity !== '0' ? $this->complexity : null,
            'vibe_flags' => $vibeFlags !== [] ? $vibeFlags : null,
        ];

        // Only a CHANGED datetime is converted and sent (see $originalDateTime).
        if ($this->date_time !== $this->originalDateTime) {
            $changes['date_time'] = wall_time_to_utc($this->date_time)?->format('Y-m-d H:i:s') ?? $this->date_time;
        }

        $cutoff = $this->signup_cutoff_at;
        if (($this->signup_cutoff_at ?? '') !== $this->originalSignupCutoffAt) {
            $changes['signup_cutoff_at'] = $cutoff !== null && $cutoff !== ''
                ? (wall_time_to_utc($cutoff)?->format('Y-m-d H:i:s') ?? $cutoff)
                : null;
        }

        // The attendance tolerance is not on a gathering's form, so an edit of a gathering
        // never writes it (the stored value stands). Bench mode is a GM setting, so a
        // non-GM's form never writes it either (the stored value stands).
        if (! $isGathering) {
            $changes['min_reliability_preference'] = $validated['min_reliability_preference'] !== null && $validated['min_reliability_preference'] !== '' && $validated['min_reliability_preference'] !== '0'
                ? $validated['min_reliability_preference']
                : null;

            if (authenticatedUser()->isGM()) {
                $changes['bench_mode'] = $this->bench_mode;
            } elseif ($this->bench_mode !== $this->game->bench_mode) {
                Log::warning('Non-GM user attempted to change bench_mode on game edit', [
                    'user_id' => authenticatedUser()->id,
                    'game_id' => $this->game->id,
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

    /** The wall-clock string a datetime-local input carries for the current viewer — the inverse of wall_time_to_utc(). */
    private static function toWallClock(?Carbon $instant): ?string
    {
        if ($instant === null) {
            return null;
        }

        // Copy first: Carbon is mutable and the model's attribute must not shift timezone.
        return $instant->copy()->timezone(viewer_timezone())->format(self::DATETIME_LOCAL_FORMAT);
    }

    /**
     * The game's stored game type — the only type an edit composes a write
     * with. A game from before the column existed reads as a board game.
     */
    private static function storedType(Game $game): string
    {
        $stored = $game->getRawOriginal('game_type');

        return is_string($stored) && GameType::tryFrom($stored) instanceof GameType
            ? $stored
            : GameType::BoardGame->value;
    }

    /** A Tier-1 game-system change on a locked game: the service's refusal, logged the same way. */
    private function refuseLockedSystemChange(): never
    {
        Log::warning('edit.rejected_locked', [
            'entity_type' => 'game',
            'entity_id' => $this->game->id,
            'actor_id' => authenticatedUser()->id,
            'fields' => [$this->game_type === 'gathering' ? 'game_systems' : 'game_system_id'],
        ]);

        throw ValidationException::withMessages([
            'edit' => __('games.error_agreement_locked'),
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
            // The stored model, never the upload: toMediaCollection() copies a
            // temporary upload and then unlinks it, so every later read of
            // `$cover` (mime type, size) would hit a file that no longer exists.
            $media = $this->game->addMedia($cover)->toMediaCollection('cover');
        } catch (Throwable $e) {
            Log::warning('Game cover image upload failed', [
                'game_id' => $this->game->id,
                'owner_id' => authenticatedUser()->id,
                'error' => $e->getMessage(),
            ]);

            $this->addError('cover_image', __('games.error_cover_image_upload_failed'));

            return false;
        }

        $this->cover_image = null;

        Log::info('Game cover image uploaded', [
            'game_id' => $this->game->id,
            'owner_id' => authenticatedUser()->id,
            'mime' => $media->mime_type,
            'size' => $media->size,
        ]);

        return true;
    }
}
