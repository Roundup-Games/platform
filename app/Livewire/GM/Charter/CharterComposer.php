<?php

namespace App\Livewire\GM\Charter;

use App\Dto\CharterContent;
use App\Models\Campaign;
use App\Models\CharterTemplate;
use App\Models\Location;
use App\Models\SessionZeroSurvey;
use App\Services\Charter\CharterMemberService;
use App\Services\Charter\CharterService;
use App\Services\Charter\CharterTemplateService;
use App\Support\CharterDiff;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * GM campaign-charter composer (M070/S01): the 8-step authoring flow.
 *
 * Owner-only — the gate is the campaign owner_id (R084: charter authoring
 * is free-tier safety, never gated on GM membership). Every write goes
 * through CharterService (INV rule: no inline transaction mutations);
 * this component holds form state, per-step validation, and step moves.
 *
 * States derived from the charter row:
 *  - none    → start screen (quick start vs blank)
 *  - draft   → stepper + section forms (autosave on every step change)
 *  - active  → published panel with the share link (no editing in S01;
 *              amendments are S03)
 *
 * Form state lives in one nested public array (content.section.field) so
 * wire:model bindings stay simple and rules delegate exactly to
 * CharterContent::rules()/rulesForSection() under a "content." prefix.
 */
#[Layout('layouts.app')]
class CharterComposer extends Component
{
    /** Stored sections + the review step. */
    private const int TOTAL_STEPS = 8;

    // ── Context ───────────────────────────────────────────

    #[Locked]
    public string $campaignId = '';

    public Campaign $campaign;

    public ?SessionZeroSurvey $charter = null;

    // ── Form State ────────────────────────────────────────

    public string $title = '';

    /** @var array<string, mixed> schema-v1 content, dot-bound in the section partials */
    public array $content = [];

    public int $step = 1;

    public bool $draftSaved = false;

    /** Amendment mode (S03): true between startAmendment() and publish/cancel. */
    public bool $amending = false;

    /** Required publish-time changelog for an amendment (S03). */
    public string $amendmentChangelog = '';

    /** Selected template seed id on the member start card (S04). */
    public string $templateId = '';

    /** Selected previous-charter seed id on the member start card (S04). */
    public string $previousCharterId = '';

    /** Name typed into the published-panel "Save as template" input (S04). */
    public string $templateName = '';

    // ── Lifecycle ─────────────────────────────────────────

    public function mount(string $id): void
    {
        $user = authenticatedUser();

        $this->campaign = Campaign::findOrFail($id);

        // CampaignPolicy::manageCharter (owner-only, R084 — global admins
        // pass via before(), consistent with every other campaign
        // surface). Every service call below re-authorizes through the
        // same ability, so this gate is the mount-time render check, not
        // the security boundary itself.
        abort_unless($user->can('manageCharter', $this->campaign), 403);

        $this->campaignId = (string) $this->campaign->id;

        $this->charter = CharterService::charterFor($this->campaign);

        if ($this->charter !== null && $this->charter->isDraft()) {
            $this->hydrateFromCharter($this->charter);
        }
    }

    // ── Validation ────────────────────────────────────────

    /**
     * Component-rule view of the current step's DTO rules. Delegates to
     * CharterContent (single source); step 8 is review-only, no rules.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $section = $this->currentSection();

        return $section !== null ? $this->prefixedRulesFor($section) : [];
    }

    // ── Actions ───────────────────────────────────────────

    /**
     * Start authoring: quick-start prefill, blank charter, or (member
     * conveniences, S04) seeded from a saved template or one of the
     * owner's previous charters. Idempotent — CharterService::start
     * returns the existing charter unchanged. The mode and seed id come
     * from wire:click, so the runtime in_array guard and the seed-id
     * presence check stay.
     */
    public function startCharter(string $mode, ?string $seedId = null): void
    {
        if (! in_array($mode, ['quickstart', 'blank', 'template', 'previous'], true)) {
            return;
        }

        if (in_array($mode, ['template', 'previous'], true) && ($seedId === null || $seedId === '')) {
            return;
        }

        if ($this->charter === null) {
            $this->charter = CharterService::start(authenticatedUser(), $this->campaign, $mode, $seedId);
        }

        if ($this->charter->isDraft()) {
            $this->hydrateFromCharter($this->charter);
        }
    }

