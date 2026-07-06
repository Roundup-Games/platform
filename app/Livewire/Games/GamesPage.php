<?php

namespace App\Livewire\Games;

use App\Enums\ActivityType;
use App\Enums\GameStatus;
use App\Enums\NotificationCategory;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Notifications\EntityCancelled;
use App\Notifications\EntityCompleted;
use App\Notifications\EntityUpdated;
use App\Services\ActivityLogService;
use App\Services\AttendanceService;
use App\Services\DebriefingService;
use App\Services\MyGamesBoardService;
use App\Services\NotificationService;
use App\Services\ParticipantLifecycle;
use App\Services\Roster;
use App\Traits\EditsVenueLocation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use RalphJSmit\Laravel\SEO\Support\SEOData;

#[Layout('layouts.app')]
class GamesPage extends Component
{
    use EditsVenueLocation;
    use WithPagination;

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

    public function mount(): void
    {
        if (Auth::guest()) {
            $this->redirect(route('discover', app()->getLocale()));
        }
    }

    // ── Edit Game ────────────────────────────────────────

    public function editGame(string $id): void
    {
        $game = Game::findOrFail($id);
        $this->authorize('update', $game);

        $this->editingGameId = $game->id;
        $this->edit_name = $game->name;
        $this->edit_description = $game->description ?? '';
        $this->edit_expected_duration = $game->expected_duration ? (string) $game->expected_duration : '';
        $this->edit_visibility = $game->visibility->value ?? 'private';
        $this->edit_location_details = is_string($game->location['details'] ?? null) ? $game->location['details'] : '';
        $this->edit_location_id = $game->location_id;
        $this->edit_location_instructions = $game->location_instructions ?? '';
        $this->edit_location_name = $game->linkedLocation->name ?? '';
        $this->edit_location_city = $game->linkedLocation->city ?? '';
        $this->edit_location_address = $game->linkedLocation->address ?? '';

        if ($game->location_id && $game->linkedLocation) {
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

        $changes = [];
        $changedLabels = [];

        if ($game->name !== $this->edit_name) {
            $changes['name'] = $this->edit_name;
            $changedLabels[] = __('games.field_name');
        }
        if (($game->description ?? '') !== $this->edit_description) {
            $changes['description'] = $this->edit_description ?: null;
            $changedLabels[] = __('games.field_description');
        }
        $newDuration = $this->edit_expected_duration !== '' ? (float) $this->edit_expected_duration : null;
        if ($game->expected_duration != $newDuration) {
            $changes['expected_duration'] = $newDuration ?? 2;
            $changedLabels[] = __('games.field_duration');
        }
        if ($game->visibility?->value !== $this->edit_visibility) {
            $changes['visibility'] = $this->edit_visibility;
            $changedLabels[] = __('games.field_visibility');
        }
        $oldLocation = $game->location['details'] ?? '';
        if ($oldLocation !== $this->edit_location_details) {
            $changes['location'] = ['details' => $this->edit_location_details];
        }
        if ($game->location_id !== $this->edit_location_id) {
            $changes['location_id'] = $this->edit_location_id ?: null;
            $changedLabels[] = __('common.field_location');
        } elseif ($oldLocation !== $this->edit_location_details) {
            // Location details changed but location_id stayed the same
            $changedLabels[] = __('common.field_location');
        }
        if (($game->location_instructions ?? '') !== $this->edit_location_instructions) {
            $changes['location_instructions'] = $this->edit_location_instructions ?: null;
        }

        if (empty($changes)) {
            $this->cancelEdit();

            return;
        }

        $game->fill($changes)->save();

        // Log activity
        app(ActivityLogService::class)->log(
            ActivityType::GameUpdated,
            authenticatedUser(),
            $game,
            ['changed_fields' => $changedLabels],
        );

        Log::info('Game updated', [
            'game_id' => $game->id,
            'owner_id' => $game->owner_id,
            'changed_fields' => $changedLabels,
        ]);

        // Notify participants (excluding owner)
        if (! empty($changedLabels)) {
            try {
                $participants = $game->participants()
                    ->where('status', 'approved')
                    ->where('user_id', '!=', $game->owner_id)
                    ->with('user')
                    ->get();

                foreach ($participants as $participant) {
                    if ($participant->user === null) {
                        continue;
                    }
                    app(NotificationService::class)->send(
                        $participant->user,
                        new EntityUpdated($game, $changedLabels),
                        NotificationCategory::GameUpdated,
                    );
                }
            } catch (\Throwable $e) {
                Log::error('notification.game_updated_dispatch_failed', [
                    'game_id' => $game->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->cancelEdit();
        session()->flash('success', __('games.flash_game_updated'));
    }

    public function render(): View
    {
        seo(new SEOData(
            title: __('games.seo_title_my_games'),
            description: __('games.seo_description_my_games', ['brand' => is_string($b = config('company.display_name')) ? $b : '']),
            robots: 'noindex, nofollow',
        ));

        $user = authenticatedUser();

        // Prioritized board: needs-attention -> upcoming -> recent -> archive.
        // See MyGamesBoardService for the bucketing contract.
        $board = app(MyGamesBoardService::class)->build($user);

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
        if ((string) $game->owner_id === (string) $user->id) {
            session()->flash('error', __('games.error_cannot_leave_own_game'));

            return;
        }

        $participant = $game->participants()
            ->where('user_id', $user->id)
            ->whereIn('status', [
                ParticipantStatus::Approved->value,
                ParticipantStatus::Waitlisted->value,
                ParticipantStatus::Benched->value,
                ParticipantStatus::Pending->value,
            ])
            ->first();

        if (! $participant) {
            session()->flash('error', __('games.error_not_a_participant'));

            return;
        }

        app(ParticipantLifecycle::class)->depart($participant, $user);

        // Promote from waitlist + warn host if below min_players
        app(Roster::class)->onDeparture($game);

        session()->flash('success', __('games.flash_you_left_the_game'));
    }

    public function cancelGame(string $id): void
    {
        $game = Game::findOrFail($id);
        $this->authorize('update', $game);

        if ($game->status !== GameStatus::Scheduled) {
            session()->flash('error', __('games.error_game_not_scheduled'));

            return;
        }

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

    public function acceptInvitation(string $participantId): void
    {
        $participant = GameParticipant::findOrFail($participantId);
        $game = $participant->game;

        if ($game === null) {
            return;
        }

        $result = app(ParticipantLifecycle::class)->acceptInvitation(
            $participant,
            $game,
            authenticatedUser(),
        );

        if ($result->success) {
            session()->flash('success', __($result->messageKey, $result->messageParams));
        } elseif ($result->errorKey) {
            session()->flash('error', __($result->errorKey, $result->errorParams));
        }
    }

    public function declineInvitation(string $participantId): void
    {
        $participant = GameParticipant::findOrFail($participantId);
        $game = $participant->game;

        if ($game === null) {
            return;
        }

        $result = app(ParticipantLifecycle::class)->declineInvitation(
            $participant,
            authenticatedUser(),
        );

        if ($result->success) {
            session()->flash('success', __($result->messageKey, $result->messageParams));
        } elseif ($result->errorKey) {
            session()->flash('error', __($result->errorKey, $result->errorParams));
        }
    }
}
