<?php

namespace Database\Factories;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceReport;
use App\Models\Game;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceReport>
 */
class AttendanceReportFactory extends Factory
{
    protected $model = AttendanceReport::class;

    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'reporter_id' => User::factory(),
            'reported_id' => User::factory(),
            'status' => AttendanceStatus::Attended->value,
            'weight_applied' => 1.0,
            'is_corroborated' => false,
            'quarantined' => false,
        ];
    }
}
