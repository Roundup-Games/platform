<?php

namespace App\Livewire\People;

use App\Enums\RelationshipType;
use App\Jobs\UpdateUserDiscoveryCache;
use App\Models\NearbyDiscoveryView;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\PeopleDiscoveryService;
use App\Traits\HasGuestLocation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

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

    public function mount(): void
    {
        $this->authUser = Auth::user();

        // Dispatch cache warm-up on mount so the background job starts
        // computing while the user browses the following/followers tabs.
        $this->dispatchNearbyWarmup();
    }

    public function updatingActiveTab(): void
    {
        $this->resetPage();
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
            UpdateUserDiscoveryCache::dispatch($this->authUser->id, 'page_visit_warmup');
            $this->nearbyWarming = true;
        }
    }

    // ── Tab Data ──────────────────────────────────────

    #[Computed]
    public function followingUsers()
    {
        return $this->authUser->followings()
            ->with('related')
            ->latest()
            ->paginate(12, ['*'], 'following_page');
    }

    #[Computed]
    public function followerUsers()
    {
        return $this->authUser->followers()
            ->with('user')
            ->latest()
            ->paginate(12, ['*'], 'followers_page');
    }

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
     */
    #[Computed]
    public function nearbyUsers(): array
    {
        // Resolve viewer location: prefer linked location, fall back to guest location
        $lat = null;
        $lng = null;

        $location = $this->authUser->linkedLocation;
        if ($location && $location->latitude && $location->longitude) {
            $lat = (float) $location->latitude;
            $lng = (float) $location->longitude;
        }

        if ($lat === null || $lng === null) {
            $lat = $this->guestLat;
            $lng = $this->guestLng;
        }

        $service = app(PeopleDiscoveryService::class);
        $response = $service->discover($this->authUser, $lat, $lng);

        /** @var LengthAwarePaginator $paginator */
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

        return $nearby['results']->total();
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
        unset($this->followingUsers, $this->followerUsers, $this->followingCount, $this->followersCount);

        session()->flash('success', __('common.flash_unfollowed', ['name' => $target->name]));
    }

    public function followBack(string $userId): void
    {
        $target = User::find($userId);
        if (! $target || $target->is($this->authUser) || $target->isAnonymized()) {
            return;
        }

        UserRelationship::follow($this->authUser, $target);
        unset($this->followingUsers, $this->followerUsers, $this->followingCount, $this->followersCount);

        session()->flash('success', __('common.flash_now_following', ['name' => $target->name]));
    }

    public function removeFollower(string $userId): void
    {
        $target = User::find($userId);
        if (! $target) {
            return;
        }

        UserRelationship::where('user_id', $target->id)
            ->where('related_user_id', $this->authUser->id)
            ->where('type', RelationshipType::Follow)
            ->delete();

        unset($this->followerUsers, $this->followersCount, $this->followingCount);

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
        unset($this->nearbyUsers, $this->nearbyCount, $this->followingCount, $this->followingUsers);
        $this->nearbyWarming = false; // allow re-warm on next poll

        session()->flash('success', __('common.flash_now_following', ['name' => $target->name]));
    }

    // ── Helpers ──────────────────────────────────────

    public function isFollowingUser(string $userId): bool
    {
        return $this->authUser->isFollowing(User::find($userId));
    }

    public function render()
    {
        return view('livewire.people.people-page');
    }
}
