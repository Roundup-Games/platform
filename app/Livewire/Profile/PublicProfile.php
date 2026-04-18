<?php

namespace App\Livewire\Profile;

use App\Models\User;
use App\Models\UserRelationship;
use App\Services\ProfileVisibilityResolver;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class PublicProfile extends Component
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

    public function mount(User $user): void
    {
        $this->profileUser = $user;

        $viewer = Auth::user();
        $this->isOwnProfile = $viewer && $viewer->is($user);

        if ($viewer && ! $this->isOwnProfile) {
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
        $viewer = Auth::user();
        if (! $viewer || $viewer->is($this->profileUser) || $this->hasBlocked || $this->isBlockedBy) {
            return;
        }

        UserRelationship::follow($viewer, $this->profileUser);
        $this->isFollowing = true;
        $this->isFriend = $viewer->isFollowedBy($this->profileUser);
        $this->followerCount = $this->profileUser->followers()->count();

        session()->flash('success', 'You are now following ' . $this->profileUser->name . '.');
    }

    public function unfollow(): void
    {
        $viewer = Auth::user();
        if (! $viewer || $viewer->is($this->profileUser)) {
            return;
        }

        UserRelationship::unfollow($viewer, $this->profileUser);
        $this->isFollowing = false;
        $this->isFriend = false;
        $this->followerCount = $this->profileUser->followers()->count();

        session()->flash('success', 'You unfollowed ' . $this->profileUser->name . '.');
    }

    public function block(): void
    {
        $viewer = Auth::user();
        if (! $viewer || $viewer->is($this->profileUser)) {
            return;
        }

        UserRelationship::block($viewer, $this->profileUser);
        $this->hasBlocked = true;
        $this->isFollowing = false;
        $this->isFollowedBy = false;
        $this->isFriend = false;
        $this->followerCount = $this->profileUser->followers()->count();
        $this->followingCount = $this->profileUser->followings()->count();

        session()->flash('success', 'You blocked ' . $this->profileUser->name . '.');
    }

    public function unblock(): void
    {
        $viewer = Auth::user();
        if (! $viewer || $viewer->is($this->profileUser)) {
            return;
        }

        UserRelationship::unblock($viewer, $this->profileUser);
        $this->hasBlocked = false;
        // Refresh follow state in case viewer had a follow before blocking
        $this->isFollowing = $viewer->isFollowing($this->profileUser);
        $this->isFollowedBy = $viewer->isFollowedBy($this->profileUser);
        $this->isFriend = $viewer->isFriend($this->profileUser);

        session()->flash('success', 'You unblocked ' . $this->profileUser->name . '.');
    }

    public function render()
    {
        $eagerLoads = [];

        // Only load relationships for fields the viewer is allowed to see
        if (in_array('game_systems', $this->visibleFields)) {
            $eagerLoads[] = 'favoriteGameSystems';
        }
        if (in_array('vibes', $this->visibleFields)) {
            $eagerLoads[] = 'favoriteVibes';
        }
        if (in_array('campaigns', $this->visibleFields)) {
            $eagerLoads['ownedCampaigns'] = fn ($q) => $q->where('visibility', 'public')->latest()->limit(5);
        }

        if (! empty($eagerLoads)) {
            $this->profileUser->load($eagerLoads);
        }

        // Load teams through TeamMember to avoid belongsToMany pivot issue
        $teamMemberships = collect();
        if (in_array('teams', $this->visibleFields)) {
            $teamMemberships = \App\Models\TeamMember::where('user_id', $this->profileUser->id)
                ->where('status', 'active')
                ->with('team')
                ->latest('joined_at')
                ->limit(5)
                ->get()
                ->filter(fn ($m) => $m->team);
        }

        return view('livewire.profile.public', [
            'profileUser' => $this->profileUser,
            'teamMemberships' => $teamMemberships,
            'visibleFields' => $this->visibleFields,
        ]);
    }
}
