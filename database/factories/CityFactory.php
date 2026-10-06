<?php

namespace Database\Factories;

use App\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<City>
 */
class CityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // slug is unique. Str::random / Str::upper (backed by random_bytes),
        // not fake()->unique() — parallel test workers are separate processes
        // seeded identically and would collide on the shared DB (same
        // rationale as GameSystemFactory).
        $name = 'City '.Str::upper(Str::random(6));

        return [
            'slug' => Str::slug($name),
            'city' => $name,
            'country' => 'DEU',
            'region_prefix' => null,
            'intro' => null,
            'featured' => false,
            'hidden' => false,
            'upcoming_activity_count' => null,
            'verified_venues_count' => null,
            'recomputed_at' => null,
        ];
    }

    /**
     * Curated translatable hero intro in both supported locales.
     */
    public function withIntro(?string $en = null, ?string $de = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'intro' => [
                'en' => $en ?? 'Discover tabletop sessions near '.($attributes['city'] ?? 'this city').'.',
                'de' => $de ?? 'Entdecke Tabletop-Runden in der Nähe von '.($attributes['city'] ?? 'dieser Stadt').'.',
            ],
        ]);
    }

    /**
     * Featured — surfaces the city on the /discover featured-cities rail.
     */
    public function featured(): static
    {
        return $this->state(fn (): array => [
            'featured' => true,
        ]);
    }

    /**
     * Hidden — excluded from every public city-hub surface.
     */
    public function hidden(): static
    {
        return $this->state(fn (): array => [
            'hidden' => true,
        ]);
    }
}
