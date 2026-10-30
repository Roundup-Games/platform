<?php

namespace App\Livewire\Games;

use App\Dto\CapacityUpdateResult;
use App\Enums\AttendanceStatus;
use App\Enums\GameStatus;
use App\Enums\JoinSource;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\Review;
use App\Models\SessionDebriefing;
use App\Models\SessionZeroSurvey;
use App\Services\AttendanceService;
use App\Services\CapacityService;
use App\Services\Charter\CharterSigningService;
use App\Services\DebriefingService;
use App\Services\EntityJoinGate;
use App\Services\ParticipantLifecycle;
use App\Services\ReviewEligibilityService;
use App\Services\ShareLinkJoinService;
use App\Services\ShortLinkService;
use App\Services\WaitlistService;
use App\Support\DiscordJoinIntent;
use App\Traits\HandlesBench;
use App\Traits\HandlesSessionEnd;
use App\Traits\HandlesWaitlist;
use App\Traits\ManagesParticipants;
use App\Traits\ManagesShortLinks;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class GameDetail extends Component
{
    use HandlesBench, HandlesSessionEnd, HandlesWaitlist, ManagesParticipants;
    use ManagesShortLinks;

    public Game $game;

    /** @var array<int|string, mixed> Attendance form data keyed by participant ID */
    public array $attendanceReports = [];

    /** @var array<string, string> Debriefing form responses keyed by prompt key */
    public array $debriefingResponses = [];

    /** @var string|null Recap content for host write-recap form */
    public ?string $recapContent = null;

    /** @var string|null Reason for attendance dispute */
    public ?string $disputeReason = null;

    /** @var string|null Validated share token captured on mount, persists across Livewire updates */
    #[Locked]
    public ?string $validatedShareToken = null;

    /** @var int|null Validated short link ID captured on mount via ph_link_id cookie */
    #[Locked]
    public ?int $validatedShortLinkId = null;

    public ?string $confirmingAction = null;

    /** @var string|null Marks the capacity-decrease confirm modal open. */
    public ?string $confirmingCapacityDecrease = null;

    /** @var int|null The pending new max_players awaiting host confirmation. */
    public ?int $pendingNewMax = null;

    /** @var string|null Host-supplied reason shown in the SeatDemoted notification. */
    public ?string $capacityReason = null;

    /** @var int|null Host-edited max_players input (wire:model target). */
    public ?int $capacityNewMax = null;

    public function mount(string $id): void
    {
        $game = Game::with('campaign')->findOrFail($id);
        $this->authorize('view', $game);
        $this->game = $game;

        // Capture valid share token on initial page load (query params don't persist across Livewire updates)
        if ($game->hasValidShareToken()) {
            $this->validatedShareToken = request()->query('share');
        }

        // Detect short link arrival via ph_link_id cookie
        $this->detectShortLink();

        // M059/S02: a member arriving from the Discord "My seat" on-ramp lands
        // here with ?discord_join=1. The query param is forgeable, so the only
        // load-bearing gate is canJoinViaDiscord() (which enforces public
        // visibility — see its docblock for why protected games are screened).
        //
        // The intent has served its purpose the moment the member lands here,
        // so it is CONSUMED regardless of whether the auto-join fires — a skip
        // (already a participant, signup closed, …) or a thrown write must never
        // leave a stale intent that nothing else reads. Failure here must NEVER
        // brick the page load: a thrown exception from the write is swallowed
        // and surfaced as a flash error so the member can retry via the explicit
        // button. Only fires on the initial browser navigation (Livewire updates
        // do not carry the query param).
        if (request()->query('discord_join') === '1' && Auth::check()) {
            app(DiscordJoinIntent::class)->consume(request());

            if ($this->canJoinViaDiscord()) {
                try {
                    $this->joinViaDiscord();
                } catch (\Throwable $e) {
                    // joinViaDiscord() already logged the failure; degrade to a
                    // normal page render with an error flash so the member can retry.
                    session()->flash('error', __('games.error_join_game_via_share_link_failed'));
                }
            }
        }

        // Initialize attendance form defaults (everyone = attended) if window is open
        if ($this->isAttendanceWindowOpen()) {
            $this->initializeAttendanceReports();
        }
    }

    // ── Trait contracts ────────────────────────────────

    public function getEntity(): Game
    {
        return $this->game;
    }

    // ── Attendance UI ──────────────────────────────────

    /**
     * Initialize attendance form with defaults: all approved participants = attended.
     */
    private function initializeAttendanceReports(): void
    {
        $viewerId = $this->viewerId();

        $this->attendanceReports = $this->game->participants
            ->filter(fn ($p) => $p->status === ParticipantStatus::Approved
                && $p->user_id !== $viewerId)
            ->mapWithKeys(fn ($p) => [
                $p->id => ['status' => AttendanceStatus::Attended->value, 'reason' => null],
            ])
            ->toArray();
    }

    /**
     * Whether the attendance reporting window is open and unresolved.
     */
    #[Computed]
    public function isAttendanceWindowOpen(): bool
    {
        $game = $this->game;

        if ($game->status !== GameStatus::Completed) {
            return false;
        }

        // Already resolved
        if ($game->attendance_resolved_at !== null) {
            return false;
        }

        // Window hasn't opened yet
        if ($game->attendance_window_opens_at !== null && now()->lt($game->attendance_window_opens_at)) {
            return false;
        }

        // Window has closed
        if ($game->attendance_window_closes_at !== null && now()->gt($game->attendance_window_closes_at)) {
            return false;
        }

        return true;
    }

    /**
     * Whether the current user has submitted at least one attendance report for this game.
     */
    #[Computed]
    public function hasSubmittedAttendance(): bool
    {
        $viewerId = $this->viewerId();

        if ($viewerId === null) {
            return false;
        }

        return AttendanceReport::where('game_id', $this->game->id)
            ->where('reporter_id', $viewerId)
            ->exists();
    }

    /**
     * Vote tallies per participant, grouped by status.
     *
     * Returns array keyed by reported_id => [status => count, ...]
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function attendanceTallies(): array
    {
        if ($this->game->status !== GameStatus::Completed) {
            return [];
        }

        return app(AttendanceService::class)->getVoteTallies($this->game);
    }

    /**
     * The current viewer's own resolved attendance status (or null if unresolved).
     */
    #[Computed]
    public function currentUserAttendanceStatus(): ?AttendanceStatus
    {
        $viewerId = $this->viewerId();

        if ($viewerId === null) {
            return null;
        }

        $participant = $this->game->participants
            ->first(fn ($p) => $p->user_id === $viewerId
                && $p->status === ParticipantStatus::Approved);

        return $participant?->attendance_status;
    }

    /**
     * Human-readable time remaining until the attendance window closes.
     *
     * Returns a string like '2h 30m' or null if not applicable.
     */
    #[Computed]
    public function attendanceTimeRemaining(): ?string
    {
        if (! $this->isAttendanceWindowOpen()) {
            return null;
        }

        $closesAt = $this->game->attendance_window_closes_at;

        if ($closesAt === null) {
            return null;
        }

        $diff = now()->diffForHumans($closesAt, short: true, parts: 2, syntax: CarbonInterface::DIFF_ABSOLUTE);

        if ($closesAt->isPast()) {
            return null; // Already past
        }

        return $diff;
    }

    /**
     * Submit the attendance form (batch of reports from $attendanceReports property).
     *
     * Translates form data into the batch format expected by AttendanceService.
     *
     * @param  array<string, mixed>  $participantIdOrReports
     */
    public function submitAttendanceReport(string|array $participantIdOrReports = [], ?string $status = null): void
    {
        $viewer = authenticatedUser();

        $game = $this->game;

        // Eager-load users for the whole roster once — the paths below read
        // $participant->user per row (re-hydrated Livewire model has no
        // mount-time eager loads), which is an N+1 otherwise.
        $game->participants->loadMissing('user');

        // Handle legacy single-report shorthand: (participantId, status)
        if (is_string($participantIdOrReports) && $status !== null) {
            $participant = $game->participants->first(fn ($p) => $p->id === $participantIdOrReports);

            if ($participant === null || $participant->user === null) {
                session()->flash('error', __('games.error_attendance_participant_not_found'));

                return;
            }

            $reports = [
                ['reported_id' => $participant->user->id, 'status' => $status],
            ];
        } else {
            // Form-based submission: use $attendanceReports property
            $this->validate([
                'attendanceReports' => ['required', 'array', 'min:1'],
                'attendanceReports.*.status' => ['required', 'string', 'in:attended,no_show,excused'],
                'attendanceReports.*.reason' => ['nullable', 'string', 'max:500'],
            ]);

            // Convert form data (keyed by participant ID) to service batch format
            $reports = [];
            foreach ($this->attendanceReports as $participantId => $data) {
                if (! is_array($data)) {
                    continue;
                }
                $participant = $game->participants->first(fn ($p) => $p->id === $participantId);

                if ($participant === null || $participant->user === null) {
                    continue;
                }

                // Excused requires a reason
                if (($data['status'] === AttendanceStatus::Excused->value) && (($data['reason'] ?? null) === null || $data['reason'] === '')) {
                    session()->flash('error', __('games.error_attendance_excused_reason_required'));

                    return;
                }

                $user = $participant->user;

                $reports[] = [
                    'reported_id' => (string) $user->id,
                    'status' => $data['status'],
                    'reason' => $data['reason'] ?? '',
                ];
            }

            if ($reports === []) {
                session()->flash('error', __('games.error_attendance_no_reports'));

                return;
            }
        }

        $result = app(AttendanceService::class)->submitReport(
            $game,
            $viewer,
            $reports, // @phpstan-ignore argument.type
        );

        if ($result['success']) {
            // Reload participants to reflect updated state
            $game->load('participants.user');
            unset($this->hasSubmittedAttendance, $this->attendanceTallies);
            session()->flash('success', __('games.flash_attendance_reported'));
        } else {
            session()->flash('error', $result['reason']);
        }
    }

    /**
     * Dispute the resolved attendance status for a NoShow participant.
     *
     * Delegates to AttendanceService::disputeAttendanceStatus() which creates
     * an Escalated ticket and marks attendance_disputed_at on the participant.
     */
    public function disputeAttendance(string $participantId): void
    {
        $viewer = authenticatedUser();

        $this->validate([
            'disputeReason' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'disputeReason.required' => __('games.error_dispute_reason_required'),
            'disputeReason.min' => __('games.error_dispute_reason_min'),
            'disputeReason.max' => __('games.error_dispute_reason_max'),
        ]);

        $participant = $this->game->participants
            ->first(fn ($p) => $p->id === $participantId);

        if ($participant === null) {
            session()->flash('error', __('games.error_attendance_participant_not_found'));

            return;
        }

        // The dispute service reads $participant->game — eager-load it here
        // (the re-hydrated relation collection does not carry it).
        $participant->loadMissing('game');

        $result = app(AttendanceService::class)->disputeAttendanceStatus(
            $participant,
            $this->disputeReason ?? '',
            $viewer,
        );

        if ($result['success']) {
            $this->disputeReason = null;
            $this->game->load('participants.user');
            unset($this->currentUserAttendanceStatus);
            session()->flash('success', __('games.flash_attendance_disputed'));
        } else {
            session()->flash('error', $result['reason']);
        }
    }

    // ── Capacity adjustment (host affordance) ──────────

    /**
     * Host-driven max_players change — pure orchestration over
     * {@see CapacityService::update()}, which owns the validation rules,
     * the terminal-state guard, and the three-way branch (increase /
     * silent decrease / two-phase-confirm demotion).
     *
     * This method keeps ONLY the Livewire surface effects: authorize,
     * deciding whether this is the confirming call of an armed modal, and
     * mapping the {@see CapacityUpdateResult} to field errors / flash /
     * confirm-modal state. The server NEVER authorises off the client-side
     * preview snapshot — {@see CapacityService::demote()} recomputes the
     * displaced set under a lockForUpdate transaction (defense against modal
     * bypass / stale preview).
     */
    public function updateCapacity(int $newMax, ?string $reason = null): void
    {
        $this->authorize('update', $this->game);

        // A confirming call requires BOTH a non-empty (trimmed) reason and
        // the armed confirm flag — everything else (re-)arms the modal.
        $confirmed = trim((string) $reason) !== ''
            && $this->confirmingCapacityDecrease === 'capacity-decrease';

        $result = app(CapacityService::class)
            ->update($this->game, $newMax, authenticatedUser(), $reason, $confirmed);

        match ($result->outcome) {
            // First call of a below-approved decrease — arm the confirm modal.
            CapacityUpdateResult::NEEDS_CONFIRMATION => $this->armCapacityDecrease($newMax),

            // newMax equals the current max — nothing changed, just disarm.
            CapacityUpdateResult::UNCHANGED => $this->clearCapacityConfirmState(),

            // Validation failures. A bad reason keeps the modal armed (the
            // host edits the textarea in place); a bad max disarms it.
            CapacityUpdateResult::INVALID_MAX => $this->applyCapacityError($result, clearConfirm: true),
            CapacityUpdateResult::INVALID_REASON => $this->applyCapacityError($result, clearConfirm: false),

            // Terminal-state refusal (completed / attendance resolved).
            CapacityUpdateResult::REFUSED => $this->refuseCapacityChange($result),

            // Applied changes (increased / decreased / demoted) — refresh
            // computed props and flash the service-chosen message.
            default => $this->applyCapacityChange($result),
        };
    }

    /**
     * Arm the two-phase capacity-decrease confirm modal for $newMax.
     */
    private function armCapacityDecrease(int $newMax): void
    {
        $this->pendingNewMax = $newMax;
        $this->confirmingCapacityDecrease = 'capacity-decrease';
        $this->confirmingAction = 'capacity-decrease';
    }

    /**
     * Surface a field-level validation failure from the service result.
     */
    private function applyCapacityError(CapacityUpdateResult $result, bool $clearConfirm): void
    {
        $this->addError($result->errorField(), __($result->errorKey));

        if ($clearConfirm) {
            $this->clearCapacityConfirmState();
        }
    }

    /**
     * Surface a terminal-state refusal (error flash, confirm state cleared).
     */
    private function refuseCapacityChange(CapacityUpdateResult $result): void
    {
        session()->flash('error', __($result->errorKey));
        $this->clearCapacityConfirmState();
    }

    /**
     * Surface an applied capacity change (refresh computed props, success
     * flash from the service-chosen key, confirm state cleared).
     */
    private function applyCapacityChange(CapacityUpdateResult $result): void
    {
        $this->refreshAfterCapacityChange();
        session()->flash('success', __($result->flashKey, $result->flashParams));
        $this->clearCapacityConfirmState();
    }

    /**
     * Cancel the pending capacity-decrease confirmation and close the modal.
     */
    public function cancelCapacityDecrease(): void
    {
        $this->clearCapacityConfirmState();
    }

    private function clearCapacityConfirmState(): void
    {
        $this->confirmingCapacityDecrease = null;
        $this->pendingNewMax = null;
        $this->capacityReason = null;
        if ($this->confirmingAction === 'capacity-decrease') {
            $this->confirmingAction = null;
        }
    }

    private function refreshAfterCapacityChange(): void
    {
        $this->game->load('participants.user');
        $this->game->refresh();
        unset(
            $this->isGameFull,
            $this->isParticipant,
            $this->isApprovedParticipant,
            $this->canApply,
            $this->canJoinWaitlist,
            $this->waitlistedPlayers,
        );
    }

    // ── Self-service leave ─────────────────────────────

    public function leaveGame(): void
    {
        $user = authenticatedUser();
        if ($this->isOwner()) {
            session()->flash('error', __('games.error_cannot_leave_own_game'));

            return;
        }

        $participant = $this->game->participants
            ->first(fn ($p) => $p->user_id === $user->id
                && in_array($p->status?->value, [
                    ParticipantStatus::Approved->value,
                    ParticipantStatus::Waitlisted->value,
                    ParticipantStatus::Benched->value,
                    ParticipantStatus::Pending->value,
                ], true));

        if ($participant === null || ! $user->can('leave', $this->game)) {
            session()->flash('error', __('games.error_not_a_game_participant'));

            return;
        }

        app(ParticipantLifecycle::class)->depart($participant, $user);

        // Refresh computed properties
        unset($this->isParticipant, $this->isApprovedParticipant, $this->isGameFull);

        session()->flash('success', __('games.flash_you_left_the_game'));
    }

    // ── Share Link Management ──────────────────────────

    public function generateShareLink(): void
    {
        // Rule lives in GamePolicy::manageShareToken — one definition shared
        // by generate/revoke/regenerate and the CampaignDetail twins.
        if (! authenticatedUser()->can('manageShareToken', $this->game)) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        $this->game->update(['share_token' => Str::uuid()->toString()]);

        Log::info('Share link generated', [
            'entity_type' => 'game',
            'entity_id' => $this->game->id,
            'user_id' => Auth::id(),
        ]);

        session()->flash('success', __('common.flash_share_link_generated'));
    }

    public function revokeShareLink(): void
    {
        if (! authenticatedUser()->can('manageShareToken', $this->game)) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        $this->game->update(['share_token' => null, 'share_token_expires_at' => null]);

        Log::info('Share link revoked', [
            'entity_type' => 'game',
            'entity_id' => $this->game->id,
            'user_id' => Auth::id(),
        ]);

        session()->flash('success', __('common.flash_share_link_revoked'));
    }

    /**
     * Detach this session from its host event (M063/S05, R059): the game
     * itself — participants, systems, status — stays untouched and becomes
     * standalone. Owner-side counterpart to ManageEvent::detachTable(),
     * gated on game ownership rather than event permission so a host can
     * always withdraw their own table.
     */
    public function detachFromEvent(): void
    {
        $this->authorize('update', $this->game);

        // Standalone game (or already detached): nothing to do — the action
        // is only rendered for hosted tables, this guards stale requests.
        if ($this->game->event_id === null) {
            return;
        }

        $name = $this->game->name;
        $eventId = $this->game->event_id;

        $this->game->event()->dissociate();
        $this->game->save();

        Log::info('Table detached from event', [
            'game_id' => $this->game->id,
            'event_id' => $eventId,
            'detached_by' => Auth::id(),
            'surface' => 'game_detail',
        ]);

        session()->flash('success', __('events.flash_table_detached', ['name' => $name]));
    }

    public function regenerateShareLink(): void
    {
        if (! authenticatedUser()->can('manageShareToken', $this->game)) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        $this->game->update([
            'share_token' => Str::uuid()->toString(),
            'share_token_expires_at' => now()->addDays(30),
        ]);

        Log::info('Share link regenerated', [
            'entity_type' => 'game',
            'entity_id' => $this->game->id,
            'user_id' => Auth::id(),
        ]);
        session()->flash('success', __('common.flash_share_link_generated'));
    }

    // ── Direct join (share link / Discord) ────────────

    /**
     * Join via a still-valid share token or short link. The pipeline —
     * guards, lock-guarded join write, flash/computed surface effects — is
     * shared with the Discord affordance in {@see attemptDirectJoin()}; this
     * surface only contributes the arrival credential, the source
     * attribution, and the success hook that clears the intent cookies.
     */
    public function joinViaShareLink(): void
    {
        $this->attemptDirectJoin(
            source: $this->validatedShortLinkId !== null ? JoinSource::ShortLink : JoinSource::ShareLink,
            eligible: $this->canJoinViaShareLink(),
            errorBag: 'share_link',
            shortLinkId: $this->validatedShortLinkId,
            shareToken: $this->validatedShareToken,
            onJoined: fn () => $this->clearJoinIntentCookies(),
        );
    }

    /**
     * Join from a Discord "My seat" intent (M059/S02). Same shared pipeline
     * ({@see ShareLinkJoinService} — same lock, capacity re-check, overflow
     * routing and approved_at stamping) minus the share-token / short-link
     * requirement (the member arrived via the Discord on-ramp), attributed
     * JoinSource::Discord so the acquisition funnel is measured. Driven
     * either by the auto-trigger on mount (?discord_join=1 +
     * canJoinViaDiscord) or by an explicit Join button.
     */
    public function joinViaDiscord(): void
    {
        $this->attemptDirectJoin(
            source: JoinSource::Discord,
            eligible: $this->canJoinViaDiscord(),
            errorBag: 'discord_join',
            onJoined: fn () => app(DiscordJoinIntent::class)->consume(request()),
        );
    }

    /**
     * The single direct-join pipeline behind both join buttons. Guard order
     * is signup cutoff (D124, surfaced with its specific message) → surface
     * eligibility ({@see EntityJoinGate}, injected as $eligible by the caller)
     * → rate limit ({@see EntityJoinGate}, the only rate-limit site) → the
     * lock-guarded write in {@see ShareLinkJoinService}, which owns the
     * capacity re-check and overflow routing (waitlist/bench). Only Livewire
     * surface effects live here: flash, the per-surface success hook, and
     * computed unsets.
     *
     * @param  bool  $eligible  surface eligibility, gate-derived by the caller
     * @param  (callable(): mixed)|null  $onJoined  per-surface success hook (return ignored)
     */
    private function attemptDirectJoin(
        JoinSource $source,
        bool $eligible,
        string $errorBag,
        ?callable $onJoined = null,
        ?int $shortLinkId = null,
        ?string $shareToken = null,
    ): void {
        $viewer = authenticatedUser();

        // Signup cutoff (decision D124). Surface the specific message rather
        // than the generic not-authorized flash so a member hitting a closed
        // signup knows why. The gate would refuse too, so the Join button is
        // hidden as well once computeds refresh.
        if ($this->game->signupHasClosed()) {
            session()->flash('error', __('games.error_signup_closed'));

            return;
        }

        if (! $eligible) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        $gate = app(EntityJoinGate::class);

        $allowed = $source === JoinSource::Discord
            ? $gate->allowsDiscordJoinAttempt($this->game, $viewer)
            : $gate->allowsShareJoinAttempt($this->game, $viewer);

        if (! $allowed) {
            session()->flash('error', __('common.error_rate_limit'));

            return;
        }

        try {
            $overflowFlash = $this->shareLinkJoinService()->join(
                $this->game,
                $viewer,
                $source,
                $shortLinkId,
                $shareToken,
            );

            // Joined — run the per-surface hook so the arrival intent
            // (cookies / Discord intent) cannot replay on a later visit.
            if ($onJoined !== null) {
                $onJoined();
            }

            // Reload roster to reflect the new participant.
            $this->game->load('participants.user');
            unset(
                $this->isParticipant,
                $this->isGameFull,
                $this->canApply,
                $this->canJoinWaitlist,
                $this->canJoinViaDiscord,
            );

            session()->flash(
                'success',
                $overflowFlash !== null
                    ? __($overflowFlash->messageKey, $overflowFlash->messageParams)
                    : __('games.flash_joined_game_via_share_link')
            );
        } catch (\Throwable $e) {
            Log::error('Direct join failed', [
                'game_id' => $this->game->id,
                'user_id' => $viewer->id,
                'source' => $source->value,
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                $errorBag => [__('games.error_join_game_via_share_link_failed')],
            ]);
        }
    }

    /**
     * Clear the arrival-intent cookies after a successful share-link /
     * short-link join — the member has joined, so the intent must not
     * replay on a later visit.
     */
    private function clearJoinIntentCookies(): void
    {
        Cookie::queue(Cookie::forget('share_intent'));
        Cookie::queue(Cookie::forget('short_link_intent'));
    }

    protected function shareLinkJoinService(): ShareLinkJoinService
    {
        return app(ShareLinkJoinService::class);
    }

    /**
     * Whether the viewer can join this game directly from a Discord "My seat"
     * intent (M059/S02). The public-visibility gate and the shared eligibility
     * predicate live in {@see EntityJoinGate::canJoinViaDiscord()} — see its
     * docblock for the screening-bypass rationale (?discord_join=1 is
     * forgeable, protected games are screened on the web Apply path).
     */
    #[Computed]
    public function canJoinViaDiscord(): bool
    {
        return app(EntityJoinGate::class)->canJoinViaDiscord($this->game, Auth::user());
    }

    /**
     * Whether the viewer may join via a still-valid share token or short
     * link. Credential validation (mount-time query-param capture /
     * ph_link_id cookie) stays in the component; the eligibility predicate —
     * ownership, participant status, game status, signup cutoff (D124) —
     * lives in {@see EntityJoinGate::canJoinViaShareLink()}.
     */
    #[Computed]
    public function canJoinViaShareLink(): bool
    {
        return app(EntityJoinGate::class)->canJoinViaShareLink(
            $this->game,
            Auth::user(),
            $this->isShareTokenStillValid(),
            $this->isShortLinkStillValid(),
        );
    }

    // ── Computed viewer state (cached per request-cycle) ──

    private function viewerId(): ?string
    {
        return ($id = Auth::id()) !== null ? (string) $id : null;
    }

    #[Computed]
    public function isOwner(): bool
    {
        return ($id = $this->viewerId()) !== null && (string) $this->game->owner_id === (string) $id;
    }

    #[Computed]
    public function isParticipant(): bool
    {
        $id = $this->viewerId();

        return $id !== null && $this->game->participants
            ->contains(fn ($p) => $p->user_id === $id && in_array($p->status?->value, [
                ParticipantStatus::Approved->value,
                ParticipantStatus::Pending->value,
                ParticipantStatus::Waitlisted->value,
                ParticipantStatus::Benched->value,
            ], true));
    }

    #[Computed]
    public function isApprovedParticipant(): bool
    {
        $id = $this->viewerId();

        return ($id !== null && $this->isOwner())
            || $id !== null && $this->game->participants
                ->contains(fn ($p) => $p->user_id === $id && $p->status === ParticipantStatus::Approved);
    }

    #[Computed]
    public function userInvitation(): ?GameParticipant
    {
        $id = $this->viewerId();

        return $id !== null ? $this->game->participants->first(fn ($p) => $p->user_id === $id
            && $p->role === ParticipantRole::Invited && $p->status === ParticipantStatus::Pending) : null;
    }

    #[Computed]
    public function hasExistingApplication(): bool
    {
        return ($id = $this->viewerId()) !== null && $this->game->applications()
            ->where('user_id', $id)
            ->where('status', 'pending')
            ->exists();
    }

    #[Computed]
    public function userWaitlistParticipant(): ?GameParticipant
    {
        $id = $this->viewerId();

        return $id !== null ? $this->game->participants->first(fn ($p) => $p->user_id === $id
            && $p->status === ParticipantStatus::Waitlisted) : null;
    }

    #[Computed]
    public function waitlistPosition(): ?int
    {
        $wl = $this->userWaitlistParticipant();

        return $wl !== null ? app(WaitlistService::class)->getWaitlistPosition($wl) : null;
    }

    #[Computed]
    public function userPendingParticipant(): ?GameParticipant
    {
        $id = $this->viewerId();

        return $id !== null ? $this->game->participants->first(fn ($p) => $p->user_id === $id
            && $p->status === ParticipantStatus::Pending && $p->confirmation_expires_at !== null) : null;
    }

    #[Computed]
    public function userBenchParticipant(): ?GameParticipant
    {
        $id = $this->viewerId();

        return $id !== null ? $this->game->participants->first(fn ($p) => $p->user_id === $id
            && $p->status === ParticipantStatus::Benched) : null;
    }

    #[Computed]
    public function isGameFull(): bool
    {
        return $this->participantService()->isAtCapacity($this->game);
    }

    #[Computed]
    public function canApply(): bool
    {
        return ($id = $this->viewerId()) !== null
            && ! $this->isOwner() && ! $this->isParticipant() && ! $this->hasExistingApplication()
            && $this->game->visibility !== Visibility::Private
            && (! $this->isGameFull() || $this->game->isBenchMode())
            && ! in_array($this->game->status?->value, [GameStatus::Canceled->value, GameStatus::Completed->value], true);
    }

    #[Computed]
    public function canJoinWaitlist(): bool
    {
        return ($id = $this->viewerId()) !== null
            && ! $this->isOwner() && ! $this->isParticipant() && ! $this->hasExistingApplication()
            && ! $this->game->isBenchMode() && $this->isGameFull()
            && $this->game->visibility !== Visibility::Private
            && ! in_array($this->game->status?->value, [GameStatus::Canceled->value, GameStatus::Completed->value], true);
    }

    /**
     * @return Collection<int, GameParticipant>
     */
    #[Computed]
    public function waitlistedPlayers()
    {
        return ($this->isOwner() && ! $this->game->isBenchMode())
            ? $this->game->participants->where('status', ParticipantStatus::Waitlisted->value)->sortBy('waitlisted_at')
            : collect();
    }

    /**
     * @return Collection<int, GameParticipant>
     */
    #[Computed]
    public function benchedPlayers()
    {
        return ($this->isOwner() && $this->game->isBenchMode())
            ? $this->game->participants->filter(fn ($p) => $p->status === ParticipantStatus::Benched)
            : collect();
    }

    /**
     * @return Collection<int, Review>
     */
    #[Computed]
    public function reviews()
    {
        return Review::whereMorphedTo('reviewable', $this->game)
            ->published()
            ->with('reviewer')->latest()->limit(10)->get();
    }

    #[Computed]
    public function canReview(): bool
    {
        $viewer = Auth::user();

        if ($viewer === null) {
            return false;
        }

        return app(ReviewEligibilityService::class)->canReviewSession($viewer, $this->game);
    }

    /**
     * The campaign-charter card context (the session-zero card's
     * replacement, M070/S05 cull): campaign games surface their
     * charter — active ones with signing progress, drafts with a
     * continue affordance for the owner — while one-shots and
     * charter-less campaigns return a null charter (the owner CTA is
     * rendered from it).
     *
     * @return array{charter: ?SessionZeroSurvey, percent: int}
     */
    #[Computed]
    public function campaignCharterState(): array
    {
        $charter = $this->game->campaign?->charter()->first();

        if ($charter === null || ! $charter->isActive()) {
            return ['charter' => $charter, 'percent' => 0];
        }

        return ['charter' => $charter, 'percent' => CharterSigningService::signingProgress($charter)['percent']];
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function debriefingState(): array
    {
        $has = $this->game->hasDebriefingTools();
        $st = ['hasTools' => $has, 'prompts' => $has ? $this->game->getDebriefingPrompts() : [],
            'userDebriefing' => null, 'hostDebriefings' => collect(), 'summary' => null];

        if (! $has || $this->game->status !== GameStatus::Completed || $this->viewerId() === null) {
            return $st;
        }

        $st['userDebriefing'] = SessionDebriefing::where('game_id', $this->game->id)
            ->where('user_id', $this->viewerId())->first();

        if ($this->isOwner()) {
            $st['hostDebriefings'] = app(DebriefingService::class)->getHostDebriefings($this->game);
        } elseif ($this->isParticipant() && $st['userDebriefing'] !== null) {
            $st['summary'] = app(DebriefingService::class)->getAnonymizedSummary($this->game);
        }

        return $st;
    }

    // ── Render ─────────────────────────────────────────

    public function render(): View
    {
        // loadMissing() keeps the static relations cached across re-renders —
        // render() runs on EVERY Livewire interaction (button clicks, panel
        // toggles), and re-querying all ten relations each time cost 6-10
        // queries per interaction on the hottest authenticated page. The
        // mutable relations (participants/applications) are still eagerly
        // refreshed: actions that mutate them call ->load() themselves, but
        // render remains the guaranteed-fresh source for the roster UI.
        $this->game->loadMissing([
            'owner', 'campaign', 'recapEntry', 'event',
            'gameSystems.categories', 'gameSystems.mechanics',
            'gameSystems.publishers', 'gameSystems.baseGame', 'gameSystems.expansions',
            'linkedLocation',
        ]);
        $this->game->load(['participants.user', 'applications.user']);

        $cc = $this->campaignCharterState();
        $db = $this->debriefingState();

        return view('livewire.games.game-detail', [
            'game' => $this->game,
            'isOwner' => $this->isOwner(),
            'isParticipant' => $this->isParticipant(),
            'isApprovedParticipant' => $this->isApprovedParticipant(),
            'userInvitation' => $this->userInvitation(),
            'canApply' => $this->canApply(),
            'hasExistingApplication' => $this->hasExistingApplication(),
            'reviews' => $this->reviews(),
            'canReview' => $this->canReview(),
            'campaignCharter' => $cc['charter'],
            'campaignCharterPercent' => $cc['percent'],
            'isGameFull' => $this->isGameFull(),
            'canJoinWaitlist' => $this->canJoinWaitlist(),
            'userWaitlistParticipant' => $this->userWaitlistParticipant(),
            'userPendingParticipant' => $this->userPendingParticipant(),
            'waitlistPosition' => $this->waitlistPosition(),
            'waitlistedPlayers' => $this->waitlistedPlayers(),
            'benchedPlayers' => $this->benchedPlayers(),
            'userBenchParticipant' => $this->userBenchParticipant(),
            'hasDebriefingTools' => $db['hasTools'],
            'debriefingPrompts' => $db['prompts'],
            'userDebriefing' => $db['userDebriefing'],
            'hostDebriefings' => $db['hostDebriefings'],
            'debriefingSummary' => $db['summary'],
            'comfortNotes' => ($this->game->game_type?->value === 'board_game'
                && is_array($this->game->safety_rules ?? null)
                && isset($this->game->safety_rules['comfort_notes']))
                    ? $this->game->safety_rules['comfort_notes'] : null,
            'hasShareLink' => $this->hasShareLink(),
            'shareLinkUrl' => $this->shareLinkUrl(),
            'canJoinViaShareLink' => $this->canJoinViaShareLink(),
            'canJoinViaDiscord' => $this->canJoinViaDiscord(),
            'shortLinks' => $this->getShortLinks(),
            'canCreateMoreShortLinks' => Auth::user() !== null
                ? app(ShortLinkService::class)->canCreateMore($this->game, Auth::user())
                : false,
            'isAttendanceWindowOpen' => $this->isAttendanceWindowOpen(),
            'hasSubmittedAttendance' => $this->hasSubmittedAttendance(),
            'attendanceTallies' => $this->attendanceTallies(),
            'currentUserAttendanceStatus' => $this->currentUserAttendanceStatus(),
            'attendanceTimeRemaining' => $this->attendanceTimeRemaining(),
            'capacityDemotionPreview' => ($this->confirmingCapacityDecrease === 'capacity-decrease'
                && $this->pendingNewMax !== null)
                    ? app(CapacityService::class)->previewDemotion($this->game, $this->pendingNewMax)
                    : null,
            'pendingNewMax' => $this->pendingNewMax,
        ]);
    }
}
