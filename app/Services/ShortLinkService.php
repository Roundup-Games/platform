<?php

namespace App\Services;

use App\Models\ShortLink;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\User;

class ShortLinkService
{
    /**
     * Generate a unique short link code.
     *
     * Uses Str::random with a uniqueness check against the database.
     * Throws after 10 failed attempts (defensive — extremely unlikely).
     */
    public function generateUniqueCode(int $length = 7): string
    {
        $attempts = 0;

        do {
            $code = Str::random($length);
            $attempts++;

            if (! ShortLink::where('code', $code)->exists()) {
                Log::debug('ShortLinkService: generated unique code', [
                    'code' => $code,
                    'attempts' => $attempts,
                ]);

                return $code;
            }
        } while ($attempts < 10);

        throw new \RuntimeException("Unable to generate a unique short link code after {$attempts} attempts.");
    }

    /**
     * Create a new short link for a linkable entity.
     *
     * Auto-generates a unique code and computes the URL from the entity's public route.
     */
    public function createLink(Model $linkable, ?User $user = null, array $params = []): ShortLink
    {
        $code = $params['code'] ?? $this->generateUniqueCode();
        $url = $params['url'] ?? $this->getEntityRoute($linkable);

        $link = ShortLink::create([
            'code' => $code,
            'url' => $url,
            'linkable_type' => get_class($linkable),
            'linkable_id' => (string) $linkable->getKey(),
            'user_id' => $user?->id,
            'label' => $params['label'] ?? null,
            'purpose' => $params['purpose'] ?? null,
            'expires_at' => $params['expires_at'] ?? null,
            'max_hits' => $params['max_hits'] ?? null,
        ]);

        Log::info('ShortLinkService: created short link', [
            'link_id' => $link->id,
            'code' => $link->code,
            'linkable_type' => get_class($linkable),
            'linkable_id' => $linkable->getKey(),
            'user_id' => $user?->id,
        ]);

        return $link;
    }

    /**
     * Resolve a short link code to its ShortLink model.
     *
     * Returns null for expired, hit-capped, or non-existent codes.
     */
    public function resolveLink(string $code): ?ShortLink
    {
        $link = ShortLink::where('code', $code)->first();

        if ($link === null) {
            Log::debug('ShortLinkService: code not found', ['code' => $code]);

            return null;
        }

        if ($link->isExpired()) {
            Log::debug('ShortLinkService: link expired', ['code' => $code, 'expires_at' => $link->expires_at]);

            return null;
        }

        if ($link->hasHitCap()) {
            Log::debug('ShortLinkService: hit cap exceeded', [
                'code' => $code,
                'hit_count' => $link->hit_count,
                'max_hits' => $link->max_hits,
            ]);

            return null;
        }

        return $link;
    }

    /**
     * Resolve a short link ID to its ShortLink model.
     *
     * Returns null for expired, hit-capped, or non-existent links.
     */
    public function resolveLinkById(int $id): ?ShortLink
    {
        $link = ShortLink::find($id);

        if ($link === null) {
            return null;
        }

        if ($link->isExpired()) {
            return null;
        }

        if ($link->hasHitCap()) {
            return null;
        }

        return $link;
    }

    /**
     * Get all non-deleted short links for an entity, ordered newest first.
     */
    public function getLinksForEntity(Model $linkable): Collection
    {
        return ShortLink::where('linkable_type', get_class($linkable))
            ->where('linkable_id', (string) $linkable->getKey())
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Get all short links created by a user, grouped by linkable entity.
     */
    public function getLinksForUser(User $user): Collection
    {
        return ShortLink::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy(fn (ShortLink $link) => $link->linkable_type . ':' . $link->linkable_id);
    }

    /**
     * Soft-delete a short link and clear its cache.
     */
    public function revokeLink(ShortLink $link): void
    {
        $link->delete();

        Log::info('ShortLinkService: revoked short link', [
            'link_id' => $link->id,
            'code' => $link->code,
        ]);
    }

    /**
     * Check whether a user can create more links for a given entity.
     *
     * Default limit is 10 links per entity per user.
     * Will read from user.max_links_per_entity when that column is added (S03).
     */
    public function canCreateMore(Model $linkable, User $user): bool
    {
        $maxLinks = $user->max_links_per_entity ?? 10;

        $currentCount = ShortLink::where('linkable_type', get_class($linkable))
            ->where('linkable_id', (string) $linkable->getKey())
            ->where('user_id', $user->id)
            ->count();

        return $currentCount < $maxLinks;
    }

    /**
     * Set expires_at on all active short links for a completed entity.
     *
     * Called when a Game/Campaign/Event transitions to a terminal status.
     * Grace period allows GMs to view analytics for N days after completion.
     *
     * @return int Number of links marked for expiry
     */
    public function expireLinksForEntity(Model $entity, int $graceDays = 7): int
    {
        $expiresAt = now()->addDays($graceDays);

        $count = ShortLink::where('linkable_type', get_class($entity))
            ->where('linkable_id', (string) $entity->getKey())
            ->whereNull('expires_at')
            ->update(['expires_at' => $expiresAt]);

        if ($count > 0) {
            Log::info('ShortLinkService: expired links for entity', [
                'linkable_type' => get_class($entity),
                'linkable_id' => $entity->getKey(),
                'count' => $count,
                'expires_at' => $expiresAt->toDateTimeString(),
                'grace_days' => $graceDays,
            ]);
        }

        return $count;
    }

    /**
     * Get the public-facing route URL for a linkable entity.
     *
     * Maps entity class to the correct public route name and parameter.
     */
    public function getEntityRoute(Model $linkable): string
    {
        return match (get_class($linkable)) {
            \App\Models\Game::class => route('games.detail', $linkable->getKey()),
            \App\Models\Campaign::class => route('campaigns.detail', $linkable->getKey()),
            \App\Models\Event::class => route('events.detail', $linkable->slug),
            \App\Models\Team::class => route('teams.detail', $linkable->slug),
            default => throw new \InvalidArgumentException('Unsupported linkable type: ' . get_class($linkable)),
        };
    }
}
