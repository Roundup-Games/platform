<?php

namespace Database\Factories;

use App\Enums\DiscordModerationMode;
use App\Models\DiscordGuild;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiscordGuild>
 */
class DiscordGuildFactory extends Factory
{
    protected $model = DiscordGuild::class;

    public function definition(): array
    {
        return [
            'guild_id' => (string) random_int(100000000000000000, 999999999999999999),
            'name' => fake()->company().' Server',
            'icon' => null,
            'owner_user_id' => User::factory(),
            'calendar_channel_id' => null,
            'digest_message_id' => null,
            'digest_channel_id' => null,
            'games_channel_id' => null,
            'locale' => 'en-US',
            'paused' => false,
            'moderation_mode' => DiscordModerationMode::Open->value,
        ];
    }

    /**
     * Guild with both channels configured (ready to post).
     */
    public function configured(): static
    {
        return $this->state([
            'calendar_channel_id' => (string) random_int(100000000000000000, 999999999999999999),
            'games_channel_id' => (string) random_int(100000000000000000, 999999999999999999),
        ]);
    }

    /**
     * Guild where the landlord has paused all posting.
     */
    public function paused(): static
    {
        return $this->state(['paused' => true]);
    }

    /**
     * Guild in Review moderation mode (future; not reachable via UI in v1).
     *
     * Only constructable via factory — used by the publisher seam test to prove
     * the moderation branch routes correctly without shipping the queue.
     */
    public function review(): static
    {
        return $this->state(['moderation_mode' => DiscordModerationMode::Review->value]);
    }
}
