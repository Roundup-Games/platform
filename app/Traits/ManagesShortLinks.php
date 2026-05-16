<?php

namespace App\Traits;

use App\Enums\JoinSource;
use App\Models\ShortLink;
use App\Services\ShortLinkService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;

/**
 * Shared short link management for GameDetail and CampaignDetail Livewire components.
 *
 * Provides detectShortLink(), createShortLink(), revokeShortLink(), getShortLinks(),
 * hasShareLink(), shareLinkUrl(), isShortLinkStillValid(), isShareTokenStillValid(),
 * and buildShareJoinBaseData().
 *
 * Consuming components must set:
 *   - $this->validatedShortLinkId (int|null)
 *   - $this->validatedShareToken (string|null)
 *
 * And implement getEntity() returning the Game|Campaign model.
 */
trait ManagesShortLinks
{
    use ValidatesShortLinkCookie;

    // ── Short Link Detection ─────────────────────────────

    /**
     * Detect and validate a short link from the ph_link_id cookie.
     * Sets validatedShortLinkId if the link belongs to this entity.
     */
    private function detectShortLink(): void
    {
        $link = $this->resolveShortLinkFromCookie();

        if ($link === null) {
            return;
        }

        $entity = $this->getEntity();

        if ($link->linkable_type === get_class($entity)
            && (string) $link->linkable_id === (string) $entity->getKey()) {
            $this->validatedShortLinkId = $link->id;
        }
    }

    // ── Short Link Computed Properties ───────────────────

    #[Computed]
    public function getShortLinks()
    {
        return app(ShortLinkService::class)->getLinksForEntity($this->getEntity());
    }

    #[Computed]
    public function hasShareLink(): bool
    {
        $entity = $this->getEntity();

        return $entity->share_token !== null || $this->getShortLinks()->isNotEmpty();
    }

    #[Computed]
    public function shareLinkUrl(): ?string
    {
        $shortLinks = $this->getShortLinks();
        if ($shortLinks->isNotEmpty()) {
            return url('/link/' . $shortLinks->first()->code);
        }

        $entity = $this->getEntity();
        if ($entity->share_token === null) {
            return null;
        }

        $route = $entity instanceof \App\Models\Game
            ? 'games.show'
            : 'campaigns.show';

        return route($route, $entity->getKey()) . '?share=' . $entity->share_token;
    }

    // ── Short Link Actions ───────────────────────────────

    public function createShortLink(?string $label = null): void
    {
        $viewer = Auth::user();
        $entity = $this->getEntity();

        if (! $viewer || $entity->owner_id !== $viewer->id) {
            session()->flash('error', __('common.error_not_authorized'));
            return;
        }

        $service = app(ShortLinkService::class);

        if (! $service->canCreateMore($entity, $viewer)) {
            session()->flash('error', __('common.error_max_links_reached'));
            return;
        }

        $link = $service->createLink($entity, $viewer, [
            'label' => $label,
            'purpose' => 'share',
        ]);

        Log::info('Short link created', [
            'entity_type' => get_class($entity),
            'entity_id' => $entity->getKey(),
            'link_id' => $link->id,
            'code_prefix' => substr($link->code, 0, 3) . '…',
            'user_id' => $viewer->id,
        ]);

        unset($this->getShortLinks, $this->hasShareLink, $this->shareLinkUrl);
        session()->flash('success', __('common.flash_share_link_generated'));
    }

    public function revokeShortLink(int $linkId): void
    {
        $viewer = Auth::user();
        $entity = $this->getEntity();

        if (! $viewer || $entity->owner_id !== $viewer->id) {
            session()->flash('error', __('common.error_not_authorized'));
            return;
        }

        $link = ShortLink::where('id', $linkId)
            ->where('linkable_type', get_class($entity))
            ->where('linkable_id', $entity->getKey())
            ->firstOrFail();

        app(ShortLinkService::class)->revokeLink($link);

        Log::info('Short link revoked', [
            'entity_type' => get_class($entity),
            'entity_id' => $entity->getKey(),
            'link_id' => $linkId,
            'code_prefix' => substr($link->code, 0, 3) . '…',
            'user_id' => $viewer->id,
        ]);

        unset($this->getShortLinks, $this->hasShareLink, $this->shareLinkUrl);
        session()->flash('success', __('common.flash_share_link_revoked'));
    }

    // ── Join Validation Helpers ──────────────────────────

    /**
     * Check whether the validated short link is still live (not revoked/expired/capped).
     * Uses the already-loaded short links collection to avoid an extra DB query.
     */
    protected function isShortLinkStillValid(): bool
    {
        if ($this->validatedShortLinkId === null) {
            return false;
        }

        $shortLinks = $this->getShortLinks();
        $link = $shortLinks->first(fn ($l) => $l->id === $this->validatedShortLinkId);

        return $link !== null
            && ! $link->isExpired()
            && ! $link->hasHitCap();
    }

    /**
     * Check if the validated share token is still valid.
     */
    protected function isShareTokenStillValid(): bool
    {
        $entity = $this->getEntity();

        return $this->validatedShareToken !== null
            && $this->validatedShareToken === $entity->share_token
            && ($entity->share_token_expires_at === null || ! $entity->share_token_expires_at->isPast());
    }

    /**
     * Build the base participant data array for share-link joins.
     *
     * @return array<string, mixed>
     */
    protected function buildShareJoinBaseData(Model $entity, string $viewerId): array
    {
        $fkColumn = $entity instanceof \App\Models\Game ? 'game_id' : 'campaign_id';

        $data = [
            $fkColumn => $entity->getKey(),
            'user_id' => $viewerId,
            'role' => 'player',
            'join_source' => $this->validatedShortLinkId !== null
                ? JoinSource::ShortLink->value
                : JoinSource::ShareLink->value,
        ];

        if ($this->validatedShortLinkId !== null) {
            $data['short_link_id'] = $this->validatedShortLinkId;
        }

        return $data;
    }
}
