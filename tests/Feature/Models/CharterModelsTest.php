<?php

use App\Models\Campaign;
use App\Models\SessionZeroConfirmation;
use App\Models\SessionZeroSurvey;
use App\Models\SessionZeroSurveyVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// M070/S01-T01 — charter schema: Campaign::charter() relation,
// SessionZeroSurveyVersion model, kind/lifecycle scopes, and the
// one-charter-per-campaign race backstop.
//
// M070/S02-T01 — confirmation schema for versioned signing: version pin,
// jsonb attestations/private_answers, status/role CHECK columns, and the
// partial unique index backing the double-sign race backstop.

// ── Campaign::charter() relation ─────────────────────────

test('campaign charter relation returns only the charter-kind row', function () {
    $campaign = Campaign::factory()->create();

    // Same campaign_id but legacy kind='survey' — the relation's kind
    // predicate must exclude it.
    SessionZeroSurvey::factory()->create(['campaign_id' => $campaign->id]);

    $charter = SessionZeroSurvey::factory()->charter()->create(['campaign_id' => $campaign->id]);

    $resolved = $campaign->charter()->first();

    expect($resolved)->not->toBeNull()
        ->and($resolved->is($charter))->toBeTrue()
        ->and($resolved->isCharter())->toBeTrue();
});

test('campaign charter relation is null when the campaign has no charter', function () {
    $campaign = Campaign::factory()->create();

    expect($campaign->charter)->toBeNull();
});

// ── Version relation & pointer ───────────────────────────

test('versions relation is ordered newest first', function () {
    $charter = SessionZeroSurvey::factory()->charter()->create();

    foreach ([1, 2, 3] as $versionNumber) {
        SessionZeroSurveyVersion::factory()->forSurvey($charter, $versionNumber)->create();
    }

    expect($charter->versions->pluck('version_number')->all())->toBe([3, 2, 1]);
});

test('currentVersion resolves the published pointer', function () {
    $charter = SessionZeroSurvey::factory()->charter()->published()->create(['current_version' => 2]);
    SessionZeroSurveyVersion::factory()->forSurvey($charter, 1)->create();
    $v2 = SessionZeroSurveyVersion::factory()->forSurvey($charter, 2)->create();

    $current = $charter->currentVersion();

    expect($current)->not->toBeNull()
        ->and($current->is($v2))->toBeTrue()
        ->and($v2->survey->is($charter))->toBeTrue();
});

test('currentVersion is null for drafts and dangling pointers', function () {
    $draft = SessionZeroSurvey::factory()->charter()->create();

    expect($draft->current_version)->toBeNull()
        ->and($draft->currentVersion())->toBeNull();

    // Defensive: pointer set but no matching snapshot row.
    $dangling = SessionZeroSurvey::factory()->charter()->published()->create(['current_version' => 5]);

    expect($dangling->currentVersion())->toBeNull();
});

// ── Scopes ───────────────────────────────────────────────

test('charter, draft, active and archived scopes filter correctly', function () {
    $draftCharter = SessionZeroSurvey::factory()->charter()->create();
    $publishedCharter = SessionZeroSurvey::factory()->charter()->published()->create();
    $legacyActive = SessionZeroSurvey::factory()->create(); // kind 'survey', status 'active'
    $legacyArchived = SessionZeroSurvey::factory()->archived()->create();

    expect(SessionZeroSurvey::query()->charter()->pluck('id')->all())
        ->toEqualCanonicalizing([$draftCharter->id, $publishedCharter->id]);

    expect(SessionZeroSurvey::query()->charter()->draft()->pluck('id')->all())
        ->toBe([$draftCharter->id]);

    // Legacy status scopes stay purely status-based across kinds.
    expect(SessionZeroSurvey::query()->active()->pluck('id')->all())
        ->toEqualCanonicalizing([$publishedCharter->id, $legacyActive->id]);

    expect(SessionZeroSurvey::query()->archived()->pluck('id')->all())
        ->toBe([$legacyArchived->id]);
});

// ── One-charter-per-campaign race backstop ───────────────

test('a second charter for the same campaign violates the partial unique index', function () {
    $campaign = Campaign::factory()->create();
    SessionZeroSurvey::factory()->charter()->create(['campaign_id' => $campaign->id]);

    $caught = null;
    try {
        // The savepoint (nested DB::transaction) keeps the aborted
        // statement from poisoning the outer test transaction (pattern:
        // RegistrationDuplicateCheckTest).
        DB::transaction(fn () => SessionZeroSurvey::factory()->charter()->create([
            'campaign_id' => $campaign->id,
        ]));
        $this->fail('Expected a unique violation from session_zero_surveys_charter_campaign_unique.');
    } catch (QueryException $e) {
        $caught = $e;
    }

    // Postgres: SQLSTATE 23505 naming the partial index.
    expect($caught->errorInfo[0] ?? null)->toBe('23505');
    expect($caught->getMessage())->toContain('session_zero_surveys_charter_campaign_unique');

    // Exactly one charter survives the "race".
    expect(SessionZeroSurvey::query()->charter()
        ->where('campaign_id', $campaign->id)
        ->count())->toBe(1);
});

