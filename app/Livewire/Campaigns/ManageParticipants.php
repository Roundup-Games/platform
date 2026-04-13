<?php

namespace App\Livewire\Campaigns;

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
        return route('campaigns.detail', $this->campaign->id);
    }

    // ── Render ─────────────────────────────────────────

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
