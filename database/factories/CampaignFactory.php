<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 *
 * S06 pivot migration: the legacy campaigns.game_system_id anchor was dropped.
 * The factory can no longer carry the offered-system set through state() (state
 * keys become INSERT columns), so the canonical write path is the belongsToMany
 * pivot, attached in afterCreating. See GameFactory for the same pattern.
 */
class CampaignFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (Campaign $campaign): void {
            // Skip the default single-system attach when a state method already
            // claimed the pivot (withCampaignGameSystems). relationLoaded()
            // guards getRelation(), which throws on a missing key.
            $claimed = $campaign->relationLoaded('factoryPivotClaimed')
                && $campaign->getRelation('factoryPivotClaimed') === true;
            if ($claimed || $campaign->gameSystems->isNotEmpty()) {
                return;
            }
            $system = GameSystem::factory()->create();
            $campaign->gameSystems()->sync([(string) $system->id]);
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
     * The set lives only in the campaign_game_system pivot (Campaigns have no
     * game_systems JSON column). Used by the AddSessionToCampaign copy-on-write
     * test to verify that a spawned session inherits the full default set and
     * can then be overridden per-session without touching the template.
     *
     * @param  array<int, string>  $ids  GameSystem UUIDs to attach.
     */
    public function withCampaignGameSystems(array $ids): static
    {
        return $this
            ->afterMaking(function (Campaign $campaign): void {
                $campaign->setRelation('factoryPivotClaimed', true);
            })
            ->afterCreating(function (Campaign $campaign) use ($ids): void {
                if (! empty($ids)) {
                    $campaign->gameSystems()->sync(array_values(array_map('strval', $ids)));
                }
            });
    }
}
