<?php

namespace Tests\Feature\Models;

use App\Models\Game;
use App\Models\GMProfile;
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

    // ── Scopes (filter logic, not trivial column checks) ──────

    public function test_scope_active_filters_by_status(): void
    {
        SessionZeroSurvey::factory()->create(['status' => 'active']);
        SessionZeroSurvey::factory()->archived()->create();

        $active = SessionZeroSurvey::active()->get();

        $this->assertCount(1, $active);
        $this->assertEquals('active', $active->first()->status);
    }

    public function test_scope_archived_filters_by_status(): void
    {
        SessionZeroSurvey::factory()->create(['status' => 'active']);
        SessionZeroSurvey::factory()->archived()->create();

        $archived = SessionZeroSurvey::archived()->get();

        $this->assertCount(1, $archived);
        $this->assertEquals('archived', $archived->first()->status);
    }

    // ── Helpers (state transitions, lookups) ──────────────────

    public function test_archive_transitions_status(): void
    {
        $survey = SessionZeroSurvey::factory()->create(['status' => 'active']);
        $survey->archive();

        $this->assertEquals('archived', $survey->fresh()->status);
    }

    public function test_increment_confirmation_count(): void
    {
        $survey = SessionZeroSurvey::factory()->create(['confirmation_count' => 0]);
        $survey->incrementConfirmationCount();

        $this->assertEquals(1, $survey->fresh()->confirmation_count);
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

    // ── SessionZeroConfirmation constraints ──────────────────

    public function test_unique_constraint_on_survey_and_user(): void
    {
        $survey = SessionZeroSurvey::factory()->create();
        $user = User::factory()->create();

        SessionZeroConfirmation::factory()->create([
            'session_zero_survey_id' => $survey->id,
            'user_id' => $user->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        SessionZeroConfirmation::factory()->create([
            'session_zero_survey_id' => $survey->id,
            'user_id' => $user->id,
        ]);
    }
}
