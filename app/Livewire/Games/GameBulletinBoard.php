<?php

namespace App\Livewire\Games;

use App\Enums\GameStatus;
use App\Enums\NotificationCategory;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameBulletin;
use App\Models\User;
use App\Notifications\BulletinPosted;
use App\Services\NotificationService;
use App\Services\DashboardCacheService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class GameBulletinBoard extends Component
{
    #[Locked]
    public Game $game;

    public string $content = '';

    public function mount(Game $game): void
    {
        $this->game = $game;
    }

    // ── Computed properties ─────────────────────────────

    #[Computed]
    public function canCreateBulletin(): bool
    {
        $user = Auth::user();

        return $user
            && $this->game->owner_id === $user->id
            && $this->game->status === GameStatus::Scheduled;
    }

    #[Computed]
    public function bulletins()
    {
        return $this->game->bulletins()
            ->notExpired()
            ->with('user')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Whether the current user can see the bulletin board section.
     * Only the owner and approved participants can see it.
     */
    #[Computed]
    public function canViewBoard(): bool
    {
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        // Owner can always see
        if ($this->game->owner_id === $user->id) {
            return true;
        }

        // Approved participants can see
        return $this->game->participants()
            ->where('user_id', $user->id)
            ->where('status', ParticipantStatus::Approved->value)
            ->exists();
    }

    // ── Actions ─────────────────────────────────────────

    public function create(): void
    {
        if (! $this->canCreateBulletin()) {
            session()->flash('error', __('games.error_bulletin_unauthorized'));

            return;
        }

        $this->validate([
            'content' => 'required|string|max:280',
        ]);

        $bulletin = GameBulletin::create([
            'game_id' => $this->game->id,
            'user_id' => Auth::id(),
            'content' => $this->content,
            'expires_at' => $this->game->date_time,
        ]);

        Log::info('Game bulletin created', [
            'game_id' => $this->game->id,
            'bulletin_id' => $bulletin->id,
            'user_id' => Auth::id(),
            'content_length' => Str::length($this->content),
        ]);

        // Invalidate action center cache for all approved participants
        $this->invalidateParticipantActionCenters();

        // Send push notification to all approved participants
        $this->notifyParticipants($bulletin);

        $this->content = '';
        unset($this->bulletins);

        session()->flash('success', __('games.flash_bulletin_created'));
    }

    // ── Internal helpers ────────────────────────────────

    private function notifyParticipants(GameBulletin $bulletin): void
    {
        $host = Auth::user();
        $notification = new BulletinPosted($this->game, $host, $bulletin);

        $participants = $this->game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->where('user_id', '!=', $host->id)
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter();

        foreach ($participants as $participant) {
            try {
                app(NotificationService::class)->send(
                    $participant,
                    $notification,
                    NotificationCategory::GameUpdated
                );
            } catch (\Throwable $e) {
                Log::error('bulletin.notification_dispatch_failed', [
                    'game_id' => $this->game->id,
                    'bulletin_id' => $bulletin->id,
                    'participant_id' => $participant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Invalidate action center cache for all approved participants
     * so they see the new bulletin immediately.
     */
    private function invalidateParticipantActionCenters(): void
    {
        $cacheService = app(DashboardCacheService::class);
        $participantUserIds = $this->game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->where('user_id', '!=', Auth::id())
            ->pluck('user_id');

        foreach ($participantUserIds as $userId) {
            $cacheService->invalidateForUser((string) $userId, ['action_center']);
        }
    }

    public function render()
    {
        return view('livewire.games.game-bulletin-board', [
            'canViewBoard' => $this->canViewBoard(),
            'canCreateBulletin' => $this->canCreateBulletin(),
            'bulletins' => $this->bulletins(),
        ]);
    }
}
