<?php

namespace Database\Factories;

use App\Models\DiscordBulletinMessage;
use App\Models\DiscordGuild;
use App\Models\GameBulletin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiscordBulletinMessage>
 *
 * Default state: a successfully posted teaser (STATUS_POSTED, message_id
 * set) — the state the happy-path fan-out produces. The failed() state
 * exists for retry-churn tests: a terminal 4xx recorded so job retries
 * skip the thread.
 */
class DiscordBulletinMessageFactory extends Factory
{
    protected $model = DiscordBulletinMessage::class;

    public function definition(): array
    {
        return [
            'bulletin_id' => GameBulletin::factory(),
            'guild_id' => DiscordGuild::factory(),
            'thread_id' => (string) random_int(100000000000000000, 999999999999999999),
            'message_id' => (string) random_int(100000000000000000, 999999999999999999),
            'status' => DiscordBulletinMessage::STATUS_POSTED,
            'error_code' => null,
        ];
    }

    /**
     * A terminal failure row — posted by the job when Discord answers a
     * thread post with a non-retryable 4xx (thread deleted, bot lost the
     * Send Messages in Threads permission).
     */
    public function failed(int $errorCode = 403): static
    {
        return $this->state(fn () => [
            'message_id' => null,
            'status' => DiscordBulletinMessage::STATUS_FAILED,
            'error_code' => $errorCode,
        ]);
    }
}
