<?php

namespace App\Livewire\Games;

use App\Enums\AttendanceStatus;
use App\Enums\GameStatus;
use App\Enums\JoinSource;
use App\Enums\NotificationCategory;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\Review;
use App\Models\SessionDebriefing;
use App\Models\SessionZeroConfirmation;
use App\Notifications\ParticipantRemoved;
use App\Services\DebriefingService;
use App\Services\NotificationService;
use App\Services\ReviewEligibilityService;
use App\Services\WaitlistService;
use App\Traits\HandlesBench;
use App\Traits\HandlesSessionEnd;
use App\Traits\HandlesWaitlist;
use App\Traits\ManagesParticipants;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class GameDetail extends Component
{
    use HandlesBench, HandlesSessionEnd, HandlesWaitlist, ManagesParticipants;

    public Game $game;

    /** @var array<string, string> Debriefing form responses keyed by prompt key */
    public array $debriefingResponses = [];

    /** @var string|null Recap content for host write-recap form */
    public ?string $recapContent = null;

    /** @var string|null Validated share token captured on mount, persists across Livewire updates */
    #[Locked]
    public ?string $validatedShareToken = null;

    public function mount(string $id): void
    {
        $game = Game::findOrFail($id);
        $this->authorize('view', $game);
        $this->game = $game;

        // Capture valid share token on initial page load (query params don't persist across Livewire updates)
        if ($game->hasValidShareToken()) {
            $this->validatedShareToken = request()->query('share');
        }

        // Set share_intent cookie for guests visiting via share link
        if (Auth::guest() && $this->validatedShareToken !== null) {
            Cookie::queue('share_intent', encrypt(json_encode([
                'entity_type' => 'game',
                'entity_id' => $game->id,
                'share_token' => $this->validatedShareToken,
            ])), 24 * 60);
        }
    }

    // ── Trait contracts ────────────────────────────────

    public function getEntity(): Game { return $this->game; }
    public function getEntityIdColumn(): string { return 'game_id'; }
    public function getParticipantModel(): string { return GameParticipant::class; }
    public function getEntityName(): string { return 'Game'; }
    public function getEntityVar(): string { return 'game'; }
    public function getBackRoute(): string { return route('games.detail', $this->game->id); }

    // ── Host-initiated removal (game-specific override) ──

    public function removeParticipant(string $participantId): void
    {
        $participant = $this->findParticipantOrFail($participantId);
        $entity = $this->game;

        if ($participant->role === 'owner') {
            session()->flash('error', __('common.error_cannot_remove_the_entity_owner', ['entity' => 'game']));
            return;
        }

        if ($entity->date_time && $entity->date_time->isFuture()) {
            $hoursUntil = now()->diffInHours($entity->date_time, false);
            $participant->update(['attendance_status' => $hoursUntil < 24
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

        if ($entity->campaign_id === null) {
            app(WaitlistService::class)->promoteAllOnCancel($entity);
        }

        $this->checkBelowMinPlayersAndNotify();
        session()->flash('success', __('common.flash_participant_removed'));
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
        if (! $this->isOwner()) {
            session()->flash('error', __('common.error_not_authorized'));
            return;
        }

        $this->revokeShareLink();
        $this->generateShareLink();

        Log::info('Share link regenerated', [
            'entity_type' => 'game',
            'entity_id' => $this->game->id,
            'user_id' => Auth::id(),
        ]);
    }

    #[Computed]
    public function hasShareLink(): bool
    {
        return $this->game->share_token !== null;
    }

    #[Computed]
    public function shareLinkUrl(): ?string
    {
        if ($this->game->share_token === null) {
            return null;
        }

        return route('games.detail', $this->game->id) . '?share=' . $this->game->share_token;
    }

    // ── Join via Share Link ────────────────────────────

    public function joinViaShareLink(): void
    {
        $viewer = Auth::user();

        if (! $viewer || ! $this->canJoinViaShareLink()) {
            session()->flash('error', __('common.error_not_authorized'));
            return;
        }

        try {
            DB::transaction(function () use ($viewer) {
                $game = Game::lockForUpdate()->find($this->game->id);

                $approvedCount = $game->participants()
                    ->where('status', ParticipantStatus::Approved->value)
                    ->count();

                $isFull = $game->max_players !== null && $approvedCount >= $game->max_players;

                if ($isFull && $game->campaign_id !== null) {
                    // Campaign session: bench the player
                    GameParticipant::create([
                        'game_id' => $game->id,
                        'user_id' => $viewer->id,
                        'role' => 'player',
                        'status' => ParticipantStatus::Benched->value,
                        'benched_at' => now(),
                        'join_source' => JoinSource::ShareLink->value,
                    ]);

                    Log::info('Player benched via share link (campaign session full)', [
                        'game_id' => $game->id,
                        'user_id' => $viewer->id,
                    ]);
                } elseif ($isFull) {
                    // Standalone game: waitlist
                    GameParticipant::create([
                        'game_id' => $game->id,
                        'user_id' => $viewer->id,
                        'role' => 'player',
                        'status' => ParticipantStatus::Waitlisted->value,
                        'waitlisted_at' => now(),
                        'join_source' => JoinSource::ShareLink->value,
                    ]);

                    Log::info('Player waitlisted via share link (game full)', [
                        'game_id' => $game->id,
                        'user_id' => $viewer->id,
                    ]);
                } else {
                    // Direct join
                    GameParticipant::create([
                        'game_id' => $game->id,
                        'user_id' => $viewer->id,
                        'role' => 'player',
                        'status' => ParticipantStatus::Approved->value,
                        'join_source' => JoinSource::ShareLink->value,
                    ]);

                    Log::info('Player joined via share link', [
                        'game_id' => $game->id,
                        'user_id' => $viewer->id,
                    ]);
                }
            });

            // Clear the share_intent cookie since the user has now joined
            Cookie::queue(Cookie::forget('share_intent'));

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
        if (! $viewer) {
            return false;
        }

        // Must have a valid share token captured on mount
        if ($this->validatedShareToken === null) {
            return false;
        }

        // Token must still match the game's current share token
        if ($this->validatedShareToken !== $this->game->share_token) {
            return false;
        }

        // Token must not be expired
        if ($this->game->share_token_expires_at !== null && $this->game->share_token_expires_at->isPast()) {
            return false;
        }

        // Cannot be the owner
        if ($this->game->owner_id === $viewer->id) {
            return false;
        }

        // Cannot already be a participant
        $existingParticipant = $this->game->participants
            ->first(fn ($p) => $p->user_id === $viewer->id
                && in_array($p->status->value, [
                    ParticipantStatus::Approved->value,
                    ParticipantStatus::Pending->value,
                    ParticipantStatus::Waitlisted->value,
                    ParticipantStatus::Benched->value,
                ]));

        if ($existingParticipant) {
            return false;
        }

        // Game must not be completed or canceled
        if (in_array($this->game->status->value, [GameStatus::Completed->value, GameStatus::Canceled->value])) {
            return false;
        }

        return true;
    }

    // ── Computed viewer state (cached per request-cycle) ──

    private function viewerId(): ?string
    {
        return Auth::id();
    }

    #[Computed]
    public function isOwner(): bool
    {
        return ($id = $this->viewerId()) && $this->game->owner_id === $id;
    }

    #[Computed]
    public function isParticipant(): bool
    {
        $id = $this->viewerId();
        return $id && $this->game->participants
            ->contains(fn ($p) => $p->user_id === $id && in_array($p->status->value, [
                ParticipantStatus::Approved->value,
                ParticipantStatus::Pending->value,
                ParticipantStatus::Waitlisted->value,
            ]));
    }

    #[Computed]
    public function userInvitation(): ?GameParticipant
    {
        $id = $this->viewerId();
        return $id ? $this->game->participants->first(fn ($p) => $p->user_id === $id
            && $p->role === 'invited' && $p->status === ParticipantStatus::Pending) : null;
    }

    #[Computed]
    public function hasExistingApplication(): bool
    {
        return ($id = $this->viewerId()) && $this->game->applications()->where('user_id', $id)->exists();
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
        return $this->game->max_players !== null
            && $this->game->participants->where('status', ParticipantStatus::Approved->value)->count() >= $this->game->max_players;
    }

    #[Computed]
    public function canApply(): bool
    {
        return ($id = $this->viewerId())
            && !$this->isOwner() && !$this->isParticipant() && !$this->hasExistingApplication()
            && $this->game->visibility !== Visibility::Private
            && (!$this->isGameFull() || $this->game->campaign_id !== null);
    }

    #[Computed]
    public function canJoinWaitlist(): bool
    {
        return ($id = $this->viewerId())
            && !$this->isOwner() && !$this->isParticipant() && !$this->hasExistingApplication()
            && $this->game->campaign_id === null && $this->isGameFull()
            && $this->game->visibility !== Visibility::Private;
    }

    #[Computed]
    public function waitlistedPlayers()
    {
        return ($this->isOwner() && $this->game->campaign_id === null)
            ? $this->game->participants->where('status', ParticipantStatus::Waitlisted->value)->sortBy('waitlisted_at')
            : collect();
    }

    #[Computed]
    public function benchedPlayers()
    {
        return ($this->isOwner() && $this->game->campaign_id !== null)
            ? $this->game->participants->filter(fn ($p) => $p->status === ParticipantStatus::Benched)
            : collect();
    }

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
        return ($viewer = Auth::user()) && app(ReviewEligibilityService::class)->canReviewSession($viewer, $this->game);
    }

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

    #[Computed]
    public function debriefingState(): array
    {
        $has = $this->game->hasDebriefingTools();
        $st = ['hasTools' => $has, 'prompts' => $has ? $this->game->getDebriefingPrompts() : [],
               'userDebriefing' => null, 'hostDebriefings' => collect(), 'summary' => null];

        if (!$has || $this->game->status !== GameStatus::Completed || !$this->viewerId()) {
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

    public function render()
    {
        $this->game->load([
            'owner', 'campaign', 'gameSystem.categories', 'gameSystem.mechanics',
            'gameSystem.publishers', 'gameSystem.baseGame', 'gameSystem.expansions',
            'participants.user', 'applications.user',
        ]);

        $sz = $this->sessionZeroState();
        $db = $this->debriefingState();

        return view('livewire.games.game-detail', [
            'game' => $this->game,
            'isOwner' => $this->isOwner(),
            'isParticipant' => $this->isParticipant(),
            'userInvitation' => $this->userInvitation(),
            'canApply' => $this->canApply(),
            'hasExistingApplication' => $this->hasExistingApplication(),
            'isGuest' => Auth::guest(),
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
            'comfortNotes' => $this->game->game_type?->value === 'board_game'
                && isset($this->game->safety_rules['comfort_notes'])
                    ? $this->game->safety_rules['comfort_notes'] : null,
            'hasShareLink' => $this->hasShareLink(),
            'shareLinkUrl' => $this->shareLinkUrl(),
            'canJoinViaShareLink' => $this->canJoinViaShareLink(),
        ])->layout(Auth::guest() ? 'components.public-layout' : 'layouts.app');
    }
}
