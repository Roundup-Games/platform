<?php

namespace App\Livewire\Games;

use App\Enums\AttendanceStatus;
use App\Enums\GameStatus;
use App\Enums\JoinSource;
use App\Enums\NotificationCategory;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\Review;
use App\Models\SessionDebriefing;
use App\Models\SessionZeroConfirmation;
use App\Models\ShortLink;
use App\Notifications\ParticipantRemoved;
use App\Services\AttendanceService;
use App\Services\DebriefingService;
use App\Services\NotificationService;
use App\Services\ReviewEligibilityService;
use App\Services\Roster;
use App\Services\ShortLinkService;
use App\Services\WaitlistService;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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

    public function mount(string $id): void
    {
        $game = Game::findOrFail($id);
        $this->authorize('view', $game);
        $this->game = $game;

        // Capture valid share token on initial page load (query params don't persist across Livewire updates)
        if ($game->hasValidShareToken()) {
            $this->validatedShareToken = request()->query('share');
        }

        // Detect short link arrival via ph_link_id cookie
        $this->detectShortLink();

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
        if ($game->attendance_window_opens_at && now()->lt($game->attendance_window_opens_at)) {
            return false;
        }

        // Window has closed
        if ($game->attendance_window_closes_at && now()->gt($game->attendance_window_closes_at)) {
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

        if (! $viewerId) {
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

        if (! $viewerId) {
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

        if (! $closesAt) {
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

        // Handle legacy single-report shorthand: (participantId, status)
        if (is_string($participantIdOrReports) && $status !== null) {
            $participant = $game->participants->first(fn ($p) => $p->id === $participantIdOrReports);

            if (! $participant || ! $participant->user) {
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

                if (! $participant || ! $participant->user) {
                    continue;
                }

                // Excused requires a reason
                if (($data['status'] === AttendanceStatus::Excused->value) && empty($data['reason'])) {
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

            if (empty($reports)) {
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

        if (! $participant) {
            session()->flash('error', __('games.error_attendance_participant_not_found'));

            return;
        }

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

    // ── Host-initiated removal (game-specific override) ──

    public function removeParticipant(string $participantId): void
    {
        $participant = $this->findParticipantOrFail($participantId);
        $entity = $this->game;

        if ($participant->role === ParticipantRole::Owner) {
            session()->flash('error', __('common.error_cannot_remove_the_entity_owner', ['entity' => 'game']));

            return;
        }

        if ($entity->date_time && $entity->date_time->isFuture()) {
            $hoursUntil = now()->diffInHours($entity->date_time, false);
            $lateCancelHours = config_int('attendance.player_late_cancel_hours', 24);
            $participant->update(['attendance_status' => $hoursUntil < $lateCancelHours
                ? AttendanceStatus::LateCancel : AttendanceStatus::CancelledEarly]);
        }

        $removedUser = $participant->user;
        $participant->update(['status' => ParticipantStatus::Rejected]);

        Log::info('Game participant removed', [
            'game_id' => $entity->id, 'user_id' => $participant->user_id, 'removed_by' => Auth::id(),
        ]);

        try {
            if ($removedUser) {
                app(NotificationService::class)->send(
                    $removedUser, new ParticipantRemoved($removedUser, $entity, 'game'),
                    NotificationCategory::ParticipantRemoved
                );
            }
        } catch (\Throwable $e) {
            Log::error('notification.participant_removed_dispatch_failed', [
                'game_id' => $entity->id, 'removed_user_id' => $participant->user_id, 'error' => $e->getMessage(),
            ]);
        }

        // Promote from waitlist + warn host if below min_players
        app(Roster::class)->onDeparture($entity);

        session()->flash('success', __('common.flash_participant_removed'));
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
                ]));

        if (! $participant) {
            session()->flash('error', __('games.error_not_a_participant'));

            return;
        }

        // Track attendance for reliability scoring
        if ($participant->status === ParticipantStatus::Approved && $this->game->date_time?->isFuture()) {
            $hoursUntil = now()->diffInHours($this->game->date_time, false);
            $lateCancelHours = config_int('attendance.player_late_cancel_hours', 24);
            $participant->update(['attendance_status' => $hoursUntil < $lateCancelHours
                ? AttendanceStatus::LateCancel : AttendanceStatus::CancelledEarly]);
        }

        $participant->update(['status' => ParticipantStatus::Rejected]);

        Log::info('Player left game', [
            'game_id' => $this->game->id,
            'user_id' => $user->id,
            'previous_status' => $participant->status?->value,
        ]);

        // Promote from waitlist + warn host if below min_players
        app(Roster::class)->onDeparture($this->game);

        // Refresh computed properties
        unset($this->isParticipant, $this->isApprovedParticipant, $this->isGameFull);

        session()->flash('success', __('games.flash_you_left_the_game'));
    }

    // ── Share Link Management ──────────────────────────

    public function generateShareLink(): void
    {
        if (! $this->isOwner()) {
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
        if (! $this->isOwner()) {
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

    public function regenerateShareLink(): void
    {
        $viewer = authenticatedUser();
        if ((string) $this->game->owner_id !== (string) $viewer->id) {
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
            'user_id' => $viewer->id,
        ]);
        session()->flash('success', __('common.flash_share_link_generated'));
    }

    // ── Join via Share Link ────────────────────────────

    public function joinViaShareLink(): void
    {
        $viewer = authenticatedUser();

        if (! $this->canJoinViaShareLink()) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        $rateLimitKey = 'share-join:'.$viewer->id.':'.$this->game->id;
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            session()->flash('error', __('common.error_rate_limit'));

            return;
        }
        RateLimiter::hit($rateLimitKey, 60);

        // Determine join source and short link ID.
        // Try short link first, but fall back to share token if the short link
        // is revoked mid-session (caught during transactional revalidation).
        $shortLinkId = $this->validatedShortLinkId;
        $joinSource = $shortLinkId !== null
            ? JoinSource::ShortLink
            : JoinSource::ShareLink;

        try {
            DB::transaction(function () use ($viewer, &$joinSource, &$shortLinkId) {
                $game = Game::lockForUpdate()->find($this->game->id);

                if ($game === null) {
                    throw new \RuntimeException('Game not found during join transaction.');
                }

                // Revalidate short link under lock to catch mid-session revocation.
                // If revoked, fall back to share token if one is still valid.
                if ($shortLinkId !== null) {
                    $freshLink = ShortLink::where('id', $shortLinkId)
                        ->whereNull('deleted_at')
                        ->first();
                    if ($freshLink === null || $freshLink->isExpired()) {
                        // Short link gone — fall back to share token if valid.
                        if ($this->isShareTokenStillValid()) {
                            $shortLinkId = null;
                            $joinSource = JoinSource::ShareLink;
                        } else {
                            throw new \RuntimeException('Short link revoked or expired during join.');
                        }
                    }
                }

                $isFull = $this->participantService()->isAtCapacity($game);

                $baseData = [
                    'game_id' => $game->id,
                    'user_id' => $viewer->id,
                    'role' => ParticipantRole::Player->value,
                    'join_source' => $joinSource->value,
                ];

                if ($shortLinkId !== null) {
                    $baseData['short_link_id'] = $shortLinkId;
                }

                if ($isFull && $game->isBenchMode()) {
                    $baseData['status'] = ParticipantStatus::Benched->value;
                    $baseData['benched_at'] = now();

                    GameParticipant::create($baseData);

                    Log::info('Player benched via share link (bench mode, full)', [
                        'game_id' => $game->id,
                        'user_id' => $viewer->id,
                        'join_source' => $joinSource->value,
                        'short_link_id' => $shortLinkId,
                    ]);
                } elseif ($isFull) {
                    $baseData['status'] = ParticipantStatus::Waitlisted->value;
                    $baseData['waitlisted_at'] = now();

                    GameParticipant::create($baseData);

                    Log::info('Player waitlisted via share link (game full)', [
                        'game_id' => $game->id,
                        'user_id' => $viewer->id,
                        'join_source' => $joinSource->value,
                        'short_link_id' => $shortLinkId,
                    ]);
                } else {
                    $baseData['status'] = ParticipantStatus::Approved->value;

                    GameParticipant::create($baseData);

                    Log::info('Player joined via share link', [
                        'game_id' => $game->id,
                        'user_id' => $viewer->id,
                        'join_source' => $joinSource->value,
                        'short_link_id' => $shortLinkId,
                    ]);
                }
            });

            // Clear the intent cookies since the user has now joined
            Cookie::queue(Cookie::forget('share_intent'));
            Cookie::queue(Cookie::forget('short_link_intent'));

            // Reload game to reflect new participant
            $this->game->load('participants.user');
            unset($this->isParticipant, $this->isGameFull, $this->canApply, $this->canJoinWaitlist);

            session()->flash('success', __('games.flash_joined_via_share_link'));
        } catch (\Throwable $e) {
            Log::error('Failed to join via share link', [
                'game_id' => $this->game->id,
                'user_id' => $viewer->id,
                'error' => $e->getMessage(),
            ]);
            throw ValidationException::withMessages([
                'share_link' => [__('games.error_join_via_share_link_failed')],
            ]);
        }
    }

    #[Computed]
    public function canJoinViaShareLink(): bool
    {
        $viewer = Auth::user();

        if ($viewer === null) {
            return false;
        }

        // Must have either a valid share token or a valid short link
        $hasShareToken = $this->isShareTokenStillValid();

        $hasShortLink = $this->isShortLinkStillValid();

        if (! $hasShareToken && ! $hasShortLink) {
            return false;
        }

        // Cannot be the owner
        if ((string) $this->game->owner_id === (string) $viewer->id) {
            return false;
        }

        // Cannot already be a participant
        $existingParticipant = $this->game->participants
            ->first(fn ($p) => $p->user_id === $viewer->id
                && in_array($p->status?->value, [
                    ParticipantStatus::Approved->value,
                    ParticipantStatus::Pending->value,
                    ParticipantStatus::Waitlisted->value,
                    ParticipantStatus::Benched->value,
                ]));

        if ($existingParticipant) {
            return false;
        }

        // Game must not be completed or canceled
        if (in_array($this->game->status?->value, [GameStatus::Completed->value, GameStatus::Canceled->value])) {
            return false;
        }

        return true;
    }

    // ── Computed viewer state (cached per request-cycle) ──

    private function viewerId(): ?string
    {
        return ($id = Auth::id()) !== null ? (string) $id : null;
    }

    #[Computed]
    public function isOwner(): bool
    {
        return ($id = $this->viewerId()) && (string) $this->game->owner_id === (string) $id;
    }

    #[Computed]
    public function isParticipant(): bool
    {
        $id = $this->viewerId();

        return $id && $this->game->participants
            ->contains(fn ($p) => $p->user_id === $id && in_array($p->status?->value, [
                ParticipantStatus::Approved->value,
                ParticipantStatus::Pending->value,
                ParticipantStatus::Waitlisted->value,
                ParticipantStatus::Benched->value,
            ]));
    }

    #[Computed]
    public function isApprovedParticipant(): bool
    {
        $id = $this->viewerId();

        return ($id && $this->isOwner())
            || $id && $this->game->participants
                ->contains(fn ($p) => $p->user_id === $id && $p->status === ParticipantStatus::Approved);
    }

    #[Computed]
    public function userInvitation(): ?GameParticipant
    {
        $id = $this->viewerId();

        return $id ? $this->game->participants->first(fn ($p) => $p->user_id === $id
            && $p->role === ParticipantRole::Invited && $p->status === ParticipantStatus::Pending) : null;
    }

    #[Computed]
    public function hasExistingApplication(): bool
    {
        return ($id = $this->viewerId()) && $this->game->applications()
            ->where('user_id', $id)
            ->where('status', 'pending')
            ->exists();
    }

    #[Computed]
    public function userWaitlistParticipant(): ?GameParticipant
    {
        $id = $this->viewerId();

        return $id ? $this->game->participants->first(fn ($p) => $p->user_id === $id
            && $p->status === ParticipantStatus::Waitlisted) : null;
    }

    #[Computed]
    public function waitlistPosition(): ?int
    {
        $wl = $this->userWaitlistParticipant();

        return $wl ? app(WaitlistService::class)->getWaitlistPosition($wl) : null;
    }

    #[Computed]
    public function userPendingParticipant(): ?GameParticipant
    {
        $id = $this->viewerId();

        return $id ? $this->game->participants->first(fn ($p) => $p->user_id === $id
            && $p->status === ParticipantStatus::Pending && $p->confirmation_expires_at !== null) : null;
    }

    #[Computed]
    public function userBenchParticipant(): ?GameParticipant
    {
        $id = $this->viewerId();

        return $id ? $this->game->participants->first(fn ($p) => $p->user_id === $id
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
        return ($id = $this->viewerId())
            && ! $this->isOwner() && ! $this->isParticipant() && ! $this->hasExistingApplication()
            && $this->game->visibility !== Visibility::Private
            && (! $this->isGameFull() || $this->game->isBenchMode())
            && ! in_array($this->game->status?->value, [GameStatus::Canceled->value, GameStatus::Completed->value]);
    }

    #[Computed]
    public function canJoinWaitlist(): bool
    {
        return ($id = $this->viewerId())
            && ! $this->isOwner() && ! $this->isParticipant() && ! $this->hasExistingApplication()
            && ! $this->game->isBenchMode() && $this->isGameFull()
            && $this->game->visibility !== Visibility::Private
            && ! in_array($this->game->status?->value, [GameStatus::Canceled->value, GameStatus::Completed->value]);
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
        return Review::where('reviewable_type', Game::class)
            ->where('reviewable_id', $this->game->id)->published()
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
     * @return array<string, mixed>
     */
    #[Computed]
    public function sessionZeroState(): array
    {
        $active = $this->game->activeSessionZeroSurvey();
        $confirmed = false;
        if ($active && ($id = $this->viewerId())) {
            $confirmed = SessionZeroConfirmation::where('session_zero_survey_id', $active->id)
                ->where('user_id', $id)->exists();
        }

        return ['active' => $active, 'isConfirmed' => $confirmed];
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

        if (! $has || $this->game->status !== GameStatus::Completed || ! $this->viewerId()) {
            return $st;
        }

        $st['userDebriefing'] = SessionDebriefing::where('game_id', $this->game->id)
            ->where('user_id', $this->viewerId())->first();

        if ($this->isOwner()) {
            $st['hostDebriefings'] = app(DebriefingService::class)->getHostDebriefings($this->game);
        } elseif ($this->isParticipant() && $st['userDebriefing']) {
            $st['summary'] = app(DebriefingService::class)->getAnonymizedSummary($this->game);
        }

        return $st;
    }

    // ── Render ─────────────────────────────────────────

    public function render(): View
    {
        $this->game->load([
            'owner', 'campaign', 'gameSystem.categories', 'gameSystem.mechanics',
            'gameSystem.publishers', 'gameSystem.baseGame', 'gameSystem.expansions',
            'participants.user', 'applications.user', 'linkedLocation',
        ]);

        $sz = $this->sessionZeroState();
        $db = $this->debriefingState();

        return view('livewire.games.game-detail', [
            'game' => $this->game,
            'isOwner' => $this->isOwner(),
            'isParticipant' => $this->isParticipant(),
            'isApprovedParticipant' => $this->isApprovedParticipant(),
            'userInvitation' => $this->userInvitation(),
            'canApply' => $this->canApply(),
            'hasExistingApplication' => $this->hasExistingApplication(),
            'isGuest' => false,
            'reviews' => $this->reviews(),
            'canReview' => $this->canReview(),
            'activeSessionZero' => $sz['active'],
            'isSessionZeroConfirmed' => $sz['isConfirmed'],
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
            'shortLinks' => $this->getShortLinks(),
            'canCreateMoreShortLinks' => Auth::user()
                ? app(ShortLinkService::class)->canCreateMore($this->game, Auth::user())
                : false,
            'isAttendanceWindowOpen' => $this->isAttendanceWindowOpen(),
            'hasSubmittedAttendance' => $this->hasSubmittedAttendance(),
            'attendanceTallies' => $this->attendanceTallies(),
            'currentUserAttendanceStatus' => $this->currentUserAttendanceStatus(),
            'attendanceTimeRemaining' => $this->attendanceTimeRemaining(),
        ]);
    }
}
