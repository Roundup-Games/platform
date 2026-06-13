<?php

namespace Database\Factories;

use App\Enums\GmProficiency;
use App\Models\Game;
use App\Models\GMProfile;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reviewable_type' => Game::class,
            'reviewable_id' => Game::factory(),
            'reviewer_id' => User::factory(),
            'gm_profile_id' => GMProfile::factory(),
            'rating' => fake()->numberBetween(1, 5),
            'body' => fake()->optional()->paragraph(),
            'proficiency_tags' => fake()->optional()->randomElements(
                GmProficiency::values(),
                fake()->numberBetween(1, 3),
            ),
            'status' => 'published',
            'reported_at' => null,
            'reported_by' => null,
            'reply' => null,
            'replied_at' => null,
        ];
    }

    /**
     * Attach the review to a specific reviewable model.
     */
    public function forReviewable(Model $reviewable): static
    {
        return $this->state(fn () => [
            'reviewable_type' => $reviewable->getMorphClass(),
            'reviewable_id' => $reviewable->getKey(),
        ]);
    }

    /**
     * Mark the review as reported.
     */
    public function reported(): static
    {
        return $this->state(fn () => [
            'status' => 'reported',
            'reported_at' => now(),
            'reported_by' => User::factory(),
        ]);
    }
}
