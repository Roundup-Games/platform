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

class SessionZeroModelTest extends TestCase
{
    use DatabaseTransactions;

    // ── Migration / Table Structure ────────────────────

    public function test_session_zero_surveys_table_exists(): void
    {
        $survey = SessionZeroSurvey::factory()->create();

        $this->assertDatabaseHas('session_zero_surveys', [
            'id' => $survey->id,
        ]);
    }

    public function test_survey_id_is_uuid(): void
    {
        $survey = SessionZeroSurvey::factory()->create();

        $this->assertIsString($survey->id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $survey->id,
        );
    }

    public function test_survey_uuid_is_auto_generated(): void
    {
        $survey = SessionZeroSurvey::factory()->create();

        $this->assertNotNull($survey->uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $survey->uuid,
        );
    }

    public function test_uuid_is_unique(): void
    {
        $survey1 = SessionZeroSurvey::factory()->create();
        $survey2 = SessionZeroSurvey::factory()->create();

        $this->assertNotEquals($survey1->uuid, $survey2->uuid);
    }

    public function test_status_defaults_to_active(): void
    {
        $survey = SessionZeroSurvey::factory()->create();

        $this->assertEquals('active', $survey->status);
    }

    public function test_confirmation_count_defaults_to_zero(): void
    {
        $survey = SessionZeroSurvey::factory()->create();

        $this->assertSame(0, $survey->confirmation_count);
    }

    public function test_game_id_is_nullable(): void
    {
        $survey = SessionZeroSurvey::factory()->create(['game_id' => null]);

        $this->assertNull($survey->game_id);
    }

    public function test_content_cast_to_array(): void
    {
        $content = [
            'safety_tools' => ['lines & veils', 'x-card'],
            'tone' => 'serious',
            'house_rules' => 'No PvP',
            'content_warnings' => 'horror themes',
            'player_expectations' => 'Be on time',
        ];
        $survey = SessionZeroSurvey::factory()->create(['content' => $content]);

        $survey->refresh();
        $this->assertIsArray($survey->content);
        $this->assertEquals($content, $survey->content);
    }

    public function test_content_is_nullable(): void
    {
        $survey = SessionZeroSurvey::factory()->create(['content' => null]);

        $this->assertNull($survey->content);
    }

    // ── Relationships ──────────────────────────────────

    public function test_survey_belongs_to_gm_profile(): void
    {
        $gmProfile = GMProfile::factory()->create();
        $survey = SessionZeroSurvey::factory()->create(['gm_profile_id' => $gmProfile->id]);

        $this->assertTrue($survey->gmProfile->is($gmProfile));
    }

    public function test_survey_belongs_to_game(): void
    {
        $game = Game::factory()->create();
        $survey = SessionZeroSurvey::factory()->create(['game_id' => $game->id]);

        $this->assertTrue($survey->game->is($game));
    }

    public function test_survey_has_many_confirmations(): void
    {
        $survey = SessionZeroSurvey::factory()->create();
        $confirmation = SessionZeroConfirmation::factory()->create([
            'session_zero_survey_id' => $survey->id,
        ]);

        $this->assertCount(1, $survey->fresh()->confirmations);
        $this->assertTrue($survey->confirmations->first()->is($confirmation));
    }

    // ── Scopes ─────────────────────────────────────────

    public function test_scope_active(): void
    {
        SessionZeroSurvey::factory()->create(['status' => 'active']);
        SessionZeroSurvey::factory()->create(['status' => 'archived']);

        $active = SessionZeroSurvey::active()->get();

        $this->assertCount(1, $active);
        $this->assertEquals('active', $active->first()->status);
    }

    public function test_scope_archived(): void
    {
        SessionZeroSurvey::factory()->create(['status' => 'active']);
        SessionZeroSurvey::factory()->archived()->create();

        $archived = SessionZeroSurvey::archived()->get();

        $this->assertCount(1, $archived);
        $this->assertEquals('archived', $archived->first()->status);
    }

    // ── Helpers ────────────────────────────────────────

