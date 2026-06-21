<?php

namespace App\Livewire\Campaigns;

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Traits\ManagesParticipants;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ManageParticipants extends Component
{
    use ManagesParticipants;

    public Campaign $campaign;

    public function mount(string $id): void
    {
        $campaign = Campaign::findOrFail($id);
        $this->authorize('update', $campaign);
        $this->campaign = $campaign;
    }

    // ── Trait contracts ────────────────────────────────

    public function getEntity(): Campaign
    {
        return $this->campaign;
    }

    // ── Render ─────────────────────────────────────────

    public function render(): View
    {
        $this->campaign->load([
            'participants.user',
            'applications.user',
        ]);

        // Campaign owner IS shown in the approved list (sorted first) because
        // campaigns are ongoing — the GM/organiser is a persistent participant.
        // This differs from Games where the owner is hidden from ManageParticipants.
        $approvedParticipants = $this->campaign->participants
            ->filter(fn ($p) => $p->status === ParticipantStatus::Approved)
            ->sortBy(fn ($p) => $p->role === ParticipantRole::Owner ? 0 : 1);

        $pendingApplicants = $this->campaign->participants
            ->filter(fn ($p) => $p->role === ParticipantRole::Applicant && $p->status === ParticipantStatus::Pending);

        $pendingInvites = $this->campaign->participants
            ->filter(fn ($p) => $p->role === ParticipantRole::Invited && $p->status === ParticipantStatus::Pending);

        return view('livewire.campaigns.manage-participants', [
            'campaign' => $this->campaign,
            'approvedParticipants' => $approvedParticipants,
            'pendingApplicants' => $pendingApplicants,
            'pendingInvites' => $pendingInvites,
            'waitlistedParticipants' => $this->getWaitlistedParticipants(),
            'benchedParticipants' => $this->getBenchedParticipants(),
        ]);
    }
}
