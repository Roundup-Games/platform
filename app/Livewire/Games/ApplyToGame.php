<?php

namespace App\Livewire\Games;

use App\Enums\Visibility;
use App\Models\Game;
use App\Models\GameApplication;
use App\Models\GameParticipant;
use App\Traits\HandlesApplicationSubmission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.app')]
class ApplyToGame extends Component
{
    use HandlesApplicationSubmission;

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

    protected function getEntity(): Game
    {
        return $this->game;
    }

    protected function getApplicationConfig(): array
    {
        return [
            'foreign_key' => 'game_id',
            'application_class' => GameApplication::class,
            'participant_class' => GameParticipant::class,
            'entity_class' => Game::class,
            'show_route' => 'games.show',
            'entity_type' => 'game',
            'log_key' => 'game_id',
            'application_status_public' => 'approved',
            'translations' => [
                'own_entity_error' => 'games.error_you_cannot_apply_to_your_own_game',
                'race_applied' => 'games.content_you_have_already_applied_to_this_game',
                'already_participant' => 'events.content_you_are_already_a_participant',
                'already_applied' => 'games.content_you_have_already_applied_to_this_game',
                'bench_success' => 'games.content_you_have_been_placed_on_the_bench',
                'waitlist_success' => 'games.content_added_to_waitlist',
                'join_success' => 'games.content_you_have_joined_the_game',
                'application_submitted' => 'games.content_application_submitted_the_game_owner',
            ],
        ];
    }

    public function render(): View
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
