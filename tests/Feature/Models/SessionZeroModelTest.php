<?php

namespace Tests\Feature\Models;

use App\Models\SessionZeroConfirmation;
use App\Models\SessionZeroSurvey;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\SetsUpLocale;

class SessionZeroModelTest extends TestCase
{
    use DatabaseTransactions;
    use SetsUpLocale;

    // ── Helpers (state transitions, lookups) ──────────────────

    public function test_archive_transitions_status(): void
    {
        $survey = SessionZeroSurvey::factory()->create(['status' => 'active']);
        $survey->archive();

        $this->assertEquals('archived', $survey->fresh()->status);
    }

    public function test_find_by_uuid(): void
    {
        $survey = SessionZeroSurvey::factory()->create();
        $found = SessionZeroSurvey::findByUuid($survey->uuid);

        $this->assertNotNull($found);
        $this->assertTrue($found->is($survey));
    }

    // ── Cascade Delete (data integrity) ──────────────────────

    public function test_survey_deleted_when_gm_profile_deleted(): void
    {
        $survey = SessionZeroSurvey::factory()->create();
        $surveyId = $survey->id;

        $survey->gmProfile->delete();

        $this->assertDatabaseMissing('session_zero_surveys', ['id' => $surveyId]);
    }

    public function test_confirmations_deleted_when_survey_deleted(): void
    {
        $confirmation = SessionZeroConfirmation::factory()->create();
        $confirmationId = $confirmation->id;

        $confirmation->survey->delete();

        $this->assertDatabaseMissing('session_zero_confirmations', ['id' => $confirmationId]);
    }

    // ── Cascade Delete (data integrity) ──────────────────────
}
