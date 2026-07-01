<?php

namespace Database\Factories;

use App\Enums\GameType;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'game_type' => 'board_game',
            'game_system_id' => GameSystem::factory(),
            // Multi-system JSON array. Null for the legacy single-system path
            // (board_game / ttrpg) so existing read sites are unaffected.
            // Populated by the gathering() state for Gathering-type games.
            'game_systems' => null,
            'name' => ['en' => fake()->words(3, true)],
            'date_time' => now()->addDays(fake()->numberBetween(1, 30)),
            'description' => ['en' => fake()->sentence()],
            'expected_duration' => fake()->randomFloat(1, 1, 6),
            'price' => fake()->randomFloat(2, 0, 25),
            'language' => 'en',
            'location' => [
                'type' => 'online',
                'details' => fake()->url(),
            ],
            'status' => 'scheduled',
            'visibility' => 'public',
            'min_players' => fake()->numberBetween(2, 4),
            'max_players' => fake()->numberBetween(4, 8),
            'experience_level' => null,
            'complexity' => null,
            'vibe_flags' => null,
        ];
    }

    /**
     * A multi-system Gathering (e.g. a board game night spanning several games).
     *
     * Creates two real GameSystems and seeds game_systems with their UUIDs,
     * setting game_system_id (the cached anchor) to the array's first element so
     * the Game::saving sync event records no drift. The systems are persisted
     * eagerly (inside the state closure) because the JSON array needs real UUIDs
     * and the anchor column carries a foreign key — nested factories don't
     * resolve inside arrays, so eager create is the only faithful option.
     *
     * Side effect: GameSystem rows are created even on factory->make(). The
     * codebase only ever creates (never makes) Games in tests, and each test runs
     * inside a DatabaseTransactions wrapper, so this is safe in practice.
     */
    public function gathering(): static
    {
        return $this->state(function (): array {
            $systems = GameSystem::factory()->count(2)->create();

            return [
                'game_type' => GameType::Gathering->value,
                'game_system_id' => $systems->first()->id,
                'game_systems' => $systems->modelKeys(),
            ];
        });
    }

    /**
     * Set an explicit multi-system set for a Gathering.
     *
     * The anchor (game_system_id) is synced to the array's first element to match
     * the Game::saving invariant. Use after gathering() (or standalone) when a
     * test needs deterministic control over the system set.
     *
     * @param  array<int, string>  $ids  GameSystem UUIDs, anchor taken from [0].
     */
    public function withGameSystems(array $ids): static
    {
        return $this->state(function () use ($ids): array {
            $ids = array_values($ids);

            return [
                'game_system_id' => $ids[0] ?? null,
                'game_systems' => $ids,
            ];
        });
    }
}
