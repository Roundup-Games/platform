<?php

namespace App\Livewire;

use App\Enums\AttendanceStatus;
use App\Enums\ParticipantStatus;
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
        $gamesThisWeek = $this->gamesThisWeek();

        return view('livewire.dashboard', [
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
            'gamesThisWeek' => $gamesThisWeek,
            'gamesThisWeekCount' => $gamesThisWeek->count(),
            'gamesThisWeekSummary' => $this->gamesThisWeekSummary($gamesThisWeek),
            'newRecaps' => $this->newRecaps(),
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

    /**
     * Get games occurring this week where the user is an owner or approved participant.
     * "This week" = start of Monday through end of Sunday in the app's timezone.
     */
    public function gamesThisWeek()
    {
        $user = Auth::user();
        $startOfWeek = now()->startOfWeek();
        $endOfWeek = now()->endOfWeek();

        // Games user owns this week
        $ownedGameIds = Game::where('owner_id', $user->id)
            ->whereBetween('date_time', [$startOfWeek, $endOfWeek])
            ->pluck('id');

        // Games user is an approved participant in this week
        $participantGameIds = GameParticipant::where('user_id', $user->id)
            ->where('status', ParticipantStatus::Approved)
            ->whereHas('game', fn ($q) => $q
                ->whereBetween('date_time', [$startOfWeek, $endOfWeek])
            )
            ->pluck('game_id');

        $gameIds = $ownedGameIds->merge($participantGameIds)->unique();

        return Game::whereIn('id', $gameIds)
            ->with(['participants' => fn ($q) => $q->where('user_id', $user->id)])
            ->orderBy('date_time')
            ->get();
    }

    /**
     * Build attendance summary for the week's games.
     *
     * @return array{attended: int, pending: int, total: int}
     */
    public function gamesThisWeekSummary($gamesThisWeek): array
    {
        $user = Auth::user();
        $attended = 0;
        $pending = 0;

        foreach ($gamesThisWeek as $game) {
            // Owner games are implicitly attended
            if ($game->owner_id === $user->id) {
                $attended++;
                continue;
            }

            $participant = $game->participants->first();
            if ($participant && $participant->attendance_status !== null) {
                if ($participant->attendance_status === AttendanceStatus::Attended) {
                    $attended++;
                }
                // no-show, late_cancel, excused are not "attended"
            } else {
                // Game hasn't happened yet or attendance not reported
                $pending++;
            }
        }

        return [
            'attended' => $attended,
            'pending' => $pending,
            'total' => $gamesThisWeek->count(),
        ];
    }

    /**
     * Get games the user participated in (not owned) that have new recaps.
     */
    public function newRecaps()
    {
        $user = Auth::user();

        return Game::whereHas('participants', fn ($q) => $q
            ->where('user_id', $user->id)
            ->where('status', ParticipantStatus::Approved)
        )
            ->where('owner_id', '!=', $user->id)
            ->whereNotNull('recap')
            ->where('recap', '!=', '')
            ->where('status', 'completed')
            ->where('updated_at', '>', now()->subDays(7))
            ->with('owner')
            ->orderByDesc('updated_at')
            ->limit(3)
            ->get();
    }
}
