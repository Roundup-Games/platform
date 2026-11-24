<?php

namespace App\Livewire\Games;

use App\Enums\CalendarSyncOperation;
use App\Enums\GameStatus;
use App\Enums\NotificationCategory;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Notifications\EntityCancelled;
use App\Notifications\EntityCompleted;
use App\Services\AttendanceService;
use App\Services\Calendar\CalendarSyncDispatcher;
use App\Services\DebriefingService;
use App\Services\Edit\AgreementEditService;
use App\Services\MyGamesBoardService;
use App\Services\NotificationService;
use App\Services\ParticipantLifecycle;
use App\Services\Roster;
use App\Traits\EditsVenueLocation;
use App\Traits\HandlesInvitations;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use RalphJSmit\Laravel\SEO\Support\SEOData;

#[Layout('layouts.app')]
class GamesPage extends Component
{
    use EditsVenueLocation;
    use HandlesInvitations;
    use WithPagination;

    /** Grows by 15 each time the user clicks "load more" on the community feed. */
    public int $activityFeedLimit = 15;

    // ── Edit Game State ────────────────────────────────
    public ?string $editingGameId = null;

    public ?string $confirmingAction = null;

    public string $edit_name = '';

    public string $edit_description = '';

    public ?string $edit_expected_duration = '';

    public string $edit_visibility = 'private';

    public string $edit_location_details = '';

    public ?string $edit_location_id = null;

    public string $edit_location_instructions = '';

    public string $edit_location_name = '';

    public string $edit_location_city = '';

    public string $edit_location_address = '';

    // ── Venue Search State (edit modal) ────────────────
    public string $edit_venue_query = '';

    /** @var array<int, mixed> */
    public array $edit_venue_results = [];

    public bool $edit_venue_searched = false;

    public string $edit_address_city = '';

    public string $edit_address_street = '';

    public string $edit_address_mode = 'venue'; // venue | address

    // ── Edit Game ────────────────────────────────────────

    public function editGame(string $id): void
    {
        $game = Game::findOrFail($id);
        $this->authorize('update', $game);

        $this->editingGameId = $game->id;
        $this->edit_name = $game->name;
        $this->edit_description = $game->description ?? '';
        $this->edit_expected_duration = $game->expected_duration > 0 ? (string) $game->expected_duration : '';
        $this->edit_visibility = $game->visibility->value ?? 'private';
        $this->edit_location_details = is_string($game->location['details'] ?? null) ? $game->location['details'] : '';
        $this->edit_location_id = $game->location_id;
        $this->edit_location_instructions = $game->location_instructions ?? '';
        $this->edit_location_name = $game->linkedLocation->name ?? '';
        $this->edit_location_city = $game->linkedLocation->city ?? '';
        $this->edit_location_address = $game->linkedLocation->address ?? '';

        if ($game->location_id !== null && $game->linkedLocation !== null) {
            $this->edit_address_city = $game->linkedLocation->city ?? '';
            $this->edit_address_street = $game->linkedLocation->address ?? '';
        }
    }

    public function cancelEdit(): void
    {
        $this->reset([
            'editingGameId', 'edit_name', 'edit_description', 'edit_expected_duration',
            'edit_visibility', 'edit_location_details', 'edit_location_id', 'edit_location_instructions',
            'edit_location_name', 'edit_location_city', 'edit_location_address',
            'edit_venue_query', 'edit_venue_results', 'edit_venue_searched',
            'edit_address_city', 'edit_address_street', 'edit_address_mode',
        ]);
    }

    // Venue search/address actions provided by EditsVenueLocation trait

