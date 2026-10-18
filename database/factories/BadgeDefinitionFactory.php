<?php

namespace Database\Factories;

use App\Enums\BadgeCategory;
use App\Enums\BadgeMode;
use App\Models\BadgeDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BadgeDefinition> */
class BadgeDefinitionFactory extends Factory
{
    protected $model = BadgeDefinition::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        // 'debut'-like registry key; unique so tests can create several
        // definitions without tripping the key unique constraint.
        $key = $this->faker->unique()->word();

        return [
            'key' => $key,
            'category' => BadgeCategory::Participation,
            'mode' => BadgeMode::OneTime,
            'tiers' => null,
            'name_key' => "badges.{$key}.name",
            'description_key' => "badges.{$key}.description",
            'icon' => null,
            'sort_order' => 0,
            'is_active' => true,
            'is_dormant' => false,
            'metadata' => null,
        ];
    }

    public function dormant(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_dormant' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
