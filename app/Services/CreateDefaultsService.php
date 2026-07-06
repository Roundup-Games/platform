<?php

namespace App\Services;

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
 * Used by CreateGame::mount() and the unified Plan-something flow to pre-fill
 * the form without overriding an explicit clone source (?clone=ID).
 */
class CreateDefaultsService
{
    /**
     * Resolve creation defaults for the given game type.
     *
     * @return array<string, mixed> Resolved per-field defaults; consumers read keys.
     */
    public function forGameType(User $user, GameType $type): array
    {
        $lastGame = $this->lastAuthoredGameOfType($user, $type);
        $favSystem = $this->favoriteGameSystemId($user, $type);

        if ($lastGame !== null) {
            $vibeFlags = ! empty($lastGame->vibe_flags)
                ? (array) $lastGame->vibe_flags
                : null;
            $firstSystem = $lastGame->gameSystems->first();

            return [
                'language' => $lastGame->language ?? $this->preferredLanguage($user),
                'location_id' => $lastGame->location_id ?? $user->location_id,
                'location_instructions' => $lastGame->location_instructions,
                'visibility' => $lastGame->visibility?->value,
                'experience_level' => $lastGame->experience_level,
                'expected_duration' => (string) $lastGame->expected_duration,
                'max_players' => $lastGame->max_players,
                'min_players' => $lastGame->min_players,
                'vibe_flags' => $vibeFlags,
                'game_system_id' => $firstSystem !== null ? $firstSystem->id : $favSystem,
                // For gatherings: carry forward the full offered-system set.
                'game_systems' => $lastGame->gameSystems->pluck('id')->all(),
            ];
        }

        // No prior session of this type — fall back to profile preferences only.
        return [
            'language' => $this->preferredLanguage($user),
            'location_id' => $user->location_id,
            'location_instructions' => null,
            'visibility' => null,
            'experience_level' => null,
            'expected_duration' => null,
            'max_players' => null,
            'min_players' => null,
            'vibe_flags' => null,
            'game_system_id' => $favSystem,
            'game_systems' => [],
        ];
    }

    /**
     * Resolve creation defaults for a new campaign (recurring event).
     *
     * Borrows from the user's most recent campaign-aware game of the same type
     * (or their last standalone game), plus profile preferences.
     *
     * @return array<string, mixed> Resolved per-field defaults; consumers read keys.
     */
    public function forCampaign(User $user): array
    {
        // Prefer the last campaign-session the user organized, fall back to any game.
        $lastCampaignGame = Game::where('owner_id', $user->id)
            ->whereNotNull('campaign_id')
            ->latest('date_time')
            ->with(['gameSystems'])
            ->first();

        $lastAnyGame = Game::where('owner_id', $user->id)
            ->latest('date_time')
            ->first();

        $source = $lastCampaignGame ?? $lastAnyGame;

        if ($source !== null) {
            $firstSystem = $source->gameSystems->first();

            return [
                'language' => $source->language ?? $this->preferredLanguage($user),
                'location_id' => $source->location_id ?? $user->location_id,
                'visibility' => $source->visibility?->value,
                'experience_level' => $source->experience_level,
                'max_players' => $source->max_players,
                'game_type' => $source->game_type?->value,
                'game_system_id' => $firstSystem !== null ? $firstSystem->id : $this->favoriteGameSystemId($user, GameType::Ttrpg),
                'recurrence' => 'weekly',
            ];
        }

        // No prior campaign or game — bare defaults.
        return [
            'language' => $this->preferredLanguage($user),
            'location_id' => $user->location_id,
            'visibility' => null,
            'experience_level' => null,
            'max_players' => null,
            'game_type' => null,
            'game_system_id' => $this->favoriteGameSystemId($user, GameType::Ttrpg),
            'recurrence' => 'weekly',
        ];
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
     * Board-game favorites for board_game/gathering; TTRPG favorites for ttrpg.
     */
    private function favoriteGameSystemId(User $user, GameType $type): ?string
    {
        $fav = $user->favoriteGameSystems()->first();

        return $fav?->id;
    }

    private function preferredLanguage(User $user): ?string
    {
        return $user->preferred_language?->value;
    }
}
