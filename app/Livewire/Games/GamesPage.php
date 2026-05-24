<?php

namespace App\Livewire\Games;

use App\Enums\GameStatus;
use App\Enums\NotificationCategory;
use App\Enums\Visibility;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Notifications\EntityCancelled;
use App\Notifications\EntityCompleted;
use App\Notifications\EntityUpdated;
use App\Services\ActivityLogService;
use App\Services\AttendanceService;
use App\Services\GameActivityFeedService;
use App\Services\NotificationService;
use App\Services\ParticipantService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class GamesPage extends Component
{
    use WithPagination;

    // ── Edit Game State ────────────────────────────────
    public ?string $editingGameId = null;
    public ?string $confirmingAction = null;
    public string $edit_name = '';
    public string $edit_description = '';
    public ?string $edit_expected_duration = '';
    public string $edit_visibility = 'private';
    public string $edit_location_details = '';

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
        $this->edit_visibility = $game->visibility?->value ?? 'private';
        $this->edit_location_details = $game->location['details'] ?? '';
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingGameId', 'edit_name', 'edit_description', 'edit_expected_duration', 'edit_visibility', 'edit_location_details']);
    }

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
        if ($this->edit_visibility === 'public' && ! Auth::user()->can_create_public_entries) {
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
        if ($game->visibility !== $this->edit_visibility) {
            $changes['visibility'] = $this->edit_visibility;
            $changedLabels[] = __('games.field_visibility');
        }
        $oldLocation = $game->location['details'] ?? '';
        if ($oldLocation !== $this->edit_location_details) {
            $changes['location'] = ['details' => $this->edit_location_details];
            $changedLabels[] = __('games.field_location');
        }

        if (empty($changes)) {
            $this->cancelEdit();
            return;
        }

        $game->fill($changes)->save();

        // Log activity
        app(ActivityLogService::class)->log(
            \App\Enums\ActivityType::GameUpdated,
            Auth::user(),
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

    public function render()
    {
        seo(new \RalphJSmit\Laravel\SEO\Support\SEOData(
            title: __('games.seo_title_my_games'),
            description: __('games.seo_description_my_games', ['brand' => config('company.display_name')]),
            robots: 'noindex, nofollow',
        ));

        $user = Auth::user();

        $ownedGames = Game::where('owner_id', $user->id)
            ->with(['gameSystem', 'participants', 'campaign'])
            ->orderBy('date_time', 'desc')
            ->get();

        $participatingGames = Game::whereHas('participants', function ($query) use ($user) {
            $query->where('user_id', $user->id)
                ->where('role', 'player')
                ->where('status', 'approved');
        })->where('owner_id', '!=', $user->id)
            ->with(['gameSystem', 'participants', 'owner', 'campaign'])
            ->orderBy('date_time', 'desc')
            ->get();

        $pendingInvitations = GameParticipant::where('user_id', $user->id)
            ->where('role', 'invited')
            ->where('status', 'pending')
            ->with(['game.gameSystem', 'game.owner'])
            ->get();

        // Community activity feed — what friends/followed users are doing in games
        $activityFeed = app(GameActivityFeedService::class)->getFeed($user, 15);

        return view('livewire.games.games-page', [
            'ownedGames' => $ownedGames,
            'participatingGames' => $participatingGames,
            'pendingInvitations' => $pendingInvitations,
            'activityFeed' => $activityFeed,
        ]);
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

        // Notify all approved participants (excluding owner) that the game was cancelled
        try {
            $approvedParticipants = $game->participants()
                ->where('status', 'approved')
                ->where('user_id', '!=', $game->owner_id)
                ->with('user')
                ->get();

            foreach ($approvedParticipants as $participant) {
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

        $game->status = GameStatus::Completed;
        $game->save();

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
                app(\App\Services\DebriefingService::class)->notifyParticipants($game);
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

        $result = app(ParticipantService::class)->acceptInvitation(
            $participant,
            $game,
            Auth::user(),
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

        $result = app(ParticipantService::class)->declineInvitation(
            $participant,
            $game,
            Auth::user(),
        );

        if ($result->success) {
            session()->flash('success', __($result->messageKey, $result->messageParams));
        } elseif ($result->errorKey) {
            session()->flash('error', __($result->errorKey, $result->errorParams));
        }
    }
}