    public function test_is_active(): void
    {
        $survey = SessionZeroSurvey::factory()->create(['status' => 'active']);

        $this->assertTrue($survey->isActive());
        $this->assertFalse($survey->isArchived());
    }

    public function test_is_archived(): void
    {
        $survey = SessionZeroSurvey::factory()->create(['status' => 'archived']);

        $this->assertTrue($survey->isArchived());
        $this->assertFalse($survey->isActive());
    }

    public function test_archive(): void
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

    public function test_find_by_uuid_returns_null_for_missing(): void
    {
        $found = SessionZeroSurvey::findByUuid(Str::uuid()->toString());

        $this->assertNull($found);
    }

    // ── Cascade Delete ─────────────────────────────────

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

    // ── SessionZeroConfirmation ────────────────────────

    public function test_confirmation_table_exists(): void
    {
        $confirmation = SessionZeroConfirmation::factory()->create();

        $this->assertDatabaseHas('session_zero_confirmations', [
            'id' => $confirmation->id,
        ]);
    }

    public function test_confirmation_id_is_uuid(): void
    {
        $confirmation = SessionZeroConfirmation::factory()->create();

        $this->assertIsString($confirmation->id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $confirmation->id,
        );
    }

    public function test_confirmed_at_auto_set_on_create(): void
    {
        $confirmation = SessionZeroConfirmation::factory()->create();

        $this->assertNotNull($confirmation->confirmed_at);
    }

    public function test_confirmed_at_cast_to_datetime(): void
    {
        $confirmation = SessionZeroConfirmation::factory()->create();

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $confirmation->confirmed_at);
    }

    public function test_user_id_is_nullable(): void
    {
        $confirmation = SessionZeroConfirmation::factory()->create(['user_id' => null]);

        $this->assertNull($confirmation->user_id);
    }

    public function test_confirmation_belongs_to_survey(): void
    {
        $survey = SessionZeroSurvey::factory()->create();
        $confirmation = SessionZeroConfirmation::factory()->create([
            'session_zero_survey_id' => $survey->id,
        ]);

        $this->assertTrue($confirmation->survey->is($survey));
    }

    public function test_confirmation_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $confirmation = SessionZeroConfirmation::factory()->create([
            'user_id' => $user->id,
        ]);

        $this->assertTrue($confirmation->user->is($user));
    }

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

    public function test_same_user_can_confirm_different_surveys(): void
    {
        $user = User::factory()->create();
        $survey1 = SessionZeroSurvey::factory()->create();
        $survey2 = SessionZeroSurvey::factory()->create();

        $c1 = SessionZeroConfirmation::factory()->create([
            'session_zero_survey_id' => $survey1->id,
            'user_id' => $user->id,
        ]);
        $c2 = SessionZeroConfirmation::factory()->create([
            'session_zero_survey_id' => $survey2->id,
            'user_id' => $user->id,
        ]);

        $this->assertNotEquals($c1->id, $c2->id);
        $this->assertDatabaseCount('session_zero_confirmations', 2);
    }

    // ── Factory ────────────────────────────────────────

    public function test_survey_factory_creates_valid_record(): void
    {
        $survey = SessionZeroSurvey::factory()->create();

        $this->assertNotNull($survey->id);
        $this->assertNotNull($survey->uuid);
        $this->assertNotNull($survey->gm_profile_id);
        $this->assertNotNull($survey->title);
        $this->assertEquals('active', $survey->status);
    }

    public function test_confirmation_factory_creates_valid_record(): void
    {
        $confirmation = SessionZeroConfirmation::factory()->create();

        $this->assertNotNull($confirmation->id);
        $this->assertNotNull($confirmation->session_zero_survey_id);
        $this->assertNotNull($confirmation->confirmed_at);
    }

    public function test_survey_factory_for_game(): void
    {
        $game = Game::factory()->create();
        $survey = SessionZeroSurvey::factory()->forGame($game)->create();

        $this->assertEquals($game->id, $survey->game_id);
    }

    public function test_survey_factory_archived_state(): void
    {
        $survey = SessionZeroSurvey::factory()->archived()->create();

        $this->assertEquals('archived', $survey->status);
    }
}
