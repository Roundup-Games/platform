<?php

namespace App\Livewire\GameSystems;

use App\Models\GameSystem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('components.public-layout')]
class GameSystemDetail extends Component
{
    #[Locked]
    public string $slug;

    private ?GameSystem $system = null;

    public function mount(string $slug): void
    {
        $this->slug = $slug;
    }

    protected function resolveSystem(): GameSystem
    {
        if ($this->system) {
            return $this->system;
        }

        $this->system = GameSystem::where('slug', $this->slug)
            ->with([
                'categories',
                'mechanics',
                'designers',
                'publishers',
                'baseGame',
                'expansions' => fn ($q) => $q
                    ->withCount([
                        'games as active_sessions_count' => fn ($q2) => $q2
                            ->where('status', 'scheduled')
                            ->where('date_time', '>', now())
                            ->where(fn ($q3) => $q3->where('visibility', 'public')->orWhere('visibility', 'protected')),
                    ])
                    ->orderBy('bgg_average_rating', 'desc')
                    ->orderBy('name'),
            ])
            ->withCount([
                'games as active_sessions_count' => fn ($q) => $q
                    ->where('status', 'scheduled')
                    ->where('date_time', '>', now())
                    ->where(fn ($q2) => $q2->where('visibility', 'public')->orWhere('visibility', 'protected')),
                'campaigns as active_campaigns_count' => fn ($q) => $q
                    ->where('status', 'active'),
            ])
            ->firstOrFail();

        return $this->system;
    }

    // ── Preference toggles ─────────────────────────────

    public function toggleFavorite(): void
    {
        $this->resolveSystem();
        $user = auth()->user();
        abort_unless($user, 403);

        $exists = $user->gameSystemPreferences()
            ->where('game_system_id', $this->system->id)
            ->wherePivot('preference_type', 'favorite')
            ->exists();

        if ($exists) {
            $user->gameSystemPreferences()->detach($this->system->id);
            $this->dispatch('preference-updated');
            session()->flash('status', __('games.flash_removed_from_favorites'));
        } else {
            // Remove avoid if present, then add favorite
            $user->gameSystemPreferences()->detach($this->system->id);
            $user->gameSystemPreferences()->attach($this->system->id, ['preference_type' => 'favorite']);
            $this->dispatch('preference-updated');
            session()->flash('status', __('games.flash_added_to_favorites'));
        }
    }

    public function toggleAvoid(): void
    {
        $this->resolveSystem();
        $user = auth()->user();
        abort_unless($user, 403);

        $exists = $user->gameSystemPreferences()
            ->where('game_system_id', $this->system->id)
            ->wherePivot('preference_type', 'avoid')
            ->exists();

        if ($exists) {
            $user->gameSystemPreferences()->detach($this->system->id);
            $this->dispatch('preference-updated');
            session()->flash('status', __('games.flash_removed_from_avoid_list'));
        } else {
            // Remove favorite if present, then add avoid
            $user->gameSystemPreferences()->detach($this->system->id);
            $user->gameSystemPreferences()->attach($this->system->id, ['preference_type' => 'avoid']);
            $this->dispatch('preference-updated');
            session()->flash('status', __('games.flash_added_to_avoid_list'));
        }
    }

    // ── Helpers ────────────────────────────────────────

    public function getUserPreferenceProperty(): ?string
    {
        if (! auth()->check()) {
            return null;
        }

        $this->resolveSystem();

        $pref = auth()->user()->gameSystemPreferences()
            ->where('game_system_id', $this->system->id)
            ->first();

        return $pref?->pivot?->preference_type;
    }

    public function getFavoritedCountProperty(): int
    {
        $this->resolveSystem();

        return DB::table('user_game_system_preferences')
            ->where('game_system_id', $this->system->id)
            ->where('preference_type', 'favorite')
            ->count();
    }

    public function getAvoidedCountProperty(): int
    {
        $this->resolveSystem();

        return DB::table('user_game_system_preferences')
            ->where('game_system_id', $this->system->id)
            ->where('preference_type', 'avoid')
            ->count();
    }

    /**
     * Returns active session and campaign counts for this game system.
     */
    public function getSessionCampaignStatsProperty(): array
    {
        $system = $this->resolveSystem();

        return [
            'active_sessions' => $system->active_sessions_count ?? 0,
            'active_campaigns' => $system->active_campaigns_count ?? 0,
        ];
    }

    // ── Render ─────────────────────────────────────────

    public function render()
    {
        try {
            $system = $this->resolveSystem();
        } catch (ModelNotFoundException $e) {
            abort(404);
        }

        return view('livewire.game-systems.game-system-detail', [
            'system' => $system,
        ]);
    }
}
