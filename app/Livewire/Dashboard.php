<?php

namespace App\Livewire;

use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GMProfile;
use App\Models\UserRelationship;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    public function render()
    {
        return view('dashboard', [
            'upcomingSessionsCount' => $this->upcomingSessionsCount(),
            'activeGamesCount' => $this->activeGamesCount(),
            'activeCampaignsCount' => $this->activeCampaignsCount(),
            'pendingInvitationsCount' => $this->pendingInvitationsCount(),
            'followersCount' => $this->followersCount(),
            'followingCount' => $this->followingCount(),
            'unreadNotificationsCount' => $this->unreadNotificationsCount(),
            'gmAverageRating' => $this->gmAverageRating(),
            'gmReviewCount' => $this->gmReviewCount(),
            'gmUpcomingSessionsCount' => $this->gmUpcomingSessionsCount(),
            'recentActivity' => $this->recentActivity(),
        ]);
    }

    #[Computed]
    public function upcomingSessionsCount(): int
    {
        $user = Auth::user();

        // Games the user owns that are upcoming
        $ownedCount = Game::where('owner_id', $user->id)
            ->where('date_time', '>', now())
            ->where('status', 'scheduled')
            ->count();

        // Games the user is a participant in (approved) that are upcoming
        $participantCount = GameParticipant::where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereHas('game', fn ($q) => $q
                ->where('date_time', '>', now())
                ->where('status', 'scheduled')
            )
            ->count();

        return $ownedCount + $participantCount;
    }

    #[Computed]
    public function activeGamesCount(): int
    {
        return Game::where('owner_id', Auth::id())
            ->where('status', 'scheduled')
            ->count();
    }

    #[Computed]
    public function activeCampaignsCount(): int
    {
        return Campaign::where('owner_id', Auth::id())
            ->where('status', 'active')
            ->count();
    }

    #[Computed]
    public function pendingInvitationsCount(): int
    {
        $user = Auth::user();

        $gameInvitations = GameParticipant::where('user_id', $user->id)
            ->where('status', 'pending')
            ->count();

        $campaignInvitations = CampaignParticipant::where('user_id', $user->id)
            ->where('status', 'pending')
            ->count();

        return $gameInvitations + $campaignInvitations;
    }

    #[Computed]
    public function followersCount(): int
    {
        return UserRelationship::where('related_user_id', Auth::id())
            ->where('type', 'follow')
            ->count();
    }

    #[Computed]
    public function followingCount(): int
    {
        return UserRelationship::where('user_id', Auth::id())
            ->where('type', 'follow')
            ->count();
    }

    #[Computed]
    public function unreadNotificationsCount(): int
    {
        return Auth::user()->unreadNotifications()->count();
    }

    #[Computed]
    public function gmAverageRating(): ?float
    {
        if (! Auth::user()->isGM()) {
            return null;
        }

        return Auth::user()->gmProfile?->average_rating;
    }

    #[Computed]
    public function gmReviewCount(): int
    {
        if (! Auth::user()->isGM()) {
            return 0;
        }

        return Auth::user()->gmProfile?->review_count ?? 0;
    }

    #[Computed]
    public function gmUpcomingSessionsCount(): int
    {
        if (! Auth::user()->isGM()) {
            return 0;
        }

        return Game::where('owner_id', Auth::id())
            ->where('date_time', '>', now())
            ->where('status', 'scheduled')
            ->count();
    }

    #[Computed]
    public function recentActivity()
    {
        return app(ActivityLogService::class)
            ->getRecentForUser(Auth::user(), 20);
    }
}
