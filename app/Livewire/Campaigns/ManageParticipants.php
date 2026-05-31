<?php

namespace App\Livewire\Campaigns;

use App\Enums\ParticipantRole;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Traits\ManagesParticipants;
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

    public function getEntityIdColumn(): string
    {
        return 'campaign_id';
    }

    public function getParticipantModel(): string
    {
        return CampaignParticipant::class;
    }

    public function getEntityName(): string
    {
        return 'Campaign';
    }

    public function getEntityVar(): string
    {
        return 'campaign';
    }

    public function getBackRoute(): string
    {
        return route('campaigns.show', $this->campaign->id);
    }

    // ── Render ─────────────────────────────────────────

    public function render()
    {
        $this->campaign->load([
            'participants.user',
            'applications.user',
        ]);

        // Campaign owner IS shown in the approved list (sorted first) because
        // campaigns are ongoing — the GM/organiser is a persistent participant.
        // This differs from Games where the owner is hidden from ManageParticipants.
        $approvedParticipants = $this->campaign->participants
            ->filter(fn ($p) => $p->status === \App\Enums\ParticipantStatus::Approved)
            ->sortBy(fn ($p) => $p->role === ParticipantRole::Owner ? 0 : 1);

        $pendingApplicants = $this->campaign->participants
            ->filter(fn ($p) => $p->role === ParticipantRole::Applicant && $p->status === \App\Enums\ParticipantStatus::Pending);

        $pendingInvites = $this->campaign->participants
            ->filter(fn ($p) => $p->role === ParticipantRole::Invited && $p->status === \App\Enums\ParticipantStatus::Pending);

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
