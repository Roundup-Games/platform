<?php

namespace Database\Factories;

use App\Enums\DiscordCardStatus;
use App\Models\DiscordCardMessage;
use App\Models\DiscordGuild;
use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiscordCardMessage>
 *
 * v1 ships the Open posting path only: every card row is Posted with a
 * non-null message_id. The pending() state exists so the future Review-mode
 * slice (and its tests) can build a pending card without a schema change —
 * it is not reachable through v1 publisher code.
 */
class DiscordCardMessageFactory extends Factory
{
    protected $model = DiscordCardMessage::class;

    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'guild_id' => DiscordGuild::factory(),
            'channel_id' => (string) random_int(100000000000000000, 999999999999999999),
            'message_id' => (string) random_int(100000000000000000, 999999999999999999),
            'status' => DiscordCardStatus::Posted->value,
            'moderator_user_id' => null,
            'moderated_at' => null,
            'expires_at' => null,
        ];
    }

    /**
     * Pending card awaiting moderator approval (future; message_id NULL).
     *
     * Only constructable via factory in v1 — used to prove the schema can
     * represent a not-yet-posted card without a migration.
     */
    public function pending(): static
    {
        return $this->state([
            'status' => DiscordCardStatus::Pending->value,
            'message_id' => null,
            'expires_at' => now()->addDay(),
        ]);
    }
}
