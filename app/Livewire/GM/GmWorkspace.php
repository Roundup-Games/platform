<?php

namespace App\Livewire\GM;

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\ParticipantRole;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GMProfile;
use App\Models\PreviouslyOnDraft;
use App\Models\ShortLink;
use App\Models\ShortLinkHit;
use App\Models\User;
use App\Services\Charter\CharterSigningService;
use App\Services\GmRoleService;
use App\Services\ReviewAggregateService;
use App\Services\ShortLinkService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.app')]
class GmWorkspace extends Component
{
    /** Per-user cache key prefix for the scalar-only (v2) link-analytics payload. */
    public const LINK_ANALYTICS_CACHE_KEY = 'gm_workspace:link_analytics:v2';

    /** Per-user cache key prefix for the participant-stats payload. */
    public const PARTICIPANT_STATS_CACHE_KEY = 'gm_workspace:participant_stats';

    public ?GMProfile $gmProfile = null;

    public ?string $confirmingAction = null;

    public function mount(): void
    {
        $user = authenticatedUser();
        $gmRoleService = app(GmRoleService::class);

        if (! $gmRoleService->isGmActive($user)) {
            $this->redirect(route('dashboard', app()->getLocale()));

            return;
        }

        $this->gmProfile = $user->gmProfile;
    }

