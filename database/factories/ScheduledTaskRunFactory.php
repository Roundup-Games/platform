<?php

namespace Database\Factories;

use App\Enums\ScheduledTaskRunStatus;
use App\Models\ScheduledTaskRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScheduledTaskRun> */
class ScheduledTaskRunFactory extends Factory
{
    protected $model = ScheduledTaskRun::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $startedAt = $this->faker->dateTimeBetween('-1 day', 'now');

        return [
            'task_key' => 'attendance:resolve',
            'status' => ScheduledTaskRunStatus::Completed,
            'started_at' => $startedAt,
            'finished_at' => $startedAt,
            'runtime_ms' => $this->faker->numberBetween(50, 60_000),
            'error' => null,
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ScheduledTaskRunStatus::Failed,
            'error' => 'SQLSTATE[HY000]: General error: simulated failure',
        ]);
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ScheduledTaskRunStatus::Running,
            'finished_at' => null,
            'runtime_ms' => null,
            'error' => null,
        ]);
    }

    public function startedAt(\DateTimeInterface $startedAt): static
    {
        return $this->state(fn (array $attributes): array => [
            'started_at' => $startedAt,
            'finished_at' => $startedAt,
        ]);
    }
}
