<?php

namespace App\Livewire\GM;

use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GMProfile;
use App\Services\GmRoleService;
use App\Services\ReviewAggregateService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class GmWorkspace extends Component
{
    public ?GMProfile $gmProfile = null;

    public function mount(): void
    {
        $user = Auth::user();
        $gmRoleService = app(GmRoleService::class);

        if (! $gmRoleService->isGmActive($user)) {
            $this->redirect(route('dashboard', app()->getLocale()));
            return;
        }

        $this->gmProfile = $user->gmProfile;
    }

    public function render()
    {
        if (! $this->gmProfile) {
            return view('livewire.gm.gm-workspace', [
                'upcomingSessions' => collect(),
                'recentReviews' => collect(),
                'totalUniquePlayers' => 0,
                'repeatPlayers' => 0,
                'totalGames' => 0,
                'activeCampaigns' => 0,
            ]);
        }

        $user = Auth::user();

        // (1) Upcoming Sessions — games owned by this GM, scheduled, next 7 days
        $upcomingSessions = Game::where('owner_id', $user->id)
            ->where('status', 'scheduled')
            ->whereBetween('date_time', [now(), now()->addDays(7)])
            ->with(['gameSystem', 'participants'])
            ->orderBy('date_time')
            ->limit(10)
            ->get();

        // (2) Review Summary — use GMProfile's stored aggregates + last 5 reviews
        $reviewAggregateService = app(ReviewAggregateService::class);
        $recentReviews = $reviewAggregateService->recentReviews($this->gmProfile, 5);

        // (3) Participant Stats — unique players across all games, repeat players
        $gameIds = Game::where('owner_id', $user->id)->pluck('id');

        $totalUniquePlayers = GameParticipant::whereIn('game_id', $gameIds)
            ->where('role', 'player')
            ->distinct('user_id')
            ->count('user_id');

        $repeatPlayers = GameParticipant::whereIn('game_id', $gameIds)
            ->where('role', 'player')
            ->select('user_id', DB::raw('COUNT(*) as game_count'))
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) >= 2')
            ->get()
            ->count();

        // (4) Quick actions data
        $totalGames = Game::where('owner_id', $user->id)->count();
        $activeCampaigns = \App\Models\Campaign::where('owner_id', $user->id)
            ->where('status', 'active')
            ->count();

        return view('livewire.gm.gm-workspace', [
            'upcomingSessions' => $upcomingSessions,
            'recentReviews' => $recentReviews,
            'totalUniquePlayers' => $totalUniquePlayers,
            'repeatPlayers' => $repeatPlayers,
            'totalGames' => $totalGames,
            'activeCampaigns' => $activeCampaigns,
        ]);
    }
}
