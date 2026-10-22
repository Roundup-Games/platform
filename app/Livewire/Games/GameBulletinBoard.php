<?php

namespace App\Livewire\Games;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\GameBulletin;
use App\Policies\GameBulletinPolicy;
use App\Services\ScopedRoleService;
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
            ->orderByDesc('is_pinned')
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

        // Since D219, approved participants can legitimately create on the
        // completed one-shot epilogue board — creation rights alone no longer
        // imply admin bypass. The badge marks global-admin authority only.
        return $this->canCreateBulletin()
            && app(ScopedRoleService::class)->isGlobalAdmin($user);
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

        // The sanitizer can strip a technically-non-empty string to
        // nothing (control/bidi/zero-width only): validating the RAW
        // value would let an empty bulletin through to every approved
        // participant's fan-out. Gate on what will actually be stored.
        if (trim((string) $sanitizedContent) === '') {
            $this->addError('content', __('games.error_bulletin_empty'));

            return;
        }

        // The scheduled branch expires bulletins at the game's date_time.
        // The non-scheduled branch is only reachable for completed one-shots
        // (the policy denies create elsewhere): epilogue replies get the
        // ~30-day epilogue window instead, because the game's date_time is
        // already in the past and would make replies instantly invisible.
        $expiresAt = $this->game->status === GameStatus::Scheduled
            ? $this->game->date_time?->toDateTimeString()
            : now()->addDays(30)->toDateTimeString();

        $bulletin = GameBulletin::postBy(
            gameId: $this->game->id,
            userId: (string) Auth::id(),
            content: $sanitizedContent ?? '',
            expiresAt: $expiresAt,
        );

        Log::info('Game bulletin created', [
            'game_id' => $this->game->id,
            'bulletin_id' => $bulletin->id,
            'user_id' => Auth::id(),
            'content_length' => Str::length($this->content),
        ]);

        // Fan-out (action-center invalidation, BulletinPosted cascade to
        // approved participants, Discord session-thread teaser push) is
        // centralized in GameBulletinObserver::created() so it runs on every
        // creation path, not just this component.

        $this->content = '';
        unset($this->bulletins);

        session()->flash('success', __('games.flash_bulletin_created'));
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
