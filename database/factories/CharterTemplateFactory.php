<?php

namespace Database\Factories;

use App\Dto\CharterContent;
use App\Models\CharterTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CharterTemplate>
 */
class CharterTemplateFactory extends Factory
{
    protected $model = CharterTemplate::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(3, true),
            'content' => CharterContent::blank()->toArray(),
            'content_schema' => CharterContent::SCHEMA_VERSION,
        ];
    }

    /**
     * CharterContent-shaped content: template seeds are normalized
     * charter snapshots (the same tolerant normalizer the service uses),
     * so a template made from this state round-trips through
     * CharterService::start('template') unchanged.
     *
     * @param  array<string, mixed>  $content
     */
    public function withContent(array $content): static
    {
        return $this->state(fn (array $attributes) => [
            'content' => CharterContent::fromArray($content)->toArray(),
        ]);
    }
}
