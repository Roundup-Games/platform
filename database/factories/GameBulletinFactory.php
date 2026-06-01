<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\GameBulletin;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameBulletin>
 */
class GameBulletinFactory extends Factory
{
    protected $model = GameBulletin::class;

    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'user_id' => User::factory(),
            'content' => fake()->text(200),
            'expires_at' => null,
        ];
    }

    /**
     * Bulletin that has already expired.
     */
    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subHour()]);
    }

    /**
     * Bulletin expiring within the next hour.
     */
    public function expiringSoon(): static
    {
        return $this->state(['expires_at' => now()->addMinutes(30)]);
    }
}
