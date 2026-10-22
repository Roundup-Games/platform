<?php

namespace App\Services\Badges;

use Illuminate\Support\Facades\Lang;

/**
 * Single source for badge display copy: the registry name key and tier
 * label resolution chains, shared by the badge chip, the awarded
 * notification, and the gallery tier ladder.
 *
 * The policy: a lang-known key wins; an unknown/unsynced/future key
 * degrades to the humanized key ("some_badge" → "Some badge") instead
 * of a raw missing-translation string. There is deliberately no second
 * convention — change the fallback here, and every surface changes.
 */
final class BadgeCopy
{
    /** Localized badge name from the registry name key, humanized key fallback. */
    public static function name(?string $nameKey, string $key): string
    {
        if ($nameKey !== null && $nameKey !== '' && Lang::has($nameKey)) {
            return (string) __($nameKey);
        }

        return str_replace('_', ' ', ucfirst($key));
    }

    /** Localized tier label, or null for tierless badges; humanized tier key fallback. */
    public static function tierLabel(?string $tier): ?string
    {
        if ($tier === null) {
            return null;
        }

        $key = "badges.content_badge_tier_{$tier}";

        return Lang::has($key)
            ? (string) __($key)
            : str_replace('_', ' ', ucfirst($tier));
    }
}
