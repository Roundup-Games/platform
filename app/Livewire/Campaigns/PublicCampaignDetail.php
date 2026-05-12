<?php

namespace App\Livewire\Campaigns;

use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\Review;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('components.public-layout')]
class PublicCampaignDetail extends Component
{
    public Campaign $campaign;

    #[Locked]
    public ?string $validatedShareToken = null;

    public function mount(string $id): void
    {
        $campaign = Campaign::findOrFail($id);
        $this->authorize('view', $campaign);
        $this->campaign = $campaign;

        // Capture valid share token on initial page load
        if ($campaign->hasValidShareToken()) {
            $this->validatedShareToken = request()->query('share');
        }

        // Set share_intent cookie for guests visiting via share link
        if (Auth::guest() && $this->validatedShareToken !== null) {
            Cookie::queue('share_intent', json_encode([
                'entity_type' => 'campaign',
                'entity_id' => $campaign->id,
                'share_token' => $this->validatedShareToken,
            ]), 24 * 60);
        }
    }

    #[Computed]
    public function isOwner(): bool
    {
        return ($id = Auth::id()) && $this->campaign->owner_id === $id;
    }

    #[Computed]
    public function approvedParticipantsCount(): int
    {
        return $this->campaign->participants
            ->where('status', ParticipantStatus::Approved->value)
            ->count();
    }

    #[Computed]
    public function reviews()
    {
        return Review::where('reviewable_type', Campaign::class)
            ->where('reviewable_id', $this->campaign->id)
            ->published()
            ->with('reviewer')
            ->latest()
            ->limit(10)
            ->get();
    }

    #[Computed]
    public function hasShareLink(): bool
    {
        return $this->campaign->share_token !== null;
    }

    #[Computed]
    public function shareLinkUrl(): ?string
    {
        if ($this->campaign->share_token === null) {
            return null;
        }

        return route('campaigns.detail', $this->campaign->id) . '?share=' . $this->campaign->share_token;
    }

    public function render()
    {
        $this->campaign->load([
            'owner',
            'gameSystem.categories',
            'gameSystem.mechanics',
            'gameSystem.publishers',
            'gameSystem.baseGame',
            'gameSystem.expansions',
            'participants.user',
            'sessions' => fn ($q) => $q->orderBy('date_time')->limit(10),
            'linkedLocation',
        ]);

        seo()->for($this->campaign);

        return view('livewire.campaigns.public-campaign-detail', [
            'campaign' => $this->campaign,
            'isOwner' => $this->isOwner(),
            'isGuest' => Auth::guest(),
            'reviews' => $this->reviews(),
            'approvedParticipantsCount' => $this->approvedParticipantsCount(),
            'hasShareLink' => $this->hasShareLink(),
            'shareLinkUrl' => $this->shareLinkUrl(),
        ]);
    }
}
