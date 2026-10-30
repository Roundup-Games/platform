<?php

namespace App\Livewire\Campaigns;

use App\Enums\GoalStatus;
use App\Enums\JournalEntryKind;
use App\Enums\JournalEntryVisibility;
use App\Models\Campaign;
use App\Models\CampaignGoal;
use App\Models\CampaignJournalEntry;
use App\Services\CampaignRecruitmentSummaryService;
use App\Support\TableLocale;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use LogicException;

/**
 * The GM's recruitment-summary composer (M071/S05-T03, spec §4): the
 * spoiler-safe "Campaign so far" block published to the public campaign
 * page (R082 — a free feature, so the gate is ownership only, never a
 * membership gate).
 *
 * Separate nested component inside CampaignJournal's GM block per the
 * S02 composition pattern; renders its own flash banner because a
 * nested action only re-renders the child (MEM1379). The entry button
 * lives in campaign-journal.blade.php and opens the composer by
 * dispatching the open-recruitment-composer event.
 *
 * Fail-closed mount contract: an unauthorized viewer (player, guest)
 * renders an empty shell and no journal data is even queried — every
 * action re-authorizes through CampaignPolicy::manageRecruitmentSummary
 * and the service re-validates ownership again (defense in depth).
 *
 * The textarea is the single source of truth: goal checkboxes and the
 * excerpt picker only append/remove plain-text lines to $draft, and the
 * Alpine redact button rewrites the selection server-side via
 * $wire.set('draft', …) — the journal rows are never touched.
 *
 * @property-read Collection<int, CampaignGoal> $safeGoals
 * @property-read Collection<int, CampaignJournalEntry> $recapEntries
 * @property-read string|null $appliedSummary
 * @property-read int $staleSessions
 * @property-read string $publicUrl
 */
class CampaignRecruitmentComposer extends Component
{
    #[Locked]
    public Campaign $campaign;

    /** Composer visibility; the closed state renders only the flash banner. */
    public bool $open = false;

    /** The editable draft — auto-seeded once, then GM-owned. */
    public string $draft = '';

    /**
     * Spec §4.1 spoiler-safety checklist. All three must be confirmed
     * before Apply; the server enforces it via the service, the Alpine
     * :disabled binding is client convenience only.
     *
     * @var array<string, bool>
     */
    public array $checklist = [
        'review_excerpts' => false,
        'names_removed' => false,
        'visitor_preview' => false,
    ];

    /**
     * Per-goal checkbox state: goal id => checked. toggleGoal() reads
     * the post-update value to decide append vs remove.
     *
     * @var array<string, bool>
     */
    public array $goalChecked = [];

    /** Visitor-preview replica panel toggle. */
    public bool $previewing = false;

    public function mount(Campaign $campaign): void
    {
        $this->campaign = $campaign;
    }

    /**
     * Fail-closed authorization: the campaign owner only, resolved
     * through the dedicated ability (never viewJournal — seated
     * participants must not publish blocks; never a membership gate —
     * R082 is free). Global admins pass via the policy's before().
     */
    private function viewerCanManage(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->can('manageRecruitmentSummary', $this->campaign);
    }

    #[On('open-recruitment-composer')]
    public function openComposer(): void
    {
        if (! $this->viewerCanManage()) {
            return;
        }

        // First open seeds the default-safe auto-draft; reopens keep
        // the GM's edits (an emptied draft deliberately reseeds — the
        // only way back to the auto-draft).
        if ($this->draft === '') {
            $this->draft = app(CampaignRecruitmentSummaryService::class)->draft($this->campaign);
        }

        $this->open = true;
    }

    // ── Computed pass-throughs ────────────────────────────

    /**
     * Active goals only, sort order: the GM marks these safe to share
     * one by one — nothing is auto-included (spec §4.1).
     *
     * @return Collection<int, CampaignGoal>
     */
    #[Computed]
    public function safeGoals(): Collection
    {
        /** @var Collection<int, CampaignGoal> */
        return $this->campaign->goals()
            ->where('status', GoalStatus::Active->value)
            ->orderBy('sort')
            ->get();
    }

    /**
     * Player-visible recaps, latest first, capped at 20 — the excerpt
     * picker's offer list. Prep notes and gms-visibility entries are
     * NEVER offered (spec §4.1: only the GM's own session recaps are
     * excerpt candidates).
     *
     * @return Collection<int, CampaignJournalEntry>
     */
    #[Computed]
    public function recapEntries(): Collection
    {
        /** @var Collection<int, CampaignJournalEntry> */
        return $this->campaign->journalEntries()
            ->where('kind', JournalEntryKind::Recap->value)
            ->where('visibility', JournalEntryVisibility::Players->value)
            ->with('game')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();
    }

    /**
     * The currently applied block body, or null when none is live.
     */
    #[Computed]
    public function appliedSummary(): ?string
    {
        return $this->campaign->recruitment_summary;
    }

    /**
     * Completed sessions accrued since the applied stamp (0 when no
     * block is live) — feeds the parent Journal tab's staleness nudge.
     */
    #[Computed]
    public function staleSessions(): int
    {
        return $this->campaign->recruitmentSummaryStaleSessions();
    }

    /**
     * The public campaign page URL (copy-public-link actions).
     */
    #[Computed]
    public function publicUrl(): string
    {
        return route('campaigns.detail', $this->campaign);
    }

