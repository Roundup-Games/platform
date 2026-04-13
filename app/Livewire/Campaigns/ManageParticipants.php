<?php

namespace App\Livewire\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.app')]
class ManageParticipants extends Component
{
    public Campaign $campaign;

    #[Validate('required|email|max:255')]
    public string $inviteEmail = '';

    public function mount(string $id): void
    {
        $campaign = Campaign::findOrFail($id);
        $this->authorize('update', $campaign);
        $this->campaign = $campaign;
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

        if ($this->campaign->participants()->where('user_id', $targetUser->id)->exists()) {
            $this->addError('inviteEmail', 'This user is already a participant or has a pending invite.');

            return;
        }

        CampaignParticipant::create([
            'campaign_id' => $this->campaign->id,
            'user_id' => $targetUser->id,
            'role' => 'invited',
            'status' => 'pending',
        ]);

        Log::info('Campaign participant invited', [
            'campaign_id' => $this->campaign->id,
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

        $this->campaign->applications()
            ->where('user_id', $participant->user_id)
            ->update(['status' => 'approved']);

        Log::info('Campaign application approved', [
            'campaign_id' => $this->campaign->id,
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

        $this->campaign->applications()
            ->where('user_id', $participant->user_id)
            ->update(['status' => 'rejected']);

        Log::info('Campaign application rejected', [
            'campaign_id' => $this->campaign->id,
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
            session()->flash('error', 'Cannot remove the campaign owner.');

            return;
        }

        $participant->update(['status' => 'rejected']);

        Log::info('Campaign participant removed', [
            'campaign_id' => $this->campaign->id,
            'user_id' => $participant->user_id,
            'removed_by' => Auth::id(),
        ]);

        session()->flash('success', 'Participant removed.');
    }

    // ── Cancel Invite ──────────────────────────────────

    public function cancelInvite(string $participantId): void
    {
        $participant = CampaignParticipant::where('id', $participantId)
            ->where('campaign_id', $this->campaign->id)
            ->where('role', 'invited')
            ->where('status', 'pending')
            ->firstOrFail();

        $participant->update(['status' => 'rejected']);

        Log::info('Campaign invite cancelled', [
            'campaign_id' => $this->campaign->id,
            'user_id' => $participant->user_id,
            'cancelled_by' => Auth::id(),
        ]);

        session()->flash('success', 'Invite cancelled.');
    }

    // ── Helpers ────────────────────────────────────────

    private function findParticipant(string $participantId): CampaignParticipant
    {
        return CampaignParticipant::where('id', $participantId)
            ->where('campaign_id', $this->campaign->id)
            ->firstOrFail();
    }

    public function render()
    {
        $this->campaign->load([
            'participants.user',
            'applications.user',
        ]);

        $approvedParticipants = $this->campaign->participants
            ->filter(fn ($p) => $p->status === 'approved')
            ->sortBy(fn ($p) => $p->role === 'owner' ? 0 : 1);

        $pendingApplicants = $this->campaign->participants
            ->filter(fn ($p) => $p->role === 'applicant' && $p->status === 'pending');

        $pendingInvites = $this->campaign->participants
            ->filter(fn ($p) => $p->role === 'invited' && $p->status === 'pending');

        return view('livewire.campaigns.manage-participants', [
            'campaign' => $this->campaign,
            'approvedParticipants' => $approvedParticipants,
            'pendingApplicants' => $pendingApplicants,
            'pendingInvites' => $pendingInvites,
        ]);
    }
}
