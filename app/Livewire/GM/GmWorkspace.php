<?php

namespace App\Livewire\GM;

use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GMProfile;
use App\Models\SessionZeroSurvey;
use App\Models\ShortLink;
use App\Models\ShortLinkHit;
use App\Services\GmRoleService;
use App\Services\ReviewAggregateService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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
                'sessionZeroSurveys' => collect(),
                'linkAnalytics' => $this->emptyLinkAnalytics(),
                'topLinks' => collect(),
                'topReferrers' => collect(),
                'allLinks' => collect(),
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

        // (5) Session Zero Surveys
        $sessionZeroSurveys = SessionZeroSurvey::where('gm_profile_id', $this->gmProfile->id)
            ->with('game')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        // (6) Share Link Analytics
        [$linkAnalytics, $topLinks, $topReferrers] = $this->getLinkAnalytics($user);

        // (7) All links for management table (moved from Blade template)
        $allLinks = ShortLink::where('user_id', $user->id)
            ->with('linkable')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return view('livewire.gm.gm-workspace', [
            'upcomingSessions' => $upcomingSessions,
            'recentReviews' => $recentReviews,
            'totalUniquePlayers' => $totalUniquePlayers,
            'repeatPlayers' => $repeatPlayers,
            'totalGames' => $totalGames,
            'activeCampaigns' => $activeCampaigns,
            'sessionZeroSurveys' => $sessionZeroSurveys,
            'linkAnalytics' => $linkAnalytics,
            'topLinks' => $topLinks,
            'topReferrers' => $topReferrers,
            'allLinks' => $allLinks,
        ]);
    }

    /**
     * Get cached link analytics for the GM.
     *
     * Returns [summaryStats, topLinks, topReferrers].
     * Cached for 1 hour since analytics are expensive aggregations
     * and data changes slowly (async queue jobs update hit counts).
     */
    private function getLinkAnalytics($user): array
    {
        $cacheKey = "gm_workspace:link_analytics:{$user->id}";

        return Cache::remember($cacheKey, now()->addHour(), function () use ($user) {
            $gmLinkIds = ShortLink::where('user_id', $user->id)->pluck('id');

            // Summary stats grouped by entity type
            $linksByType = ShortLink::where('user_id', $user->id)
                ->select('linkable_type', DB::raw('COUNT(*) as count'))
                ->groupBy('linkable_type')
                ->get()
                ->mapWithKeys(function ($item) {
                    $type = class_basename($item->linkable_type) ?? 'Unknown';
                    return [$type => $item->count];
                });

            $totalHits = $gmLinkIds->isNotEmpty()
                ? ShortLinkHit::whereIn('short_link_id', $gmLinkIds)
                    ->where('hit_at', '>=', now()->subDays(30))
                    ->count()
                : 0;

            $totalLinks = $gmLinkIds->count();

            $linkAnalytics = [
                'totalLinks' => $totalLinks,
                'totalHits30d' => $totalHits,
                'linksByType' => $linksByType,
            ];

            // Top 5 links by hit count
            $topLinks = ShortLink::where('user_id', $user->id)
                ->with('linkable')
                ->orderByDesc('hit_count')
                ->limit(5)
                ->get();

            // Top referrer domains — DB-level aggregation via Eloquent.
            $topReferrers = $gmLinkIds->isNotEmpty()
                ? ShortLinkHit::whereIn('short_link_id', $gmLinkIds)
                    ->whereNotNull('referer')
                    ->where('referer', '!=', '')
                    ->where('hit_at', '>=', now()->subDays(30))
                    ->selectRaw("SUBSTRING(referer FROM '^(?:https?://)?([^/]+)') as domain")
                    ->selectRaw('COUNT(*) as cnt')
                    ->groupByRaw("SUBSTRING(referer FROM '^(?:https?://)?([^/]+)')")
                    ->orderByDesc('cnt')
                    ->limit(5)
                    ->get()
                    ->map(fn ($row) => ['domain' => $row->domain, 'count' => (int) $row->cnt])
                    ->values()
                : collect();

            return [$linkAnalytics, $topLinks, $topReferrers];
        });
    }

    private function emptyLinkAnalytics(): array
    {
        return [
            'totalLinks' => 0,
            'totalHits30d' => 0,
            'linksByType' => collect(),
        ];
    }
}
