<?php

use App\Dto\CharterContent;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\GMProfile;
use App\Models\Location;
use App\Models\SessionZeroConfirmation;
use App\Models\SessionZeroSurvey;
use App\Models\SessionZeroSurveyVersion;
use App\Models\User;
use App\Services\Charter\CharterService;
use App\Services\Charter\CharterSigningService;
use App\Services\Charter\CharterTemplateService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Traits\CapturesLogRecords;
use Tests\Traits\CreatesUsers;

uses(CapturesLogRecords::class, CreatesUsers::class);

// M070/S01-T03 — CharterService: owner-gated start (idempotent, race-safe
// via the partial unique index), guarded draft saves, and transactional
// publish to immutable version 1. saveDraft/publish operate on the charter
// row the caller loaded owner-gated (the composer re-resolves the owner in
// mount); start() re-authorizes because it receives the user directly.

/**
 * Campaign owner + campaign with deterministic quick-start inputs
 * (no GMProfile → nullable gm_profile_id; no location → venue rotates).
 *
 * @return array{0: User, 1: Campaign}
 */
function charter_campaign_owner(): array
{
    $owner = User::factory()->create();
    $campaign = Campaign::factory()->create([
        'owner_id' => $owner->id,
        'name' => ['en' => 'The Threshold Heist'],
        'description' => ['en' => 'A noir intrigue campaign in the city of Threshold.'],
        'max_players' => 5,
        'session_duration' => 3,
        'recurrence' => 'weekly',
    ]);

    return [$owner, $campaign];
}

/**
 * Fully publishable schema-v1 content: quick-start defaults plus the one
 * authoring choice quick-start deliberately leaves empty (expected shape).
 *
 * @return array<string, mixed>
 */
function charter_valid_content(Campaign $campaign): array
{
    $content = CharterContent::quickStart($campaign)->toArray();
    $content['overview']['expected_shape'] = 'ongoing';

    return $content;
}

// ── start() ──────────────────────────────────────────────

test('start creates a draft charter with quick-start prefill for the owner', function () {
    [$owner, $campaign] = charter_campaign_owner();

    $charter = CharterService::start($owner, $campaign);

    expect($charter)->toBeInstanceOf(SessionZeroSurvey::class)
        ->and($charter->kind)->toBe('charter')
        ->and($charter->campaign_id)->toBe($campaign->id)
        ->and($charter->status)->toBe('draft')
        ->and($charter->current_version)->toBeNull()
        ->and($charter->gm_profile_id)->toBeNull()
        ->and($charter->title)->toBe('The Threshold Heist')
        ->and($charter->uuid)->toBeString()->not->toBe('');

    $content = $charter->content;
    expect($content['overview']['pitch'])->toBe('A noir intrigue campaign in the city of Threshold.')
        ->and($content['overview']['table_size'])->toBe(5)
        ->and($content['logistics']['cadence'])->toBe('weekly')
        ->and($content['logistics']['session_length'])->toBe('3h')
        ->and($content['logistics']['venue_mode'])->toBe('rotate')
        ->and($content['safety']['tools'])->toContain('session-zero')
        ->and($content['policies']['pvp'])->toBe('limited');
});

test('start in blank mode seeds blank defaults instead of campaign prefill', function () {
    [$owner, $campaign] = charter_campaign_owner();

    $content = CharterService::start($owner, $campaign, 'blank')->content;

    expect($content['overview']['pitch'])->toBe('')
        ->and($content['safety']['tools'])->toBe([])
        ->and($content['policies']['pvp'])->toBe('')
        ->and($content['logistics']['cadence'])->toBe('');
});

test('start rejects non-owners with an authorization exception', function () {
    [$owner, $campaign] = charter_campaign_owner();
    $other = User::factory()->create();

    CharterService::start($other, $campaign);
})->throws(AuthorizationException::class);

test('start is idempotent: the second call returns the same row without overwriting it', function () {
    [$owner, $campaign] = charter_campaign_owner();

    $first = CharterService::start($owner, $campaign);

    // The owner edits the draft between the two start() calls.
    $mutated = CharterContent::fromArray($first->content)->toArray();
    $mutated['overview']['pitch'] = 'Hand-edited pitch';
    CharterService::saveDraft($owner, $first, 'Hand-edited title', $mutated);

    $second = CharterService::start($owner, $campaign, 'blank');

    expect($second->is($first))->toBeTrue()
        ->and($second->title)->toBe('Hand-edited title')
        ->and($second->content['overview']['pitch'])->toBe('Hand-edited pitch')
        ->and(SessionZeroSurvey::query()->charter()->where('campaign_id', $campaign->id)->count())->toBe(1);
});

test('start anchors gm_profile_id when the owner has a GM profile', function () {
    [$owner, $campaign] = charter_campaign_owner();
    $gmProfile = GMProfile::factory()->create(['user_id' => $owner->id]);

    expect(CharterService::start($owner, $campaign)->gm_profile_id)->toBe($gmProfile->id);
});

// ── charterFor() ─────────────────────────────────────────

test('charterFor resolves the campaign charter in any state', function () {
    $campaign = Campaign::factory()->create();

    expect(CharterService::charterFor($campaign))->toBeNull();

    $charter = SessionZeroSurvey::factory()->charter()->create(['campaign_id' => $campaign->id]);

    expect(CharterService::charterFor($campaign)?->is($charter))->toBeTrue();
});

// ── saveDraft() ──────────────────────────────────────────

test('saveDraft updates the draft title and normalizes content through fromArray', function () {
    [$owner, $campaign] = charter_campaign_owner();
    $charter = CharterService::start($owner, $campaign);

    $content = charter_valid_content($campaign);
    $content['overview']['pitch'] = '  Revised pitch  ';
    $content['unknown_section'] = ['nope' => true];

    CharterService::saveDraft($owner, $charter, 'Revised title', $content);

    $fresh = $charter->fresh();
    expect($fresh->title)->toBe('Revised title')
        ->and($fresh->content['overview']['pitch'])->toBe('Revised pitch')
        ->and(array_keys($fresh->content))->toBe(CharterContent::SECTIONS);
});

