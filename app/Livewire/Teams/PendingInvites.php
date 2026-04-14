<?php

namespace App\Livewire\Teams;

use App\Models\TeamMember;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PendingInvites extends Component
{
    public function acceptInvite(int $memberId): void
    {
        $member = $this->findPendingInvite($memberId);

        // Check user doesn't already have an active membership on another team
        $existingActive = TeamMember::where('user_id', Auth::id())
            ->where('status', 'active')
            ->where('id', '!=', $member->id)
            ->exists();

        if ($existingActive) {
            session()->flash('error', __('You already have an active team membership. Leave your current team first.'));

            return;
        }

        $member->update([
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Log::info('Team invite accepted', [
            'team_id' => $member->team_id,
            'user_id' => Auth::id(),
            'member_id' => $member->id,
        ]);

        session()->flash('success', __('Welcome to the team!'));
    }

    public function declineInvite(int $memberId): void
    {
        $member = $this->findPendingInvite($memberId);

        $member->update([
            'status' => 'removed',
            'left_at' => now(),
        ]);

        Log::info('Team invite declined', [
            'team_id' => $member->team_id,
            'user_id' => Auth::id(),
            'member_id' => $member->id,
        ]);

        session()->flash('success', __('Invite declined.'));
    }

    private function findPendingInvite(int $memberId): TeamMember
    {
        return TeamMember::where('id', $memberId)
            ->where('user_id', Auth::id())
            ->where('status', 'pending')
            ->firstOrFail();
    }

    public function render()
    {
        $pendingInvites = TeamMember::where('user_id', Auth::id())
            ->where('status', 'pending')
            ->with(['team', 'invitedBy'])
            ->get();

        return view('livewire.teams.pending-invites', [
            'pendingInvites' => $pendingInvites,
        ]);
    }
}