    /**
     * Enter amendment mode on the published charter (S03): re-open the
     * stepper hydrated from the charter row. Edits are session-local —
     * the published row keeps matching the current version until
     * publish() calls CharterService::amend. Drafts publish instead of
     * amending; archived charters are frozen history and offer nothing.
     */
    public function startAmendment(): void
    {
        if ($this->charter === null || ! $this->charter->isActive()) {
            return;
        }

        $this->amending = true;
        $this->amendmentChangelog = '';
        $this->hydrateFromCharter($this->charter);
    }

    /**
     * Leave amendment mode without publishing: drop the session-local
     * edits by re-hydrating from the untouched charter row.
     */
    public function cancelAmendment(): void
    {
        $charter = $this->charter;

        if (! $this->amending || $charter === null) {
            return;
        }

        $this->amending = false;
        $this->amendmentChangelog = '';
        $this->hydrateFromCharter($charter);
    }

    /**
     * Archive the published charter (S04): flip it out of the active
     * roster so it stops consuming a free-tier slot (R084). Routes
     * through CharterService::archive, which re-authorizes the owner and
     * refuses anything but an active charter; the panel re-renders in the
     * archived read-only state (no amend/archive actions). Free-tier
     * safety: archiving is owner-only lifecycle housekeeping, never a
     * membership check.
     */
    public function archive(): void
    {
        $charter = $this->charter;

        if ($charter === null || ! $charter->isActive()) {
            return;
        }

        CharterService::archive(authenticatedUser(), $charter);

        $charter->refresh();
        session()->flash('charter_archived', true);
    }

    /**
     * Save the published charter as a reusable template (S04 member
     * convenience). Routes through CharterTemplateService, which
     * re-authorizes the owner and re-checks the member seam; the name
     * error lands on the composer's input field. Works for archived
     * charters too — a finished campaign is the classic template source.
     */
    public function saveAsTemplate(): void
    {
        $charter = $this->charter;

        if ($charter === null || $charter->isDraft()) {
            return;
        }

        try {
            CharterTemplateService::saveFromCharter(authenticatedUser(), $charter, $this->templateName);
        } catch (ValidationException $e) {
            $this->resetErrorBag();

            foreach ($e->errors() as $key => $messages) {
                foreach ((array) $messages as $message) {
                    // The service validates the trimmed name under its own
                    // key — keep the error on the composer field.
                    $bagKey = $key === 'name'
                        ? 'templateName'
                        : (string) $key;
                    $this->addError($bagKey, $message);
                }
            }

            return;
        }

        $this->templateName = '';
        session()->flash('charter_template_saved', true);
    }

    /**
     * Move to step $n. Forward moves validate the section being left (on
     * failure Livewire stays on the step with errors); every move autosaves
     * the draft. Backward moves only autosave — a GM must be able to step
     * back out of an unfinished section, and publish still validates fully.
     * Amendment mode shares the stepper: moves validate as usual, but the
     * autosave is skipped (persistDraft never persists while amending).
     */
    public function goToStep(int $n): void
    {
        if (! ($this->charter?->isDraft() || $this->amending)) {
            return;
        }

        $n = max(1, min(self::TOTAL_STEPS, $n));
        if ($n === $this->step) {
            return;
        }

        if ($n > $this->step && ($section = $this->currentSection()) !== null) {
            $this->normalizeHtmlEdges();
            $this->validate($this->prefixedRulesFor($section));
        }

        $this->persistDraft();
        $this->step = $n;
    }

    /** Explicit Save Draft button (no-op persistence while amending). */
    public function saveDraft(): void
    {
        if (! ($this->charter?->isDraft() || $this->amending)) {
            return;
        }

        $this->normalizeHtmlEdges();
        $this->persistDraft();
    }

