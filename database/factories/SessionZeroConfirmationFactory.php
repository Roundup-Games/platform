<?php

namespace Database\Factories;

use App\Models\SessionZeroConfirmation;
use App\Models\SessionZeroSurvey;
use App\Models\SessionZeroSurveyVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SessionZeroConfirmation>
 */
class SessionZeroConfirmationFactory extends Factory
{
    protected $model = SessionZeroConfirmation::class;

    /**
     * Define the model's default state: a legacy survey confirmation.
     *
     * The charter-signing columns (session_zero_survey_version_id,
     * attestations, private_answers, status, role) are deliberately omitted
     * so legacy rows rely on DB defaults (status 'signed', role
     * 'participant') and NULLs — onVersion() opts a row into signing.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_zero_survey_id' => SessionZeroSurvey::factory(),
            'user_id' => User::factory(),
            'confirmed_at' => now(),
        ];
    }

    /**
     * Create an unconfirmed entry (no confirmed_at timestamp).
     */
    public function unconfirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'confirmed_at' => null,
        ]);
    }

    /**
     * Pin the confirmation to an immutable charter version snapshot (M070/S02
     * signing). session_zero_survey_id stays populated — the column is NOT
     * NULL and roster queries walk survey -> versions -> confirmations.
     */
    public function onVersion(SessionZeroSurveyVersion $version): static
    {
        return $this->state(fn (array $attributes): array => [
            'session_zero_survey_id' => $version->session_zero_survey_id,
            'session_zero_survey_version_id' => $version->id,
        ]);
    }

    /**
     * GM countersignature row (role discriminator on the same table).
     */
    public function gm(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => SessionZeroConfirmation::ROLE_GM,
        ]);
    }

    /**
     * Agreement reset by a material amendment (M070/S03).
     */
    public function pendingReagreement(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SessionZeroConfirmation::STATUS_PENDING_REAGREEMENT,
        ]);
    }
}
