<?php

namespace App\Services;

use App\Enums\VibeFlag;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Stateless service for resolving user preference logic.
 *
 * Extracts the preference resolution algorithms from the User model,
 * making them testable and reusable without User model bloat.
 */
class UserPreferenceResolver
{
    /**
     * Resolved game-system preferences including base/expansion implications.
     *
     * Rules:
     *  - Favorited base games imply all their expansions as 'implied_favorites'.
     *  - If a system is both favorited (or implied) AND explicitly avoided,
     *    the explicit avoid wins.
     *  - Handles circular safety: a system can be both a base (has expansions)
     *    and an expansion (has base_game_id).
     *
     * @return array{favorites: Collection<int, GameSystem>, avoided: Collection<int, GameSystem>, implied_favorites: Collection<int, GameSystem>}
     */
    public function resolvedGameSystemPreferences(User $user): array
    {
        $favorites = $user->favoriteGameSystems()->get();
        $avoided = $user->avoidedGameSystems()->get();
        $avoidedIds = $avoided->pluck('id')->flip();

        // Collect implied favorites from expansions of favorited base games
        // Only include expansions that are NOT explicitly avoided (avoid wins)
        $impliedIds = new Collection;
        foreach ($favorites as $system) {
            foreach ($system->expansions as $expansion) {
                if (! $avoidedIds->has($expansion->id)) {
                    $impliedIds->put($expansion->id, $expansion);
                }
            }
        }

        $impliedFavorites = $impliedIds;

        // Remove any favorites that are explicitly avoided
        $resolvedFavorites = $favorites->reject(
            fn (GameSystem $sys) => $avoidedIds->has($sys->id),
        );

        // Collect implied avoids from expansions of avoided base games
        $impliedAvoidIds = collect();
        foreach ($avoided as $system) {
            foreach ($system->expansions as $expansion) {
                $impliedAvoidIds->put($expansion->id, $expansion);
            }
        }

        // Merge explicit avoids with implied avoids (implied only if not explicitly favorited)
        $allAvoided = $avoided->keyBy('id');
        foreach ($impliedAvoidIds as $id => $expansion) {
            if (! $resolvedFavorites->keyBy('id')->has($id) && ! $impliedFavorites->has($id)) {
                $allAvoided->put($id, $expansion);
            }
        }

        return [
            'favorites' => $resolvedFavorites,
            'avoided' => $allAvoided->values(),
            'implied_favorites' => $impliedFavorites,
        ];
    }

    /**
     * Resolved vibe preferences with mutual-exclusivity enforcement.
     *
     * Rules:
     *  - For each favorite, its exclusive partner is auto-avoided.
     *  - For each avoid, its exclusive partner is NOT auto-favorited.
     *  - Deduplication: if a flag is both explicitly favorite and auto-avoided,
     *    explicit favorite wins (the partner goes to avoided instead).
     *
     * @return array{favorites: string[], avoided: string[]}
     */
    public function resolvedVibePreferences(User $user): array
    {
        $explicitFavorites = $user->favoriteVibes()
            ->pluck('vibe_preference_value')
            ->map(fn (mixed $flag) => $flag instanceof VibeFlag ? $flag->value : (is_string($flag) ? $flag : ''))
            ->unique()
            ->values()
            ->all();

        $explicitAvoids = $user->avoidedVibes()
            ->pluck('vibe_preference_value')
            ->map(fn (mixed $flag) => $flag instanceof VibeFlag ? $flag->value : (is_string($flag) ? $flag : ''))
            ->unique()
            ->values()
            ->all();

        $favoriteSet = array_flip($explicitFavorites);
        $avoidSet = array_flip($explicitAvoids);

        // Build a lookup: flag value => partner flag value
        $partnerLookup = [];
        foreach (VibeFlag::mutuallyExclusivePairs() as [$a, $b]) {
            $partnerLookup[$a->value] = $b->value;
            $partnerLookup[$b->value] = $a->value;
        }

        // Auto-avoid partners of favorites
        foreach ($explicitFavorites as $fav) {
            if (isset($partnerLookup[$fav]) && ! isset($favoriteSet[$partnerLookup[$fav]])) {
                $avoidSet[$partnerLookup[$fav]] = true;
            }
        }

        // Remove from avoid set anything that's explicitly favorited (favorite wins)
        foreach ($explicitFavorites as $fav) {
            unset($avoidSet[$fav]);
        }

        return [
            'favorites' => array_keys($favoriteSet),
            'avoided' => array_keys($avoidSet),
        ];
    }
}
