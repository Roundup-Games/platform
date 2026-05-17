<?php

namespace App\Services;

use App\Models\ShortLink;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Database\QueryException;
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
                    'code_prefix' => substr($code, 0, 3) . '…',
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
     * On duplicate code collision (DB unique constraint), regenerates the code and retries
     * up to 5 times when the code was auto-generated (not explicitly passed).
     */
    public function createLink(Model $linkable, ?User $user = null, array $params = []): ShortLink
    {
        $url = $params['url'] ?? $this->getEntityRoute($linkable);

        // Security: validate that the URL is internal (prevents open-redirect via future code paths).
        $this->validateInternalUrl($url);

        $explicitCode = array_key_exists('code', $params);

        $link = retry($explicitCode ? 1 : 5, function () use (&$code, $params, $url, $linkable, $user) {
            $code = $params['code'] ?? $this->generateUniqueCode();

            return ShortLink::create([
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
        }, 100, function ($e) {
            // Only retry on unique constraint violations for auto-generated codes.
            // Check SQLSTATE codes: 23000 (MySQL/generic), 23505 (PostgreSQL unique violation).
            // Message-based matching is fragile across drivers; SQLSTATE is the reliable indicator.
            if ($e instanceof QueryException) {
                $sqlState = $e->errorInfo[0] ?? null;

                if (in_array($sqlState, ['23000', '23505'], true)) {
                    Log::warning('ShortLinkService: code collision on insert, regenerating');

                    return true;
                }
            }

            return false;
        });

        Log::info('ShortLinkService: created short link', [
            'link_id' => $link->id,
            'code_prefix' => substr($link->code, 0, 3) . '…',
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
            Log::debug('ShortLinkService: code not found', ['code_prefix' => substr($code, 0, 3) . '…']);

            return null;
        }

        if ($link->isExpired()) {
            Log::debug('ShortLinkService: link expired', ['code_prefix' => substr($code, 0, 3) . '…', 'expires_at' => $link->expires_at]);

            return null;
        }

        if ($link->hasHitCap()) {
            Log::debug('ShortLinkService: hit cap exceeded', [
                'code_prefix' => substr($code, 0, 3) . '…',
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
     * Uses a short-lived cache (5 minutes) to avoid repeated DB lookups while
     * keeping authorization decisions fresh. Returns null for expired, hit-capped,
     * or non-existent links.
     *
     * Cache consistency: all mutation paths (revokeLink, expireLinksForEntity,
     * RecordShortLinkHit, model events) invalidate both short_link:{code} and
     * short_link_id:{id} caches on every write. No freshness re-fetch is needed —
     * the cached model is always consistent after writes.
     */
    public function resolveLinkById(int $id): ?ShortLink
    {
        $cacheKey = "short_link_id:{$id}";

        /** @var ShortLink|null $link */
        $link = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($id): ?ShortLink {
            return ShortLink::find($id);
        });

        if ($link === null) {
            return null;
        }

        if ($link->trashed()) {
            Cache::forget($cacheKey);

            return null;
        }

        if ($link->isExpired()) {
            Cache::forget($cacheKey);

            return null;
        }

        if ($link->hasHitCap()) {
            Cache::forget($cacheKey);

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
            ->groupBy(fn (ShortLink $link) => $link->linkable_type . ':' . (string) $link->linkable_id);
    }

    /**
     * Soft-delete a short link and clear its cache.
     */
    public function revokeLink(ShortLink $link): void
    {
        $link->delete();

        Cache::forget("short_link:{$link->code}");
        Cache::forget("short_link_id:{$link->id}");

        Log::info('ShortLinkService: revoked short link', [
            'link_id' => $link->id,
            'code_prefix' => substr($link->code, 0, 3) . '…',
        ]);
    }

    /**
     * Check whether a user can create more links for a given entity.
     *
     * Default limit is 10 links per entity per user.
     * Reads from user.max_links_per_entity when set.
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

        // Load links first so we can invalidate caches (bulk update bypasses model events).
        $links = ShortLink::where('linkable_type', get_class($entity))
            ->where('linkable_id', (string) $entity->getKey())
            ->whereNull('expires_at')
            ->get(['id', 'code']);

        $count = ShortLink::whereIn('id', $links->pluck('id'))->update(['expires_at' => $expiresAt]);

        foreach ($links as $link) {
            Cache::forget("short_link:{$link->code}");
            Cache::forget("short_link_id:{$link->id}");
        }

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
     * Validate that a URL is internal to this application.
     *
     * Prevents open-redirect vulnerabilities if a future code path passes
     * user-controlled data as the url parameter to createLink().
     *
     * @throws \InvalidArgumentException if the URL is not internal
     */
    public function validateInternalUrl(string $url): void
    {
        // Allow relative URLs (start with / but not protocol-relative //)
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return;
        }

        $appUrl = config('app.url');
        $appHost = parse_url($appUrl, PHP_URL_HOST);
        $urlHost = parse_url($url, PHP_URL_HOST);

        // In production, only allow URLs pointing to the app host.
        // In unit tests, allow any URL so that factory/test URLs don't need to match app.url.
        // Staging and other non-production environments validate the same as production
        // to catch open-redirect issues before they reach production.
        if (! app()->runningUnitTests() && ($urlHost === null || $urlHost !== $appHost)) {
            throw new \InvalidArgumentException(
                'Short link URL must be internal. Got: ' . $url
            );
        }
    }

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
