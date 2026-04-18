<?php

namespace App\Livewire\People;

use App\Enums\RelationshipType;
use App\Models\User;
use App\Models\UserRelationship;
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
