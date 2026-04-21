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

    public function mount(): void
    {
        $this->authUser = Auth::user();
    }

    public function updatingActiveTab(): void
    {
        $this->resetPage();
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

    #[Computed]
    public function nearbyUsers(): array
    {
        // Track this tab view for sweep targeting (no job dispatch)
        NearbyDiscoveryView::updateOrCreate(
            ['user_id' => $this->authUser->id],
            ['last_discovery_view' => now()],
        );

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
        ];
    }

    #[Computed]
    public function nearbyCount(): int
    {
        return $this->nearbyUsers['results']->total();
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

    public function unfollow(int $userId): void
    {
        $target = User::find($userId);
        if (! $target || $target->is($this->authUser)) {
            return;
        }

        UserRelationship::unfollow($this->authUser, $target);
        unset($this->followingUsers, $this->followerUsers, $this->followingCount, $this->followersCount);

        session()->flash('success', 'You unfollowed ' . $target->name . '.');
    }

    public function followBack(int $userId): void
    {
        $target = User::find($userId);
        if (! $target || $target->is($this->authUser)) {
            return;
        }

        UserRelationship::follow($this->authUser, $target);
        unset($this->followingUsers, $this->followerUsers, $this->followingCount, $this->followersCount);

        session()->flash('success', 'You are now following ' . $target->name . '.');
    }

    public function removeFollower(int $userId): void
    {
        $target = User::find($userId);
        if (! $target) {
            return;
        }

        // Remove the follow from the target to auth user
        UserRelationship::where('user_id', $target->id)
            ->where('related_user_id', $this->authUser->id)
            ->where('type', RelationshipType::Follow)
            ->delete();

        unset($this->followerUsers, $this->followersCount, $this->followingCount);

        session()->flash('success', 'You removed ' . $target->name . ' from your followers.');
    }

    public function unblock(int $userId): void
    {
        $target = User::find($userId);
        if (! $target) {
            return;
        }

        UserRelationship::unblock($this->authUser, $target);
        unset($this->blockedUsers, $this->blockedCount);

        session()->flash('success', 'You unblocked ' . $target->name . '.');
    }

    public function followFromNearby(int $userId): void
    {
        $target = User::find($userId);
        if (! $target || $target->is($this->authUser)) {
            return;
        }

        UserRelationship::follow($this->authUser, $target);

        // follow() now handles cache invalidation + dispatch internally
        unset($this->nearbyUsers, $this->nearbyCount, $this->followingCount, $this->followingUsers);

        session()->flash('success', 'You are now following ' . $target->name . '.');
    }

    // ── Helpers ──────────────────────────────────────

    public function isFollowingUser(int $userId): bool
    {
        return $this->authUser->isFollowing(User::find($userId));
    }

    public function render()
    {
        return view('livewire.people.people-page');
    }
}
