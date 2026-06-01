<?php

namespace App\Livewire\Games;

use App\Enums\ParticipantRole;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Traits\ManagesParticipants;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ManageParticipants extends Component
{
    use ManagesParticipants;

    public Game $game;

    public function mount(string $id): void
    {
        $game = Game::findOrFail($id);
        $this->authorize('update', $game);
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
        return GameParticipant::class;
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
        return route('games.show', $this->game->id);
    }

    // ── Render ─────────────────────────────────────────

    public function render()
    {
        $this->game->load([
            'participants.user',
            'applications.user',
        ]);

        $approvedParticipants = $this->game->participants
            ->filter(fn ($p) => $p->status === \App\Enums\ParticipantStatus::Approved)
            ->filter(fn ($p) => $p->role !== ParticipantRole::Owner);

        $pendingApplicants = $this->game->participants
            ->filter(fn ($p) => $p->role === ParticipantRole::Applicant && $p->status === \App\Enums\ParticipantStatus::Pending);

        $pendingInvites = $this->game->participants
            ->filter(fn ($p) => $p->role === ParticipantRole::Invited && $p->status === \App\Enums\ParticipantStatus::Pending);

        return view('livewire.games.manage-participants', [
            'game' => $this->game,
            'approvedParticipants' => $approvedParticipants,
            'pendingApplicants' => $pendingApplicants,
            'pendingInvites' => $pendingInvites,
            'waitlistedParticipants' => $this->getWaitlistedParticipants(),
            'benchedParticipants' => $this->getBenchedParticipants(),
        ]);
    }
}
