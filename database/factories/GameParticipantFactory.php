<?php

namespace Database\Factories;

use App\Enums\AttendanceStatus;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameParticipant>
 */
class GameParticipantFactory extends Factory
{
    protected $model = GameParticipant::class;

    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'user_id' => User::factory(),
            'role' => 'player',
            'status' => ParticipantStatus::Approved,
            'attendance_status' => AttendanceStatus::Attended,
        ];
    }

    /**
     * A participant waiting in the FIFO waitlist queue.
     *
     * Stamps waitlisted_at — WaitlistService::getWaitlistPosition orders the
     * queue by it, and rows with status Waitlisted but a null timestamp make
     * the position comparison fail on Postgres.
     */
    public function waitlisted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ParticipantStatus::Waitlisted,
            'waitlisted_at' => now(),
        ]);
    }
}
