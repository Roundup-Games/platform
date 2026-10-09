<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Share-token gate shared by Game and Campaign (the only entities with
 * `share_token` / `share_token_expires_at` columns).
 *
 * Used by the Game/Campaign policies' view ability and by the detail
 * components to admit anonymous visitors carrying a valid share link.
 * Validation is timing-safe by construction (hash_equals).
 *
 * @phpstan-require-extends Model
 */
trait HasShareToken
{
    /**
     * Check whether the current request carries a valid share token for this
     * entity. Validates that: the query param 'share' matches the stored
     * token AND the token hasn't expired.
     */
    public function hasValidShareToken(?string $token = null): bool
    {
        $token = $token ?? request()->query('share');

        if (! $token || ! $this->share_token) {
            return false;
        }

        if ($this->share_token_expires_at !== null && $this->share_token_expires_at->isPast()) {
            return false;
        }

        return hash_equals($this->share_token, $token);
    }
}
