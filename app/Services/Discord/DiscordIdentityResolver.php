<?php

namespace App\Services\Discord;

use App\Enums\OAuthProvider;
use App\Http\Controllers\Auth\OAuthController;
use App\Models\LinkedAccount;
use App\Models\User;

/**
 * Resolves a Discord member snowflake to the roundup {@see User} who owns the
 * linked Discord account (M057/S03/T02).
 *
 * The identity bridge for button RSVPs: a clicker is identified by their
 * Discord member snowflake (`interaction.member.user.id`), which maps to
 * exactly one {@see LinkedAccount} row where `provider = discord` and
 * `provider_user_id = <snowflake>`. That linked account's owning user is the
 * roundup identity the RSVP writes under — the SAME participant pipeline as a
 * web RSVP, one source of truth.
 *
 * Mirrors the {@see OAuthController} lookup
 * precedent (`LinkedAccount::where('provider', ...)->where('provider_user_id',
 * ...)->first()`) rather than a `whereHas` on User, so the resolved object is
 * the linked-account row's owning user directly.
 *
 * An unlinked clicker (no matching row, or a row whose user was deleted)
 * resolves to null — the controller forks that branch into an ephemeral
 * deep-link to RSVP on roundup web rather than writing a participant row.
 */
class DiscordIdentityResolver
{
    /**
     * Resolve a Discord member snowflake to its owning roundup User.
     *
     * @param  string  $snowflake  The interaction member's Discord user id
     *                             (numeric string, 17–20 digits).
     * @return User|null The roundup user whose Discord account is linked to
     *                   this snowflake, or null when the clicker is unlinked
     *                   (or the linked account's user no longer exists).
     */
    public function resolveBySnowflake(string $snowflake): ?User
    {
        // An empty/whitespace snowflake never matches — short-circuit before
        // the query so a malformed interaction can't match a (theoretically
        // empty) provider_user_id row.
        if (trim($snowflake) === '') {
            return null;
        }

        $linkedAccount = LinkedAccount::where('provider', OAuthProvider::Discord->value)
            ->where('provider_user_id', $snowflake)
            ->first();

        // belongsTo returns null if user_id is null or the user was deleted —
        // both cases fork to the unlinked deep-link branch in the controller.
        return $linkedAccount?->user;
    }
}