// ── CHECK constraints ────────────────────────────────────

test('kind and status check constraints reject unknown values', function () {
    $kindViolation = null;
    try {
        DB::transaction(fn () => SessionZeroSurvey::factory()->create(['kind' => 'warlord']));
        $this->fail('Expected a check violation from session_zero_surveys_kind_check.');
    } catch (QueryException $e) {
        $kindViolation = $e;
    }

    expect($kindViolation->errorInfo[0] ?? null)->toBe('23514');
    expect($kindViolation->getMessage())->toContain('session_zero_surveys_kind_check');

    $statusViolation = null;
    try {
        DB::transaction(fn () => SessionZeroSurvey::factory()->create(['status' => 'zombie']));
        $this->fail('Expected a check violation from session_zero_surveys_status_check.');
    } catch (QueryException $e) {
        $statusViolation = $e;
    }

    expect($statusViolation->errorInfo[0] ?? null)->toBe('23514');
    expect($statusViolation->getMessage())->toContain('session_zero_surveys_status_check');
});

// ── Legacy behavior ──────────────────────────────────────

test('legacy surveys are unaffected: kind defaults to survey with gm profile set', function () {
    $survey = SessionZeroSurvey::factory()->create();

    expect($survey->kind)->toBe('survey')
        ->and($survey->isCharter())->toBeFalse()
        ->and($survey->isActive())->toBeTrue()
        ->and($survey->current_version)->toBeNull()
        ->and($survey->gmProfile)->not->toBeNull()
        ->and($survey->campaign)->toBeNull();
});

// ── Cascades ─────────────────────────────────────────────

test('deleting a survey cascades to its version snapshots', function () {
    $charter = SessionZeroSurvey::factory()->charter()->published()->create();
    $version = SessionZeroSurveyVersion::factory()->forSurvey($charter, 1)->create();
    $versionId = $version->id;

    $charter->delete();

    expect(SessionZeroSurveyVersion::find($versionId))->toBeNull();
});

test('deleting a campaign cascades to its charter', function () {
    $campaign = Campaign::factory()->create();
    $charter = SessionZeroSurvey::factory()->charter()->create(['campaign_id' => $campaign->id]);

    $campaign->delete();

    expect(SessionZeroSurvey::find($charter->id))->toBeNull();
});

// ── M070/S02-T01: confirmation schema for versioned signing ──

test('legacy confirmations default to signed participant rows with no version pin', function () {
    $confirmation = SessionZeroConfirmation::factory()->create();

    // Assert against the persisted row: status/role come from the DB
    // column defaults, which Eloquent does not hydrate into the
    // in-memory instance on create.
    $fresh = $confirmation->fresh();

    expect($fresh->status)->toBe(SessionZeroConfirmation::STATUS_SIGNED)
        ->and($fresh->role)->toBe(SessionZeroConfirmation::ROLE_PARTICIPANT)
        ->and($fresh->session_zero_survey_version_id)->toBeNull()
        ->and($fresh->attestations)->toBeNull()
        ->and($fresh->private_answers)->toBeNull()
        ->and($fresh->version)->toBeNull()
        // Legacy columns keep their original meaning.
        ->and($fresh->confirmed_at)->not->toBeNull()
        ->and($fresh->survey)->not->toBeNull()
        ->and($fresh->user)->not->toBeNull();
});

test('charter confirmations pin to a version and round-trip jsonb casts both ways', function () {
    $charter = SessionZeroSurvey::factory()->charter()->published()->create();
    $version = SessionZeroSurveyVersion::factory()->forSurvey($charter, 1)->create();

    // Read attestation + the four §8 agreement ticks (S01 copy shapes).
    $attestations = [
        'read_charter' => true,
        'agreements' => [
            'boundaries' => true,
            'safety_tools' => true,
            'policies' => true,
            'amendments' => true,
        ],
    ];
    // §2 two-way private boundary answers.
    $privateAnswers = [
        'lines' => 'No harm to pets',
        'veils' => 'Fade-to-black for romance',
    ];

    $confirmation = SessionZeroConfirmation::factory()
        ->onVersion($version)
        ->create([
            'attestations' => $attestations,
            'private_answers' => $privateAnswers,
        ]);

    $fresh = $confirmation->fresh();

    // onVersion() also anchors the NOT NULL survey FK to the charter.
    // toEqual, not toBe: jsonb does not preserve key order (Postgres sorts
    // keys by length, then bytewise), so a round trip reorders object keys.
    expect($fresh->session_zero_survey_version_id)->toBe($version->id)
        ->and($fresh->session_zero_survey_id)->toBe($charter->id)
        ->and($fresh->attestations)->toEqual($attestations)
        ->and($fresh->private_answers)->toEqual($privateAnswers)
        // version() resolves the pinned snapshot...
        ->and($fresh->version->is($version))->toBeTrue()
        // ...and the inverse HasMany on the version resolves back.
        ->and($version->confirmations->pluck('id'))->toContain($fresh->id);
});

