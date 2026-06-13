<?php

namespace App\Livewire\Teams;

use App\Enums\ParticipantStatus;
use App\Models\TeamMember;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PendingInvites extends Component
{
    public function acceptInvite(string $memberId): void
    {
        $member = $this->findPendingInvite($memberId);

        // Check user doesn't already have an active membership on another team
        $existingActive = TeamMember::where('user_id', Auth::id())
            ->where('status', 'active')
            ->where('id', '!=', $member->id)
            ->exists();

        if ($existingActive) {
            session()->flash('error', __('teams.error_you_already_have_an_active'));

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

        session()->flash('success', __('teams.content_welcome_to_the_team'));
    }

    public function declineInvite(string $memberId): void
    {
        $member = $this->findPendingInvite($memberId);

        $member->update([
            'status' => ParticipantStatus::Removed->value,
            'left_at' => now(),
        ]);

        Log::info('Team invite declined', [
            'team_id' => $member->team_id,
            'user_id' => Auth::id(),
            'member_id' => $member->id,
        ]);

        session()->flash('success', __('common.flash_invite_declined'));
    }

    private function findPendingInvite(string $memberId): TeamMember
    {
        return TeamMember::where('id', $memberId)
            ->where('user_id', Auth::id())
            ->where('status', 'pending')
            ->firstOrFail();
    }

    public function render(): View
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
