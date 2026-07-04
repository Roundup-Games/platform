<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    /**
     * Keep the campaign_game_system pivot in sync with the legacy
     * game_system_id anchor, so pivot-backed reads return these
     * fixtures. Runs once on create(); the anchor is retired in T06.
     *
     * This is the factory's canonical write path for single-system campaigns.
     * Multi-system recurring defaults (used by the copy-on-write feature test)
     * are set up via withCampaignGameSystems(), whose afterCreating runs AFTER
     * this one and overwrites the pivot with the explicit set, so the final
     * state is the caller's intended offering.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Campaign $campaign): void {
            $anchor = $campaign->game_system_id;
            if ($anchor !== null) {
                $campaign->gameSystems()->sync([$anchor]);
            }
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
            'game_system_id' => GameSystem::factory(),
            'name' => ['en' => fake()->words(3, true).' Campaign'],
            'description' => ['en' => fake()->paragraph()],
            'visibility' => 'public',
            'recurrence' => 'weekly',
            'time_of_day' => '19:00',
            'session_duration' => fake()->randomFloat(1, 2, 5),
            'price_per_session' => fake()->randomFloat(2, 0, 20),
            'language' => 'en',
            'status' => 'active',
            'min_players' => fake()->numberBetween(2, 4),
            'max_players' => fake()->numberBetween(4, 8),
            'experience_level' => null,
            'complexity' => null,
            'vibe_flags' => null,
            'bench_mode' => false,
        ];
    }

    /**
     * Seed a multi-system recurring default offering.
     *
     * Campaigns have no game_systems JSON column, so the multi-system set lives
     * only in the campaign_game_system pivot. This state creates the given
     * GameSystems, syncs them onto the campaign's pivot, and sets the legacy
     * game_system_id anchor to the first element (kept alive until dropped).
     * Used by the AddSessionToCampaign copy-on-write test to verify that
     * a spawned session inherits the full default set and can then be
     * overridden per-session without touching the template.
     *
     * @param  array<int, string>  $ids  GameSystem UUIDs; anchor taken from [0].
     */
    public function withCampaignGameSystems(array $ids): static
    {
        return $this->state(function () use ($ids): array {
            $ids = array_values($ids);

            return [
                'game_system_id' => $ids[0] ?? null,
            ];
        })->afterCreating(function (Campaign $campaign) use ($ids): void {
            if (! empty($ids)) {
                $campaign->gameSystems()->sync(array_values(array_map('strval', $ids)));
            }
        });
    }
}
