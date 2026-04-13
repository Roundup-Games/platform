<?php

namespace App\Livewire\Campaigns;

use App\Models\Campaign;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CampaignDetail extends Component
{
    public Campaign $campaign;

    public function mount(string $id): void
    {
        $campaign = Campaign::findOrFail($id);
        $this->authorize('view', $campaign);
        $this->campaign = $campaign;
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

        return view('livewire.campaigns.campaign-detail', [
            'campaign' => $this->campaign,
            'isOwner' => Auth::check() && $this->campaign->owner_id === Auth::id(),
            'isParticipant' => Auth::check() && $this->campaign->participants()
                ->where('user_id', Auth::id())
                ->exists(),
        ]);
    }
}
