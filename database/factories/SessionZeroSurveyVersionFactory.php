<?php

namespace Database\Factories;

use App\Models\SessionZeroSurvey;
use App\Models\SessionZeroSurveyVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SessionZeroSurveyVersion> */
class SessionZeroSurveyVersionFactory extends Factory
{
    protected $model = SessionZeroSurveyVersion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'session_zero_survey_id' => SessionZeroSurvey::factory()->charter(),
            'version_number' => 1,
            // Minimal valid schema-v1 charter content (section keys locked
            // by the CharterContent DTO, M070/S01-T02). The charter's 8th
            // section (The Agreement) is signing-time copy, not stored
            // content — keep this array identical to the survey factory's
            // charter() state.
            'content' => [
                'overview' => ['pitch' => 'A test campaign charter'],
                'boundaries' => ['lines_text' => 'No harm to children'],
                'safety' => ['tools' => ['session-zero', 'lines-and-veils']],
                'policies' => ['pvp' => 'limited'],
                'ai_tools' => ['ai_prep' => 'case_by_case'],
                'logistics' => ['cadence' => 'weekly'],
                'character' => ['build_constraints' => 'Level 1, PHB only'],
            ],
            'content_schema' => 1,
            'changelog' => null,
            'is_material' => false,
            'published_at' => now(),
        ];
    }

    /**
     * Snapshot for a specific charter survey (keeps the given version number).
     */
    public function forSurvey(SessionZeroSurvey $survey, int $versionNumber = 1): static
    {
        return $this->state(fn (array $attributes): array => [
            'session_zero_survey_id' => $survey->id,
            'version_number' => $versionNumber,
        ]);
    }
}
