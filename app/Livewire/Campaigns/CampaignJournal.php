<?php

namespace App\Livewire\Campaigns;

use App\Enums\CharacterStateChangeType;
use App\Enums\GoalStatus;
use App\Enums\JournalEntryVisibility;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignGoal;
use App\Models\CampaignJournalEntry;
use App\Models\CampaignParticipant;
use App\Models\CharacterStateChange;
use App\Models\User;
use App\Services\CampaignJournalService;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The member-gated campaign journal (M071/S02), mounted inside
 * CampaignDetail's Journal tab (GameBulletinBoard nested-component shape).
 *
 * Reading surfaces: the reverse-chronological timeline with kind filters
 * and keyset pagination (S02-T03), the status-grouped goal board and the
 * per-character state timelines (S02-T04). Authoring is thin form wiring:
 * every action does a pre-guard + flash and delegates the business logic
 * to CampaignJournalService (INV-6) — the service re-validates everything.
 *
 * @property-read bool $canViewJournal
 * @property-read bool $isGm
 * @property-read bool $canPostInCharacter
 * @property-read CursorPaginator<int, CampaignJournalEntry> $timeline
 * @property-read array<int, array{status: GoalStatus, goals: Collection<int, CampaignGoal>}> $goalGroups
 * @property-read Collection<int, CampaignParticipant> $participantTimelines
 */
class CampaignJournal extends Component
{
    use WithPagination;

    /** D232: eagerly loaded state changes per character (latest first). */
    private const int STATE_CHANGES_PER_CHARACTER = 10;

    #[Locked]
    public Campaign $campaign;

    /** D232: older-history rows loaded per "Load earlier changes" click. */
    private const int OLDER_STATE_CHANGES_CHUNK = 25;

    /** Upper bound of loaded older-history ids per character (5 chunks). */
    private const int MAX_OLDER_STATE_CHANGE_IDS = 125;

    /**
     * D232: state changes older than the eagerly-loaded window, fetched
     * on explicit "Load earlier changes" clicks — keyed by participant
     * id. Only the row IDS are kept in component state, hard-capped at
     * MAX_OLDER_STATE_CHANGE_IDS: public Livewire properties are
     * re-serialized into every round-trip, so the payload stays a
     * bounded list of uuids — never hydrated models or deltas, which
     * would bloat each request linearly with history depth.
     *
     * @var array<string, array<int, string>>
     */
    public array $olderStateChangeIds = [];

    /**
     * The keyset cursor per participant: the (created_at, id) of the
     * oldest LOADED older row. Display tuples are re-rendered from the
     * ids on each request, so only the cursor needs to persist.
     *
     * @var array<string, array{at: string, id: string}>
     */
    public array $olderStateChangeCursor = [];

    /**
     * Per participant: true once the whole remaining history has been
     * loaded — hides the "Load earlier changes" affordance.
     *
     * @var array<string, bool>
     */
    public array $olderStateChangesExhausted = [];

    /**
     * Per participant: true once MAX_OLDER_STATE_CHANGE_IDS rows are
     * loaded — deeper history exists but stays unrendered. The on-demand
     * surface is a "recent history" affordance, not an archive reader;
     * the cap keeps one GM's deep dive from pinning megabytes into every
     * later round-trip (filter switches, goal edits, form posts).
     *
     * @var array<string, bool>
     */
    public array $olderStateChangesCapped = [];

    /** Timeline kind filter: 'all' or a JournalEntryKind value. */
    public string $kindFilter = 'all';

    // ── Authoring form state (S02-T04) ─────────────────────

    /** In-character post body (any active participant, incl. the GM). */
    public string $inCharacterBody = '';

    /** Optional free-text in-game date stamp (e.g. "9. Flockentag, 1372 Dal"). */
    public string $inGameDate = '';

    /** GM prep note body. The form renders for the GM only. */
    public string $prepNoteBody = '';

    /** Goal authoring: title + optional description (new goals start Planned). */
    public string $goalTitle = '';

