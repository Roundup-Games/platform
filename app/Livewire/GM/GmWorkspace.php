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
use Illuminate\Support\Facades\Log;
use App\Services\ShortLinkService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.app')]
class GmWorkspace extends Component
{
    public ?GMProfile $gmProfile = null;

    public ?string $confirmingAction = null;

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

        // (3) Participant Stats + Quick actions — cached for 1 hour
        [$totalUniquePlayers, $repeatPlayers, $totalGames, $activeCampaigns] = $this->getCachedParticipantStats($user);

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
     * Revoke a short link from the workspace analytics table.
     *
     * Validates ownership before revoking and clears the analytics cache
     * so the table updates immediately.
     */
    #[On('revoke-link')]
    public function revokeLink(int $linkId): void
    {
        $user = Auth::user();

        $link = ShortLink::where('id', $linkId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        // Verify the linkable entity is owned by this GM (defense-in-depth).
        // Aligns with ManagesShortLinks::revokeShortLink() which checks both
        // user_id AND entity ownership. Uses isset() because owner_id is an
        // Eloquent dynamic property (column accessor), not a declared method.
        $entity = $link->linkable;
        if ($entity && isset($entity->owner_id) && $entity->owner_id !== $user->id) {
            Log::warning('GM workspace: revoke denied — entity not owned by user', [
                'link_id' => $linkId, 'user_id' => $user->id,
                'linkable_type' => $link->linkable_type, 'linkable_id' => $link->linkable_id,
            ]);
            session()->flash('error', __('common.error_not_authorized'));
            return;
        }

        app(ShortLinkService::class)->revokeLink($link);

        Cache::forget("gm_workspace:link_analytics:{$user->id}");
        Cache::forget("gm_workspace:participant_stats:{$user->id}");

        Log::info('GM workspace: short link revoked', [
            'link_id' => $linkId,
            'user_id' => $user->id,
        ]);

        session()->flash('success', __('common.flash_share_link_revoked'));
    }


    /**
     * Get cached participant and entity stats for the GM.
     *
     * Returns [totalUniquePlayers, repeatPlayers, totalGames, activeCampaigns].
     * Cached for 1 hour — these are aggregate counts that change slowly.
     */
    private function getCachedParticipantStats($user): array
    {
        $cacheKey = "gm_workspace:participant_stats:{$user->id}";

        return Cache::remember($cacheKey, now()->addHour(), function () use ($user) {
            $gameIds = Game::where('owner_id', $user->id)->pluck('id');

            $totalUniquePlayers = GameParticipant::whereIn('game_id', $gameIds)
                ->where('role', 'player')
                ->distinct('user_id')
                ->count('user_id');

            // Count repeat players (appeared in 2+ games) in the database.
            // A subquery counts the qualifying users so we get a single integer
            // back instead of loading all grouped rows into PHP.
            $repeatSub = GameParticipant::whereIn('game_id', $gameIds)
                ->where('role', 'player')
                ->groupBy('user_id')
                ->havingRaw('COUNT(*) >= 2')
                ->selectRaw('1');
            $repeatPlayers = (int) DB::table(DB::raw("({$repeatSub->toSql()}) as repeat_sub"))
                ->mergeBindings($repeatSub->getQuery())
                ->count();

            $totalGames = $gameIds->count();
            $activeCampaigns = \App\Models\Campaign::where('owner_id', $user->id)
                ->where('status', 'active')
                ->count();

            return [$totalUniquePlayers, $repeatPlayers, $totalGames, $activeCampaigns];
        });
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
            // Subquery for GM's short link IDs — avoids loading all IDs into PHP
            // and generates efficient WHERE short_link_id IN (SELECT ...) instead.
            $gmLinkSub = fn ($q) => $q->select('id')
                ->from('short_links')
                ->where('user_id', $user->id);

            // Summary stats grouped by entity type
            $linksByType = ShortLink::where('user_id', $user->id)
                ->select('linkable_type', DB::raw('COUNT(*) as count'))
                ->groupBy('linkable_type')
                ->get()
                ->mapWithKeys(function ($item) {
                    $type = class_basename($item->linkable_type) ?? 'Unknown';
                    return [$type => $item->count];
                });

            $totalHits = ShortLinkHit::whereIn('short_link_id', $gmLinkSub)
                ->where('hit_at', '>=', now()->subDays(30))
                ->count();

            $totalLinks = ShortLink::where('user_id', $user->id)->count();

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

            // Top referrer domains — pre-extracted at write time for fast aggregation.
            $topReferrers = ShortLinkHit::whereIn('short_link_id', $gmLinkSub)
                ->whereNotNull('referer_domain')
                ->where('hit_at', '>=', now()->subDays(30))
                ->selectRaw('referer_domain as domain')
                ->selectRaw('COUNT(*) as cnt')
                ->groupByRaw('referer_domain')
                ->orderByDesc('cnt')
                ->limit(5)
                ->get()
                ->map(fn ($row) => ['domain' => $row->domain, 'count' => (int) $row->cnt])
                ->values();

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
