<?php

namespace Database\Factories;

use App\Models\DiscordGuild;
use App\Models\DiscordGuildOrganizer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<DiscordGuildOrganizer>
 */
class DiscordGuildOrganizerFactory extends Factory
{
    protected $model = DiscordGuildOrganizer::class;

    public function definition(): array
    {
        return [
            'guild_id' => DiscordGuild::factory(),
            'user_id' => User::factory(),
            'publish_enabled' => false,
            'opted_in_at' => null,
        ];
    }

    /**
     * Organizer who has opted in to publishing to the guild (D119 consent).
     */
    public function optedIn(): static
    {
        return $this->state([
            'publish_enabled' => true,
            'opted_in_at' => Carbon::now(),
        ]);
    }

    /**
     * Organizer who has opted out (publish_enabled false, no opted_in_at).
     */
    public function optedOut(): static
    {
        return $this->state([
            'publish_enabled' => false,
            'opted_in_at' => null,
        ]);
    }
}