    public string $goalDescription = '';

    /**
     * Per-goal status selects: goal id => chosen GoalStatus value. The
     * blade falls back to the goal's current status until the GM picks
     * one, so the array only ever holds explicit user choices.
     *
     * @var array<string, string>
     */
    public array $goalStatuses = [];

    /** State-change logging (GM-only form): target character. */
    public string $stateParticipantId = '';

    /** @var string One of CharacterStateChangeType's values. */
    public string $stateChangeType = '';

    /** State-change logging: the free-text delta. */
    public string $stateDelta = '';

    public function mount(Campaign $campaign): void
    {
        $this->campaign = $campaign;
    }

    public function updatingKindFilter(): void
    {
        // A filter switch must not strand the reader on a cursor page that
        // was built for the previous filter.
        $this->resetPage('cursor');
    }

    // ── Computed viewer state ─────────────────────────────

    /**
     * The member gate (CampaignPolicy::viewJournal): owner or seated
     * participant. Fails closed for guests (null user) even though the
     * dashboard route is auth-gated — defense in depth. Nothing in this
     * component renders without it.
     */
    #[Computed]
    public function canViewJournal(): bool
    {
        $user = Auth::user();

        return $user?->can('viewJournal', $this->campaign) ?? false;
    }

    /**
     * The journal's GM surfaces (gms-visibility entries, the GM Notes
     * filter, the write forms) key on CAMPAIGN OWNERSHIP — not the
     * policy's admin-before grant. A global admin passes viewJournal
     * but reads the member journal: deliberately fail-closed (no
     * GM-note leak) at the cost of no "content hidden" signal.
     */
    #[Computed]
    public function isGm(): bool
    {
        return (string) $this->campaign->owner_id === (string) Auth::id();
    }

    /**
     * Reverse-chronological timeline, keyset-paginated. Keyset (not offset)
     * because HasPlatformUuid's UUIDv7 ids give a stable total order:
     * orderByDesc('id') breaks created_at ties at second precision (D170),
     * so pages never skip or repeat rows as entries are written.
     *
     * @return CursorPaginator<int, CampaignJournalEntry>
     */
    #[Computed]
    public function timeline()
    {
        if (! $this->canViewJournal()) {
            // Fail closed: an unauthorized viewer gets an empty timeline and
            // no query runs — the visibility gate alone is not enough because
            // a stranger is not a player either.
            /** @var CursorPaginator<int, CampaignJournalEntry> $empty */
            $empty = new CursorPaginator(collect(), 20);

            return $empty;
        }

        $query = CampaignJournalEntry::query()
            ->where('campaign_id', $this->campaign->id)
            ->with('authorUser', 'authorParticipant.user', 'game')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (! $this->isGm()) {
            // Players never see gms rows in ANY filter. The visibility gate
            // runs before the kind filter so a player-supplied prep_note
            // filter value is a no-op rather than a leak.
            $query->where('visibility', JournalEntryVisibility::Players->value);
        }

        if ($this->kindFilter !== 'all') {
            $query->where('kind', $this->kindFilter);
        }

        return $query->cursorPaginate(20);
    }

    /**
     * The status-grouped goal board (S02-T04): goals ordered by sort,
     * grouped by status in the fixed board order planned → active →
     * achieved → failed → abandoned. Only non-empty groups render — the
     * board stays light instead of padding four empty columns.
     *
     * @return array<int, array{status: GoalStatus, goals: Collection<int, CampaignGoal>}>
     */
    #[Computed]
    public function goalGroups(): array
    {
        if (! $this->canViewJournal()) {
            return [];
        }

        /** @var Collection<string, Collection<int, CampaignGoal>> $goals */
        $goals = $this->campaign->goals()
            ->orderBy('sort')
            ->get()
            // Group by the backed value, not the enum instance: PHP array
            // keys are int|string and Collection::get() rejects enums
            // ("Cannot access offset of type GoalStatus on array").
            ->groupBy(fn (CampaignGoal $goal): string => $goal->status->value);

        $groups = [];

        foreach (GoalStatus::cases() as $status) {
            /** @var Collection<int, CampaignGoal>|null $groupGoals */
            $groupGoals = $goals->get($status->value);

            if ($groupGoals !== null && $groupGoals->isNotEmpty()) {
                $groups[] = ['status' => $status, 'goals' => $groupGoals];
            }
        }

        return $groups;
    }

