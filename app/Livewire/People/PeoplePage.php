<?php

namespace App\Livewire\People;

use App\Enums\RelationshipType;
use App\Jobs\UpdateUserDiscoveryCache;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\PeopleDiscoveryService;
use App\Traits\HasGuestLocation;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * @property-read array<string, mixed> $nearbyUsers
 *
 * The following computed properties are declared for phpstan (Livewire's
 * #[Computed] magic property access); see the matching methods below.
 * @property-read LengthAwarePaginator<int, UserRelationship> $followingUsers
 * @property-read LengthAwarePaginator<int, UserRelationship> $followerUsers
 * @property-read Collection<int, User> $nearbyUserMap
 * @property-read array<string, true> $mutualFollowerIds
 * @property-read array<string, true> $followingBackIds
 */
#[Layout('layouts.app')]
class PeoplePage extends Component
{
    use HasGuestLocation;
    use WithPagination;

    #[Url(as: 'tab')]
    public string $activeTab = 'following';

    #[Locked]
    public User $authUser;

    /**
     * Whether the nearby cache-warm job has been dispatched this request.
     * Prevents redundant dispatches on wire:poll cycles.
     */
    public bool $nearbyWarming = false;

    /** @var int How many nearby users to display (incremented by load-more) */
    public int $nearbyDisplayCount = 12;

    public function mount(): void
    {
        $this->authUser = authenticatedUser();

        // Dispatch cache warm-up on mount so the background job starts
        // computing while the user browses the following/followers tabs.
        $this->dispatchNearbyWarmup();
    }

    public function updatingActiveTab(): void
    {
        $this->resetPage();
        $this->nearbyDisplayCount = 12;
    }

    // ── Nearby Warm-up ────────────────────────────────

    /**
     * Dispatch the nearby discovery cache warm-up job if needed.
     *
     * Called on mount and by wire:poll. Uses shouldWarmCache() to avoid
     * re-dispatching while the job is running (ShouldBeUnique on the job
     * provides secondary deduplication).
     */
    public function dispatchNearbyWarmup(): void
    {
        if ($this->nearbyWarming) {
            return;
        }

        $location = $this->authUser->linkedLocation;
        $lat = $location && $location->latitude && $location->longitude
            ? (float) $location->latitude : $this->guestLat;
        $lng = $location && $location->latitude && $location->longitude
            ? (float) $location->longitude : $this->guestLng;

        if ($lat === null || $lng === null) {
            return;
        }

        $service = app(PeopleDiscoveryService::class);

        if ($service->shouldWarmCache($this->authUser, $lat, $lng)) {
            UpdateUserDiscoveryCache::dispatch((string) $this->authUser->id, 'page_visit_warmup');
            $this->nearbyWarming = true;
        }
    }

    /**
     * Load more nearby users (load-more pattern).
     */
    public function loadMoreNearby(): void
    {
        $this->nearbyDisplayCount += 12;
    }

    // ── Tab Data ──────────────────────────────────────

    /**
     * @return LengthAwarePaginator<int, UserRelationship>
     */
    #[Computed]
    public function followingUsers()
    {
        return $this->authUser->followings()
            ->with('related')
            ->latest()
            ->paginate(12, ['*'], 'following_page');
    }

    /**
     * @return LengthAwarePaginator<int, UserRelationship>
     */
    #[Computed]
    public function followerUsers()
    {
        return $this->authUser->followers()
            ->with('user')
            ->latest()
            ->paginate(12, ['*'], 'followers_page');
    }

    /**
     * @return LengthAwarePaginator<int, UserRelationship>
     */
    #[Computed]
    public function blockedUsers()
    {
        return $this->authUser->blocks()
            ->with('related')
            ->latest()
            ->paginate(12, ['*'], 'blocked_page');
    }

    /**
     * Cache-only nearby discovery results.
     *
     * Returns {results, status, noLocation} where status is:
     *   'ok'         — cached results available
     *   'pending'    — warm-up job running, no results yet
     *   'no_location' — user has no location set
     *
     * The blade template shows a "still looking" state when pending,
     * and wire:poll.5s triggers hydration when the cache fills.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function nearbyUsers(): array
    {
        // Resolve viewer location: prefer linked location, fall back to guest location
        $location = $this->authUser->linkedLocation;
        if ($location && $location->latitude && $location->longitude) {
            $lat = (float) $location->latitude;
            $lng = (float) $location->longitude;
        } else {
            $lat = $this->guestLat;
            $lng = $this->guestLng;
        }

        $service = app(PeopleDiscoveryService::class);
        $response = $service->discover($this->authUser, $lat, $lng, $this->nearbyDisplayCount, 1);

        /** @var LengthAwarePaginator<int, array{user: User, compatibility_score: float, match_reasons: array<string, string>, tier: string, distance_km: float|null}> $paginator */
        $paginator = $response['results'];

        // Convert paginator items to plain arrays (user_id instead of User model)
        // to prevent Livewire serialization failures with Eloquent models
        $items = collect($paginator->items())->map(fn (array $item) => [
            'user_id' => $item['user']->id,
            'compatibility_score' => $item['compatibility_score'],
            'match_reasons' => $item['match_reasons'],
            'tier' => $item['tier'],
            'distance_km' => $item['distance_km'],
        ])->all();

        $serializablePaginator = new LengthAwarePaginator(
            $items,
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
            ['path' => $paginator->path()],
        );

        return [
            'results' => $serializablePaginator,
            'status' => $response['status'],
            'noLocation' => $response['status'] === 'no_location',
            'pending' => $response['status'] === 'pending',
        ];
    }

    /**
     * Nearby count for the tab badge.
     *
     * Returns -1 when pending (signals the blade to hide the count).
     * Returns 0 when no location (signals the blade to show "0").
     */
    #[Computed]
    public function nearbyCount(): int
    {
        $nearby = $this->nearbyUsers;
        $status = $nearby['status'] ?? 'pending';

        if ($status === 'pending') {
            return -1; // sentinel: hide the count badge
        }

        $results = $nearby['results'] ?? null;
        if ($results instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator) {
            return $results->total();
        }

        return 0;
    }

    /**
     * The User models for the current nearby-results page, keyed by id.
     *
     * Replaces a raw Eloquent query that lived in the blade template (ran on
     * every render — including every 5s poll tick while the warm-up ran).
     * Kept as a #[Computed] so it is fetched once per request.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function nearbyUserMap()
    {
        $nearby = $this->nearbyUsers;
        $results = $nearby['results'] ?? null;

        $ids = $results instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator
            ? collect($results->items())->pluck('user_id')->unique()->values()
            : collect();

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::with('media')
            ->whereKey($ids)
            ->get()
            ->keyBy('id');
    }

    /**
     * IDs on the current "Following" page who follow the viewer back
     * (mutual relationships). One query instead of one exists() per row.
     *
     * @return array<string, true> id => true lookup set
     */
    #[Computed]
    public function mutualFollowerIds(): array
    {
        $pageIds = $this->followingUsers->getCollection()
            ->map(fn ($rel) => $rel->related?->id)
            ->filter()
            ->values()
            ->all();

        if ($pageIds === []) {
            return [];
        }

        // followers() rows carry the follower's id in user_id.
        /** @var list<string> $followers */
        $followers = $this->authUser->followers()
            ->whereIn('user_id', $pageIds)
            ->pluck('user_id')
            ->all();

        return array_fill_keys($followers, true);
    }

    /**
     * IDs on the current "Followers" page whom the viewer follows back.
     * One query instead of one exists() per row.
     *
     * @return array<string, true> id => true lookup set
     */
    #[Computed]
    public function followingBackIds(): array
    {
        $pageIds = $this->followerUsers->getCollection()
            ->map(fn ($rel) => $rel->user?->id)
            ->filter()
            ->values()
            ->all();

        if ($pageIds === []) {
            return [];
        }

        // followings() rows carry the followed user's id in related_user_id.
        /** @var list<string> $following */
        $following = $this->authUser->followings()
            ->whereIn('related_user_id', $pageIds)
            ->pluck('related_user_id')
            ->all();

        return array_fill_keys($following, true);
    }

    // ── Follow Stats ─────────────────────────────────

    #[Computed]
    public function followingCount(): int
    {
        return $this->authUser->followings()->count();
    }

    #[Computed]
    public function followersCount(): int
    {
        return $this->authUser->followers()->count();
    }

    #[Computed]
    public function blockedCount(): int
    {
        return $this->authUser->blocks()->count();
    }

    // ── Actions ──────────────────────────────────────

    public function unfollow(string $userId): void
    {
        $target = User::find($userId);
        if (! $target || $target->is($this->authUser) || $target->isAnonymized()) {
            return;
        }

        UserRelationship::unfollow($this->authUser, $target);
        unset($this->followingUsers, $this->followerUsers, $this->followingCount, $this->followersCount, $this->mutualFollowerIds, $this->followingBackIds);

        session()->flash('success', __('common.flash_unfollowed', ['name' => $target->name]));
    }

    public function followBack(string $userId): void
    {
        $target = User::find($userId);
        if (! $target || $target->is($this->authUser) || $target->isAnonymized()) {
            return;
        }

        UserRelationship::follow($this->authUser, $target);
        unset($this->followingUsers, $this->followerUsers, $this->followingCount, $this->followersCount, $this->mutualFollowerIds, $this->followingBackIds);

        session()->flash('success', __('common.flash_now_following', ['name' => $target->name]));
    }

    public function removeFollower(string $userId): void
    {
        $target = User::find($userId);
        if (! $target) {
            return;
        }

        UserRelationship::whereBelongsTo($target)
            ->where('related_user_id', $this->authUser->id)
            ->where('type', RelationshipType::Follow)
            ->delete();

        unset($this->followerUsers, $this->followersCount, $this->followingCount, $this->followingBackIds, $this->mutualFollowerIds);

        session()->flash('success', __('common.flash_follower_removed', ['name' => $target->name]));
    }

    public function unblock(string $userId): void
    {
        $target = User::find($userId);
        if (! $target) {
            return;
        }

        UserRelationship::unblock($this->authUser, $target);
        unset($this->blockedUsers, $this->blockedCount);

        session()->flash('success', __('common.flash_user_unblocked', ['name' => $target->name]));
    }

    public function followFromNearby(string $userId): void
    {
        $target = User::find($userId);
        if (! $target || $target->is($this->authUser) || $target->isAnonymized()) {
            return;
        }

        UserRelationship::follow($this->authUser, $target);

        // follow() handles cache invalidation + dispatch internally
        unset($this->nearbyUsers, $this->nearbyCount, $this->followingCount, $this->followingUsers, $this->nearbyUserMap, $this->mutualFollowerIds, $this->followingBackIds);
        $this->nearbyWarming = false; // allow re-warm on next poll

        session()->flash('success', __('common.flash_now_following', ['name' => $target->name]));
    }

    // ── Helpers ──────────────────────────────────────

    public function isFollowingUser(string $userId): bool
    {
        return $this->authUser->isFollowing(User::find($userId) ?? new User);
    }

    public function render(): View
    {
        return view('livewire.people.people-page');
    }
}
