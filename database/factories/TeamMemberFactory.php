<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamMember>
 */
class TeamMemberFactory extends Factory
{
    protected $model = TeamMember::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'role' => 'player',
            'status' => 'active',
            'joined_at' => now(),
        ];
    }

    public function captain(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'captain',
        ]);
    }
}
