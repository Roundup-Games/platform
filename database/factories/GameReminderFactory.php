<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\GameReminder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameReminder>
 */
class GameReminderFactory extends Factory
{
    protected $model = GameReminder::class;

    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'send_at' => now()->addHours(3),
            'message' => null,
            'offset_minutes' => null,
            'sent_at' => null,
        ];
    }

    /**
     * Reminder whose send time has arrived and is awaiting dispatch.
     */
    public function due(): static
    {
        return $this->state(['send_at' => now()->subHour()]);
    }

    /**
     * Reminder scheduled in the future (not yet due).
     */
    public function upcoming(): static
    {
        return $this->state(['send_at' => now()->addDays(2)]);
    }

    /**
     * Reminder that has already been dispatched (sent_at stamped).
     */
    public function sent(): static
    {
        return $this->state(['send_at' => now()->subHours(2), 'sent_at' => now()->subHour()]);
    }

    /**
     * Reminder carrying organizer-authored custom copy.
     */
    public function withMessage(?string $message = null): static
    {
        return $this->state(['message' => $message ?? 'Don\'t forget your dice!']);
    }

    /**
     * Reminder attached to an existing Game (avoids factory-creating a game).
     */
    public function forGame(Game $game): static
    {
        return $this->state(['game_id' => $game]);
    }
}
