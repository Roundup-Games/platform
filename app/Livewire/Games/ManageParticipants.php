<?php

namespace App\Livewire\Games;

use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.app')]
class ManageParticipants extends Component
{
    public Game $game;

    #[Validate('required|email|max:255')]
    public string $inviteEmail = '';

    public function mount(string $id): void
    {
        $game = Game::findOrFail($id);
        $this->authorize('update', $game);
        $this->game = $game;
    }

    // ── Invite ─────────────────────────────────────────

    public function inviteParticipant(): void
    {
        $this->validateOnly('inviteEmail');

        $targetUser = User::where('email', $this->inviteEmail)->first();

        if (! $targetUser) {
            $this->addError('inviteEmail', 'No user found with that email address.');

            return;
        }

        if ($targetUser->id === Auth::id()) {
            $this->addError('inviteEmail', 'You cannot invite yourself.');

            return;
        }

        if ($this->game->participants()->where('user_id', $targetUser->id)->exists()) {
            $this->addError('inviteEmail', 'This user is already a participant or has a pending invite.');

            return;
        }

        GameParticipant::create([
            'game_id' => $this->game->id,
            'user_id' => $targetUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        Log::info('Game participant invited', [
            'game_id' => $this->game->id,
            'invited_user_id' => $targetUser->id,
            'invited_by' => Auth::id(),
        ]);

        $this->reset('inviteEmail');
        session()->flash('success', "Invite sent to {$targetUser->email}.");
    }

    // ── Approve Application ────────────────────────────

    public function approveApplication(string $participantId): void
    {
        $participant = $this->findParticipant($participantId);

        if ($participant->role !== 'applicant') {
            return;
        }

        $participant->update([
            'role' => 'player',
            'status' => 'approved',
        ]);

        // Also update the corresponding application record
        $this->game->applications()
            ->where('user_id', $participant->user_id)
            ->update(['status' => 'approved']);

        Log::info('Game application approved', [
            'game_id' => $this->game->id,
            'user_id' => $participant->user_id,
            'approved_by' => Auth::id(),
        ]);

        session()->flash('success', 'Application approved.');
    }

    public function rejectApplication(string $participantId): void
    {
        $participant = $this->findParticipant($participantId);

        if ($participant->role !== 'applicant') {
            return;
        }

        $participant->update(['status' => 'rejected']);

        $this->game->applications()
            ->where('user_id', $participant->user_id)
            ->update(['status' => 'rejected']);

        Log::info('Game application rejected', [
            'game_id' => $this->game->id,
            'user_id' => $participant->user_id,
            'rejected_by' => Auth::id(),
        ]);

        session()->flash('success', 'Application rejected.');
    }

    // ── Remove Participant ─────────────────────────────

    public function removeParticipant(string $participantId): void
    {
        $participant = $this->findParticipant($participantId);

        if ($participant->role === 'owner') {
            session()->flash('error', 'Cannot remove the game owner.');

            return;
        }

        $participant->update(['status' => 'rejected']);

        Log::info('Game participant removed', [
            'game_id' => $this->game->id,
            'user_id' => $participant->user_id,
            'removed_by' => Auth::id(),
        ]);

        session()->flash('success', 'Participant removed.');
    }

    // ── Cancel Invite ──────────────────────────────────

    public function cancelInvite(string $participantId): void
    {
        $participant = GameParticipant::where('id', $participantId)
            ->where('game_id', $this->game->id)
            ->where('role', 'invited')
            ->where('status', 'pending')
            ->firstOrFail();

        $participant->update(['status' => 'rejected']);

        Log::info('Game invite cancelled', [
            'game_id' => $this->game->id,
            'user_id' => $participant->user_id,
            'cancelled_by' => Auth::id(),
        ]);

        session()->flash('success', 'Invite cancelled.');
    }

    // ── Helpers ────────────────────────────────────────

    private function findParticipant(string $participantId): GameParticipant
    {
        return GameParticipant::where('id', $participantId)
            ->where('game_id', $this->game->id)
            ->firstOrFail();
    }

    public function render()
    {
        $this->game->load([
            'participants.user',
            'applications.user',
        ]);

        $approvedParticipants = $this->game->participants
            ->filter(fn ($p) => $p->status === 'approved')
            ->sortBy(fn ($p) => $p->role === 'owner' ? 0 : 1);

        $pendingApplicants = $this->game->participants
            ->filter(fn ($p) => $p->role === 'applicant' && $p->status === 'pending');

        $pendingInvites = $this->game->participants
            ->filter(fn ($p) => $p->role === 'invited' && $p->status === 'pending');

        return view('livewire.games.manage-participants', [
            'game' => $this->game,
            'approvedParticipants' => $approvedParticipants,
            'pendingApplicants' => $pendingApplicants,
            'pendingInvites' => $pendingInvites,
        ]);
    }
}