    // ── Draft assembly actions ────────────────────────────

    /**
     * Append the goal's "- title" line when its checkbox flips on,
     * remove every exact copy of that line when it flips off — the
     * textarea stays the single source of truth. Matching is
     * LINE-EXACT, deliberately: substring containment misfires on
     * prefix titles ("- Act I" matches "- Act II: The Siege" and
     * silently adds nothing), and removing the first line must not
     * leave a stray leading newline behind. A goal id from another
     * campaign (or invented) is a no-op: the scoped query never finds
     * it. Re-authorizes like every sibling action (fail-closed
     * contract).
     */
    public function toggleGoal(string $goalId): void
    {
        if (! $this->viewerCanManage()) {
            return;
        }

        /** @var CampaignGoal|null $goal */
        $goal = $this->campaign->goals()->whereKey($goalId)->first();

        if ($goal === null) {
            return;
        }

        $line = '- '.$goal->title;
        $lines = explode("\n", $this->draft);

        if ($this->goalChecked[$goalId] ?? false) {
            if (! in_array($line, $lines, true)) {
                $this->draft = trim($this->draft) === '' ? $line : $this->draft."\n".$line;
            }

            return;
        }

        $this->draft = implode("\n", array_filter(
            $lines,
            fn (string $candidate): bool => $candidate !== $line,
        ));
    }

    /**
     * Append a labeled excerpt block (label line + entry body). Nothing
     * is auto-included — only explicitly picked entries enter the
     * draft. Interlude/foreign/gms-visibility ids are no-ops.
     */
    public function appendExcerpt(string $entryId): void
    {
        // Same fail-closed re-authorization as every sibling action —
        // appendExcerpt reads player-visibility journal bodies into the
        // (client-visible) draft, so it must never run for a non-GM
        // under any future nesting change.
        if (! $this->viewerCanManage()) {
            return;
        }

        /** @var CampaignJournalEntry|null $entry */
        $entry = $this->campaign->journalEntries()
            ->whereKey($entryId)
            ->where('kind', JournalEntryKind::Recap->value)
            ->where('visibility', JournalEntryVisibility::Players->value)
            ->first();

        if ($entry === null) {
            return;
        }

        // Label and date resolve under the campaign's table locale —
        // the same single-language contract draft() seeds under
        // (CampaignRecruitmentSummaryService: a DE campaign with an
        // EN-UI GM gets a fully German block, ambient locale restored).
        $block = TableLocale::with($this->campaign->language, fn (): string => __('journal.label_recruitment_excerpt', [
            'session' => format_date($entry->game?->date_time),
        ]))."\n".$entry->body;

        $this->draft = trim($this->draft) === '' ? $block : $this->draft."\n\n".$block;
    }

    // ── Publish / retract ─────────────────────────────────

    public function apply(): void
    {
        $user = Auth::user();

        if ($user === null || ! $user->can('manageRecruitmentSummary', $this->campaign)) {
            session()->flash('error', __('journal.error_recruitment_unauthorized'));

            return;
        }

        try {
            app(CampaignRecruitmentSummaryService::class)
                ->apply($this->campaign, $user, $this->draft, $this->checklist);
        } catch (LogicException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('success', __('journal.flash_recruitment_applied'));
        unset($this->appliedSummary, $this->staleSessions);
    }

    public function remove(): void
    {
        $user = Auth::user();

        if ($user === null || ! $user->can('manageRecruitmentSummary', $this->campaign)) {
            session()->flash('error', __('journal.error_recruitment_unauthorized'));

            return;
        }

        try {
            app(CampaignRecruitmentSummaryService::class)->remove($this->campaign, $user);
        } catch (LogicException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('success', __('journal.flash_recruitment_removed'));
        unset($this->appliedSummary, $this->staleSessions);
    }

    public function render(): View
    {
        if (! $this->viewerCanManage()) {
            // Fail closed: empty shell, no journal data queried, but the
            // flash banner still renders so rejected actions surface.
            return view('livewire.campaigns.campaign-recruitment-composer', [
                'authorized' => false,
                'safeGoals' => collect(),
                'recapEntries' => collect(),
                'appliedSummary' => null,
                'staleSessions' => 0,
                'publicUrl' => '',
                'refreshedAfterCount' => null,
            ]);
        }

        return view('livewire.campaigns.campaign-recruitment-composer', [
            'authorized' => true,
            'safeGoals' => $this->safeGoals(),
            'recapEntries' => $this->recapEntries(),
            'appliedSummary' => $this->appliedSummary(),
            'staleSessions' => $this->staleSessions(),
            'publicUrl' => $this->publicUrl(),
            // The preview's "Last refreshed after session N" line shows
            // the applied stamp when a block is live, otherwise the
            // count apply() would stamp right now — an honest replica.
            // Zero (no completed session yet) hides the line entirely:
            // "after session 0" reads wrong pre-first-session.
            'refreshedAfterCount' => $this->refreshedAfterCount(),
        ]);
    }

    /**
     * The "after session N" the preview should display: the applied
     * stamp when a block is live, otherwise the count apply() would
     * stamp right now — null when zero (no completed session yet).
     */
    private function refreshedAfterCount(): ?int
    {
        $count = max(
            ($this->campaign->recruitment_summary_completed_sessions
                ?? $this->campaign->completedSessionsCount()),
            0,
        );

        return $count > 0 ? $count : null;
    }
}
