<?php

namespace App\Traits;

use App\Models\ShortLink;
use App\Services\ShortLinkService;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared short link cookie validation for policies and Livewire components.
 *
 * Reads the encrypted ph_link_id cookie, resolves the ShortLink via
 * ShortLinkService (with cache + trashed/expiry checks), and validates
 * that it belongs to the target entity.
 */
trait ValidatesShortLinkCookie
{
    /**
     * Check if the current request carries a valid short link for the given entity.
     */
    protected function isValidShortLinkForEntity(Model $entity): bool
    {
        $linkId = request()->cookie('ph_link_id');

        if ($linkId === null || ! is_string($linkId) || ! ctype_digit($linkId)) {
            return false;
        }

        $link = app(ShortLinkService::class)->resolveLinkById((int) $linkId);

        if ($link === null) {
            return false;
        }

        $status = $entity->status ?? null;
        if ($status !== null) {
            $statusValue = $status instanceof \BackedEnum ? $status->value : (string) $status;
            if (in_array($statusValue, ['completed', 'cancelled', 'canceled'], true)) {
                return false;
            }
        }

        $entityKey = $entity->getKey();
        assert(is_int($entityKey) || is_string($entityKey));

        return $link->linkable_type === get_class($entity)
            && (string) $link->linkable_id === (string) $entityKey;
    }

    /**
     * Resolve a short link from the ph_link_id cookie.
     */
    protected function resolveShortLinkFromCookie(): ?ShortLink
    {
        $linkId = request()->cookie('ph_link_id');

        if (! is_string($linkId) || ! ctype_digit($linkId)) {
            return null;
        }

        return app(ShortLinkService::class)->resolveLinkById((int) $linkId);
    }
}