    /**
     * Publish from the review step. Drafts publish version 1 through
     * CharterService::publish; amendment mode publishes an immutable
     * vN+1 through CharterService::amend (title + required changelog).
     * Validation failures map back to their sections: the user jumps to
     * the first failing step with the errors in the bag; the charter row
     * itself only changes when the service transaction commits.
     */
    public function publish(): void
    {
        $charter = $this->charter;

        if ($charter === null) {
            return;
        }

        if ($this->amending) {
            $this->publishAmendment($charter);

            return;
        }

        if (! $charter->isDraft()) {
            return;
        }

        $this->normalizeHtmlEdges();
        $validated = $this->validate(['title' => 'required|string|max:255']);

        try {
            CharterService::publish(authenticatedUser(), $charter, $validated['title'], $this->content);
        } catch (ValidationException $e) {
            $this->resetErrorBag();

            foreach ($e->errors() as $key => $messages) {
                foreach ((array) $messages as $message) {
                    $this->addError($this->errorKeyFor((string) $key), $message);
                }
            }

            $this->step = $this->firstFailingStep(array_keys($e->errors()));

            return;
        }

        $charter->refresh();
    }

    /** Amendment branch of publish(): amend() owns the transaction. */
    private function publishAmendment(SessionZeroSurvey $charter): void
    {
        if (! $charter->isActive()) {
            return;
        }

        $this->normalizeHtmlEdges();
        $validated = $this->validate([
            'title' => 'required|string|max:255',
            'amendmentChangelog' => 'required|string|max:2000',
        ]);

        try {
            CharterService::amend(
                authenticatedUser(),
                $charter,
                $validated['title'],
                $this->content,
                $validated['amendmentChangelog'],
            );
        } catch (ValidationException $e) {
            $this->resetErrorBag();

            foreach ($e->errors() as $key => $messages) {
                foreach ((array) $messages as $message) {
                    // The service validates the trimmed changelog under its
                    // own key — keep the error on the composer field.
                    $bagKey = $key === 'changelog'
                        ? 'amendmentChangelog'
                        : $this->errorKeyFor((string) $key);
                    $this->addError($bagKey, $message);
                }
            }

            $this->step = $this->firstFailingStep(array_keys($e->errors()));

            return;
        }

        $this->amending = false;
        $this->amendmentChangelog = '';
        $charter->refresh();
        session()->flash('charter_amended', (int) $charter->current_version);
    }

    // ── Computed ──────────────────────────────────────────

    /** 'start' | 'draft' | 'published' */
    #[Computed]
    public function state(): string
    {
        if ($this->charter === null) {
            return 'start';
        }

        return $this->charter->isDraft() ? 'draft' : 'published';
    }

    /**
     * Free-tier upsell signal (S04): is this owner at the concurrent
     * active-charter cap (R084)? Rendered on the start screen only —
     * draft navigation, amendments, and signing never consult
     * membership (free-tier safety).
     */
    #[Computed]
    public function atCharterLimit(): bool
    {
        return CharterMemberService::atCharterLimit(authenticatedUser());
    }

    /** The free-tier concurrent active-charter cap (R084), for labels. */
    #[Computed]
    public function activeCharterLimit(): int
    {
        return CharterMemberService::FREE_ACTIVE_CHARTERS;
    }

    /**
     * Member conveniences switch (S04, R085): may this owner use saved
     * templates and start-from-previous? The start cards and the
     * save-as-template action are hidden entirely for free owners —
     * additive member value, no tease.
     */
    #[Computed]
    public function memberConveniences(): bool
    {
        return CharterMemberService::canUseConveniences(authenticatedUser());
    }

    /**
     * The owner's saved charter templates, name-ordered (the template
     * start card's select).
     *
     * @return Collection<int, CharterTemplate>
     */
    #[Computed]
    public function templates(): Collection
    {
        return CharterTemplateService::listFor(authenticatedUser());
    }

