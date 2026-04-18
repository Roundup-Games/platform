<?php

namespace Database\Factories;

use App\Enums\RelationshipType;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserRelationship>
 */
class UserRelationshipFactory extends Factory
{
    protected $model = UserRelationship::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'related_user_id' => User::factory(),
            'type' => RelationshipType::Follow,
        ];
    }

    /**
     * Create a follow relationship.
     */
    public function follow(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => RelationshipType::Follow,
        ]);
    }

    /**
     * Create a block relationship.
     */
    public function block(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => RelationshipType::Block,
        ]);
    }
}
