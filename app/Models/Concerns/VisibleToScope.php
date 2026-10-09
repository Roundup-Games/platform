<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Services\SocialGraphService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The single definition of the app's connection-aware visibility rule for
 * Game and Campaign.
 *
 * Guests see public only. Authenticated users see public + protected items
 * owned by their connections (friends, teammates) or where they are a
 * participant. Private items are never included in listings.
 *
 * This is the most security-sensitive read rule in the platform — discovery,
 * dashboards, counts, and listings MUST resolve visibility through this scope
 * (or a query composing it). Never re-implement the clause inline; a second
 * copy silently diverges from the first and becomes a data-exposure bug.
 *
 * @template TModel of Model
 *
 * @phpstan-require-extends Model
 */
trait VisibleToScope
{
    /**
     * Scope to entities visible to a given user (or guest).
     *
     * Requires the `visibility` + `owner_id` columns and a `participants`
     * relationship — both Game and Campaign provide them.
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeVisibleTo(Builder $query, ?User $viewer = null): Builder
    {
        if ($viewer === null) {
            return $query->where('visibility', 'public');
        }

        $allowedOwnerIds = app(SocialGraphService::class)
            ->getAllowedOwnerIdsForProtectedContent($viewer);

        return $query->where(function ($q) use ($allowedOwnerIds, $viewer) {
            $q->where('visibility', 'public')
                ->orWhere(function ($q) use ($allowedOwnerIds, $viewer) {
                    $q->where('visibility', 'protected')
                        ->where(function ($q) use ($allowedOwnerIds, $viewer) {
                            $q->whereIn('owner_id', $allowedOwnerIds)
                                ->orWhereHas('participants', fn ($pq) => $pq->whereBelongsTo($viewer));
                        });
                });
        });
    }
}