    /**
     * The owner's charters that have a published version, excluding this
     * campaign's own (start-from-previous card's select). Archived
     * charters count — a finished campaign is the classic reuse source.
     *
     * @return Collection<int, SessionZeroSurvey>
     */
    #[Computed]
    public function previousCharters(): Collection
    {
        return SessionZeroSurvey::query()
            ->charter()
            ->whereNotNull('current_version')
            ->where('campaign_id', '!=', $this->campaign->id)
            ->whereHas('campaign', fn ($query) => $query->whereBelongsTo(authenticatedUser(), 'owner'))
            ->orderBy('title')
            ->get();
    }

    /**
     * Stepper rail entries: the 7 stored sections with completion dots,
     * plus the §8 review/publish step (null dot — signing-time copy).
     *
     * @return array<int, array{id: string, step: int, short: string, title: string, icon: string, complete: ?bool}>
     */
    #[Computed]
    public function railSections(): array
    {
        $icons = [
            'overview' => 'menu_book',
            'boundaries' => 'front_hand',
            'safety' => 'shield',
            'policies' => 'gavel',
            'ai_tools' => 'smart_toy',
            'logistics' => 'map',
            'character' => 'person',
        ];

        $rail = [];
        foreach (CharterContent::SECTIONS as $index => $section) {
            $rail[] = [
                'id' => $section,
                'step' => $index + 1,
                'short' => __('charter.section_'.$section.'_short'),
                'title' => __('charter.section_'.$section.'_title'),
                'icon' => $icons[$section],
                'complete' => CharterContent::sectionComplete($section, $this->content),
            ];
        }

        $rail[] = [
            'id' => 'agreement',
            'step' => self::TOTAL_STEPS,
            'short' => __('charter.section_agreement_short'),
            'title' => __('charter.section_agreement_title'),
            'icon' => 'handshake',
            'complete' => null,
        ];

        return $rail;
    }

    /** Share uuid for a published charter (null while drafting). */
    #[Computed]
    public function shareableUuid(): ?string
    {
        return $this->charter !== null && ! $this->charter->isDraft()
            ? $this->charter->uuid
            : null;
    }

    /**
     * Detected materiality of the in-session amendment (S03): does the
     * edited content change a §2–§5 section or a §1 tone axis / theme set
     * versus the current published version? Drives the review-step badge.
     * Both sides are normalized inside isMaterialChange.
     */
    #[Computed]
    public function amendmentIsMaterial(): bool
    {
        $current = $this->amending ? $this->charter?->currentVersion() : null;

        if ($current === null) {
            return false;
        }

        return CharterContent::isMaterialChange(
            $current->content ?? [],
            CharterContent::fromArray($this->content)->toArray(),
        );
    }

    /**
     * Field-level diff rows for the review-step preview (S03): the current
     * published version versus the in-session edits, through the shared
     * deterministic engine (same rows the participant view will show).
     * Venue ids resolve to their names — §6 displays the venue, not the
     * id column.
     *
     * @return array<int, array{section: string, field: string, type: string, old: mixed, new: mixed}>
     */
    #[Computed]
    public function amendmentChanges(): array
    {
        $current = $this->amending ? $this->charter?->currentVersion() : null;

        if ($current === null) {
            return [];
        }

        $changes = CharterDiff::changes($current->content ?? [], $this->content);

        foreach ($changes as $index => $change) {
            if ($change['section'] !== 'logistics' || $change['field'] !== 'location_id') {
                continue;
            }

            foreach (['old', 'new'] as $side) {
                $uuid = $change[$side];
                if (is_string($uuid) && $uuid !== '') {
                    $name = Location::query()->whereKey($uuid)->value('name');
                    $changes[$index][$side] = is_string($name) && $name !== '' ? $name : $uuid;
                }
            }
        }

        return $changes;
    }

    /** Share link for a published charter (T05 owns the named route). */
    #[Computed]
    public function shareableLink(): ?string
    {
        return $this->shareableUuid() !== null
            ? url('/'.app()->getLocale().'/charter/'.$this->shareableUuid())
            : null;
    }

