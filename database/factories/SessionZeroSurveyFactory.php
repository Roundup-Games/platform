<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\GMProfile;
use App\Models\SessionZeroSurvey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SessionZeroSurvey>
 */
class SessionZeroSurveyFactory extends Factory
{
    protected $model = SessionZeroSurvey::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'gm_profile_id' => GMProfile::factory(),
            'title' => fake()->sentence(),
            'content' => [
                'safety_tools' => ['lines & veils', 'x-card'],
                'tone' => fake()->randomElement(['serious', 'lighthearted', 'heroic', 'dark']),
                'house_rules' => fake()->optional()->paragraph(),
                'content_warnings' => fake()->optional()->sentence(),
                'player_expectations' => fake()->optional()->paragraph(),
            ],
            'status' => 'active',
        ];
    }

    /**
     * Campaign charter row (M070): kind='charter', owned by a campaign
     * (ownership derives from the campaign owner — no GMProfile anchor),
     * in the authoring draft state. Content is a minimal full-section
     * array (schema-v1 section keys locked by the CharterContent DTO,
     * M070/S01-T02; the charter's 8th section, The Agreement, is
     * signing-time copy, not stored content) — keep identical to the
     * version factory's content.
     */
    public function charter(): static
    {
        return $this->state(fn (array $attributes) => [
            'campaign_id' => Campaign::factory(),
            'gm_profile_id' => null,
            'status' => 'draft',
            'current_version' => null,
            'content' => [
                'overview' => ['pitch' => 'A test campaign charter'],
                'boundaries' => ['lines_text' => 'No harm to children'],
                'safety' => ['tools' => ['session-zero', 'lines-and-veils']],
                'policies' => ['pvp' => 'limited'],
                'ai_tools' => ['ai_prep' => 'case_by_case'],
                'logistics' => ['cadence' => 'weekly'],
                'character' => ['build_constraints' => 'Level 1, PHB only'],
            ],
        ]);
    }

    /**
     * Published lifecycle state: active with a current version pointer.
     * Pair with SessionZeroSurveyVersionFactory::forSurvey() to make the
     * pointer resolve: publish() in CharterService always snapshots first.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'current_version' => 1,
        ]);
    }

    /**
     * Set the survey status to archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'archived',
        ]);
    }
}
