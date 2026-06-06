<?php

namespace Database\Factories;

use App\Enums\VenueType;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        // Generate coordinates roughly in the DACH region (Germany/Austria/Switzerland)
        return [
            'name' => fake()->company() . ' ' . fake()->randomElement(['Spielhalle', 'Café', 'Community Center', 'Games Store']),
            'description' => fake()->optional()->sentence(),
            'address' => fake()->streetAddress(),
            'city' => fake()->randomElement(['Berlin', 'Munich', 'Vienna', 'Zurich', 'Hamburg', 'Cologne', 'Frankfurt', 'Stuttgart']),
            'postal_code' => fake()->postcode(),
            'country' => fake()->randomElement(['DEU', 'AUT', 'CHE']),
            'latitude' => fake()->latitude(47.0, 54.0),
            'longitude' => fake()->longitude(6.0, 14.0),
            'place_id' => 'ChIJ' . fake()->regexify('[A-Za-z0-9_-]{25}'),
            'source' => fake()->randomElement(['google', 'manual', 'geocode']),
            'metadata' => null,
        ];
    }

    /**
     * Indicate that the location is a verified venue.
     */
    public function verifiedVenue(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_verified' => true,
            'venue_type' => fake()->randomElement(VenueType::cases())->value,
            'venue_notes' => fake()->optional()->sentence(),
            'website_url' => fake()->optional()->url(),
        ]);
    }
}
