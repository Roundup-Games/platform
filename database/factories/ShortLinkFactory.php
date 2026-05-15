<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\ShortLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ShortLink>
 */
class ShortLinkFactory extends Factory
{
    protected $model = ShortLink::class;

    public function definition(): array
    {
        return [
            'code' => Str::random(7),
            'url' => 'https://example.com/' . Str::random(6),
            'linkable_type' => Game::class,
            'linkable_id' => Game::factory(),
            'user_id' => null,
            'label' => fake()->optional()->words(2, true),
            'purpose' => fake()->optional()->word(),
            'expires_at' => null,
            'max_hits' => null,
            'hit_count' => 0,
            'last_hit_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function hitCapped(int $maxHits = 10): static
    {
        return $this->state(fn (array $attributes) => [
            'max_hits' => $maxHits,
            'hit_count' => $maxHits,
        ]);
    }
}
