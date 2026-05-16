<?php

namespace App\Livewire\Games;

use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\Review;
use App\Services\ShortLinkService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('components.public-layout')]
class PublicGameDetail extends Component
{
    public Game $game;

    #[Locked]
    public ?string $validatedShareToken = null;

    /** @var int|null Validated short link ID from ph_link_id cookie */
    #[Locked]
    public ?int $validatedShortLinkId = null;

    #[Locked]
    public ?string $validatedShortLinkCode = null;

    public function mount(string $id): void
    {
        $game = Game::findOrFail($id);
        $this->authorize('view', $game);
        $this->game = $game;

        // Capture valid share token on initial page load
        if ($game->hasValidShareToken()) {
            $this->validatedShareToken = request()->query('share');
        }

        // Detect short link arrival via ph_link_id cookie.
        // Reject malformed values before casting to prevent wrong-link attribution.
        $linkId = request()->cookie('ph_link_id');
        if (is_string($linkId) && ctype_digit($linkId)) {
            $link = app(ShortLinkService::class)->resolveLinkById((int) $linkId);
            if ($link !== null
                && $link->linkable_type === Game::class
                && (string) $link->linkable_id === (string) $game->getKey()) {
                $this->validatedShortLinkId = $link->id;
                $this->validatedShortLinkCode = $link->code;
            }
        }

        // Set share_intent cookie for guests visiting via share link
        if (Auth::guest() && $this->validatedShareToken !== null) {
            Cookie::queue('share_intent', json_encode([
                'entity_type' => 'game',
                'entity_id' => $game->id,
                'share_token' => $this->validatedShareToken,
            ]), 24 * 60);
        }

        // Set short_link_intent cookie for guests arriving via short link.
        // Only short_link_id is needed — entity identity is derived server-side.
        if (Auth::guest() && $this->validatedShortLinkId !== null) {
            Cookie::queue('short_link_intent', json_encode([
                'short_link_id' => $this->validatedShortLinkId,
            ]), 24 * 60);
        }
    }

    #[Computed]
    public function isOwner(): bool
    {
        return ($id = Auth::id()) && $this->game->owner_id === $id;
    }

    #[Computed]
    public function approvedParticipantsCount(): int
    {
        return $this->game->participants
            ->where('status', ParticipantStatus::Approved->value)
            ->count();
    }

    #[Computed]
    public function reviews()
    {
        if (Auth::guest()) {
            return collect();
        }

        return Review::where('reviewable_type', Game::class)
            ->where('reviewable_id', $this->game->id)->published()
            ->with('reviewer')->latest()->limit(10)->get();
    }

    #[Computed]
    public function hasShareLink(): bool
    {
        // Note: validatedShortLinkId is #[Locked] and set only on mount.
        // If the link is revoked within this Livewire session, this still
        // returns true until a full page refresh. This is a deliberate
        // tradeoff for UI consistency (hasShareLink/shareLinkUrl stay in sync).
        return $this->game->share_token !== null || $this->validatedShortLinkId !== null;
    }

    #[Computed]
    public function shareLinkUrl(): ?string
    {
        // Use the code cached during mount — avoids a DB lookup that can return null
        // if the link is revoked between mount and render, keeping hasShareLink/shareLinkUrl consistent.
        if ($this->validatedShortLinkCode !== null) {
            return url('/link/' . $this->validatedShortLinkCode);
        }

        if ($this->game->share_token === null) {
            return null;
        }

        return route('games.detail', $this->game->id) . '?share=' . $this->game->share_token;
    }

    public function render()
    {
        $relations = [
            'owner', 'campaign', 'gameSystem.categories', 'gameSystem.mechanics',
            'gameSystem.publishers', 'gameSystem.baseGame', 'gameSystem.expansions',
            'linkedLocation',
        ];
        if (Auth::check()) {
            $relations[] = 'participants.user';
        } else {
            $relations[] = 'participants';
        }
        $this->game->load($relations);

        seo()->for($this->game);

        return view('livewire.games.public-game-detail', [
            'game' => $this->game,
            'isOwner' => $this->isOwner(),
            'isGuest' => Auth::guest(),
            'reviews' => $this->reviews(),
            'approvedParticipantsCount' => $this->approvedParticipantsCount(),
            'hasShareLink' => $this->hasShareLink(),
            'shareLinkUrl' => $this->shareLinkUrl(),
        ]);
    }
}
