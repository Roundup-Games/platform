<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * The badge-shelf "feature this badge" action, shared by the profile
 * components that render a shelf (AuthenticatedProfile, PublicProfile).
 *
 * One implementation so the two surfaces can never drift: owner-only
 * by construction (the hold is resolved through the VIEWER's badges
 * relation — anyone else's id simply misses), revoked holds never
 * feature, and the max-3 shelf rule reports through the shared
 * 'featuredLimit' error bag key. The concurrent-toggle race past the
 * max is the documented accepted race (display sorts featured-first,
 * the shelf self-corrects on the next render).
 */
trait FeaturesBadges
{
    /** Spec §2.4: a user features at most this many badges on the shelf. */
    public const MAX_FEATURED_BADGES = 3;

    public function toggleFeatured(string $badgeUserId): void
    {
        $viewer = Auth::user();

        // Guests never see toggles; a crafted request just no-ops. The
        // isUuid guard is part of that promise: badge_user's PK is a
        // native uuid, so a malformed id would otherwise surface as a
        // Postgres 22P02 error, not a quiet miss.
        if ($viewer === null || ! Str::isUuid($badgeUserId)) {
            return;
        }

        $hold = $viewer->badges()->whereKey($badgeUserId)->first();

        if ($hold === null || $hold->revoked_at !== null) {
            return;
        }

        if (! $hold->is_featured) {
            // Lapsed holds grey out everywhere the shelf matters (the
            // GM-directory chip drops them), so they never newly
            // feature — the slot goes to a badge that actually
            // displays. Un-featuring below stays open for any stale
            // featured row, so nothing can get stuck.
            if ($hold->lapsed_at !== null) {
                return;
            }

            $featuredCount = $viewer->badges()
                ->where('is_featured', true)
                ->whereNull('revoked_at')
                ->count();

            if ($featuredCount >= self::MAX_FEATURED_BADGES) {
                $this->addError('featuredLimit', __('badges.content_badge_shelf_limit_reached'));

                return;
            }

            $hold->update(['is_featured' => true]);

            return;
        }

        $hold->update(['is_featured' => false]);
    }
}
