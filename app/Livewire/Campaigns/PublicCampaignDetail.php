<?php

namespace App\Livewire\Campaigns;

use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\Review;
use App\Services\ShortLinkService;
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

    /** @var int|null Validated short link ID from ph_link_id cookie */
    #[Locked]
    public ?int $validatedShortLinkId = null;

    public function mount(string $id): void
    {
        $campaign = Campaign::findOrFail($id);
        $this->authorize('view', $campaign);
        $this->campaign = $campaign;

        // Capture valid share token on initial page load
        if ($campaign->hasValidShareToken()) {
            $this->validatedShareToken = request()->query('share');
        }

        // Detect short link arrival via ph_link_id cookie.
        // Reject malformed values before casting to prevent wrong-link attribution.
        $linkId = request()->cookie('ph_link_id');
        if (is_string($linkId) && ctype_digit($linkId)) {
            $link = app(ShortLinkService::class)->resolveLinkById((int) $linkId);
            if ($link !== null
                && $link->linkable_type === Campaign::class
                && (string) $link->linkable_id === (string) $campaign->getKey()) {
                $this->validatedShortLinkId = $link->id;
            }
        }

        // Set share_intent cookie for guests visiting via share link
        if (Auth::guest() && $this->validatedShareToken !== null) {
            Cookie::queue('share_intent', json_encode([
                'entity_type' => 'campaign',
                'entity_id' => $campaign->id,
                'share_token' => $this->validatedShareToken,
            ]), 24 * 60);
        }

        // Set short_link_intent cookie for guests arriving via short link.
        // Only short_link_id is needed — entity identity is derived server-side.
        if (Auth::guest() && $this->validatedShortLinkId !== null) {
            Cookie::queue('short_link_intent', json_encode([
                'short_link_id' => $this->validatedShortLinkId,
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
        if (Auth::guest()) {
            return collect();
        }

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
        return $this->campaign->share_token !== null || $this->validatedShortLinkId !== null;
    }

    #[Computed]
    public function shareLinkUrl(): ?string
    {
        // If we have a validated short link, use its URL
        if ($this->validatedShortLinkId !== null) {
            $link = \App\Models\ShortLink::find($this->validatedShortLinkId);
            if ($link) {
                return url('/link/' . $link->code);
            }
        }

        if ($this->campaign->share_token === null) {
            return null;
        }

        return route('campaigns.detail', $this->campaign->id) . '?share=' . $this->campaign->share_token;
    }

    public function render()
    {
        $relations = [
            'owner',
            'gameSystem.categories',
            'gameSystem.mechanics',
            'gameSystem.publishers',
            'gameSystem.baseGame',
            'gameSystem.expansions',
            'sessions' => fn ($q) => $q->orderBy('date_time')->limit(10),
            'linkedLocation',
        ];
        if (Auth::check()) {
            $relations[] = 'participants.user';
        } else {
            $relations[] = 'participants';
        }
        $this->campaign->load($relations);

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