    public function render(): View
    {
        if ($this->gmProfile === null) {
            return view('livewire.gm.gm-workspace', [
                'upcomingSessions' => collect(),
                'recentReviews' => collect(),
                'totalUniquePlayers' => 0,
                'repeatPlayers' => 0,
                'totalGames' => 0,
                'activeCampaigns' => 0,
                'linkAnalytics' => $this->emptyLinkAnalytics(),
                'topLinks' => collect(),
                'topReferrers' => collect(),
                'allLinks' => collect(),
                'charterHealth' => collect(),
                'charterlessCampaigns' => collect(),
                'pendingDigests' => collect(),
                'recapsDue' => collect(),
            ]);
        }

        $user = authenticatedUser();

        // (1) Upcoming Sessions — games owned by this GM, scheduled, next 7 days
        $upcomingSessions = Game::whereBelongsTo($user, 'owner')
            ->where('status', 'scheduled')
            ->whereBetween('date_time', [now(), now()->addDays(7)])
            ->with(['gameSystems', 'participants'])
            ->orderBy('date_time')
            ->limit(10)
            ->get();

        // (2) Review Summary — use GMProfile's stored aggregates + last 5 reviews
        $reviewAggregateService = app(ReviewAggregateService::class);
        $recentReviews = $reviewAggregateService->recentReviews($this->gmProfile, 5);

        // (3) Participant Stats + Quick actions — cached for 1 hour
        [$totalUniquePlayers, $repeatPlayers, $totalGames, $activeCampaigns] = $this->getCachedParticipantStats($user);

        // (6) Share Link Analytics
        [$linkAnalytics, $topLinks, $topReferrers] = $this->getLinkAnalytics($user);

        // (7) All links for management table (moved from Blade template)
        $allLinks = ShortLink::whereBelongsTo($user)
            ->with('linkable')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        // (8) Charter health (M070/S04-T03, concept §4): one row per owned
        // charter — signing progress, pending signatures, and the
        // countersign trail — grouped-count shaped, no roster N+1.
        $charterHealth = collect(CharterSigningService::workspaceHealth($user));

        // (9) Charter starter (the retired session-zero create flow's
        // replacement): owned active campaigns that have no charter
        // yet — the workspace's entry point into CharterComposer.
        $charterlessCampaigns = Campaign::query()
            ->whereBelongsTo($user, 'owner')
            ->where('status', CampaignStatus::Active->value)
            ->whereDoesntHave('charter')
            ->orderBy('name')
            ->limit(5)
            ->get();

        // (10) Needs your attention (M071): unpublished Previously On
        // drafts with an upcoming session — the 24h compose hook
        // builds them, this surface tells the GM they are waiting.
        // Sorted by session start in PHP (bounded, at most one
        // unpublished draft per campaign by the D230 invariant).
        $pendingDigests = PreviouslyOnDraft::query()
            ->whereNull('published_at')
            ->whereHas('campaign', fn ($query) => $query->whereBelongsTo($user, 'owner'))
            ->whereHas('game', fn ($query) => $query
                ->where('status', GameStatus::Scheduled->value)
                ->where('date_time', '>', now()))
            ->with(['campaign', 'game'])
            ->get()
            ->sortBy(fn (PreviouslyOnDraft $draft) => $draft->game?->date_time)
            ->values();

        // (11) Recaps due: the GM's completed campaign sessions from
        // the last two weeks whose journal has no recap entry yet —
        // the recap surface lives on the game page's session-end tools.
        $recapsDue = Game::query()
            ->whereBelongsTo($user, 'owner')
            ->where('status', GameStatus::Completed->value)
            ->whereNotNull('campaign_id')
            ->where('date_time', '>=', now()->subDays(14))
            ->whereDoesntHave('recapEntry')
            ->with('campaign')
            ->orderByDesc('date_time')
            ->limit(5)
            ->get();

        return view('livewire.gm.gm-workspace', [
            'upcomingSessions' => $upcomingSessions,
            'recentReviews' => $recentReviews,
            'totalUniquePlayers' => $totalUniquePlayers,
            'repeatPlayers' => $repeatPlayers,
            'totalGames' => $totalGames,
            'activeCampaigns' => $activeCampaigns,
            'linkAnalytics' => $linkAnalytics,
            'topLinks' => $topLinks,
            'topReferrers' => $topReferrers,
            'allLinks' => $allLinks,
            'charterHealth' => $charterHealth,
            'charterlessCampaigns' => $charterlessCampaigns,
            'pendingDigests' => $pendingDigests,
            'recapsDue' => $recapsDue,
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
        $user = authenticatedUser();

        $link = ShortLink::where('id', $linkId)
            ->whereBelongsTo($user)
            ->firstOrFail();

        // Verify the linkable entity is owned by this GM (defense-in-depth).
        // Aligns with ManagesShortLinks::revokeShortLink() which checks both
        // user_id AND entity ownership. Uses isset() because owner_id is an
        // Eloquent dynamic property (column accessor), not a declared method.
        $entity = $link->linkable;
        $ownerId = $entity?->getAttributes()['owner_id'] ?? null;
        if ($ownerId !== null && $ownerId !== $user->id) {
            Log::warning('GM workspace: revoke denied — entity not owned by user', [
                'link_id' => $linkId, 'user_id' => $user->id,
                'linkable_type' => $link->linkable_type, 'linkable_id' => $link->linkable_id,
            ]);
            session()->flash('error', __('common.error_not_authorized'));

            return;
        }

        app(ShortLinkService::class)->revokeLink($link);

        Cache::forget(self::LINK_ANALYTICS_CACHE_KEY.":{$user->id}");
        Cache::forget(self::PARTICIPANT_STATS_CACHE_KEY.":{$user->id}");

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
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function getCachedParticipantStats(User $user): array
    {
        $cacheKey = self::PARTICIPANT_STATS_CACHE_KEY.":{$user->id}";

        return Cache::remember($cacheKey, now()->addHour(), function () use ($user) {
            $gameIds = Game::whereBelongsTo($user, 'owner')->pluck('id');

            $totalUniquePlayers = GameParticipant::whereIn('game_id', $gameIds)
                ->where('role', ParticipantRole::Player->value)
                ->distinct('user_id')
                ->count('user_id');

            // Count repeat players (appeared in 2+ games) in the database.
            // A subquery counts the qualifying users so we get a single integer
            // back instead of loading all grouped rows into PHP.
            $repeatSub = GameParticipant::whereIn('game_id', $gameIds)
                ->where('role', ParticipantRole::Player->value)
                ->groupBy('user_id')
                ->havingRaw('COUNT(*) >= 2')
                ->selectRaw('1');
            /** @var literal-string $subSql */
            $subSql = "({$repeatSub->toSql()}) as repeat_sub";
            $repeatPlayers = (int) DB::table(DB::raw($subSql))
                ->mergeBindings($repeatSub->getQuery())
                ->count();

            $totalGames = $gameIds->count();
            $activeCampaigns = Campaign::whereBelongsTo($user, 'owner')
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
     *
     * @return array<int, mixed>
     */
    private function getLinkAnalytics(User $user): array
    {
        // v2: payload carries scalars/arrays only. v1 cached Collection objects,
        // which come back from the cache round-trip as __PHP_Incomplete_Class
        // on the dev stack (MEM1087) — the blade then iterated the broken
        // object and 500'd the workspace (the M066/S02 /de/gm-workspace 500).
        $cacheKey = self::LINK_ANALYTICS_CACHE_KEY.":{$user->id}";

        $payload = Cache::remember($cacheKey, now()->addHour(), function () use ($user): array {
            // Subquery for GM's short link IDs — avoids loading all IDs into PHP
            // and generates efficient WHERE short_link_id IN (SELECT ...) instead.
            $gmLinkSub = ShortLink::whereBelongsTo($user)->select('id');

            // Summary stats grouped by entity type
            $linksByType = ShortLink::whereBelongsTo($user)
                ->select('linkable_type', DB::raw('COUNT(*) as count'))
                ->groupBy('linkable_type')
                ->get()
                ->mapWithKeys(function ($item) {
                    // Alias-aware basename (D170): group by class basename
                    // ('Game') regardless of the stored alias form.
                    $type = class_basename(Relation::getMorphedModel($item->linkable_type) ?? $item->linkable_type);

                    return [$type => (int) $item->count];
                });

            $totalHits = ShortLinkHit::whereIn('short_link_id', $gmLinkSub)
                ->where('hit_at', '>=', now()->subDays(30))
                ->count();

            $totalLinks = ShortLink::whereBelongsTo($user)->count();

            $linkAnalytics = [
                'totalLinks' => $totalLinks,
                'totalHits30d' => $totalHits,
                'linksByType' => $linksByType->toArray(),
            ];

            // Top 5 links by hit count — cached as scalar ids only; the models
            // are rehydrated below so hydrated Eloquent objects never enter the
            // cache payload (MEM1087).
            $topLinkIds = ShortLink::whereBelongsTo($user)
                ->orderByDesc('hit_count')
                ->limit(5)
                ->pluck('id')
                ->all();

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
                ->values()
                ->toArray();

            return [$linkAnalytics, $topLinkIds, $topReferrers];
        });

        [$linkAnalytics, $topLinkIds, $topReferrers] = $payload;

        // Rehydrate top links in their cached (hit_count desc) order.
        $topLinks = ShortLink::with('linkable')
            ->whereIn('id', $topLinkIds)
            ->get()
            ->sortBy(fn (ShortLink $link) => array_search($link->id, $topLinkIds, true))
            ->values();

        return [$linkAnalytics, $topLinks, collect($topReferrers)];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyLinkAnalytics(): array
    {
        return [
            'totalLinks' => 0,
            'totalHits30d' => 0,
            'linksByType' => collect(),
        ];
    }
}
