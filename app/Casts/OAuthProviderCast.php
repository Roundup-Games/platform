<?php

namespace App\Casts;

use App\Enums\OAuthProvider;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Defensive cast for LinkedAccount.provider (and similar OAuth-provider columns).
 *
 * Laravel's native backed-enum cast calls {@see OAuthProvider::from()}, which
 * throws ValueError on unknown values. That is correct for a column whose
 * value set is fully app-owned, but LinkedAccount rows persist for the user's
 * lifetime: if a provider case is ever removed from the enum (e.g. Discord is
 * sunset), existing rows would take down every page that reads the model
 * until a manual data migration runs. This cast degrades to null + a warning
 * log instead, so the page keeps rendering while the inconsistency is
 * observable in the logs.
 *
 * @implements CastsAttributes<OAuthProvider|null, mixed>
 */
class OAuthProviderCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?OAuthProvider
    {
        // PDO may yield string|int|nullable for backed-enum columns; narrow
        // before coercion so Larastan can reason about the cast.
        if ($value === null) {
            return null;
        }

        $raw = is_string($value) || is_int($value) ? (string) $value : null;
        if ($raw === null || $raw === '') {
            return null;
        }

        $provider = OAuthProvider::tryFrom($raw);

        if ($provider === null) {
            Log::warning('linked_accounts.unknown_provider', [
                'column' => $key,
                'value' => $raw,
                'model_id' => $model->getKey(),
            ]);
        }

        return $provider;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof OAuthProvider) {
            return $value->value;
        }

        // Mirror get()'s narrowing: PDO/legacy callers may hand us non-
        // scalar values (arrays/objects) that would TypeError inside
        // tryFrom(). Coerce scalars to their string form and tryFrom that;
        // for genuinely non-scalar values (a bug at the caller), return
        // the value cast to string so the read-side warning log fires
        // rather than a write-side throw (the same defensive posture as
        // get()). The column is non-nullable; returning null here would
        // NULL the column, which is worse than passing through a weird
        // string.
        if (! is_string($value) && ! is_int($value)) {
            return is_scalar($value) ? (string) $value : '';
        }

        // Raw string from factories, fixtures, or legacy rows: coerce to the
        // backed value when it matches a case, otherwise pass through so the
        // read-side warning log fires rather than a write-side throw.
        $matched = OAuthProvider::tryFrom((string) $value);

        return $matched !== null ? $matched->value : (string) $value;
    }
}
