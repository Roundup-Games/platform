<?php

namespace App\Livewire\Games;

use App\Enums\AttendanceStatus;
use App\Enums\NotificationCategory;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\SessionDebriefing;
use App\Notifications\BelowMinPlayersWarning;
use App\Services\BenchService;
use App\Services\DebriefingService;
use App\Services\NotificationService;
use App\Services\RecapService;
use App\Services\WaitlistService;
use App\Traits\ManagesParticipants;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class GameDetail extends Component
{
    use ManagesParticipants;

    public Game $game;

    public function mount(string $id): void
    {
        $game = Game::findOrFail($id);
        $this->authorize('view', $game);
        $this->game = $game;
    }

    // ── Trait contracts ────────────────────────────────

    public function getEntity(): Game
    {
        return $this->game;
    }

    public function getEntityIdColumn(): string
    {
        return 'game_id';
    }

    public function getParticipantModel(): string
    {
        return \App\Models\GameParticipant::class;
    }

    public function getEntityName(): string
    {
        return 'Game';
    }

    public function getEntityVar(): string
    {
        return 'game';
    }

    public function getBackRoute(): string
    {
        return route('games.detail', $this->game->id);
    }

    // ── Waitlist Actions ───────────────────────────────

    /**
     * Join the waitlist for a full standalone game.
     */
    public function joinWaitlist(): void
    {
        $viewer = Auth::user();

        if (! $viewer) {
            return;
        }

        try {
            app(WaitlistService::class)->addToWaitlist($this->game, $viewer);
            session()->flash('success', __('games.content_added_to_waitlist'));
        } catch (\LogicException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    /**
     * Confirm a waitlist promotion spot.
     */
    public function confirmWaitlistSpot(string $participantId): void
    {
        $participant = $this->findParticipantOrFail($participantId);
        $viewer = Auth::user();

        if ($participant->user_id !== $viewer->id) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        if ($participant->status !== ParticipantStatus::Pending) {
            session()->flash('error', __('games.content_invitation_no_longer_valid'));

            return;
        }

        try {
            app(WaitlistService::class)->confirmPromotion($participant);
            session()->flash('success', __('games.content_waitlist_spot_confirmed'));
        } catch (\LogicException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    /**
     * Decline a waitlist promotion spot.
     */
    public function declineWaitlistSpot(string $participantId): void
    {
        $participant = $this->findParticipantOrFail($participantId);
        $viewer = Auth::user();

        if ($participant->user_id !== $viewer->id) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        if ($participant->status !== ParticipantStatus::Pending) {
            session()->flash('error', __('games.content_invitation_no_longer_valid'));

            return;
        }

        app(WaitlistService::class)->declinePromotion($participant);
        session()->flash('success', __('games.content_waitlist_spot_declined'));
    }

    /**
     * Host manually promotes a waitlisted player (skips FIFO).
     */
    public function manualPromote(string $participantId): void
    {
        $viewer = Auth::user();

        if ($this->game->owner_id !== $viewer->id) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        $participant = $this->findParticipantOrFail($participantId);

        if ($participant->status !== ParticipantStatus::Waitlisted) {
            session()->flash('error', __('games.content_invitation_no_longer_valid'));

            return;
        }

        app(WaitlistService::class)->manuallyPromote($participant);
        session()->flash('success', __('games.flash_manual_promote_success'));
    }

    /**
     * Host promotes a benched player to approved (campaign sessions only).
     */
    public function promoteFromBench(string $participantId): void
    {
        $viewer = Auth::user();

        if (! $viewer || $this->game->owner_id !== $viewer->id) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        if ($this->game->campaign_id === null) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        try {
            app(BenchService::class)->promoteFromBench($participantId, 'game');
            session()->flash('success', __('games.flash_promote_from_bench_success'));
        } catch (\LogicException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    /**
     * Cancel a participant's own attendance with late-cancellation detection.
     *
     * Overrides the trait's removeParticipant for self-cancellation flow.
     */
    public function cancelOwnParticipation(string $participantId): void
    {
        $participant = $this->findParticipantOrFail($participantId);
        $viewer = Auth::user();

        if ($participant->user_id !== $viewer->id) {
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        // Attendance status based on cancellation timing
        if ($this->game->date_time && $this->game->date_time->isFuture()) {
            $hoursUntilGame = now()->diffInHours($this->game->date_time, false);

            if ($hoursUntilGame < 24) {
                // Late cancellation: within 24h of game time
                $participant->update([
                    'attendance_status' => AttendanceStatus::LateCancel,
                ]);

                Log::info('Game participant late cancellation', [
                    'game_id' => $this->game->id,
                    'user_id' => $viewer->id,
                    'hours_until_game' => $hoursUntilGame,
                ]);
            } else {
                // Early cancellation: >24h before game time — neutral
                $participant->update([
                    'attendance_status' => AttendanceStatus::CancelledEarly,
                ]);

                Log::info('Game participant early cancellation', [
                    'game_id' => $this->game->id,
                    'user_id' => $viewer->id,
                    'hours_until_game' => $hoursUntilGame,
                ]);
            }
        }

        // Remove the participant
        $participant->update(['status' => ParticipantStatus::Rejected]);

        Log::info('Game participant self-cancelled', [
            'game_id' => $this->game->id,
            'user_id' => $viewer->id,
        ]);

        // Promote from waitlist if applicable
        if ($this->game->campaign_id === null) {
            app(WaitlistService::class)->promoteAllOnCancel($this->game);
        }

        // Check below-min-players
        $this->checkBelowMinPlayersAndNotify();

        session()->flash('success', __('common.flash_participant_removed'));
    }

    /**
     * Override removeParticipant to integrate waitlist promotion on host-initiated removal.
     */
    public function removeParticipant(string $participantId): void
    {
        $participant = $this->findParticipantOrFail($participantId);
        $entity = $this->game;

        if ($participant->role === 'owner') {
            session()->flash('error', __('common.error_cannot_remove_the_entity_owner', ['entity' => 'game']));

            return;
        }

        // Attendance status based on removal timing
        if ($entity->date_time && $entity->date_time->isFuture()) {
            $hoursUntilGame = now()->diffInHours($entity->date_time, false);

            if ($hoursUntilGame < 24) {
                $participant->update([
                    'attendance_status' => AttendanceStatus::LateCancel,
                ]);
            } else {
                $participant->update([
                    'attendance_status' => AttendanceStatus::CancelledEarly,
                ]);
            }
        }

        $removedUser = $participant->user;

        $participant->update(['status' => ParticipantStatus::Rejected]);

        Log::info('Game participant removed', [
            'game_id' => $entity->id,
            'user_id' => $participant->user_id,
            'removed_by' => Auth::id(),
        ]);

        // Notify removed user
        try {
            if ($removedUser) {
                app(NotificationService::class)->send(
                    $removedUser,
                    new \App\Notifications\ParticipantRemoved($removedUser, $entity, 'game'),
                    NotificationCategory::ParticipantRemoved
                );
            }
        } catch (\Throwable $e) {
            Log::error('notification.participant_removed_dispatch_failed', [
                'game_id' => $entity->id,
                'removed_user_id' => $participant->user_id,
                'error' => $e->getMessage(),
            ]);
        }

        // Promote from waitlist if standalone game
        if ($entity->campaign_id === null) {
            app(WaitlistService::class)->promoteAllOnCancel($entity);
        }

        // Check below-min-players
        $this->checkBelowMinPlayersAndNotify();

        session()->flash('success', __('common.flash_participant_removed'));
    }

    // ── Debriefing Properties ──────────────────────────

    /** @var array<string, string> Debriefing form responses keyed by prompt key */
    public array $debriefingResponses = [];

    /** @var string|null Recap content for host write-recap form */
    public ?string $recapContent = null;

    // ── Debriefing Actions ─────────────────────────────

    /**
     * Submit a debriefing response for the current game.
     */
    public function submitDebriefing(): void
    {
        $viewer = Auth::user();

        if (! $viewer) {
            return;
        }

        try {
            app(DebriefingService::class)->submitDebriefing(
                $this->game,
                $viewer,
                $this->debriefingResponses,
            );

            $this->debriefingResponses = [];
            session()->flash('success', __('games.flash_debriefing_submitted'));
        } catch (\LogicException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    // ── Recap Action ───────────────────────────────────

    /**
     * Write a recap for the completed game (host only).
     */
    public function writeRecap(): void
    {
        $viewer = Auth::user();

        if (! $viewer) {
            return;
        }

        $this->validate([
            'recapContent' => ['required', 'string', 'max:2000', 'min:1'],
        ]);

        try {
            app(RecapService::class)->writeRecap(
                $this->game,
                $viewer,
                $this->recapContent,
            );

            $this->recapContent = null;
            $this->game->refresh();
            session()->flash('success', __('games.flash_recap_written'));
        } catch (\LogicException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    // ── Helpers ────────────────────────────────────────

    private function findParticipantOrFail(string $participantId): GameParticipant
    {
        return GameParticipant::where('id', $participantId)
            ->where('game_id', $this->game->id)
            ->firstOrFail();
    }

    /**
     * Check if roster is below min_players and notify the host.
     */
    private function checkBelowMinPlayersAndNotify(): void
    {
        if (! $this->game->min_players) {
            return;
        }

        $approvedCount = $this->game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->count();

        if ($approvedCount < $this->game->min_players) {
            Log::warning('waitlist.below_min_players', [
                'game_id' => $this->game->id,
                'current_roster' => $approvedCount,
                'min_players' => $this->game->min_players,
            ]);

            try {
                $owner = $this->game->owner;
                if ($owner) {
                    app(NotificationService::class)->send(
                        $owner,
                        new BelowMinPlayersWarning($this->game, $approvedCount, $this->game->min_players),
                        NotificationCategory::BelowMinPlayers
                    );
                }
            } catch (\Throwable $e) {
                Log::error('notification.below_min_players_dispatch_failed', [
                    'game_id' => $this->game->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function render()
    {
        $this->game->load([
            'owner',
            'campaign',
            'gameSystem.categories',
            'gameSystem.mechanics',
            'gameSystem.publishers',
            'gameSystem.baseGame',
            'gameSystem.expansions',
            'participants.user',
            'applications.user',
        ]);

        $viewer = Auth::user();
        $isOwner = $viewer && $this->game->owner_id === $viewer->id;
        $isParticipant = $viewer && $this->game->participants
            ->contains(fn ($p) => $p->user_id === $viewer->id
                && in_array($p->status->value, [
                    ParticipantStatus::Approved->value,
                    ParticipantStatus::Pending->value,
                    ParticipantStatus::Waitlisted->value,
                ]));

        $userInvitation = null;
        $hasExistingApplication = false;
        $userWaitlistParticipant = null;
        $userPendingParticipant = null;
        $waitlistPosition = null;
        $userBenchParticipant = null;

        if ($viewer) {
            $userInvitation = $this->game->participants
                ->first(fn ($p) => $p->user_id === $viewer->id
                    && $p->role === 'invited'
                    && $p->status === 'pending');

            $hasExistingApplication = $this->game->applications()
                ->where('user_id', $viewer->id)
                ->exists();

            // Waitlisted state
            $userWaitlistParticipant = $this->game->participants
                ->first(fn ($p) => $p->user_id === $viewer->id
                    && $p->status === ParticipantStatus::Waitlisted);

            if ($userWaitlistParticipant) {
                $waitlistPosition = app(WaitlistService::class)->getWaitlistPosition($userWaitlistParticipant);
            }

            // Pending confirmation state (promoted from waitlist)
            $userPendingParticipant = $this->game->participants
                ->first(fn ($p) => $p->user_id === $viewer->id
                    && $p->status === ParticipantStatus::Pending
                    && $p->confirmation_expires_at !== null);

            // Benched state (campaign sessions)
            $userBenchParticipant = $this->game->participants
                ->first(fn ($p) => $p->user_id === $viewer->id
                    && $p->status === ParticipantStatus::Benched);
        }

        $isGameFull = $this->game->max_players !== null
            && $this->game->participants
                ->where('status', ParticipantStatus::Approved->value)
                ->count() >= $this->game->max_players;

        $canApply = $viewer
            && ! $isOwner
            && ! $isParticipant
            && ! $hasExistingApplication
            && $this->game->visibility !== 'private'
            && (! $isGameFull || $this->game->campaign_id !== null);
        // Campaign sessions allow applying when full (applicant gets benched)

        $canJoinWaitlist = $viewer
            && ! $isOwner
            && ! $isParticipant
            && ! $hasExistingApplication
            && $this->game->campaign_id === null
            && $isGameFull
            && $this->game->visibility !== 'private';

        // Waitlist data for host view
        $waitlistedPlayers = collect();
        if ($isOwner && $this->game->campaign_id === null) {
            $waitlistedPlayers = $this->game->participants
                ->where('status', ParticipantStatus::Waitlisted->value)
                ->sortBy('waitlisted_at');
        }

        // Bench data for host view (campaign sessions only)
        $benchedPlayers = collect();
        if ($isOwner && $this->game->campaign_id !== null) {
            $benchedPlayers = $this->game->participants
                ->filter(fn ($p) => $p->status === ParticipantStatus::Benched);
        }

        $canReview = false;
        $reviews = collect();

        if ($viewer) {
            $canReview = app(\App\Services\ReviewEligibilityService::class)
                ->canReviewSession($viewer, $this->game);
        }

        $reviews = \App\Models\Review::where('reviewable_type', \App\Models\Game::class)
            ->where('reviewable_id', $this->game->id)
            ->published()
            ->with('reviewer')
            ->latest()
            ->limit(10)
            ->get();

        $activeSessionZero = $this->game->activeSessionZeroSurvey();

        $isSessionZeroConfirmed = false;
        if ($activeSessionZero && $viewer) {
            $isSessionZeroConfirmed = \App\Models\SessionZeroConfirmation::where('session_zero_survey_id', $activeSessionZero->id)
                ->where('user_id', $viewer->id)
                ->exists();
        }

        // ── Debriefing state ──
        $hasDebriefingTools = $this->game->hasDebriefingTools();
        $debriefingPrompts = $hasDebriefingTools ? $this->game->getDebriefingPrompts() : [];
        $userDebriefing = null;
        $hostDebriefings = collect();
        $debriefingSummary = null;

        if ($hasDebriefingTools && $this->game->status === 'completed' && $viewer) {
            $userDebriefing = SessionDebriefing::where('game_id', $this->game->id)
                ->where('user_id', $viewer->id)
                ->first();

            if ($isOwner) {
                $hostDebriefings = app(DebriefingService::class)->getHostDebriefings($this->game);
            } elseif ($isParticipant && $userDebriefing) {
                $debriefingSummary = app(DebriefingService::class)->getAnonymizedSummary($this->game);
            }
        }

        return view('livewire.games.game-detail', [
            'game' => $this->game,
            'isOwner' => $isOwner,
            'isParticipant' => $isParticipant,
            'userInvitation' => $userInvitation,
            'canApply' => $canApply,
            'hasExistingApplication' => $hasExistingApplication,
            'isGuest' => Auth::guest(),
            'reviews' => $reviews,
            'canReview' => $canReview,
            'activeSessionZero' => $activeSessionZero,
            'isSessionZeroConfirmed' => $isSessionZeroConfirmed,
            'isGameFull' => $isGameFull,
            'canJoinWaitlist' => $canJoinWaitlist,
            'userWaitlistParticipant' => $userWaitlistParticipant,
            'userPendingParticipant' => $userPendingParticipant,
            'waitlistPosition' => $waitlistPosition,
            'waitlistedPlayers' => $waitlistedPlayers,
            'benchedPlayers' => $benchedPlayers,
            'userBenchParticipant' => $userBenchParticipant,
            'hasDebriefingTools' => $hasDebriefingTools,
            'debriefingPrompts' => $debriefingPrompts,
            'userDebriefing' => $userDebriefing,
            'hostDebriefings' => $hostDebriefings,
            'debriefingSummary' => $debriefingSummary,
        ])->layout(Auth::guest() ? 'components.public-layout' : 'layouts.app');
    }
}