    /**
     * Venue options for the logistics step: the locations this owner
     * already uses (their campaigns and games), always including the
     * campaign's own location. Owner-scoped, so nothing private leaks.
     *
     * @return Collection<int, Location>
     */
    #[Computed]
    public function locations(): Collection
    {
        $userId = (string) authenticatedUser()->id;

        return Location::query()
            ->where(function ($q) use ($userId) {
                $q->whereHas('campaigns', fn ($cq) => $cq->where('owner_id', $userId))
                    ->orWhereHas('games', fn ($gq) => $gq->where('owner_id', $userId));
            })
            ->when($this->campaign->location_id !== null, fn ($q) => $q->orWhere('id', $this->campaign->location_id))
            ->orderBy('name')
            ->get();
    }

    // ── Render ────────────────────────────────────────────

    public function render(): View
    {
        return view('livewire.gm.charter.charter-composer', [
            'campaign' => $this->campaign,
            'state' => $this->state(),
            'railSections' => $this->railSections(),
            'locations' => $this->locations(),
            'shareableUuid' => $this->shareableUuid(),
            'shareableLink' => $this->shareableLink(),
        ]);
    }

    // ── Internals ─────────────────────────────────────────

    private function hydrateFromCharter(SessionZeroSurvey $charter): void
    {
        $this->title = (string) $charter->title;
        $this->content = CharterContent::fromArray($charter->content ?? [])->toArray();
        $this->step = 1;
        $this->draftSaved = false;
        $this->resetErrorBag();
    }

    /**
     * Autosave the draft through the service; falls back to the campaign
     * name for an empty title. Never persists in amendment mode — the
     * published row must keep matching the current version until amend
     * publishes vN+1; amendment edits are session-local only.
     */
    private function persistDraft(): void
    {
        $charter = $this->charter;

        if ($charter === null || $this->amending) {
            return;
        }

        $title = trim($this->title) !== '' ? $this->title : (string) $this->campaign->name;

        CharterService::saveDraft(authenticatedUser(), $charter, $title, $this->content);

        $charter->refresh();
        $this->draftSaved = true;
    }

    /** Stored section for the current step (null on the review step 8). */
    private function currentSection(): ?string
    {
        return CharterContent::SECTIONS[$this->step - 1] ?? null;
    }

    /**
     * Component-rule view of one section's DTO rules: keys prefixed with
     * "content." so Livewire validates the nested public array.
     *
     * @return array<string, array<int, string>>
     */
    private function prefixedRulesFor(string $section): array
    {
        $rules = [];
        foreach (CharterContent::rulesForSection($section) as $key => $sectionRules) {
            $rules['content.'.$key] = $sectionRules;
        }

        return $rules;
    }

    /**
     * HTML edge cases the DTO treats as null: an emptied number input
     * submits '' which would fail 'nullable|integer' before fromArray
     * ever gets to coerce it.
     */
    private function normalizeHtmlEdges(): void
    {
        $logistics = $this->content['logistics'] ?? null;

        if (is_array($logistics) && ($logistics['max_missed_sessions'] ?? null) === '') {
            $logistics['max_missed_sessions'] = null;
            $this->content['logistics'] = $logistics;
        }
    }

    /** Error-bag key for a content-path failure; guard keys pass through. */
    private function errorKeyFor(string $key): string
    {
        $section = explode('.', $key)[0];

        return in_array($section, CharterContent::SECTIONS, true)
            ? 'content.'.$key
            : $key;
    }

    /**
     * Earliest section step named by the failing keys (fallback: stay on
     * the review step).
     *
     * @param  array<int, string>  $keys
     */
    private function firstFailingStep(array $keys): int
    {
        foreach (CharterContent::SECTIONS as $index => $section) {
            foreach ($keys as $key) {
                if (str_starts_with((string) $key, $section.'.')) {
                    return $index + 1;
                }
            }
        }

        return self::TOTAL_STEPS;
    }
}