test('a second signature for the same version, user and role violates the partial unique index', function () {
    $charter = SessionZeroSurvey::factory()->charter()->published()->create();
    $version = SessionZeroSurveyVersion::factory()->forSurvey($charter, 1)->create();
    $user = User::factory()->create();

    SessionZeroConfirmation::factory()->onVersion($version)->create(['user_id' => $user->id]);

    $caught = null;
    try {
        // The savepoint (nested DB::transaction) keeps the aborted
        // statement from poisoning the outer test transaction (pattern:
        // RegistrationDuplicateCheckTest).
        DB::transaction(fn () => SessionZeroConfirmation::factory()->onVersion($version)->create([
            'user_id' => $user->id,
        ]));
        $this->fail('Expected a unique violation from session_zero_confirmations_version_user_role_unique.');
    } catch (QueryException $e) {
        $caught = $e;
    }

    // Postgres: SQLSTATE 23505 naming the partial index.
    expect($caught->errorInfo[0] ?? null)->toBe('23505');
    expect($caught->getMessage())->toContain('session_zero_confirmations_version_user_role_unique');

    // The role axis of the index: the same user MAY hold a GM
    // countersignature next to their participant signature.
    $gmSignature = SessionZeroConfirmation::factory()
        ->onVersion($version)
        ->gm()
        ->create(['user_id' => $user->id]);

    expect($gmSignature->role)->toBe(SessionZeroConfirmation::ROLE_GM)
        ->and(SessionZeroConfirmation::query()
            ->where('session_zero_survey_version_id', $version->id)
            ->count())->toBe(2);
});

test('status and role check constraints reject unknown values', function () {
    $version = SessionZeroSurveyVersion::factory()->create();

    $statusViolation = null;
    try {
        DB::transaction(fn () => SessionZeroConfirmation::factory()->onVersion($version)->create([
            'status' => 'zombie',
        ]));
        $this->fail('Expected a check violation from session_zero_confirmations_status_check.');
    } catch (QueryException $e) {
        $statusViolation = $e;
    }

    expect($statusViolation->errorInfo[0] ?? null)->toBe('23514');
    expect($statusViolation->getMessage())->toContain('session_zero_confirmations_status_check');

    $roleViolation = null;
    try {
        DB::transaction(fn () => SessionZeroConfirmation::factory()->onVersion($version)->create([
            'role' => 'warlord',
        ]));
        $this->fail('Expected a check violation from session_zero_confirmations_role_check.');
    } catch (QueryException $e) {
        $roleViolation = $e;
    }

    expect($roleViolation->errorInfo[0] ?? null)->toBe('23514');
    expect($roleViolation->getMessage())->toContain('session_zero_confirmations_role_check');
});

test('deleting a version cascades to its pinned confirmations', function () {
    $charter = SessionZeroSurvey::factory()->charter()->published()->create();
    $version = SessionZeroSurveyVersion::factory()->forSurvey($charter, 1)->create();
    $confirmation = SessionZeroConfirmation::factory()->onVersion($version)->create();
    $confirmationId = $confirmation->id;

    $version->delete();

    expect(SessionZeroConfirmation::find($confirmationId))->toBeNull();
});

test('pending reagreement state marks a signature awaiting re-signature', function () {
    $version = SessionZeroSurveyVersion::factory()->create();

    $confirmation = SessionZeroConfirmation::factory()
        ->onVersion($version)
        ->pendingReagreement()
        ->create();

    expect($confirmation->status)->toBe(SessionZeroConfirmation::STATUS_PENDING_REAGREEMENT)
        ->and($confirmation->fresh()->role)->toBe(SessionZeroConfirmation::ROLE_PARTICIPANT);
});

test('legacy rows still enforce one confirmation per survey and user', function () {
    $survey = SessionZeroSurvey::factory()->create();
    $user = User::factory()->create();

    SessionZeroConfirmation::factory()->create([
        'session_zero_survey_id' => $survey->id,
        'user_id' => $user->id,
    ]);

    $caught = null;
    try {
        // Savepoint pattern (RegistrationDuplicateCheckTest).
        DB::transaction(fn () => SessionZeroConfirmation::factory()->create([
            'session_zero_survey_id' => $survey->id,
            'user_id' => $user->id,
        ]));
        $this->fail('Expected a unique violation from session_zero_confirmations_session_zero_survey_id_user_id_uniqu.');
    } catch (QueryException $e) {
        $caught = $e;
    }

    // The narrowed legacy index keeps legacy semantics: SQLSTATE 23505 on
    // the (survey, user) pair for rows without a version pin.
    expect($caught->errorInfo[0] ?? null)->toBe('23505');
    expect($caught->getMessage())->toContain('session_zero_confirmations_session_zero_survey_id_user_id_uniqu');
});
