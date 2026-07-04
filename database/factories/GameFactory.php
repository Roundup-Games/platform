<?php

namespace Database\Factories;

use App\Enums\GameType;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Game>
 *
 * S06 pivot migration: the legacy games.game_system_id anchor + game_systems
 * JSON array were dropped. The factory can no longer carry the offered-system
 * set through state() (state keys become INSERT columns), so the canonical
 * write path is the belongsToMany pivot, attached in afterCreating.
 *
 * Ordering (avoids orphan GameSystem rows):
 *   1. afterMaking callbacks run at make() time (before persist). gathering() /
 *      withGameSystems() set a transient factoryPivotClaimed relation flag.
 *   2. afterCreating callbacks run in registration order: configure()'s default
 *      (registered at construction) runs first — it skips when the flag is set,
 *      otherwise attaches one default system so bare Game::factory()->create()
 *      still yields a single-system game. gathering()/withGameSystems() run
 *      after and sync their explicit set, replacing any default.
 */
class GameFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (Game $game): void {
            // Skip the default single-system attach when a state method already
            // claimed the pivot (gathering/withGameSystems). The flag is stashed
            // via setRelation so it never reaches the INSERT statement.
            // relationLoaded() guards getRelation(), which throws on a missing key.
            $claimed = $game->relationLoaded('factoryPivotClaimed')
                && $game->getRelation('factoryPivotClaimed') === true;
            if ($claimed || $game->gameSystems->isNotEmpty()) {
                return;
            }
            $system = GameSystem::factory()->create();
            $game->gameSystems()->sync([(string) $system->id]);
        });
    }

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
     * Creates two real GameSystems and attaches them via the pivot. Systems are
     * persisted inside afterCreating (post-save) because the pivot needs real
     * UUIDs and belongsToMany::sync() requires the Game to exist first.
     *
     * Side effect: GameSystem rows are created even on factory->make(). The
     * codebase only ever creates (never makes) Games in tests, and each test
     * runs inside a DatabaseTransactions wrapper, so this is safe in practice.
     */
    public function gathering(): static
    {
        return $this
            ->afterMaking(function (Game $game): void {
                $game->setRelation('factoryPivotClaimed', true);
            })
            ->state([
                'game_type' => GameType::Gathering->value,
            ])
            ->afterCreating(function (Game $game): void {
                $systems = GameSystem::factory()->count(2)->create();
                $game->gameSystems()->sync(
                    $systems->pluck('id')
                        ->map(fn (mixed $id): string => (string) $id)
                        ->all()
                );
            });
    }

    /**
     * Set an explicit multi-system set for a Gathering (or single-system game).
     *
     * Use after gathering() (or standalone) when a test needs deterministic
     * control over the offered set. Attaches via the pivot in afterCreating.
     *
     * @param  array<int, string>  $ids  GameSystem UUIDs to attach.
     */
    public function withGameSystems(array $ids): static
    {
        return $this
            ->afterMaking(function (Game $game): void {
                $game->setRelation('factoryPivotClaimed', true);
            })
            ->afterCreating(function (Game $game) use ($ids): void {
                $game->gameSystems()->sync(array_values(array_map('strval', $ids)));
            });
    }
}
