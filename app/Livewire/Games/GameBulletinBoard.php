<?php

namespace App\Livewire\Games;

use App\Enums\NotificationCategory;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameBulletin;
use App\Models\User;
use App\Notifications\BulletinPosted;
use App\Services\DashboardCacheService;
use App\Services\NotificationService;
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

        return $user && $user->can('create', [GameBulletin::class, $this->game]);
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

        // Sanitize: strip control characters (RTL overrides, zero-width spaces, etc.)
        // that could cause visual spoofing. Blade's {{ }} handles HTML escaping.
        $sanitizedContent = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{200E}-\x{200F}\x{202A}-\x{202E}\x{FEFF}\x{FFF9}-\x{FFFB}]/u', '', $this->content);

        $bulletin = GameBulletin::postAsHost(
            gameId: $this->game->id,
            userId: Auth::id(),
            content: $sanitizedContent,
            expiresAt: $this->game->date_time?->toDateTimeString(),
        );

        Log::info('Game bulletin created', [
            'game_id' => $this->game->id,
            'bulletin_id' => $bulletin->id,
            'user_id' => Auth::id(),
            'content_length' => Str::length($this->content),
        ]);

        // Send push notification to all approved participants
        // (also handles action center cache invalidation)
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
            ->get();

        // Invalidate action center for all approved participants
        // so they see the new bulletin immediately.
        $participantUserIds = $participants->pluck('user_id')->map(fn ($id) => (string) $id)->all();
        app(DashboardCacheService::class)->invalidateForUsers(
            $participantUserIds,
            ['action_center'],
        );

        // Send push notifications
        $participantUsers = $participants->pluck('user')->filter();

        foreach ($participantUsers as $participant) {
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

    public function render()
    {
        return view('livewire.games.game-bulletin-board', [
            'canViewBoard' => $this->canViewBoard(),
            'canCreateBulletin' => $this->canCreateBulletin(),
            'bulletins' => $this->bulletins(),
        ]);
    }
}
