<?php

use App\Enums\JoinSource;
use App\Models\CampaignParticipant;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// M084/S06-T01 — JoinSource::RosterReinvite (D321) at the enum AND the database level.
//
// `join_source` is cast to the JoinSource enum on both participant models, but
// PostgreSQL enforces the allowed values with a native CHECK constraint per
// table. The test bootstrap loads the committed dump (seven values) and THEN
// runs the pending migration files, so the "persists" cases below prove the
// constraints were really altered — an enum-only change fails there with a
// constraint violation — while the "still rejected" cases prove the constraints
// were not weakened on the way.

// ── Enum ────────────────────────────────────────────────

test('roster_reinvite is a JoinSource case', function () {
    $source = JoinSource::from('roster_reinvite');

    expect($source)->toBe(JoinSource::RosterReinvite)
        ->and($source->value)->toBe('roster_reinvite')
        ->and(JoinSource::values())->toContain('roster_reinvite');
});

test('the roster re-invite label resolves from the lang files', function (string $locale) {
    app()->setLocale($locale);

    $label = JoinSource::RosterReinvite->label();

    // K004: pin the key, never the wording. The second expectation fails when
    // the key is missing in a locale, because __() then returns the key itself.
    expect($label)->toBe(__('common.content_source_roster_reinvite'))
        ->not->toBe('common.content_source_roster_reinvite');
})->with(['en', 'de']);

// ── Database: the CHECK constraints accept the value ───

test('a game participant persists with join_source roster_reinvite', function () {
    $participant = GameParticipant::factory()->create(['join_source' => JoinSource::RosterReinvite]);

    $participant->refresh();

    expect($participant->join_source)->toBe(JoinSource::RosterReinvite)
        ->and($participant->source_label)->toBe(__('common.content_source_roster_reinvite'));

    $this->assertDatabaseHas('game_participants', [
        'id' => $participant->id,
        'join_source' => JoinSource::RosterReinvite->value,
    ]);
});

test('a campaign participant persists with join_source roster_reinvite', function () {
    $participant = CampaignParticipant::factory()->create(['join_source' => JoinSource::RosterReinvite]);

    $participant->refresh();

    expect($participant->join_source)->toBe(JoinSource::RosterReinvite)
        ->and($participant->source_label)->toBe(__('common.content_source_roster_reinvite'));

    $this->assertDatabaseHas('campaign_participants', [
        'id' => $participant->id,
        'join_source' => JoinSource::RosterReinvite->value,
    ]);
});

// ── Database: the CHECK constraints still reject ────────

test('an unknown join source is still rejected by the check constraint', function (string $table, string $constraint) {
    $participant = match ($table) {
        'game_participants' => GameParticipant::factory()->create(),
        'campaign_participants' => CampaignParticipant::factory()->create(),
    };

    // A raw query-builder insert bypasses the enum cast, so a rejection here
    // can only come from the database constraint. A fresh primary key and a
    // fresh user keep the (parent, user_id) unique index out of the way.
    $row = array_merge($participant->getAttributes(), [
        'id' => (string) Str::uuid(),
        'user_id' => User::factory()->create()->id,
        'join_source' => 'carrier_pigeon',
    ]);

    $caught = null;
    try {
        // The nested transaction is a savepoint here: Postgres aborts a whole
        // transaction on any statement error until it is rolled back, so
        // without it the expected failure would poison the test's own
        // transaction and the count() below could not run.
        DB::transaction(fn () => DB::table($table)->insert($row));
        $this->fail("Expected a check violation from {$constraint}.");
    } catch (QueryException $e) {
        $caught = $e;
    }

    // Postgres: SQLSTATE 23514 naming the constraint.
    expect($caught->errorInfo[0] ?? null)->toBe('23514')
        ->and($caught->getMessage())->toContain($constraint)
        ->and(DB::table($table)->where('join_source', 'carrier_pigeon')->count())->toBe(0);
})->with([
    'game participants' => ['game_participants', 'game_participants_join_source_check'],
    'campaign participants' => ['campaign_participants', 'campaign_participants_join_source_check'],
]);
