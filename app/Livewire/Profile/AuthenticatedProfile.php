<?php

namespace App\Livewire\Profile;

use App\Models\Campaign;
use App\Models\Game;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\ProfileVisibilityResolver;
use App\Services\ReviewAggregateService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class AuthenticatedProfile extends Component
{
    #[Locked]
    public User $profileUser;

    #[Locked]
    public bool $isOwnProfile = false;

    #[Locked]
    public bool $isFollowing = false;

    #[Locked]
    public bool $isFollowedBy = false;

    #[Locked]
    public bool $isFriend = false;

    #[Locked]
    public bool $hasBlocked = false;

    #[Locked]
    public bool $isBlockedBy = false;

    #[Locked]
    public int $followerCount = 0;

    #[Locked]
    public int $followingCount = 0;

    /** @var string[] Profile field keys visible to the current viewer */
    #[Locked]
    public array $visibleFields = [];

    /** @var int Current page for GM reviews pagination */
    public int $reviewsPage = 1;

    public function mount(User $user): void
    {
        $this->profileUser = $user;

        $viewer = authenticatedUser();
        $this->isOwnProfile = $viewer->is($user);

        if (! $this->isOwnProfile) {
            $this->isFollowing = $viewer->isFollowing($user);
            $this->isFollowedBy = $viewer->isFollowedBy($user);
            $this->isFriend = $viewer->isFriend($user);
            $this->hasBlocked = $viewer->hasBlocked($user);
            $this->isBlockedBy = $viewer->isBlockedBy($user);
        }

        // Resolve visible profile fields based on privacy settings and viewer relationship
        $this->visibleFields = app(ProfileVisibilityResolver::class)
            ->profileFieldsVisible($viewer, $user);

        $this->followerCount = $user->followers()->count();
        $this->followingCount = $user->followings()->count();
    }

    public function follow(): void
    {
        $viewer = authenticatedUser();
        if ($viewer->is($this->profileUser) || $this->hasBlocked || $this->isBlockedBy) {
            return;
        }

        UserRelationship::follow($viewer, $this->profileUser);
        $this->isFollowing = true;
        $this->isFriend = $viewer->isFollowedBy($this->profileUser);
        $this->followerCount = $this->profileUser->followers()->count();

        session()->flash('success', __('common.flash_now_following', ['name' => $this->profileUser->name]));
    }

    public function unfollow(): void
    {
        $viewer = authenticatedUser();
        if ($viewer->is($this->profileUser)) {
            return;
        }

        UserRelationship::unfollow($viewer, $this->profileUser);
        $this->isFollowing = false;
        $this->isFriend = false;
        $this->followerCount = $this->profileUser->followers()->count();

        session()->flash('success', __('common.flash_unfollowed', ['name' => $this->profileUser->name]));
    }

    public function block(): void
    {
        $viewer = authenticatedUser();
        if ($viewer->is($this->profileUser)) {
            return;
        }

        UserRelationship::block($viewer, $this->profileUser);
        $this->hasBlocked = true;
        $this->isFollowing = false;
        $this->isFollowedBy = false;
        $this->isFriend = false;
        $this->followerCount = $this->profileUser->followers()->count();
        $this->followingCount = $this->profileUser->followings()->count();

        session()->flash('success', __('common.flash_user_blocked', ['name' => $this->profileUser->name]));
    }

    public function unblock(): void
    {
        $viewer = authenticatedUser();
        if ($viewer->is($this->profileUser)) {
            return;
        }

        UserRelationship::unblock($viewer, $this->profileUser);
        $this->hasBlocked = false;
        // Refresh follow state in case viewer had a follow before blocking
        $this->isFollowing = $viewer->isFollowing($this->profileUser);
        $this->isFollowedBy = $viewer->isFollowedBy($this->profileUser);
        $this->isFriend = $viewer->isFriend($this->profileUser);

        session()->flash('success', __('common.flash_user_unblocked', ['name' => $this->profileUser->name]));
    }

    public function loadMoreReviews(): void
    {
        $this->reviewsPage++;
    }

    public function render(): View
    {
        $eagerLoads = [];

        // Only load relationships for fields the viewer is allowed to see
        if (in_array('game_systems', $this->visibleFields)) {
            $eagerLoads[] = 'favoriteGameSystems';
        }
        if (in_array('vibes', $this->visibleFields)) {
            $eagerLoads[] = 'favoriteVibes';
        }

        if (! empty($eagerLoads)) {
            $this->profileUser->load($eagerLoads);
        }

        // Load teams through TeamMember to avoid belongsToMany pivot issue
        $teamMemberships = collect();
        if (in_array('teams', $this->visibleFields)) {
            $teamMemberships = TeamMember::where('user_id', $this->profileUser->id)
                ->where('status', 'active')
                ->with('team')
                ->latest('joined_at')
                ->limit(5)
                ->get()
                ->filter(fn ($m) => $m->team !== null);
        }

        // Visibility-scoped games and campaigns
        $games = $this->resolveVisibleGames();
        $campaigns = $this->resolveVisibleCampaigns();

        // Load GM profile (always visible regardless of privacy settings when active)
        $this->profileUser->load(['gmProfile' => fn ($q) => $q->where('is_active', true)]);

        // Resolve GM reviews with pagination if GM profile exists
        $gmReviews = null;
        $gmSocialLinks = collect();
        if ($this->profileUser->gmProfile) {
            $gmReviews = app(ReviewAggregateService::class)
                ->recentReviews($this->profileUser->gmProfile, 5, $this->reviewsPage);
            $gmSocialLinks = $this->profileUser->gmSocialLinks()->get()
                ->sortBy(fn ($link) => config("platforms.{$link->platform}.sort_order", 999));
        }

        // Reliability data: tier is always visible, detailed stats respect 'stats' privacy
        /** @var array{tier?: string, score?: int, game_count?: int} $reliabilityData */
        $reliabilityData = $this->profileUser->reliability_score ?? [];
        $reliabilityTier = $reliabilityData['tier'] ?? 'newcomer';
        $reliabilityScore = $reliabilityData['score'] ?? 0;
        $reliabilityGameCount = $reliabilityData['game_count'] ?? 0;
        $showReliabilityDetails = in_array('stats', $this->visibleFields);

        return view('livewire.profile.authenticated-profile', [
            'profileUser' => $this->profileUser,
            'teamMemberships' => $teamMemberships,
            'visibleFields' => $this->visibleFields,
            'games' => $games,
            'campaigns' => $campaigns,
            'gmReviews' => $gmReviews,
            'gmSocialLinks' => $gmSocialLinks,
            'reliabilityTier' => $reliabilityTier,
            'reliabilityScore' => $reliabilityScore,
            'reliabilityGameCount' => $reliabilityGameCount,
            'showReliabilityDetails' => $showReliabilityDetails,
        ]);
    }

    /**
     * Build the visibility scope for the current viewer.
     *
     * Own profile: public + protected + private
     * Friend/teammate: public + protected
     * Stranger/unauthenticated: public only
     * Blocked: empty (nothing visible)
     *
     * @return array<int, string>
     */
    private function visibilityScope(): array
    {
        if ($this->isBlockedBy || $this->hasBlocked) {
            return [];
        }

        if ($this->isOwnProfile) {
            return ['public', 'protected', 'private'];
        }

        if ($this->isFriend || $this->isFriendOrTeammate()) {
            return ['public', 'protected'];
        }

        return ['public'];
    }

    private function isFriendOrTeammate(): bool
    {
        $viewer = authenticatedUser();

        return $viewer->isFriendOrTeammate($this->profileUser);
    }

    /**
     * Resolve visibility-scoped games: owned + participated, deduplicated.
     *
     * Owned games are tiered by the viewer's relationship to the profile user
     * ($scope). Participated games are owned by a THIRD PARTY, so their
     * visibility must be gated by the viewer's relationship to each game's
     * actual owner (visibleTo) — otherwise a protected game owned by someone
     * outside the viewer's circle leaks onto a mutual friend's profile.
     *
     * @return Collection<int, Game>
     */
    private function resolveVisibleGames()
    {
        $scope = $this->visibilityScope();
        if (empty($scope)) {
            return new Collection;
        }

        $viewer = authenticatedUser();
        $profileUserId = $this->profileUser->id;

        // Owned games: tiered by the viewer→profile-user relationship.
        $ownedGames = Game::where('owner_id', $profileUserId)
            ->whereIn('visibility', $scope)
            ->where('status', 'scheduled')
            ->where('date_time', '>', now())
            ->with(['owner', 'gameSystems'])
            ->withCount('participants')
            ->get();

        // Participated games (approved, owned by someone else): gated by
        // visibleTo($viewer) so the real owner's visibility intent is honored.
        $participatedGameIds = \DB::table('game_participants')
            ->where('user_id', $profileUserId)
            ->where('status', 'approved')
            ->pluck('game_id');

        $participatedGames = Game::whereIn('id', $participatedGameIds)
            ->whereNotIn('id', $ownedGames->modelKeys())
            ->visibleTo($viewer)
            ->where('status', 'scheduled')
            ->where('date_time', '>', now())
            ->with(['owner', 'gameSystems'])
            ->withCount('participants')
            ->get();

        return $ownedGames->merge($participatedGames)
            ->sortBy('date_time')
            ->take(10)
            ->values();
    }

    /**
     * Resolve visibility-scoped campaigns: owned + participated, deduplicated.
     *
     * Same owned-vs-participated split as {@see resolveVisibleGames()}: third-party
     * campaigns the profile user plays in are gated by visibleTo($viewer).
     *
     * @return Collection<int, Campaign>
     */
    private function resolveVisibleCampaigns()
    {
        $scope = $this->visibilityScope();
        if (empty($scope)) {
            return new Collection;
        }

        $viewer = authenticatedUser();
        $profileUserId = $this->profileUser->id;

        // Owned campaigns: tiered by the viewer→profile-user relationship.
        $ownedCampaigns = Campaign::where('owner_id', $profileUserId)
            ->whereIn('visibility', $scope)
            ->with(['owner', 'gameSystems'])
            ->withCount('participants')
            ->get();

        // Participated campaigns (approved, owned by someone else).
        $participatedCampaignIds = \DB::table('campaign_participants')
            ->where('user_id', $profileUserId)
            ->where('status', 'approved')
            ->pluck('campaign_id');

        $participatedCampaigns = Campaign::whereIn('id', $participatedCampaignIds)
            ->whereNotIn('id', $ownedCampaigns->modelKeys())
            ->visibleTo($viewer)
            ->with(['owner', 'gameSystems'])
            ->withCount('participants')
            ->get();

        return $ownedCampaigns->merge($participatedCampaigns)
            ->sortByDesc('created_at')
            ->take(10)
            ->values();
    }
}
