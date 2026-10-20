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
use App\Models\User;
use App\Services\CampaignJournalService;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
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

    #[Locked]
    public Campaign $campaign;

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
     * Per-character state timelines (S02-T04): the seated table (Approved
     * and Benched participants — the member-gate set; the owner's own
     * participant row is Approved) with user + state changes eager-loaded
     * in one pass, newest-first, so expanding a timeline costs zero extra
     * requests. Rows of removed members survive in the table (nullOnDelete
     * precedent) for the S04 scans but are not surfaced here.
     *
     * @return Collection<int, CampaignParticipant>
     */
    #[Computed]
    public function participantTimelines(): Collection
    {
        if (! $this->canViewJournal()) {
            return collect();
        }

        /** @var Collection<int, CampaignParticipant> $participants */
        $participants = $this->campaign->participants()
            ->whereIn('status', [ParticipantStatus::Approved->value, ParticipantStatus::Benched->value])
            ->with([
                'user',
                'stateChanges' => fn ($query) => $query->orderByDesc('created_at')->orderByDesc('id'),
            ])
            ->get();

        return $participants;
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
    }

    public function render(): View
    {
        return view('livewire.campaigns.campaign-journal', [
            'canViewJournal' => $this->canViewJournal(),
            'isGm' => $this->isGm(),
            'canPostInCharacter' => $this->canPostInCharacter(),
            'timeline' => $this->timeline(),
            'goalGroups' => $this->goalGroups(),
            'participantTimelines' => $this->participantTimelines(),
        ]);
    }
}
