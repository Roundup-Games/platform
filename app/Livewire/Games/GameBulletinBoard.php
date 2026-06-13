<?php

namespace App\Livewire\Games;

use App\Enums\NotificationCategory;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameBulletin;
use App\Models\User;
use App\Notifications\BulletinPosted;
use App\Policies\GameBulletinPolicy;
use App\Services\NotificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
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

        return $user?->can('create', [GameBulletin::class, $this->game]) ?? false;
    }

    /**
     * @return Collection<int, GameBulletin>
     */
    #[Computed]
    public function bulletins()
    {
        return $this->game->bulletins()
            ->notExpired()
            ->with('user')
            ->orderByDesc('created_at')
            ->get();
    }

    #[Computed]
    public function isElevatedAccess(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        // If the user is the game host, this is normal access — not elevated
        if ((string) $this->game->owner_id === (string) $user->id) {
            return false;
        }

        // If they can create but aren't the host, the permission comes from admin bypass
        return $this->canCreateBulletin();
    }

    #[Computed]
    public function canViewBoard(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        // Delegate to GameBulletinPolicy::viewBoard — the policy is registered
        // on GameBulletin (not Game) so we resolve it explicitly.
        return app(GameBulletinPolicy::class)->viewBoard($user, $this->game);
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
            userId: (string) Auth::id(),
            content: $sanitizedContent ?? '',
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
        $host = authenticatedUser();
        $notification = new BulletinPosted($this->game, $host, $bulletin);

        $participants = $this->game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->where('user_id', '!=', $host->id)
            ->with('user')
            ->get();

        // Action center cache is already invalidated by GameBulletinObserver::created(),
        // so we only need to dispatch notifications here.

        // Send push notifications
        $participantUsers = $participants->pluck('user')->filter();

        foreach ($participantUsers as $participant) {
            if (! ($participant instanceof User)) {
                continue;
            }
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

    public function render(): View
    {
        return view('livewire.games.game-bulletin-board', [
            'canViewBoard' => $this->canViewBoard(),
            'canCreateBulletin' => $this->canCreateBulletin(),
            'isElevatedAccess' => $this->isElevatedAccess(),
            'bulletins' => $this->bulletins(),
        ]);
    }
}