    /**
     * Save the inline edit modal. Validation, the public-visibility gate and
     * the no-op filter run here; the write itself goes through
     * {@see AgreementEditService::apply()} — the one place that decides what a
     * change is worth to the participants (D313) and whether the agreement lock
     * refuses it (D314). This method carries no tier logic of its own.
     *
     * @throws ValidationException From $this->validate(), or from the edit service when a Tier-1 change hits an agreement-locked game — under the `edit` key the modal renders.
     */
    public function saveGameEdit(): void
    {
        if ($this->editingGameId === null) {
            return;
        }

        $game = Game::findOrFail($this->editingGameId);
        $this->authorize('update', $game);

        $this->validate([
            'edit_name' => 'required|string|max:255',
            'edit_description' => 'nullable|string|max:5000',
            'edit_expected_duration' => 'nullable|numeric|min:0.5|max:24',
            'edit_visibility' => Visibility::validationRule(),
            'edit_location_details' => 'nullable|string|max:1000',
        ]);

        // Gate public visibility
        if ($this->edit_visibility === 'public' && ! authenticatedUser()->can_create_public_entries) {
            $this->edit_visibility = 'protected';
        }

        // Machine column names are the single source of truth: the activity
        // log and EntityUpdated both consume them, and the notification
        // resolves human labels per RECIPIENT locale (ChangedFieldLabels).
        $changes = [];

        if ($game->name !== $this->edit_name) {
            $changes['name'] = $this->edit_name;
        }
        if (($game->description ?? '') !== $this->edit_description) {
            $changes['description'] = $this->edit_description !== '' ? $this->edit_description : null;
        }
        $newDuration = $this->edit_expected_duration !== '' ? (float) $this->edit_expected_duration : null;
        if ((float) ($game->expected_duration ?? 0) !== (float) ($newDuration ?? 0)) {
            $changes['expected_duration'] = $newDuration ?? 2;
        }
        if ($game->visibility?->value !== $this->edit_visibility) {
            $changes['visibility'] = $this->edit_visibility;
        }
        $oldLocation = $game->location['details'] ?? '';
        if ($oldLocation !== $this->edit_location_details) {
            $changes['location'] = ['details' => $this->edit_location_details];
        }
        if ($game->location_id !== $this->edit_location_id) {
            $changes['location_id'] = $this->edit_location_id !== '' ? $this->edit_location_id : null;
        }
        if (($game->location_instructions ?? '') !== $this->edit_location_instructions) {
            $changes['location_instructions'] = $this->edit_location_instructions !== '' ? $this->edit_location_instructions : null;
        }

        if ($changes === []) {
            $this->cancelEdit();

            return;
        }

        // The ONE write path for owner field changes. It is deliberately NOT
        // wrapped in a try/catch: when a Tier-1 change hits an agreement-locked
        // game it throws a ValidationException that Livewire places in the error
        // bag — the modal stays open and renders the reason, and nothing below
        // runs, so a refused edit never flashes a success.
        app(AgreementEditService::class)->apply($game, $changes, authenticatedUser());

        $this->cancelEdit();
        session()->flash('success', __('games.flash_game_updated'));
    }

    /** Reveal 15 more community-feed items (load-more, not numbered pages). */
    public function loadMoreActivity(): void
    {
        $this->activityFeedLimit += 15;
    }

    public function render(): View
    {
        seo(new SEOData(
            title: __('games.heading_my_games'),
            description: __('games.seo_description_my_games', ['brand' => is_string($b = config('company.display_name')) ? $b : '']),
            robots: 'noindex, nofollow',
        ));

        $user = authenticatedUser();

        // Prioritized board: needs-attention -> upcoming -> recent -> archive.
        // See MyGamesBoardService for the bucketing contract.
        $board = app(MyGamesBoardService::class)->build($user, $this->activityFeedLimit);

        return view('livewire.games.games-page', [
            'needsAttention' => $board['needs_attention'],
            'upcomingHosting' => $board['upcoming_hosting'],
            'upcomingPlaying' => $board['upcoming_playing'],
            'pendingInvitations' => $board['pending_invitations'],
            'recentCompleted' => $board['recent_completed'],
            'archiveGames' => $board['archive'],
            'activityFeed' => $board['activity_feed'],
            'hasAnyGames' => $board['has_any_games'],
        ]);
    }

    public function leaveGame(string $gameId): void
    {
        $user = authenticatedUser();
        $game = Game::findOrFail($gameId);

        // Owner cannot leave their own game
        if ($game->owner_id === $user->id) {
            session()->flash('error', __('games.error_cannot_leave_own_game'));

            return;
        }

        $participant = $game->participants()
            ->whereBelongsTo($user)
            ->whereIn('status', [
                ParticipantStatus::Approved->value,
                ParticipantStatus::Waitlisted->value,
                ParticipantStatus::Benched->value,
                ParticipantStatus::Pending->value,
            ])
            ->first();

        if ($participant === null || ! $user->can('leave', $game)) {
            session()->flash('error', __('games.error_not_a_game_participant'));

            return;
        }

        app(ParticipantLifecycle::class)->depart($participant, $user);

        session()->flash('success', __('games.flash_you_left_the_game'));
    }

    public function cancelGame(string $id): void
    {
        $game = $this->scheduledGameForCancellation($id);

        if ($game === null) {
            return;
        }

        $this->cancelScheduledGame($game);
    }

    /**
     * "Recreate with changes" (linked recreate, D318/D319): the host declares
     * the recreate, the game is cancelled exactly like {@see cancelGame}, and
     * the host lands on the create form pre-filled from the cancelled game,
     * with ?recreate= carrying the link.
     *
     * The declaration is the host's explicit choice — it is never inferred
     * from a later clone — and it is stamped before the cancellation so it is
     * on record when the late-cancel offence is recorded. The offence itself
     * is recorded exactly as today; AttendanceService::resolveLinkedRecreate()
     * neutralises it only once the replacement is published.
     */
    public function recreateGame(string $id): void
    {
        $game = $this->scheduledGameForCancellation($id);

        if ($game === null) {
            return;
        }

        // Stamped in memory first: the cancellation's own save persists the
        // declaration in the SAME write as the status flip, so a declared
        // game can never be left un-cancelled and the model's saved hooks run
        // once, exactly as they do for a plain cancel.
        $game->recreate_declared_at = now();

        $this->cancelScheduledGame($game);

        Log::info('recreate.declared', [
            'game_id' => $game->id,
            'host_id' => $game->owner_id,
        ]);

        $this->redirect(route('games.create', ['recreate' => $game->id]), navigate: true);
    }

