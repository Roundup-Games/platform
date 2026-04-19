<?php

namespace App\Livewire\Campaigns;

use App\Enums\ContentLanguage;
use App\Enums\ExperienceLevel;
use App\Enums\VibeFlag;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\GameSystem;
use App\Traits\EscapesLikeWildcards;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class CampaignsPage extends Component
{
    use EscapesLikeWildcards;
    use WithPagination;

    // Community filters
    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public ?int $game_system_id = null;

    #[Url]
    public string $experience_level = '';

    #[Url]
    public array $vibe_flags = [];

    #[Url]
    public string $language = '';

    #[Url]
    public string $recurrence = '';

    #[Url]
    public string $price = '';

    #[Url]
    public ?string $complexity_min = null;

    #[Url]
    public ?string $complexity_max = null;

    public function mount(): void
    {
        if (Auth::guest()) {
            $this->redirect(route('discover', app()->getLocale()));
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingGameSystemId(): void
    {
        $this->resetPage();
    }

    public function updatingExperienceLevel(): void
    {
        $this->resetPage();
    }

    public function updatingVibeFlags(): void
    {
        $this->resetPage();
    }

    public function updatingLanguage(): void
    {
        $this->resetPage();
    }

    public function updatingRecurrence(): void
    {
        $this->resetPage();
    }

    public function updatingPrice(): void
    {
        $this->resetPage();
    }

    public function updatingComplexityMin(): void
    {
        $this->resetPage();
    }

    public function updatingComplexityMax(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset([
            'search', 'game_system_id', 'experience_level', 'vibe_flags',
            'language', 'recurrence', 'price', 'complexity_min', 'complexity_max',
        ]);
        $this->resetPage();
    }

    public function toggleVibeFlag(string $flag): void
    {
        $index = array_search($flag, $this->vibe_flags, true);
        if ($index !== false) {
            unset($this->vibe_flags[$index]);
            $this->vibe_flags = array_values($this->vibe_flags);
        } else {
            $this->vibe_flags[] = $flag;
        }
        $this->resetPage();
    }

    public function hasActiveFilters(): bool
    {
        return $this->search
            || $this->game_system_id
            || $this->experience_level
            || !empty($this->vibe_flags)
            || $this->language
            || $this->recurrence
            || $this->price
            || $this->complexity_min
            || $this->complexity_max;
    }

    public function render()
    {
        $user = Auth::user();

        // My Campaigns — owned by the user
        $ownedCampaigns = Campaign::where('owner_id', $user->id)
            ->with(['gameSystem', 'participants'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Campaigns I'm In — approved participations, not owned
        $participatingCampaigns = Campaign::whereHas('participants', function ($query) use ($user) {
            $query->where('user_id', $user->id)
                ->where('role', 'player')
                ->where('status', 'approved');
        })->where('owner_id', '!=', $user->id)
            ->with(['gameSystem', 'participants', 'owner'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Open Invitations — invited, pending
        $pendingInvitations = CampaignParticipant::where('user_id', $user->id)
            ->where('role', 'invited')
            ->where('status', 'pending')
            ->with(['campaign.gameSystem', 'campaign.owner'])
            ->get();

        // Community campaigns — visibility-scoped, filtered, paginated
        $communityQuery = Campaign::query()
            ->where(function ($q) use ($user) {
                $q->where('visibility', 'public');
                $q->orWhere(function ($q) use ($user) {
                    $q->where('visibility', 'protected')
                        ->where(function ($q) use ($user) {
                            $allowedOwnerIds = $user->getAllowedOwnerIdsForProtectedContent();
                            $q->whereIn('owner_id', $allowedOwnerIds)
                                ->orWhereHas('participants', fn ($pq) => $pq->where('user_id', $user->id));
                        });
                });
            })
            ->where('status', 'active')
            ->with(['owner', 'gameSystem'])
            ->withCount('participants');

        // Search
        $communityQuery->when($this->search, fn ($q) => $q->where(function ($q) {
            $escaped = $this->escapeLikeWildcards($this->search);
            $q->where('name', 'like', "%{$escaped}%")
              ->orWhere('description', 'like', "%{$escaped}%");
        }));

        // Game system filter
        $communityQuery->when($this->game_system_id, fn ($q) => $q->where('game_system_id', $this->game_system_id));

        // Experience level filter
        $communityQuery->when($this->experience_level, fn ($q) => $q->where('experience_level', $this->experience_level));

        // Vibe flags filter (JSON containment)
        $communityQuery->when(!empty($this->vibe_flags), function ($q) {
            foreach ($this->vibe_flags as $flag) {
                $q->whereJsonContains('vibe_flags', $flag);
            }
        });

        // Language filter
        $communityQuery->when($this->language, fn ($q) => $q->where('language', $this->language));

        // Recurrence filter
        $communityQuery->when($this->recurrence, fn ($q) => $q->where('recurrence', $this->recurrence));

        // Price filter
        $communityQuery->when($this->price === 'free', fn ($q) => $q->where(fn ($q) => $q->where('price_per_session', 0)->orWhereNull('price_per_session')));
        $communityQuery->when($this->price === 'paid', fn ($q) => $q->where('price_per_session', '>', 0));

        // Complexity range
        $communityQuery->when($this->complexity_min, fn ($q) => $q->where('complexity', '>=', (float) $this->complexity_min));
        $communityQuery->when($this->complexity_max, fn ($q) => $q->where('complexity', '<=', (float) $this->complexity_max));

        $communityCampaigns = $communityQuery->orderBy('created_at', 'desc')->paginate(12);

        return view('livewire.campaigns.campaigns-page', [
            'ownedCampaigns' => $ownedCampaigns,
            'participatingCampaigns' => $participatingCampaigns,
            'pendingInvitations' => $pendingInvitations,
            'communityCampaigns' => $communityCampaigns,
            'gameSystems' => GameSystem::orderBy('name')->get(['id', 'name']),
            'experienceLevels' => ExperienceLevel::cases(),
            'vibeFlagGroups' => VibeFlag::grouped(),
            'languages' => ContentLanguage::cases(),
        ]);
    }

    public function cancelCampaign(string $id): void
    {
        $campaign = Campaign::findOrFail($id);
        $this->authorize('update', $campaign);

        if ($campaign->status !== 'active') {
            session()->flash('error', __('campaigns.error_campaign_not_active'));
            return;
        }

        $previousStatus = $campaign->status;
        $campaign->status = 'cancelled';
        $campaign->save();

        Log::info('Campaign canceled', [
            'entity_id' => $campaign->id,
            'owner_id' => $campaign->owner_id,
            'previous_status' => $previousStatus,
            'new_status' => 'cancelled',
        ]);

        session()->flash('success', __('campaigns.flash_campaign_canceled'));
    }

    public function completeCampaign(string $id): void
    {
        $campaign = Campaign::findOrFail($id);
        $this->authorize('update', $campaign);

        if ($campaign->status !== 'active') {
            session()->flash('error', __('campaigns.error_campaign_not_active'));
            return;
        }

        $previousStatus = $campaign->status;
        $campaign->status = 'completed';
        $campaign->save();

        Log::info('Campaign completed', [
            'entity_id' => $campaign->id,
            'owner_id' => $campaign->owner_id,
            'previous_status' => $previousStatus,
            'new_status' => 'completed',
        ]);

        session()->flash('success', __('campaigns.flash_campaign_completed'));
    }

    public function acceptInvitation(string $participantId): void
    {
        $participant = CampaignParticipant::findOrFail($participantId);

        if ($participant->user_id !== Auth::id()) {
            session()->flash('error', __('campaigns.error_not_your_invitation'));
            return;
        }

        if ($participant->role !== 'invited' || $participant->status !== 'pending') {
            session()->flash('error', __('campaigns.error_invitation_invalid'));
            return;
        }

        $campaign = $participant->campaign;

        if ($campaign->max_players) {
            $currentPlayers = $campaign->participants()
                ->where('role', 'player')
                ->where('status', 'approved')
                ->count();
            if ($currentPlayers >= $campaign->max_players) {
                session()->flash('error', __('campaigns.error_campaign_full'));
                return;
            }
        }

        $previousRole = $participant->role;
        $previousStatus = $participant->status;
        $participant->role = 'player';
        $participant->status = 'approved';
        $participant->save();

        Log::info('Campaign invitation accepted', [
            'entity_id' => $campaign->id,
            'participant_id' => $participant->id,
            'user_id' => $participant->user_id,
            'previous_role' => $previousRole,
            'new_role' => 'player',
            'previous_status' => $previousStatus,
            'new_status' => 'approved',
        ]);

        session()->flash('success', __('campaigns.flash_invitation_accepted'));
    }

    public function declineInvitation(string $participantId): void
    {
        $participant = CampaignParticipant::findOrFail($participantId);

        if ($participant->user_id !== Auth::id()) {
            session()->flash('error', __('campaigns.error_not_your_invitation'));
            return;
        }

        if ($participant->role !== 'invited' || $participant->status !== 'pending') {
            session()->flash('error', __('campaigns.error_invitation_invalid'));
            return;
        }

        $previousStatus = $participant->status;
        $participant->status = 'rejected';
        $participant->save();

        Log::info('Campaign invitation declined', [
            'entity_id' => $participant->campaign_id,
            'participant_id' => $participant->id,
            'user_id' => $participant->user_id,
            'previous_status' => $previousStatus,
            'new_status' => 'rejected',
        ]);

        session()->flash('success', __('campaigns.flash_invitation_declined'));
    }
}
