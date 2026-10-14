<?php

namespace App\Models\Builders;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 *
 * Eloquent builder for HasPlatformUuid models: id lookups validate the id
 * shape before it reaches Postgres.
 *
 * A value that is not a uuid cannot match a uuid column — but Postgres
 * rejects it at bind time (SQLSTATE 22P02, "invalid input syntax for type
 * uuid") before the query executes, so a malformed id from a truncated
 * share link or an automated probe becomes a 500 instead of a miss.
 * find()/findOrFail() therefore treat non-uuid ids as "not found": null
 * from find(), ModelNotFoundException (the usual 404) from findOrFail() —
 * identical to what the same URL shape already returns for a well-formed
 * id that does not exist.
 *
 * Non-uuid values inside an array of ids are dropped, so findOrFail()
 * reports them as missing through its usual count check.
 *
 * Only find*() lookups are guarded; hand-written where('id', ...) clauses
 * keep their raw behaviour. The HTTP backstop in bootstrap/app.php renders
 * any 22P02 that slips past the funnel guards as a 404 instead of a 500.
 */
class UuidBuilder extends Builder
{
    public function find($id, $columns = ['*'])
    {
        if ($id instanceof Arrayable) {
            $id = $id->toArray();
        }

        if (is_array($id)) {
            $id = array_values(array_filter(
                $id,
                fn (mixed $value): bool => is_string($value) && Str::isUuid($value),
            ));
        } elseif (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }

        return parent::find($id, $columns);
    }
}
