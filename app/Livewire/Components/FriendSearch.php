<?php

namespace App\Livewire\Components;

use App\Models\User;
use App\Traits\EscapesLikeWildcards;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Reusable friend search component with debounced search.
 *
 * Queries the current user's friends (mutual follows) by name or email.
 * Selected friends render as chips with remove capability.
 *
 * Usage in parent Blade:
 *   <livewire:components.friend-search
 *       :selectedIds="$existingFriendIds"
 *   />
 *
 * Dispatches:
 *   friends-selected — { ids: int[] }
 */
class FriendSearch extends Component
{
    use EscapesLikeWildcards;

    /** @var string Current search query */
    public string $search = '';

    /** @var int[] User IDs of selected friends */
    public array $selectedIds = [];

    /** @var bool Whether the dropdown is open */
    public bool $isOpen = false;

    public function mount(array $selectedIds = []): void
    {
        $this->selectedIds = $selectedIds;
    }

    /**
     * Search the current user's friends by name or email.
     * Only returns mutual follows (isFriend() check via SQL join).
     */
    #[Computed]
    public function searchResults()
    {
        $user = Auth::user();
        if (! $user || mb_strlen(trim($this->search)) < 2) {
            return collect();
        }

        $term = trim($this->search);
        $driver = User::query()->getQuery()->getConnection()->getDriverName();
        $likeOperator = $driver === 'pgsql' ? 'ilike' : 'like';
        $escapedTerm = $this->escapeLikeWildcards($term);

        // Get IDs of mutual follows (I follow them AND they follow me, no blocks)
        $iFollowIds = $user->followings()
            ->where('type', \App\Enums\RelationshipType::Follow)
            ->pluck('related_user_id');

        $followsMeIds = $user->followers()
            ->where('type', \App\Enums\RelationshipType::Follow)
            ->pluck('user_id');

        $friendIds = $iFollowIds->intersect($followsMeIds);

        if ($friendIds->isEmpty()) {
            return collect();
        }

        // Exclude blocked users (both directions)
        $blockedByMe = $user->blocks()->pluck('related_user_id');
        $blockedMe = $user->blockedBy()->pluck('user_id');
        $friendIds = $friendIds->diff($blockedByMe)->diff($blockedMe);

        // Exclude already-selected friends
        $friendIds = $friendIds->diff($this->selectedIds);

        if ($friendIds->isEmpty()) {
            return collect();
        }

        return User::whereIn('id', $friendIds)
            ->where(function ($q) use ($likeOperator, $escapedTerm) {
                $q->where('name', $likeOperator, "%{$escapedTerm}%")
                    ->orWhere('email', $likeOperator, "%{$escapedTerm}%");
            })
            ->orderBy('name')
            ->limit(10)
            ->get();
    }

    /**
     * Get the full User models for currently selected IDs.
     */
    #[Computed]
    public function selectedFriends()
    {
        if (empty($this->selectedIds)) {
            return collect();
        }

        return User::whereIn('id', $this->selectedIds)
            ->orderBy('name')
            ->get();
    }

    public function updatedSearch(): void
    {
        $this->isOpen = true;
    }

    /**
     * Add a friend to the selected list.
     */
    public function selectFriend(int $userId): void
    {
        if (! in_array($userId, $this->selectedIds)) {
            $this->selectedIds[] = $userId;
        }

        $this->search = '';
        $this->isOpen = false;
        unset($this->searchResults);

        Log::info('friend-search.friend-selected', [
            'auth_user_id' => Auth::id(),
            'selected_user_id' => $userId,
            'total_selected' => count($this->selectedIds),
        ]);

        $this->dispatch('friends-selected', ids: $this->selectedIds);
    }

    /**
     * Remove a friend from the selected list.
     */
    public function removeFriend(string $userId): void
    {
        $this->selectedIds = array_values(
            array_filter($this->selectedIds, fn ($id) => $id !== $userId)
        );

        unset($this->searchResults, $this->selectedFriends);

        Log::info('friend-search.friend-removed', [
            'auth_user_id' => Auth::id(),
            'removed_user_id' => $userId,
            'total_selected' => count($this->selectedIds),
        ]);

        $this->dispatch('friends-selected', ids: $this->selectedIds);
    }

    /**
     * Close the dropdown (e.g. on click-away or Escape).
     */
    public function closeDropdown(): void
    {
        $this->isOpen = false;
    }

    /**
     * Open the dropdown (e.g. on focus).
     */
    public function setOpen(): void
    {
        $this->isOpen = true;
    }

    public function render()
    {
        return view('livewire.components.friend-search');
    }
}