    /**
     * The guards every cancel-path action shares: the game must exist, the
     * user must pass GamePolicy::update, and only a scheduled game can be
     * cancelled. Returns null once refused, with the reason flashed.
     */
    private function scheduledGameForCancellation(string $id): ?Game
    {
        $game = Game::findOrFail($id);
        $this->authorize('update', $game);

        if ($game->status !== GameStatus::Scheduled) {
            session()->flash('error', __('games.error_game_not_scheduled'));

            return null;
        }

        return $game;
    }

    /**
     * Cancel a scheduled game: the status flip, the host late-cancel offence,
     * the waitlist/bench cascade and the participant notifications. This is
     * the one cancellation body — cancelGame() and recreateGame() both call
     * it, so the two paths cannot drift.
     */
    private function cancelScheduledGame(Game $game): void
    {
        $game->status = GameStatus::Canceled;
        $game->save();

        Log::info('Game canceled', [
            'game_id' => $game->id,
            'owner_id' => $game->owner_id,
            'previous_status' => 'scheduled',
            'new_status' => 'canceled',
        ]);

        // Track host cancellation offence (late cancel <24h with roster)
        try {
            app(AttendanceService::class)->recordHostCancellationOffence($game);
        } catch (\Throwable $e) {
            Log::error('attendance.host_cancellation_offence_failed', [
                'game_id' => $game->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Reject every waitlisted and benched participant — the cancellation
        // cascade. Owned by Roster so the waitlist+bench ordering lives in
        // one place and the cancel flow cannot forget half of it.
        app(Roster::class)->onCancellation($game);

        // Notify all approved participants (excluding owner) that the game was cancelled
        try {
            $approvedParticipants = $game->participants()
                ->where('status', 'approved')
                ->where('user_id', '!=', $game->owner_id)
                ->with('user')
                ->get();

            foreach ($approvedParticipants as $participant) {
                if ($participant->user === null) {
                    continue;
                }
                app(NotificationService::class)->send(
                    $participant->user,
                    new EntityCancelled($game),
                    NotificationCategory::GameCancelled
                );
            }
        } catch (\Throwable $e) {
            Log::error('notification.game_cancelled_dispatch_failed', [
                'game_id' => $game->id,
                'error' => $e->getMessage(),
            ]);
        }

        session()->flash('success', __('games.flash_game_canceled'));
    }

    public function completeGame(string $id): void
    {
        $game = Game::findOrFail($id);
        $this->authorize('update', $game);

        if ($game->status !== GameStatus::Scheduled) {
            session()->flash('error', __('games.error_game_not_scheduled'));

            return;
        }

        DB::transaction(function () use ($game) {
            $game->status = GameStatus::Completed;
            $game->save();

            // Open attendance reporting window
            app(AttendanceService::class)->handleGameCompletion($game);
        });

        Log::info('Game completed', [
            'game_id' => $game->id,
            'owner_id' => $game->owner_id,
            'previous_status' => 'scheduled',
            'new_status' => 'completed',
        ]);

        // Managed-sync trigger (M083/S03-T03): a future-dated completion is
        // a cancellation in the feed's publish semantics (sixth pass) — the
        // sync service maps it to status=cancelled and skips entities
        // already outside the feed window, so the fan-out is safe for past
        // completions too.
        CalendarSyncDispatcher::dispatchToRoster($game, CalendarSyncOperation::Cancel);

        // Notify all approved participants (excluding owner) that the game was completed
        try {
            $approvedParticipants = $game->participants()
                ->where('status', 'approved')
                ->where('user_id', '!=', $game->owner_id)
                ->with('user')
                ->get();

            foreach ($approvedParticipants as $participant) {
                if ($participant->user === null) {
                    continue;
                }
                app(NotificationService::class)->send(
                    $participant->user,
                    new EntityCompleted($game),
                    NotificationCategory::GameCompleted
                );
            }
        } catch (\Throwable $e) {
            Log::error('notification.game_completed_dispatch_failed', [
                'game_id' => $game->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Send debriefing notifications if game has debriefing tools
        if ($game->hasDebriefingTools()) {
            try {
                app(DebriefingService::class)->notifyParticipants($game);
            } catch (\Throwable $e) {
                Log::error('notification.debriefing_available_dispatch_failed', [
                    'game_id' => $game->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        session()->flash('success', __('games.flash_game_completed'));
    }

    // Invitation accept/decline actions provided by HandlesInvitations

    /** @return class-string<GameParticipant> */
    protected function invitationParticipantClass(): string
    {
        return GameParticipant::class;
    }
}
