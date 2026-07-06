<?php

namespace App\Services;

use App\Dto\CreateDefaults;
use App\Enums\GameType;
use App\Models\Game;
use App\Models\User;

/**
 * Sources smart defaults for game/campaign creation forms.
 *
 * Priority chain (first non-null wins per field):
 *   1. The user's most recently created game of the SAME type — language,
 *      location, visibility, vibe flags, experience level, typical duration
 *      and seat count travel forward so a recurring host doesn't re-enter them.
 *   2. Profile preferences — preferred_language, favorite game system, location.
 *   3. Type defaults (delegated to the Livewire component's applyTypeDefaults()).
 *
 * Returns a {@see CreateDefaults} with all-null fields when there is no prior
 * session of the requested type; consumers supply their own per-field fallbacks.
 *
 * This service is the single coercion point: Eloquent attribute access resolves
 * to mixed under Larastan, so every field is cast to its declared type here,
 * producing a strongly-typed DTO consumers can assign to typed Livewire
 * properties without PHPStan "does not accept mixed" failures.
 */
class CreateDefaultsService
{
    /**
     * Resolve creation defaults for the given game type.
     */
    public function forGameType(User $user, GameType $type): CreateDefaults
    {
        $lastGame = $this->lastAuthoredGameOfType($user, $type);

        if ($lastGame === null) {
            return new CreateDefaults;
        }

        $favSystem = $this->favoriteGameSystemId($user, $type);
        $firstSystem = $lastGame->gameSystems->first();

        return new CreateDefaults(
            language: $lastGame->language ?? $this->preferredLanguage($user),
            locationId: $lastGame->location_id ?? $user->location_id,
            locationInstructions: $lastGame->location_instructions,
            visibility: $lastGame->visibility !== null ? strval($lastGame->visibility->value) : null,
            experienceLevel: $lastGame->experience_level,
            expectedDuration: strval($lastGame->expected_duration),
            maxPlayers: $lastGame->max_players,
            minPlayers: $lastGame->min_players,
            gameSystemId: $firstSystem !== null ? $firstSystem->id : $favSystem,
            // For gatherings: carry forward the full offered-system set.
            gameSystems: $lastGame->gameSystems->map(fn ($s) => (string) $s->id)->values()->all(),
            vibeFlags: ! empty($lastGame->vibe_flags) ? (array) $lastGame->vibe_flags : null,
        );
    }

    /**
     * Resolve creation defaults for a new campaign (recurring event).
     *
     * Borrows from the user's most recent campaign-aware game of the same type
     * (or their last standalone game), plus profile preferences.
     */
    public function forCampaign(User $user): CreateDefaults
    {
        // Prefer the last campaign-session the user organized, fall back to any game.
        // Both paths eager-load gameSystems so the offered-system lookup below
        // doesn't trigger a lazy-load query on the fallback branch.
        $lastCampaignGame = Game::where('owner_id', $user->id)
            ->whereNotNull('campaign_id')
            ->latest('date_time')
            ->with(['gameSystems'])
            ->first();

        $lastAnyGame = Game::where('owner_id', $user->id)
            ->with(['gameSystems'])
            ->latest('date_time')
            ->first();

        $source = $lastCampaignGame ?? $lastAnyGame;

        if ($source === null) {
            return new CreateDefaults;
        }

        $firstSystem = $source->gameSystems->first();

        return new CreateDefaults(
            language: $source->language ?? $this->preferredLanguage($user),
            locationId: $source->location_id ?? $user->location_id,
            visibility: $source->visibility !== null ? strval($source->visibility->value) : null,
            experienceLevel: $source->experience_level,
            maxPlayers: $source->max_players,
            gameType: $source->game_type?->value,
            gameSystemId: $firstSystem !== null ? $firstSystem->id : $this->favoriteGameSystemId($user, GameType::Ttrpg),
            recurrence: 'weekly',
        );
    }

    // ── Helpers ────────────────────────────────────────

    /**
     * The user's most recently created game of the given type (any status).
     */
    private function lastAuthoredGameOfType(User $user, GameType $type): ?Game
    {
        return Game::where('owner_id', $user->id)
            ->where('game_type', $type->value)
            ->latest('date_time')
            ->with(['gameSystems'])
            ->first();
    }

    /**
     * The user's favorite game system appropriate for the type, if any.
     *
     * Filters by GameSystem.type so a TTRPG favorite is never picked for a
     * board-game session (and vice versa). Board-game favorites apply to
     * board_game + gathering; TTRPG favorites apply to ttrpg.
     */
    private function favoriteGameSystemId(User $user, GameType $type): ?string
    {
        $systemType = $type === GameType::Ttrpg ? 'ttrpg' : 'boardgame';

        $fav = $user->favoriteGameSystems()
            ->where('type', $systemType)
            ->first();

        return $fav?->id;
    }

    private function preferredLanguage(User $user): ?string
    {
        return $user->preferred_language?->value;
    }
}