test('saveDraft rejects a charter that is no longer a draft', function () {
    [$owner, $campaign] = charter_campaign_owner();
    $charter = SessionZeroSurvey::factory()->charter()->published()->create(['campaign_id' => $campaign->id]);

    CharterService::saveDraft($owner, $charter, 'x', CharterContent::blank()->toArray());
})->throws(ValidationException::class);

test('saveDraft refuses a save that loses the race with a concurrent publish — no draft content on the published row', function () {
    [$owner, $campaign] = charter_campaign_owner();
    CharterService::start($owner, $campaign);

    // Two in-memory copies of the same draft: the "autosave" instance
    // goes stale while the "composer" instance publishes.
    $stale = SessionZeroSurvey::query()->where('campaign_id', $campaign->id)->sole();
    $live = SessionZeroSurvey::query()->where('campaign_id', $campaign->id)->sole();

    CharterService::publish($owner, $live, 'Published title', charter_valid_content($campaign));

    try {
        // The stale copy still says draft, so the pre-check passes — the
        // conditional update is what must refuse, or the published
        // charter's title/content would be rewritten without a version.
        CharterService::saveDraft($owner, $stale, 'Stale autosave', CharterContent::blank()->toArray());
        $this->fail('Expected ValidationException for a lost draft-save race.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('charter');
    }

    $fresh = $live->fresh();

    expect($fresh->status)->toBe('active')
        ->and($fresh->title)->toBe('Published title');
});

// ── Per-mutation actor authorization (manageCharter) ─────

test('saveDraft rejects a title longer than the varchar(255) column — the autosave path cannot 500', function () {
    [$owner, $campaign] = charter_campaign_owner();
    $charter = CharterService::start($owner, $campaign);
    $overlong = str_repeat('T', 256);

    try {
        CharterService::saveDraft($owner, $charter, $overlong, CharterContent::blank()->toArray());
        $this->fail('Expected ValidationException for an overlong title.');
    } catch (ValidationException $e) {
        expect($e->errors()['title'])->toBeArray();
    }

    // The draft row never reached Postgres — no partial write.
    expect($charter->fresh()->title)->not->toBe($overlong);
});

test('saveDraft rejects a non-owner actor', function () {
    [$owner, $campaign] = charter_campaign_owner();
    $charter = CharterService::start($owner, $campaign);
    $other = User::factory()->create();

    CharterService::saveDraft($other, $charter, 'x', CharterContent::blank()->toArray());
})->throws(AuthorizationException::class);

test('publish and amend refuse a venue the owner does not already disclose (D079)', function () {
    [$owner, $campaign] = charter_campaign_owner();
    $charter = CharterService::start($owner, $campaign, 'quickstart');

    // A third party's private venue uuid — well-formed, so the uuid
    // rule passes; never offered by the composer's owner-scoped set.
    $foreign = Location::factory()->create();

    $content = charter_valid_content($campaign);
    $content['logistics']['venue_mode'] = 'location';
    $content['logistics']['location_id'] = (string) $foreign->id;

    try {
        CharterService::publish($owner, $charter, (string) $campaign->name, $content);
        $this->fail('Expected ValidationException for a foreign venue.');
    } catch (ValidationException $e) {
        expect($e->errors()['logistics.location_id'])->toContain(__('charter.error_location_not_available'));
    }

    // Publish with the owner-disclosable set (the campaign's own venue)
    // passes the gate, and the amendment path carries the same rule.
    $ownLocation = Location::factory()->create();
    $campaign->update(['location_id' => $ownLocation->id]);
    $content['logistics']['location_id'] = (string) $ownLocation->id;

    CharterService::publish($owner, $charter, (string) $campaign->name, $content);
    $published = $charter->refresh();

    // The amendment path carries the same rule — a material edit that
    // swaps in the foreign venue is refused before any version is cut.
    $amendedContent = $content;
    $amendedContent['logistics']['location_id'] = (string) $foreign->id;

    try {
        CharterService::amend($owner, $published, (string) $campaign->name, $amendedContent, 'Venue moved.');
        $this->fail('Expected ValidationException for a foreign venue on amend.');
    } catch (ValidationException $e) {
        expect($e->errors()['logistics.location_id'])->toContain(__('charter.error_location_not_available'));
    }
});

test('publish rejects a non-owner actor', function () {
    [$owner, $campaign] = charter_campaign_owner();
    $charter = CharterService::start($owner, $campaign);
    $other = User::factory()->create();

    CharterService::publish($other, $charter, 'T', charter_valid_content($campaign));
})->throws(AuthorizationException::class);

test('amend rejects a non-owner actor', function () {
    [$owner, $campaign, $charter] = charter_published_v1();
    $other = User::factory()->create();

    CharterService::amend($other, $charter, 'T', charter_amendment_content($campaign, false), 'Tweaks.');
})->throws(AuthorizationException::class);

// ── publish() ────────────────────────────────────────────

test('publish snapshots immutable version 1 and flips the charter to active', function () {
    [$owner, $campaign] = charter_campaign_owner();
    $charter = CharterService::start($owner, $campaign);
    $content = charter_valid_content($campaign);

    $version = CharterService::publish($owner, $charter, 'Published title', $content);

    $expected = CharterContent::fromArray($content)->toArray();
    expect($version)->toBeInstanceOf(SessionZeroSurveyVersion::class)
        ->and($version->session_zero_survey_id)->toBe($charter->id)
        ->and($version->version_number)->toBe(1)
        ->and($version->content_schema)->toBe(1)
        ->and($version->content)->toBe($expected)
        ->and($version->changelog)->toBeNull()
        ->and($version->published_at)->not->toBeNull();

    $fresh = $charter->fresh();
    expect($fresh->status)->toBe('active')
        ->and($fresh->current_version)->toBe(1)
        ->and($fresh->title)->toBe('Published title')
        ->and($fresh->content)->toBe($expected)
        ->and($fresh->currentVersion()?->is($version))->toBeTrue();
});

test('publish with an empty safety toolkit is rejected without writing anything', function () {
    [$owner, $campaign] = charter_campaign_owner();
    $charter = CharterService::start($owner, $campaign);
    $content = charter_valid_content($campaign);
    $content['safety']['tools'] = [];

    try {
        CharterService::publish($owner, $charter, 'T', $content);
        $this->fail('Expected ValidationException for missing safety tools.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('safety.tools');
    }

    expect(SessionZeroSurveyVersion::where('session_zero_survey_id', $charter->id)->count())->toBe(0)
        ->and($charter->fresh()->status)->toBe('draft')
        ->and($charter->fresh()->current_version)->toBeNull();
});

test('publish rejects a title longer than the varchar(255) column — the service path cannot 500', function () {
    [$owner, $campaign] = charter_campaign_owner();
    $charter = CharterService::start($owner, $campaign);
    $overlong = str_repeat('T', 256);

    try {
        CharterService::publish($owner, $charter, $overlong, charter_valid_content($campaign));
        $this->fail('Expected ValidationException for an overlong title.');
    } catch (ValidationException $e) {
        expect($e->errors()['title'])->toBeArray();
    }

    // Nothing published, no version row, charter still a draft.
    expect($charter->fresh()->status)->toBe('draft')
        ->and($charter->fresh()->current_version)->toBeNull();
});

test('publish rejects fixed-location venue mode with no location chosen', function () {
    [$owner, $campaign] = charter_campaign_owner();
    $charter = CharterService::start($owner, $campaign);

    // Quick-start content with venue_mode flipped to location and NO
    // venue picked — a charter contract that reads "Play location: fixed
    // location" with no venue line must never publish.
    $content = charter_valid_content($campaign);
    $content['logistics']['venue_mode'] = 'location';
    $content['logistics']['location_id'] = null;

    try {
        CharterService::publish($owner, $charter, 'No venue', $content);
        $this->fail('Expected ValidationException for a missing location.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('logistics.location_id');
    }

    expect($charter->fresh()->status)->toBe('draft');
});

test('publishing twice is rejected without creating a second version row', function () {
    [$owner, $campaign] = charter_campaign_owner();
    $charter = CharterService::start($owner, $campaign);

    CharterService::publish($owner, $charter, 'Published', charter_valid_content($campaign));

    try {
        CharterService::publish($owner, $charter, 'Published', charter_valid_content($campaign));
        $this->fail('Expected ValidationException on double publish.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('charter');
    }

    expect(SessionZeroSurveyVersion::where('session_zero_survey_id', $charter->id)->count())->toBe(1);
});

test('publish is transactional: invalid content leaves the charter untouched', function () {
    [$owner, $campaign] = charter_campaign_owner();
    $charter = CharterService::start($owner, $campaign);
    $content = charter_valid_content($campaign);
    $content['logistics']['session_length'] = 'other';
    $content['logistics']['session_length_other'] = '';

    try {
        CharterService::publish($owner, $charter, 'T', $content);
        $this->fail('Expected ValidationException for missing session-length detail.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('logistics.session_length_other');
    }

    $fresh = $charter->fresh();
    expect(SessionZeroSurveyVersion::count())->toBe(0)
        ->and($fresh->status)->toBe('draft')
        ->and($fresh->current_version)->toBeNull()
        ->and($fresh->title)->toBe('The Threshold Heist');
});

/**
 * A published v1 charter ready for amendments: owner, campaign, charter
 * row, and the v1 snapshot (MEM1320 is honored by charter_campaign_owner,
 * which pins session_duration so quick-start maps to a publishable
 * session length).
 *
 * @return array{0: User, 1: Campaign, 2: SessionZeroSurvey, 3: SessionZeroSurveyVersion}
 */
function charter_published_v1(): array
{
    [$owner, $campaign] = charter_campaign_owner();
    $charter = CharterService::start($owner, $campaign);
    $version = CharterService::publish($owner, $charter, 'Published title', charter_valid_content($campaign));

    return [$owner, $campaign, $charter, $version];
}

/**
 * Amendable v1 content with exactly one edit: material (a §3 safety
 * note) or minor (§1 pitch only) — the §2–§5/tone-axes materiality
 * matrix itself is covered by CharterContentTest.
 *
 * @return array<string, mixed>
 */
function charter_amendment_content(Campaign $campaign, bool $material): array
{
    $content = charter_valid_content($campaign);
    if ($material) {
        $content['safety']['custom_note'] = 'New mood check after session two.';
    } else {
        $content['overview']['pitch'] = 'Tightened pitch after session two.';
    }

    return $content;
}

// ── amend() ──────────────────────────────────────────────

test('amend rejects a draft charter', function () {
    [$owner, $campaign] = charter_campaign_owner();
    $charter = CharterService::start($owner, $campaign);

    try {
        CharterService::amend($owner, $charter, 'T', charter_valid_content($campaign), 'Tweaks.');
        $this->fail('Expected ValidationException for amending a draft.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('charter')
            ->and($e->errors()['charter'][0])->toBe(__('charter.error_only_active_amendable'));
    }

    expect(SessionZeroSurveyVersion::where('session_zero_survey_id', $charter->id)->count())->toBe(0);
});

test('amend rejects an archived charter', function () {
    [$owner, $campaign, $charter] = charter_published_v1();
    $charter->archive();

    try {
        CharterService::amend($owner, $charter, 'T', charter_amendment_content($campaign, false), 'Tweaks.');
        $this->fail('Expected ValidationException for amending an archived charter.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('charter');
    }

    expect(SessionZeroSurveyVersion::where('session_zero_survey_id', $charter->id)->count())->toBe(1);
});

test('amend rejects a legacy survey that is not a charter', function () {
    $owner = User::factory()->create();
    $survey = SessionZeroSurvey::factory()->create();

    // Legacy surveys carry no campaign, so there is nothing to authorize
    // against — the per-mutation authorization fires before the
    // not-an-active-charter validation (archived-charter coverage below
    // still pins that ValidationException path).
    CharterService::amend($owner, $survey, 'T', CharterContent::blank()->toArray(), 'Tweaks.');
})->throws(AuthorizationException::class);

test('amend publishes version 2 with changelog and flips the charter row', function () {
    [$owner, $campaign, $charter, $v1] = charter_published_v1();
    $v1Before = $v1->fresh();
    $content = charter_amendment_content($campaign, false);
    $content['unknown_section'] = ['nope' => true];

    $v2 = CharterService::amend($owner, $charter, 'Amended title', $content, '  Tightened the pitch.  ');

    $normalized = CharterContent::fromArray($content)->toArray();
    expect($v2->session_zero_survey_id)->toBe($charter->id)
        ->and($v2->version_number)->toBe(2)
        ->and($v2->content_schema)->toBe(1)
        ->and($v2->content)->toBe($normalized)
        ->and($v2->changelog)->toBe('Tightened the pitch.')
        ->and($v2->is_material)->toBeFalse()
        ->and($v2->published_at)->not->toBeNull();

    $fresh = $charter->fresh();
    expect($fresh->status)->toBe('active')
        ->and($fresh->current_version)->toBe(2)
        ->and($fresh->title)->toBe('Amended title')
        ->and($fresh->content)->toBe($normalized)
        ->and($fresh->currentVersion()?->is($v2))->toBeTrue()
        // Normalization drops unknown input sections before persisting.
        ->and(array_keys($fresh->content))->toBe(CharterContent::SECTIONS);

    // Version rows are immutable history: v1 is untouched by the amend
    // (both sides fresh from jsonb, so key order is on equal footing).
    expect($v1->fresh()->content)->toBe($v1Before->content)
        ->and($v1->fresh()->changelog)->toBeNull()
        ->and($v1->fresh()->is_material)->toBeFalse();
});

test('a safety-section edit amends as a material version', function () {
    [$owner, $campaign, $charter] = charter_published_v1();

    $v2 = CharterService::amend($owner, $charter, 'Same title', charter_amendment_content($campaign, true), 'New safety note.');

    expect($v2->is_material)->toBeTrue()
        ->and($v2->changelog)->toBe('New safety note.')
        ->and($charter->fresh()->current_version)->toBe(2);
});

// ── amend(): material reset / minor carry ───────────────

test('a material amendment resets every signed confirmation to pending re-agreement', function () {
    [$owner, $campaign, $charter, $v1] = charter_published_v1();
    $attestations = array_fill_keys(CharterContent::AGREEMENT_ITEMS, true);

    SessionZeroConfirmation::factory()->onVersion($v1)->create([
        'user_id' => User::factory()->create()->id,
        'role' => SessionZeroConfirmation::ROLE_PARTICIPANT,
        'status' => SessionZeroConfirmation::STATUS_SIGNED,
        'attestations' => $attestations,
        'private_answers' => ['my_lines_categories' => ['violence']],
    ]);
    SessionZeroConfirmation::factory()->onVersion($v1)->gm()->create([
        'user_id' => $owner->id,
        'status' => SessionZeroConfirmation::STATUS_SIGNED,
        'attestations' => $attestations,
    ]);
    $pendingRow = SessionZeroConfirmation::factory()->onVersion($v1)->pendingReagreement()->create([
        'user_id' => User::factory()->create()->id,
        'role' => SessionZeroConfirmation::ROLE_PARTICIPANT,
    ]);

    $v2 = CharterService::amend($owner, $charter, 'Reset title', charter_amendment_content($campaign, true), 'Material change.');

    // Participant AND gm rows on v1 flip; the already-pending row is untouched.
    expect(SessionZeroConfirmation::where('session_zero_survey_version_id', $v1->id)
        ->where('status', SessionZeroConfirmation::STATUS_SIGNED)->count())->toBe(0)
        ->and(SessionZeroConfirmation::where('session_zero_survey_version_id', $v1->id)
            ->where('status', SessionZeroConfirmation::STATUS_PENDING_REAGREEMENT)->count())->toBe(3)
        ->and($pendingRow->fresh()->status)->toBe(SessionZeroConfirmation::STATUS_PENDING_REAGREEMENT)
        // A material amendment inserts nothing on the new version.
        ->and(SessionZeroConfirmation::where('session_zero_survey_version_id', $v2->id)->count())->toBe(0);
});

test('a minor amendment carries the latest signature per user and role onto the new version', function () {
    [$owner, $campaign, $charter, $v1] = charter_published_v1();

    $participant = User::factory()->create();
    SessionZeroConfirmation::factory()->onVersion($v1)->create([
        'user_id' => $participant->id,
        'role' => SessionZeroConfirmation::ROLE_PARTICIPANT,
        'status' => SessionZeroConfirmation::STATUS_SIGNED,
        'attestations' => array_fill_keys(CharterContent::AGREEMENT_ITEMS, true),
        'private_answers' => [
            'my_lines_categories' => ['violence', 'phobias'],
            'my_veils_categories' => [],
            'my_lines_text' => 'No detailed torture.',
            'my_veils_text' => null,
            'note_to_gm' => 'Please watch the spider content.',
        ],
        'confirmed_at' => now()->subDays(3),
    ]);
    SessionZeroConfirmation::factory()->onVersion($v1)->gm()->create([
        'user_id' => $owner->id,
        'status' => SessionZeroConfirmation::STATUS_SIGNED,
        'attestations' => array_fill_keys(CharterContent::AGREEMENT_ITEMS, true),
        'private_answers' => null,
    ]);
    $resetParticipant = User::factory()->create();
    SessionZeroConfirmation::factory()->onVersion($v1)->pendingReagreement()->create([
        'user_id' => $resetParticipant->id,
        'role' => SessionZeroConfirmation::ROLE_PARTICIPANT,
    ]);

    $v2 = CharterService::amend($owner, $charter, 'Carried title', charter_amendment_content($campaign, false), 'Pitch only.');

    $carried = SessionZeroConfirmation::where('session_zero_survey_version_id', $v2->id)->get();
    expect($carried)->toHaveCount(2);

    $original = SessionZeroConfirmation::where('session_zero_survey_version_id', $v1->id)
        ->where('user_id', $participant->id)->first();
    $participantRow = $carried->firstWhere('user_id', $participant->id);
    expect($participantRow)->not->toBeNull()
        ->and($participantRow->role)->toBe(SessionZeroConfirmation::ROLE_PARTICIPANT)
        ->and($participantRow->status)->toBe(SessionZeroConfirmation::STATUS_SIGNED)
        // Byte-for-byte carry of attestations and private answers (both
        // sides fresh from jsonb, so key order is on equal footing).
        ->and($participantRow->attestations)->toBe($original->attestations)
        ->and($participantRow->private_answers)->toBe($original->private_answers)
        // The carried row binds at amend time; the original keeps its history.
        ->and($participantRow->confirmed_at->gt(now()->subMinute()))->toBeTrue()
        ->and($participantRow->confirmed_at->gt($original->confirmed_at))->toBeTrue();

    $gmRow = $carried->firstWhere('role', SessionZeroConfirmation::ROLE_GM);
    expect($gmRow)->not->toBeNull()
        ->and($gmRow->user_id)->toBe($owner->id)
        ->and($gmRow->status)->toBe(SessionZeroConfirmation::STATUS_SIGNED)
        ->and($gmRow->private_answers)->toBeNull();

    // Pending-reagreement participants and never-signed users get nothing.
    expect($carried->firstWhere('user_id', $resetParticipant->id))->toBeNull()
        ->and($original->fresh()->status)->toBe(SessionZeroConfirmation::STATUS_SIGNED);
});

test('a second consecutive minor amendment does not duplicate carried rows', function () {
    [$owner, $campaign, $charter, $v1] = charter_published_v1();
    $attestations = array_fill_keys(CharterContent::AGREEMENT_ITEMS, true);

    SessionZeroConfirmation::factory()->onVersion($v1)->create([
        'user_id' => User::factory()->create()->id,
        'role' => SessionZeroConfirmation::ROLE_PARTICIPANT,
        'status' => SessionZeroConfirmation::STATUS_SIGNED,
        'attestations' => $attestations,
        'private_answers' => null,
    ]);
    SessionZeroConfirmation::factory()->onVersion($v1)->gm()->create([
        'user_id' => $owner->id,
        'status' => SessionZeroConfirmation::STATUS_SIGNED,
        'attestations' => $attestations,
    ]);

    $v2 = CharterService::amend($owner, $charter, 'v2', charter_amendment_content($campaign, false), 'Pitch.');

    $content3 = charter_amendment_content($campaign, false);
    $content3['overview']['pitch'] = 'Tightened again after session four.';
    $v3 = CharterService::amend($owner, $charter, 'v3', $content3, 'Again.');

    expect(SessionZeroConfirmation::where('session_zero_survey_version_id', $v1->id)->count())->toBe(2)
        ->and(SessionZeroConfirmation::where('session_zero_survey_version_id', $v2->id)->count())->toBe(2)
        ->and(SessionZeroConfirmation::where('session_zero_survey_version_id', $v3->id)->count())->toBe(2)
        // One row per (user, role) everywhere — the partial unique index
        // backstops this, the latest-per-pair selection prevents hitting it.
        ->and(SessionZeroConfirmation::where('session_zero_survey_id', $charter->id)->count())->toBe(6)
        ->and($charter->fresh()->current_version)->toBe(3);
});

test('a material amendment after a carried minor version resets signatures across all versions', function () {
    [$owner, $campaign, $charter, $v1] = charter_published_v1();
    SessionZeroConfirmation::factory()->onVersion($v1)->create([
        'user_id' => User::factory()->create()->id,
        'role' => SessionZeroConfirmation::ROLE_PARTICIPANT,
        'status' => SessionZeroConfirmation::STATUS_SIGNED,
        'attestations' => array_fill_keys(CharterContent::AGREEMENT_ITEMS, true),
        'private_answers' => null,
    ]);

    $v2 = CharterService::amend($owner, $charter, 'v2', charter_amendment_content($campaign, false), 'Pitch.');
    expect(SessionZeroConfirmation::where('session_zero_survey_version_id', $v2->id)->count())->toBe(1);

    $v3 = CharterService::amend($owner, $charter, 'v3', charter_amendment_content($campaign, true), 'Safety change.');

    // EVERY signed row on any earlier version flips — the v1 original that
    // the minor carry left in place AND the v2 carried row.
    expect(SessionZeroConfirmation::where('session_zero_survey_id', $charter->id)
        ->where('status', SessionZeroConfirmation::STATUS_SIGNED)->count())->toBe(0)
        ->and(SessionZeroConfirmation::where('session_zero_survey_id', $charter->id)
            ->where('status', SessionZeroConfirmation::STATUS_PENDING_REAGREEMENT)->count())->toBe(2)
        ->and(SessionZeroConfirmation::where('session_zero_survey_version_id', $v3->id)->count())->toBe(0);
});

// ── amend() validation ──────────────────────────────────

test('amend rejects an empty changelog without writing anything', function () {
    [$owner, $campaign, $charter] = charter_published_v1();
    $content = charter_amendment_content($campaign, false);

    foreach (['', '   '] as $changelog) {
        try {
            CharterService::amend($owner, $charter, 'T', $content, $changelog);
            $this->fail("Expected ValidationException for changelog [{$changelog}].");
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('changelog');
        }
    }

    expect(SessionZeroSurveyVersion::where('session_zero_survey_id', $charter->id)->count())->toBe(1)
        ->and($charter->fresh()->current_version)->toBe(1);
});

test('amend rejects an over-long title and changelog', function () {
    [$owner, $campaign, $charter] = charter_published_v1();

    try {
        CharterService::amend($owner, $charter, str_repeat('t', 256), charter_amendment_content($campaign, false), str_repeat('c', 2001));
        $this->fail('Expected ValidationException for over-long title and changelog.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKeys(['title', 'changelog']);
    }
});

test('amend validates content with the publish rule set and keying', function () {
    [$owner, $campaign, $charter] = charter_published_v1();
    $content = charter_amendment_content($campaign, false);
    $content['safety']['tools'] = [];

    try {
        CharterService::amend($owner, $charter, 'T', $content, 'Change.');
        $this->fail('Expected ValidationException for missing safety tools.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('safety.tools');
    }

    // The transaction rolled back: still v1, still the published content.
    $fresh = $charter->fresh();
    expect(SessionZeroSurveyVersion::where('session_zero_survey_id', $charter->id)->count())->toBe(1)
        ->and($fresh->current_version)->toBe(1)
        ->and($fresh->content['overview']['pitch'])->toBe('A noir intrigue campaign in the city of Threshold.');
});

// ── Concurrent amend race (DB backstop) ────────────────

test('a concurrent amendment that claimed the version number converges to a validation error', function () {
    [$owner, $campaign, $charter] = charter_published_v1();

    // Simulate the concurrent winner: v2 already exists on the charter.
    $charter->versions()->create([
        'version_number' => 2,
        'content' => CharterContent::blank()->toArray(),
        'content_schema' => CharterContent::SCHEMA_VERSION,
        'changelog' => 'Amended elsewhere.',
        'is_material' => false,
        'published_at' => now(),
    ]);

    try {
        CharterService::amend($owner, $charter, 'Loser', charter_amendment_content($campaign, false), 'Too late.');
        $this->fail('Expected ValidationException for the concurrent amend.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('charter')
            ->and($e->errors()['charter'][0])->toBe(__('charter.error_amended_concurrently'));
    }

    // The loser's write is rolled back: the charter still points at v1.
    $fresh = $charter->fresh();
    expect(SessionZeroSurveyVersion::where('session_zero_survey_id', $charter->id)->count())->toBe(2)
        ->and($fresh->current_version)->toBe(1)
        ->and($fresh->title)->toBe('Published title');
});

// ── One charter per campaign (DB race backstop) ─────────

test('a second charter row for the same campaign violates the partial unique index', function () {
    $campaign = Campaign::factory()->create();
    SessionZeroSurvey::factory()->charter()->create(['campaign_id' => $campaign->id]);

    $caught = null;
    try {
        SessionZeroSurvey::factory()->charter()->create(['campaign_id' => $campaign->id]);
        $this->fail('Expected a unique violation from session_zero_surveys_charter_campaign_unique.');
    } catch (QueryException $e) {
        $caught = $e;
    }

    // Postgres: SQLSTATE 23505 naming the partial index.
    expect($caught->errorInfo[0] ?? null)->toBe('23505')
        ->and($caught->getMessage())->toContain('session_zero_surveys_charter_campaign_unique');
});

// ── Free-tier limit + member seam (M070/S04) ───────────

/**
 * A fresh campaign owned by $owner with the deterministic quick-start
 * inputs that make charter_valid_content publishable.
 */
function charter_limit_campaign(User $owner): Campaign
{
    return Campaign::factory()->create([
        'owner_id' => $owner->id,
        'name' => ['en' => 'The Threshold Heist'],
        'description' => ['en' => 'A noir intrigue campaign in the city of Threshold.'],
        'max_players' => 5,
        'session_duration' => 3,
        'recurrence' => 'weekly',
    ]);
}

/**
 * Publish an ACTIVE charter on a fresh campaign owned by $owner through
 * the real lifecycle (start → publish → refresh).
 */
function charter_limit_active_charter(User $owner): SessionZeroSurvey
{
    $campaign = charter_limit_campaign($owner);
    $charter = CharterService::start($owner, $campaign);
    CharterService::publish($owner, $charter, (string) $campaign->name, charter_valid_content($campaign));

    return $charter->refresh();
}

test('start refuses a third charter for a free owner at the cap', function () {
    $owner = User::factory()->create();
    charter_limit_active_charter($owner);
    charter_limit_active_charter($owner);
    $campaign = charter_limit_campaign($owner);

    try {
        CharterService::start($owner, $campaign);
        $this->fail('Expected ValidationException for the free-tier charter limit.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('charter')
            ->and($e->errors()['charter'][0])->toBe(__('charter.error_charter_limit_reached', ['limit' => 2]));
    }

    expect(SessionZeroSurvey::query()->charter()->where('campaign_id', $campaign->id)->exists())->toBeFalse();
});

test('start stays idempotent at the limit: an existing charter is returned, not gated', function () {
    $owner = User::factory()->create();

    // The draft predates the cap being hit — re-entering it must never
    // consult membership (free-tier safety: drafts stay editable).
    $campaign = charter_limit_campaign($owner);
    $draft = CharterService::start($owner, $campaign);

    charter_limit_active_charter($owner);
    charter_limit_active_charter($owner);

    $again = CharterService::start($owner, $campaign);

    expect($again->is($draft))->toBeTrue();
});

test('a subscribed GM starts charters freely past the cap', function () {
    $member = $this->createSubscribedGm();
    charter_limit_active_charter($member);
    charter_limit_active_charter($member);

    $third = CharterService::start($member, charter_limit_campaign($member));

    expect($third->status)->toBe('draft');
});

test('publish is the authoritative gate: a draft started before the cap cannot flip active', function () {
    $owner = User::factory()->create();

    // The third draft exists before the cap is hit (start only counts
    // actives), then two publishes consume both free slots.
    $draftCampaign = charter_limit_campaign($owner);
    $draft = CharterService::start($owner, $draftCampaign);
    charter_limit_active_charter($owner);
    charter_limit_active_charter($owner);

    try {
        CharterService::publish($owner, $draft, 'Third', charter_valid_content($draftCampaign));
        $this->fail('Expected ValidationException at the publish gate.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('charter')
            ->and($e->errors()['charter'][0])->toBe(__('charter.error_charter_limit_reached', ['limit' => 2]));
    }

    // The transaction rolled back: no version row, the charter stays a draft.
    expect(SessionZeroSurveyVersion::where('session_zero_survey_id', $draft->id)->count())->toBe(0)
        ->and($draft->fresh()->status)->toBe('draft');
});

test('a subscribed GM publishes past the cap freely', function () {
    $member = $this->createSubscribedGm();
    charter_limit_active_charter($member);
    charter_limit_active_charter($member);

    $campaign = charter_limit_campaign($member);
    $draft = CharterService::start($member, $campaign);
    $version = CharterService::publish($member, $draft, 'Third', charter_valid_content($campaign));

    expect($version->version_number)->toBe(1)
        ->and($draft->fresh()->status)->toBe('active');
});

// ── archive() (M070/S04) ───────────────────────────────

test('archive refuses anyone but the campaign owner', function () {
    [$owner] = charter_campaign_owner();
    $charter = charter_limit_active_charter($owner);
    $other = User::factory()->create();

    CharterService::archive($other, $charter);
})->throws(AuthorizationException::class);

test('archive refuses a draft and an already-archived charter', function () {
    $owner = User::factory()->create();
    $draft = CharterService::start($owner, charter_limit_campaign($owner));

    try {
        CharterService::archive($owner, $draft);
        $this->fail('Expected ValidationException for archiving a draft.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('charter')
            ->and($e->errors()['charter'][0])->toBe(__('charter.error_only_active_archivable'));
    }

    $active = charter_limit_active_charter($owner);
    CharterService::archive($owner, $active);

    try {
        CharterService::archive($owner, $active);
        $this->fail('Expected ValidationException for archiving an archived charter.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('charter');
    }
});

test('archive flips the charter out of the active roster, keeps its versions, and logs ids only', function () {
    $this->captureLogRecords();

    $owner = User::factory()->create();
    $charter = charter_limit_active_charter($owner);

    CharterService::archive($owner, $charter);

    $fresh = $charter->fresh();
    expect($fresh->status)->toBe('archived')
        ->and(SessionZeroSurveyVersion::where('session_zero_survey_id', $charter->id)->count())->toBe(1);

    $record = $this->logRecord('Charter archived');
    expect($record)->not->toBeNull()
        ->and($record['context'])->toBe([
            'survey_id' => $charter->id,
            'uuid' => $charter->uuid,
            'campaign_id' => $charter->campaign_id,
        ]);
});

test('archiving frees a slot: the owner may start and publish again', function () {
    $owner = User::factory()->create();
    $first = charter_limit_active_charter($owner);
    charter_limit_active_charter($owner);

    CharterService::archive($owner, $first);

    $campaign = charter_limit_campaign($owner);
    $third = CharterService::start($owner, $campaign);
    CharterService::publish($owner, $third, 'Replacement', charter_valid_content($campaign));

    expect($third->fresh()->status)->toBe('active');
});

test('an archived charter is refused for signing by the existing S02 gate', function () {
    $owner = User::factory()->create();
    $charter = charter_limit_active_charter($owner);
    CharterService::archive($owner, $charter);

    $participant = User::factory()->create();
    CampaignParticipant::factory()->create([
        'campaign_id' => $charter->campaign_id,
        'user_id' => $participant->id,
        'status' => ParticipantStatus::Approved->value,
    ]);

    $attestations = array_fill_keys(CharterContent::AGREEMENT_ITEMS, true);

    try {
        CharterSigningService::sign($participant, $charter->refresh(), [
            'attestations' => $attestations,
            'private_answers' => [],
        ]);
        $this->fail('Expected ValidationException for signing an archived charter.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('charter')
            ->and($e->errors()['charter'][0])->toBe(__('charter.error_charter_not_active'));
    }
});

// ── start() seeded modes: template & previous (M070/S04) ──

test('template mode seeds a draft deep-equal to the caller\'s saved template', function () {
    $member = $this->createSubscribedGm();
    $source = charter_limit_active_charter($member);
    $template = CharterTemplateService::saveFromCharter($member, $source, 'My standard charter');

    $target = charter_limit_campaign($member);
    $draft = CharterService::start($member, $target, 'template', $template->id);

    expect($draft->status)->toBe('draft')
        // The title still defaults to the target campaign's name — a
        // template is reusable content, not a title.
        ->and($draft->title)->toBe((string) $target->name)
        ->and($draft->campaign_id)->toBe($target->id)
        // Deep-equal: the seeded content IS the normalized template snapshot.
        ->and($draft->content)->toBe($template->content);
});

test('template mode rejects another user\'s template id', function () {
    $member = $this->createSubscribedGm();
    $source = charter_limit_active_charter($member);
    $template = CharterTemplateService::saveFromCharter($member, $source, 'Mine');

    $otherMember = $this->createSubscribedGm();
    $target = charter_limit_campaign($otherMember);

    try {
        CharterService::start($otherMember, $target, 'template', $template->id);
        $this->fail('Expected ValidationException for a foreign template id.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('charter')
            ->and($e->errors()['charter'][0])->toBe(__('charter.error_template_not_yours'));
    }

    expect(SessionZeroSurvey::query()->charter()->where('campaign_id', $target->id)->exists())->toBeFalse();
});

test('template mode rejects an unknown template id', function () {
    $member = $this->createSubscribedGm();
    $target = charter_limit_campaign($member);

    try {
        CharterService::start($member, $target, 'template', (string) Str::uuid());
        $this->fail('Expected ValidationException for an unknown template id.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('charter')
            ->and($e->errors()['charter'][0])->toBe(__('charter.error_template_not_yours'));
    }
});

test('seed modes reject a non-uuid seed id inline instead of a 22P02/404', function (string $mode) {
    $member = $this->createSubscribedGm();
    $target = charter_limit_campaign($member);

    // A tampered wire:click seedId must refuse with the same message as
    // an unknown id — whereKey()->first() is not covered by UuidBuilder
    // (find() lookups only), so without the uuid_or_null() gate a
    // malformed id binds raw and the bootstrap 22P02 backstop renders a
    // 404 page instead of this inline refusal.
    try {
        CharterService::start($member, $target, $mode, 'not-a-uuid');
        $this->fail('Expected ValidationException for a malformed seed id.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('charter')
            ->and($e->errors()['charter'][0])->toBe(__("charter.error_{$mode}_not_yours"));
    }

    expect(SessionZeroSurvey::query()->charter()->where('campaign_id', $target->id)->exists())->toBeFalse();
})->with(['template', 'previous']);

test('previous mode seeds a draft from the caller\'s published charter on another campaign', function () {
    $member = $this->createSubscribedGm();
    $source = charter_limit_active_charter($member);

    $target = charter_limit_campaign($member);
    $draft = CharterService::start($member, $target, 'previous', $source->id);

    expect($draft->status)->toBe('draft')
        ->and($draft->title)->toBe((string) $target->name)
        ->and($draft->campaign_id)->toBe($target->id)
        // Deep-equal: the seeded content is the source charter's snapshot.
        ->and($draft->content)->toBe(CharterContent::fromArray($source->content)->toArray());
});

test('previous mode rejects a foreign charter id', function () {
    $otherMember = $this->createSubscribedGm();
    $source = charter_limit_active_charter($otherMember);

    $member = $this->createSubscribedGm();
    $target = charter_limit_campaign($member);

    try {
        CharterService::start($member, $target, 'previous', $source->id);
        $this->fail('Expected ValidationException for a foreign charter id.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('charter')
            ->and($e->errors()['charter'][0])->toBe(__('charter.error_previous_not_yours'));
    }
});

test('the free-tier limit gate applies before seed resolution', function () {
    $owner = User::factory()->create();
    charter_limit_active_charter($owner);
    charter_limit_active_charter($owner);

    $member = $this->createSubscribedGm();
    $template = CharterTemplateService::saveFromCharter($member, charter_limit_active_charter($member), 'Theirs');

    $target = charter_limit_campaign($owner);

    // Even a foreign template id surfaces the limit error, not the
    // not-yours error — the cap is checked first.
    try {
        CharterService::start($owner, $target, 'template', $template->id);
        $this->fail('Expected ValidationException at the free-tier charter limit.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('charter')
            ->and($e->errors()['charter'][0])->toBe(__('charter.error_charter_limit_reached', ['limit' => 2]));
    }
});

test('a member at the cap starts from a template freely', function () {
    $member = $this->createSubscribedGm();
    $source = charter_limit_active_charter($member);
    $template = CharterTemplateService::saveFromCharter($member, $source, 'Standard');
    charter_limit_active_charter($member);

    $third = charter_limit_campaign($member);
    $draft = CharterService::start($member, $third, 'template', $template->id);

    expect($draft->status)->toBe('draft')
        ->and($draft->content)->toBe($template->content);
});

// ── start() seeded modes: the member seam is server-side (review pass 4) ──

test('template mode is refused for a free owner — the UI gate is not the gate', function () {
    // The composer hides the start cards behind memberConveniences, but
    // startCharter() forwards the mode verbatim from wire:click: the
    // service-layer check is the only real one (D223 single seam).
    $freeOwner = User::factory()->create();
    $target = charter_limit_campaign($freeOwner);

    // A template that exists (saved by a member) — ownership never gets
    // a chance to matter: the member gate fires first.
    $member = $this->createSubscribedGm();
    $template = CharterTemplateService::saveFromCharter(
        $member,
        charter_limit_active_charter($member),
        'Member standard'
    );

    try {
        CharterService::start($freeOwner, $target, 'template', $template->id);
        $this->fail('Expected ValidationException: template seeding is a member convenience.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('charter')
            ->and($e->errors()['charter'][0])->toBe(__('charter.error_seeds_member_only'));
    }

    expect(SessionZeroSurvey::query()->charter()->where('campaign_id', $target->id)->exists())->toBeFalse();
});

test('previous mode is refused for a free owner even on their own published charter', function () {
    $freeOwner = User::factory()->create();
    $source = charter_limit_active_charter($freeOwner);
    $target = charter_limit_campaign($freeOwner);

    try {
        CharterService::start($freeOwner, $target, 'previous', $source->id);
        $this->fail('Expected ValidationException: charter reuse is a member convenience.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('charter')
            ->and($e->errors()['charter'][0])->toBe(__('charter.error_seeds_member_only'));
    }

    expect(SessionZeroSurvey::query()->charter()->where('campaign_id', $target->id)->exists())->toBeFalse();
});
