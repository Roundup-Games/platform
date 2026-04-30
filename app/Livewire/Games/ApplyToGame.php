<?php

namespace App\Livewire\Games;

use App\Enums\NotificationCategory;
use App\Enums\Visibility;
use App\Models\Game;
use App\Models\GameApplication;
use App\Models\GameParticipant;
use App\Models\User;
use App\Notifications\NewApplication;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.app')]
class ApplyToGame extends Component
{
    public Game $game;

    #[Validate('nullable|string|max:1000')]
    public string $message = '';

    public function mount(string $id): void
    {
        $game = Game::findOrFail($id);

        // Must be logged in to apply
        if (! Auth::check()) {
            $this->redirect(route('login'));

            return;
        }

        $this->authorize('view', $game);
        $this->game = $game;

        // Only public and protected games accept applications
        if ($game->visibility === Visibility::Private) {
            abort(403, 'This game does not accept applications.');
        }

        // Check if already a participant or has pending application
        if ($game->participants()->where('user_id', Auth::id())->exists()) {
            session()->flash('info', __('events.content_already_participant_or_pending_game'));

            return;
        }

        if ($game->applications()->where('user_id', Auth::id())->exists()) {
            session()->flash('info', __('games.content_you_have_already_applied_to_this_game'));

            return;
        }
    }

    public function submitApplication(): void
    {
        // Owner cannot apply to their own game
        if ($this->game->owner_id === Auth::id()) {
            $this->addError('message', __('games.error_you_cannot_apply_to_your_own_game'));

            return;
        }

        $gameId = $this->game->id;
        $userId = Auth::id();
        $message = $this->message;

        $this->validate();

        try {
            DB::transaction(function () use ($gameId, $userId, $message) {
                // Pessimistic lock on existing participant/application rows for this game+user
                GameParticipant::lockForUpdate()
                    ->where('game_id', $gameId)
                    ->where('user_id', $userId)
                    ->exists();

                GameApplication::lockForUpdate()
                    ->where('game_id', $gameId)
                    ->where('user_id', $userId)
                    ->exists();

                // Double-check no existing participant record
                if (GameParticipant::where('game_id', $gameId)->where('user_id', $userId)->exists()) {
                    throw new \RuntimeException(__('events.content_you_are_already_a_participant'));
                }

                // Double-check no existing application
                if (GameApplication::where('game_id', $gameId)->where('user_id', $userId)->exists()) {
                    throw new \RuntimeException(__('games.content_you_have_already_applied_to_this_game'));
                }

                // For public games, auto-approve; for protected games, require approval
                $game = Game::find($gameId);
                $isPublic = $game->visibility === Visibility::Public;
                $isCampaignSession = $game->campaign_id !== null;

                // Check if game is full (applies to both standalone and campaign sessions)
                $approvedCount = GameParticipant::where('game_id', $gameId)
                    ->where('status', 'approved')
                    ->count();
                $isFull = $game->max_players !== null && $approvedCount >= $game->max_players;

                // Determine participant status
                $participantStatus = 'pending';
                $participantRole = 'applicant';
                $benchedAt = null;
                $waitlistedAt = null;

                if ($isPublic) {
                    if ($isCampaignSession && $isFull) {
                        // Campaign session is full → bench the applicant
                        $participantStatus = 'benched';
                        $participantRole = 'player';
                        $benchedAt = now();
                    } elseif (! $isCampaignSession && $isFull) {
                        // Standalone public game is full → auto-waitlist
                        $participantStatus = 'waitlisted';
                        $participantRole = 'player';
                        $waitlistedAt = now();
                    } else {
                        $participantStatus = 'approved';
                        $participantRole = 'player';
                    }
                }

                // Create application record (always pending status)
                // The application itself is always 'pending' from the applicant's perspective.
                // The participant record carries the resolved status (approved/waitlisted/benched).
                GameApplication::create([
                    'game_id' => $gameId,
                    'user_id' => $userId,
                    'status' => 'pending',
                    'message' => $message ?: null,
                ]);

                // Create participant record
                GameParticipant::create([
                    'game_id' => $gameId,
                    'user_id' => $userId,
                    'role' => $participantRole,
                    'status' => $participantStatus,
                    'benched_at' => $benchedAt,
                    'waitlisted_at' => $waitlistedAt,
                ]);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Unique constraint violation — concurrent duplicate
            Log::warning('Game application race caught by unique constraint', [
                'game_id' => $gameId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            session()->flash('info', __('games.content_you_have_already_applied_to_this_game'));
            $this->redirect(route('games.detail', $this->game->id), navigate: true);

            return;
        } catch (\RuntimeException $e) {
            $this->addError('message', $e->getMessage());

            return;
        }

        $isPublic = $this->game->visibility === Visibility::Public;
        $isCampaignSession = $this->game->campaign_id !== null;
        $approvedCount = GameParticipant::where('game_id', $this->game->id)
            ->where('status', 'approved')
            ->count();
        $isFull = $this->game->max_players !== null && $approvedCount >= $this->game->max_players;

        Log::info('Game application submitted', [
            'game_id' => $this->game->id,
            'user_id' => Auth::id(),
            'auto_approved' => $isPublic && ! $isFull,
            'benched' => $isPublic && $isCampaignSession && $isFull,
            'waitlisted' => $isPublic && ! $isCampaignSession && $isFull,
        ]);

        // Notify game owner of new application (protected games only)
        if (! $isPublic) {
            try {
                $owner = User::find($this->game->owner_id);
                if ($owner) {
                    app(NotificationService::class)->send(
                        $owner,
                        new NewApplication(Auth::user(), $this->game, 'game'),
                        NotificationCategory::NewApplication
                    );
                }
            } catch (\Throwable $e) {
                Log::error('notification.new_application_dispatch_failed', [
                    'game_id' => $this->game->id,
                    'applicant_id' => Auth::id(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($isPublic && $isCampaignSession && $isFull) {
            session()->flash('success', __('games.content_you_have_been_placed_on_the_bench'));
        } elseif ($isPublic && ! $isCampaignSession && $isFull) {
            session()->flash('success', __('games.content_added_to_waitlist'));
        } elseif ($isPublic) {
            session()->flash('success', __('games.content_you_have_joined_the_game'));
        } else {
            session()->flash('success', __('games.content_application_submitted_the_game_owner'));
        }

        $this->redirect(route('games.detail', $this->game->id), navigate: true);
    }

    public function render()
    {
        return view('livewire.games.apply-to-game', [
            'game' => $this->game,
            'hasExistingApplication' => Auth::check() && $this->game->applications()
                ->where('user_id', Auth::id())
                ->exists(),
            'isParticipant' => Auth::check() && $this->game->participants()
                ->where('user_id', Auth::id())
                ->exists(),
        ]);
    }
}
