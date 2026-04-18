<?php

namespace App\Livewire\Campaigns;

use App\Models\Campaign;
use App\Traits\ManagesParticipants;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CampaignDetail extends Component
{
    use ManagesParticipants;

    public Campaign $campaign;

    public function mount(string $id): void
    {
        $campaign = Campaign::findOrFail($id);
        $this->authorize('view', $campaign);
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
        return \App\Models\CampaignParticipant::class;
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

    public function render()
    {
        $this->campaign->load([
            'owner',
            'gameSystem',
            'participants.user',
            'applications.user',
            'sessions' => fn ($q) => $q->orderBy('date_time')->limit(10),
        ]);

        $userInvitation = null;
        if (Auth::check()) {
            $userInvitation = $this->campaign->participants
                ->first(fn ($p) => $p->user_id === Auth::id()
                    && $p->role === 'invited'
                    && $p->status === 'pending');
        }

        return view('livewire.campaigns.campaign-detail', [
            'campaign' => $this->campaign,
            'isOwner' => Auth::check() && $this->campaign->owner_id === Auth::id(),
            'isParticipant' => Auth::check() && $this->campaign->participants()
                ->where('user_id', Auth::id())
                ->exists(),
            'userInvitation' => $userInvitation,
        ]);
    }
}
