<?php

namespace Database\Factories;

use App\Enums\GmProficiency;
use App\Models\GMProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GMProfile>
 */
class GMProfileFactory extends Factory
{
    protected $model = GMProfile::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'bio' => fake()->optional()->paragraph(),
            'specializations' => fake()->optional()->randomElements(
                GmProficiency::values(),
                fake()->numberBetween(1, 3),
            ),
            'average_rating' => null,
            'review_count' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Generate a slug from the associated user's name on creation.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (GMProfile $profile) {
            if (empty($profile->slug)) {
                $name = $profile->user?->name ?? 'gm';
                $profile->slug = Str::slug($name).'-'.Str::random(6);
            }
        });
    }
}