    /**
     * Per-character state timelines (S02-T04, bounded per D232): the
     * seated table (Approved and Benched participants — the member-gate
     * set; the owner's own participant row is Approved) with user eager-
     * loaded and the LATEST 10 state changes PER CHARACTER attached in
     * one windowed query, newest-first — so Journal-tab render cost
     * stays flat no matter how long the campaign runs or how many
     * characters are seated. Deeper history loads on explicit request
     * (loadOlderStateChanges). Rows of removed members survive in the
     * table as campaign history (nullOnDelete precedent); both the S04
     * digest scan and this timeline skip them — there is no seated
     * character left to attribute.
     *
     * The window function is load-bearing: an eager-load closure with
     * ->limit() applies the limit to the COMBINED whereIn(participant_id)
     * query — one global top-10 across every seated character, where the
     * most-active character swallows the window and the rest render
     * empty with an unreachable history. row_number() partitions per
     * participant inside Postgres, the ScheduledTaskRun::latestPerTask /
     * PreviouslyOnService::latestConditionAndItemChanges shape.
     *
     * The window is filtered INSIDE SQL (fromSub + window_row <= N), so
     * the query and hydration cost — not just the render cost — stays
     * flat no matter how long the campaign runs; and the outer ORDER BY
     * makes newest-first a SQL contract instead of the WindowAgg sort's
     * side effect. Column and literal references are code-controlled
     * (model + migration).
     *
     * @return Collection<int, CampaignParticipant>
     */
    #[Computed]
    public function participantTimelines(): Collection
    {
        if (! $this->canViewJournal()) {
            return collect();
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, CampaignParticipant> $participants */
        $participants = $this->campaign->participants()
            ->whereIn('status', [ParticipantStatus::Approved->value, ParticipantStatus::Benched->value])
            ->with('user')
            ->withCount('stateChanges')
            ->get();

        /** @var Collection<string, Collection<int, CharacterStateChange>> $windowed */
        $windowed = CharacterStateChange::query()
            ->fromSub(
                CharacterStateChange::query()
                    ->whereIn('participant_id', $participants->modelKeys())
                    ->selectRaw('character_state_changes.*, row_number() over (partition by participant_id order by created_at desc, id desc) as window_row'),
                'windowed_state_changes'
            )
            ->where('window_row', '<=', self::STATE_CHANGES_PER_CHARACTER)
            ->orderBy('participant_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('participant_id');

        $participants->each(fn (CampaignParticipant $participant): CampaignParticipant => $participant->setRelation(
            'stateChanges',
            $windowed->get($participant->id, collect()),
        ));

        return $participants;
    }

    /**
     * Load the NEXT bounded chunk of older state changes for one
     * character on explicit request (D232): one round-trip never fetches
     * an unbounded history, and repeated clicks walk the keyset down
     * until exhausted. The cursor sits below everything already
     * displayed — the last loaded older row when present, otherwise the
     * eager window's oldest row (created_at, id tuple — the same
     * ordering the window uses), so the first click composes correctly
     * no matter when it fires. Fail-closed: the viewJournal gate
     * re-checked, the participant must be a seated member of THIS
     * campaign.
     */
    public function loadOlderStateChanges(string $participantId): void
    {
        if (
            ! $this->canViewJournal()
            || ($this->olderStateChangesExhausted[$participantId] ?? null) === true
            || ($this->olderStateChangesCapped[$participantId] ?? null) === true
        ) {
            return;
        }

        $participant = $this->campaign->participants()
            ->whereKey($participantId)
            ->whereIn('status', [ParticipantStatus::Approved->value, ParticipantStatus::Benched->value])
            ->first();

        if ($participant === null) {
            return;
        }

        $loadedIds = array_values(array_filter(
            $this->olderStateChangeIds[$participantId] ?? [],
            fn (string $id): bool => Str::isUuid($id),
        ));

        $cursor = $this->olderStateChangeCursor[$participantId] ?? null;
        // The docblock shape is the writer's contract, not the client's:
        // a tampered payload may miss keys or carry non-strings, so every
        // component is read through the null-coalescing cast first.
        $cursorAt = (string) ($cursor['at'] ?? '');
        $cursorId = (string) ($cursor['id'] ?? '');
        $cursorWellFormed = $cursor !== null
            && $cursorAt !== ''
            && Str::isUuid($cursorId)
            && str_starts_with($cursorAt, '20')
            && Carbon::canBeCreatedFromFormat($cursorAt, 'Y-m-d H:i:s');

        if ($loadedIds !== [] && $cursorWellFormed) {
            // Keyset cursor = the oldest row already displayed, persisted
            // from the previous chunk (the ids alone do not carry it).
            // Malformed persisted state (tampered updates payload) is
            // discarded — the first-click path re-derives the cursor
            // server-side instead of binding garbage into the query.
        } else {
            // First click: the cursor is the window's oldest row = the
            // 10th newest under (created_at DESC, id DESC) — fetched at
            // the query level (offset+limit, Larastan's
            // noUnnecessaryCollectionCall rule).
            $oldest = $participant->stateChanges()
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->offset(self::STATE_CHANGES_PER_CHARACTER - 1)
                ->limit(1)
                ->first();

            if ($loadedIds !== []) {
                // Tampered/discarded history state: restart from the
                // server-derived cursor instead of stacking onto it.
                $loadedIds = [];
                unset($this->olderStateChangeCursor[$participantId]);
            }

            if ($oldest === null) {
                // Fewer rows than the window holds: nothing older exists.
                $this->olderStateChangesExhausted[$participantId] = true;

                return;
            }

            $cursorAt = (string) $oldest->created_at;
            $cursorId = (string) $oldest->id;
        }

        // CHUNK + 1 fetched so "more exists" is detected from the rows
        // themselves — no extra COUNT(*) round-trip per click.
        /** @var Collection<int, CharacterStateChange> $older */
        $older = $participant->stateChanges()
            ->where(function ($query) use ($cursorAt, $cursorId): void {
                $query->where('created_at', '<', $cursorAt)
                    ->orWhere(fn ($sub) => $sub
                        ->where('created_at', '=', $cursorAt)
                        ->where('id', '<', $cursorId));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::OLDER_STATE_CHANGES_CHUNK + 1)
            ->get();

        $more = $older->count() > self::OLDER_STATE_CHANGES_CHUNK;
        $chunk = $older->take(self::OLDER_STATE_CHANGES_CHUNK);

        $loadedIds = array_merge($loadedIds, $chunk->map(fn (CharacterStateChange $change): string => (string) $change->id)->all());
        $this->olderStateChangeIds[$participantId] = $loadedIds;

        if (! $more) {
            $this->olderStateChangesExhausted[$participantId] = true;

            return;
        }

        if (count($loadedIds) >= self::MAX_OLDER_STATE_CHANGE_IDS) {
            // Bound reached: deeper history exists, but the loaded set
            // must not grow without limit. The cursor stops here too —
            // the capped flag retires the affordance.
            $this->olderStateChangesCapped[$participantId] = true;

            return;
        }

        $oldestLoaded = $chunk->last();

        if ($oldestLoaded !== null) {
            $this->olderStateChangeCursor[$participantId] = [
                'at' => (string) $oldestLoaded->created_at,
                'id' => (string) $oldestLoaded->id,
            ];
        }
    }

    /**
     * Display tuples for one character's loaded older history, built
     * fresh on every render from the bounded id set — one query per
     * character with loaded history. Rendering from the ids (not cached
     * tuples) is what keeps dates locale-current: format_date runs per
     * request, so a mid-session locale switch re-localizes loaded rows
     * too.
     *
     * @return list<array{id: string, label: string, delta: string, when: string}>
     */
    public function olderChangesFor(string $participantId): array
    {
        // Same gate as loadOlderStateChanges: the snapshot method is
        // public, and the asymmetry would become a leak the moment the
        // component is re-nested outside the gated Journal tab.
        if (! $this->canViewJournal()) {
            return [];
        }

        $ids = array_slice(
            array_values(array_filter(
                $this->olderStateChangeIds[$participantId] ?? [],
                fn (string $id): bool => Str::isUuid($id),
            )),
            0,
            self::MAX_OLDER_STATE_CHANGE_IDS,
        );

        if ($ids === []) {
            return [];
        }

        $tuples = [];

        // The id set is client-hydrated state, so the read-back re-scopes
        // to THIS campaign before rendering: foreign ids never resolve,
        // and a tampered list cannot widen the query beyond the cap.
        foreach (CharacterStateChange::query()
            ->whereKey($ids)
            ->where('campaign_id', $this->campaign->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get() as $change) {
            $tuples[] = [
                'id' => (string) $change->id,
                'label' => $change->change_type->label(),
                'delta' => $change->delta,
                'when' => format_date($change->created_at, 'datetime'),
            ];
        }

        return $tuples;
    }

    // ── Authoring actions (S02-T04) ───────────────────────
    // Pre-guard + flash only — CampaignJournalService owns every rule
    // and re-validates (defense in depth, INV-6).

    /**
     * The viewer's own eligible participant row (Approved or Benched,
     * incl. the owner's row), or null when they cannot post in-character.
     */
    private function eligibleParticipant(): ?CampaignParticipant
    {
        $user = Auth::user();

        if ($user === null || ! $this->canViewJournal()) {
            return null;
        }

        /** @var CampaignParticipant|null $participant */
        $participant = CampaignParticipant::query()
            ->whereBelongsTo($this->campaign)
            ->whereBelongsTo($user)
            ->whereIn('status', [ParticipantStatus::Approved->value, ParticipantStatus::Benched->value])
            ->first();

        return $participant;
    }

    #[Computed]
    public function canPostInCharacter(): bool
    {
        return $this->eligibleParticipant() !== null;
    }

    /**
     * Shared GM pre-guard: flashes the unauthorized error and returns null
     * when the acting viewer is not the campaign GM. The service re-checks
     * ownership on every write path.
     */
    private function authorizeGm(): ?User
    {
        $user = Auth::user();

        if ($user === null || ! $this->canViewJournal() || ! $this->isGm()) {
            session()->flash('error', __('common.error_not_authorized'));

            return null;
        }

        return $user;
    }

    public function postInCharacter(): void
    {
        $participant = $this->eligibleParticipant();

        if ($participant === null) {
            session()->flash('error', __('journal.error_participant_not_active'));

            return;
        }

        // Rate limit (10/h per member per campaign): journal entries are
        // member-visible UGC — public campaigns seat any authenticated
        // member, and every comparable member surface (venue proposals,
        // support, system requests) throttles the same way.
        $rateLimitKey = 'journal:'.$this->campaign->id.':'.$participant->user_id;

        if (RateLimiter::attempt($rateLimitKey, 10, fn () => true, decaySeconds: 3600) !== true) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($rateLimitKey) / 60));
            session()->flash('error', __('journal.error_rate_limited', ['minutes' => $minutes]));

            return;
        }

        try {
            app(CampaignJournalService::class)->writeInCharacter(
                $this->campaign,
                $participant,
                $this->inCharacterBody,
                $this->inGameDate !== '' ? $this->inGameDate : null,
            );
        } catch (\LogicException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('success', __('journal.flash_in_character_posted'));
        $this->reset('inCharacterBody', 'inGameDate');
        unset($this->timeline);
    }

    public function postPrepNote(): void
    {
        $user = $this->authorizeGm();

        if ($user === null) {
            return;
        }

        try {
            app(CampaignJournalService::class)->writePrepNote($this->campaign, $user, $this->prepNoteBody);
        } catch (\LogicException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('success', __('journal.flash_prep_note_posted'));
        $this->reset('prepNoteBody');
        unset($this->timeline);
    }

    public function createGoal(): void
    {
        $user = $this->authorizeGm();

        if ($user === null) {
            return;
        }

        try {
            app(CampaignJournalService::class)->createGoal(
                $this->campaign,
                $user,
                $this->goalTitle,
                $this->goalDescription !== '' ? $this->goalDescription : null,
            );
        } catch (\LogicException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('success', __('journal.flash_goal_created'));
        $this->reset('goalTitle', 'goalDescription');
        unset($this->goalGroups);
    }

    public function changeGoalStatus(string $goalId): void
    {
        $user = $this->authorizeGm();

        if ($user === null) {
            return;
        }

        /** @var CampaignGoal|null $goal */
        $goal = $this->campaign->goals()->whereKey($goalId)->first();

        if ($goal === null) {
            // A goal id from another campaign (or invented): the scoped
            // query means the write path is never even reached.
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        // An untouched select (key missing) or a tampered value falls
        // back to the goal's current status — the service no-ops on
        // unchanged statuses, so a stray "Update" click never errors.
        $status = GoalStatus::tryFrom($this->goalStatuses[$goalId] ?? '') ?? $goal->status;

        try {
            app(CampaignJournalService::class)->changeGoalStatus($goal, $user, $status);
        } catch (\LogicException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('success', __('journal.flash_goal_status_changed'));
        unset($this->goalStatuses[$goalId], $this->goalGroups);
    }

    public function logStateChange(): void
    {
        $user = $this->authorizeGm();

        if ($user === null) {
            return;
        }

        $type = CharacterStateChangeType::tryFrom($this->stateChangeType);

        /** @var CampaignParticipant|null $participant */
        $participant = $this->campaign->participants()
            ->whereKey($this->stateParticipantId)
            ->whereIn('status', [ParticipantStatus::Approved->value, ParticipantStatus::Benched->value])
            ->first();

        if ($type === null || $participant === null) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        try {
            app(CampaignJournalService::class)->logStateChange(
                $this->campaign,
                $user,
                $participant,
                $type,
                $this->stateDelta,
            );
        } catch (\LogicException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('success', __('journal.flash_state_change_logged'));
        $this->reset('stateParticipantId', 'stateChangeType', 'stateDelta');
        unset($this->participantTimelines);
        // The new row shifts the eager window down — any loaded older
        // history for this character is now stale (the row that just
        // fell out of the window is missing from it AND from the loaded
        // chunk, silently invisible until reload). Drop both markers so
        // "Load earlier" re-walks the keyset from the new window edge.
        unset(
            $this->olderStateChangeIds[$participant->id],
            $this->olderStateChangeCursor[$participant->id],
            $this->olderStateChangesExhausted[$participant->id],
            $this->olderStateChangesCapped[$participant->id],
        );
    }

    public function render(): View
    {
        return view('livewire.campaigns.campaign-journal', [
            // The eager window size, shared with the blade's "load
            // earlier changes" affordance so the visibility threshold
            // can never drift from the window the cursor math composes
            // with (STATE_CHANGES_PER_CHARACTER).
            'stateChangesWindow' => self::STATE_CHANGES_PER_CHARACTER,
            'canViewJournal' => $this->canViewJournal(),
            'isGm' => $this->isGm(),
            'canPostInCharacter' => $this->canPostInCharacter(),
            'timeline' => $this->timeline(),
            'goalGroups' => $this->goalGroups(),
            'participantTimelines' => $this->participantTimelines(),
        ]);
    }
}
