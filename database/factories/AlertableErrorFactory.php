<?php

namespace Database\Factories;

use App\Models\AlertableError;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AlertableError> */
class AlertableErrorFactory extends Factory
{
    protected $model = AlertableError::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $seenAt = $this->faker->dateTimeBetween('-2 days', 'now');

        return [
            'message_hash' => hash('sha256', $this->faker->unique()->sentence()),
            'message' => $this->faker->sentence(),
            'level' => 'ERROR',
            'channel' => 'stack',
            'context' => null,
            'occurrences' => 1,
            'first_seen_at' => $seenAt,
            'last_seen_at' => $seenAt,
            'last_notified_at' => null,
            'resolved_at' => null,
        ];
    }

    /** A row whose message matches config('observability.critical_patterns'). */
    public function critical(string $message = 'badges.sweep.user_failed'): static
    {
        return $this->state(fn (array $attributes): array => [
            'message' => $message,
            'message_hash' => hash('sha256', $message),
            'level' => 'ERROR',
        ]);
    }

    public function notified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'last_notified_at' => $this->faker->dateTimeBetween('-1 day', 'now'),
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'resolved_at' => $this->faker->dateTimeBetween('-1 day', 'now'),
        ]);
    }

    public function seenAt(\DateTimeInterface $seenAt): static
    {
        return $this->state(fn (array $attributes): array => [
            'first_seen_at' => $seenAt,
            'last_seen_at' => $seenAt,
        ]);
    }

    /** Stale sighting — last seen long enough ago to be 7-day-resolvable. */
    public function seenLongAgo(): static
    {
        return $this->state(fn (array $attributes): array => [
            'first_seen_at' => now()->subDays(10),
            'last_seen_at' => now()->subDays(9),
        ]);
    }
}
