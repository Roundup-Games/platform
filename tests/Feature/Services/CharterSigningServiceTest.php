<?php

use App\Dto\CharterContent;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\GMProfile;
use App\Models\SessionZeroConfirmation;
use App\Models\SessionZeroSurvey;
use App\Models\SessionZeroSurveyVersion;
use App\Models\User;
use App\Services\Charter\CharterService;
use App\Services\Charter\CharterSigningService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

// M070/S02-T02 — CharterSigningService: version-pinned participant signing
// (five §8 attestations, normalized private boundary answers, race-safe
// insert), owner countersignature gated on every approved participant having
// signed, the owner's roster, and the GM-only private-answers choke point.
// Error messages are asserted wording-agnostic via __() lookups (K004).

/**
 * Campaign owner + campaign with deterministic quick-start inputs
 * (session_duration 3.0 pins a mappable session-length; expected_shape is
 * the one authoring choice quick-start leaves empty — filled before publish).
 *
 * @return array{0: User, 1: Campaign}
 */
function signing_owner_campaign(): array
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
 * Owner + campaign + charter published through the real lifecycle, so the
 * immutable version 1 snapshot exists and current_version points at it.
 *
 * @return array{0: User, 1: Campaign, 2: SessionZeroSurvey, 3: SessionZeroSurveyVersion}
 */
function signing_published_charter(): array
{
    [$owner, $campaign] = signing_owner_campaign();
    $charter = CharterService::start($owner, $campaign);
    $content = CharterContent::quickStart($campaign)->toArray();
    $content['overview']['expected_shape'] = 'ongoing';
    $version = CharterService::publish($owner, $charter, 'The Threshold Heist', $content);

    return [$owner, $campaign, $charter, $version];
}

/**
 * An approved participant (fresh user + CampaignParticipant row).
 *
 * @return array{0: User, 1: CampaignParticipant}
 */
function signing_approved_participant(Campaign $campaign): array
{
    $user = User::factory()->create();
    $participant = CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $user->id,
    ]);

    return [$user, $participant];
}

/**
 * Valid signing input with deliberately dirty private answers: a duplicate
 * and an out-of-vocabulary category to drop, padded text to trim, an empty
 * text to null.
 *
 * @return array<string, mixed>
 */
function signing_valid_input(): array
{
    return [
        'attestations' => [
            'read_attestation' => true,
            'safety' => true,
            'policies' => true,
            'ai_tools' => true,
            'logistics' => true,
        ],
        'private_answers' => [
            'my_lines_categories' => ['violence', 'bogus_category', 'violence'],
            'my_veils_categories' => ['sexual_content', 'phobias'],
            'my_lines_text' => '  No graphic torture scenes.  ',
            'my_veils_text' => '',
            'note_to_gm' => 'Please keep spider imagery off-screen.',
        ],
    ];
}

// ── sign() ───────────────────────────────────────────────

test('sign pins version 1 with all five attestations and normalized private answers', function () {
    [$owner, $campaign, $charter, $version] = signing_published_charter();
    [$participant] = signing_approved_participant($campaign);

    $confirmation = CharterSigningService::sign($participant, $charter, signing_valid_input());

    expect($confirmation)->toBeInstanceOf(SessionZeroConfirmation::class)
        ->and($confirmation->session_zero_survey_id)->toBe($charter->id)
        ->and($confirmation->session_zero_survey_version_id)->toBe($version->id)
        ->and($confirmation->user_id)->toBe($participant->id)
        ->and($confirmation->role)->toBe(SessionZeroConfirmation::ROLE_PARTICIPANT)
        ->and($confirmation->status)->toBe(SessionZeroConfirmation::STATUS_SIGNED)
        ->and($confirmation->confirmed_at)->not->toBeNull()
        ->and($confirmation->attestations)->toBe([
            'read_attestation' => true,
            'safety' => true,
            'policies' => true,
            'ai_tools' => true,
            'logistics' => true,
        ])
        // Junk dropped, duplicate deduped, text trimmed, empty text nulled.
        ->and($confirmation->private_answers)->toBe([
            'my_lines_categories' => ['violence'],
            'my_veils_categories' => ['sexual_content', 'phobias'],
            'my_lines_text' => 'No graphic torture scenes.',
            'my_veils_text' => null,
            'note_to_gm' => 'Please keep spider imagery off-screen.',
        ]);
});

test('sign accepts input without any private answers', function () {
    [$owner, $campaign, $charter] = signing_published_charter();
    [$participant] = signing_approved_participant($campaign);

    $input = signing_valid_input();
    unset($input['private_answers']);

    $confirmation = CharterSigningService::sign($participant, $charter, $input);

    // Nothing declared stores NULL — never an all-empty array that
    // would shadow previously submitted answers on the GM roster
    // (privateAnswersFor continuity; see sign()'s empty-shape guard).
    expect($confirmation->private_answers)->toBeNull();
});

test('sign rejects a user who is not a campaign participant', function () {
    [$owner, , $charter] = signing_published_charter();
    $guest = User::factory()->create();

    try {
        CharterSigningService::sign($guest, $charter, signing_valid_input());
        $this->fail('Expected ValidationException for a non-participant.');
    } catch (ValidationException $e) {
        expect($e->errors()['charter'][0])->toBe(__('charter.error_not_participant'));
    }
});

test('sign rejects a participant whose application is still pending', function () {
    [$owner, $campaign, $charter] = signing_published_charter();
    $pendingUser = User::factory()->create();
    CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $pendingUser->id,
        'status' => ParticipantStatus::Pending->value,
    ]);

    try {
        CharterSigningService::sign($pendingUser, $charter, signing_valid_input());
        $this->fail('Expected ValidationException for a pending participant.');
    } catch (ValidationException $e) {
        expect($e->errors()['charter'][0])->toBe(__('charter.error_not_participant'));
    }
});

test('sign rejects a draft charter that has no published version', function () {
    [$owner, $campaign] = signing_owner_campaign();
    $charter = CharterService::start($owner, $campaign);
    [$participant] = signing_approved_participant($campaign);

    try {
        CharterSigningService::sign($participant, $charter, signing_valid_input());
        $this->fail('Expected ValidationException for a draft charter.');
    } catch (ValidationException $e) {
        expect($e->errors()['charter'][0])->toBe(__('charter.error_charter_not_active'));
    }
});

test('sign rejects an archived charter', function () {
    [$owner, $campaign, $charter] = signing_published_charter();
    [$participant] = signing_approved_participant($campaign);
    $charter->archive();

    try {
        CharterSigningService::sign($participant, $charter, signing_valid_input());
        $this->fail('Expected ValidationException for an archived charter.');
    } catch (ValidationException $e) {
        expect($e->errors()['charter'][0])->toBe(__('charter.error_charter_not_active'));
    }
});

test('sign rejects a legacy session-zero survey row (kind is not charter)', function () {
    $survey = SessionZeroSurvey::factory()->create(); // kind='survey', active
    $user = User::factory()->create();

    try {
        CharterSigningService::sign($user, $survey, signing_valid_input());
        $this->fail('Expected ValidationException for a non-charter survey.');
    } catch (ValidationException $e) {
        expect($e->errors()['charter'][0])->toBe(__('charter.error_charter_not_active'));
    }
});

test('signing twice is rejected as already signed', function () {
    [$owner, $campaign, $charter] = signing_published_charter();
    [$participant] = signing_approved_participant($campaign);

    CharterSigningService::sign($participant, $charter, signing_valid_input());

    try {
        CharterSigningService::sign($participant, $charter, signing_valid_input());
        $this->fail('Expected ValidationException on double sign.');
    } catch (ValidationException $e) {
        expect($e->errors()['charter'][0])->toBe(__('charter.error_already_signed'));
    }

    expect(SessionZeroConfirmation::query()->where('role', SessionZeroConfirmation::ROLE_PARTICIPANT)->count())->toBe(1);
});

test('a concurrent insert winning the version unique race converges to already signed', function () {
    [$owner, $campaign, $charter, $version] = signing_published_charter();
    [$participant] = signing_approved_participant($campaign);

    // The rival request commits the same (version, user, role) row between
    // our pre-check and our insert — simulated at the creating-event seam.
    $raced = false;
    SessionZeroConfirmation::creating(function () use (&$raced, $version, $participant): bool {
        if ($raced) {
            return true;
        }
        $raced = true;
        SessionZeroConfirmation::withoutEvents(
            fn () => SessionZeroConfirmation::factory()
                ->onVersion($version)
                ->create(['user_id' => $participant->id])
        );

        return true;
    });

    try {
        CharterSigningService::sign($participant, $charter, signing_valid_input());
        $this->fail('Expected ValidationException when the race is lost.');
    } catch (ValidationException $e) {
        expect($e->errors()['charter'][0])->toBe(__('charter.error_already_signed'));
    }

    // No row-count assertion here: the 23505 aborts the wrapped test
    // transaction (Postgres 25P02 on any later statement), exactly like
    // ParticipantService's duplicate-invite catch path. The converged
    // already-signed error above is the race contract.
});

test('a missing attestation item is rejected', function () {
    [$owner, $campaign, $charter] = signing_published_charter();
    [$participant] = signing_approved_participant($campaign);

    $input = signing_valid_input();
    unset($input['attestations']['safety']);

    try {
        CharterSigningService::sign($participant, $charter, $input);
        $this->fail('Expected ValidationException for a missing attestation.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('attestations.safety');
    }
});

test('an unticked attestation item is rejected', function () {
    [$owner, $campaign, $charter] = signing_published_charter();
    [$participant] = signing_approved_participant($campaign);

    $input = signing_valid_input();
    $input['attestations']['read_attestation'] = false;

    try {
        CharterSigningService::sign($participant, $charter, $input);
        $this->fail('Expected ValidationException for a false attestation.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('attestations.read_attestation');
    }
});

test('an overlong private answer text is rejected', function () {
    [$owner, $campaign, $charter] = signing_published_charter();
    [$participant] = signing_approved_participant($campaign);

    $input = signing_valid_input();
    $input['private_answers']['my_lines_text'] = str_repeat('a', 2001);

    try {
        CharterSigningService::sign($participant, $charter, $input);
        $this->fail('Expected ValidationException for an overlong private answer.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('private_answers.my_lines_text');
    }

    expect(SessionZeroConfirmation::query()->where('user_id', $participant->id)->count())->toBe(0);
});

// ── countersign() ────────────────────────────────────────

test('the owner countersigns once every approved participant has signed', function () {
    [$owner, $campaign, $charter, $version] = signing_published_charter();
    [$alice] = signing_approved_participant($campaign);
    [$bob] = signing_approved_participant($campaign);

    CharterSigningService::sign($alice, $charter, signing_valid_input());
    CharterSigningService::sign($bob, $charter, signing_valid_input());

    $gm = CharterSigningService::countersign($owner, $charter);

    expect($gm)->toBeInstanceOf(SessionZeroConfirmation::class)
        ->and($gm->session_zero_survey_id)->toBe($charter->id)
        ->and($gm->session_zero_survey_version_id)->toBe($version->id)
        ->and($gm->user_id)->toBe($owner->id)
        ->and($gm->role)->toBe(SessionZeroConfirmation::ROLE_GM)
        ->and($gm->status)->toBe(SessionZeroConfirmation::STATUS_SIGNED)
        ->and($gm->attestations)->toBe([
            'read_attestation' => true,
            'safety' => true,
            'policies' => true,
            'ai_tools' => true,
            'logistics' => true,
        ])
        ->and($gm->private_answers)->toBeNull();

    // The countersignature never leaks into the private-answers view.
    $answers = CharterSigningService::privateAnswersFor($charter, $version, $owner);
    expect($answers)->toHaveCount(2)
        ->and($answers->pluck('role')->unique()->values()->all())->toBe([SessionZeroConfirmation::ROLE_PARTICIPANT]);
});

test('countersign rejects anyone but the campaign owner', function () {
    [$owner, $campaign, $charter] = signing_published_charter();
    [$alice] = signing_approved_participant($campaign);
    CharterSigningService::sign($alice, $charter, signing_valid_input());

    try {
        CharterSigningService::countersign($alice, $charter);
        $this->fail('Expected ValidationException for a non-owner countersign.');
    } catch (ValidationException $e) {
        expect($e->errors()['charter'][0])->toBe(__('charter.error_not_owner'));
    }
});

test('countersign is refused while approved participants are still pending', function () {
    [$owner, $campaign, $charter] = signing_published_charter();
    [$alice] = signing_approved_participant($campaign);
    [$bob] = signing_approved_participant($campaign);

    CharterSigningService::sign($alice, $charter, signing_valid_input());

    try {
        CharterSigningService::countersign($owner, $charter);
        $this->fail('Expected ValidationException while a participant is pending.');
    } catch (ValidationException $e) {
        expect($e->errors()['charter'][0])->toBe(__('charter.error_countersign_pending_signatures', ['count' => 1]));
    }

    expect(SessionZeroConfirmation::query()->where('role', SessionZeroConfirmation::ROLE_GM)->count())->toBe(0);
});

test('countersign is refused when the charter has zero approved participants — silence is never consent', function () {
    [$owner, $campaign, $charter] = signing_published_charter();

    try {
        CharterSigningService::countersign($owner, $charter);
        $this->fail('Expected ValidationException for an empty approved roster.');
    } catch (ValidationException $e) {
        expect($e->errors()['charter'][0])->toBe(__('charter.error_countersign_no_participants'));
    }

    expect(SessionZeroConfirmation::query()->where('role', SessionZeroConfirmation::ROLE_GM)->count())->toBe(0);
});

test('countersigning twice is rejected', function () {
    [$owner, $campaign, $charter] = signing_published_charter();
    [$alice] = signing_approved_participant($campaign);
    CharterSigningService::sign($alice, $charter, signing_valid_input());

    CharterSigningService::countersign($owner, $charter);

    try {
        CharterSigningService::countersign($owner, $charter);
        $this->fail('Expected ValidationException on double countersign.');
    } catch (ValidationException $e) {
        expect($e->errors()['charter'][0])->toBe(__('charter.error_already_countersigned'));
    }
});

test('countersign rejects a charter that is not active', function () {
    [$owner, $campaign] = signing_owner_campaign();
    $charter = CharterService::start($owner, $campaign);

    try {
        CharterSigningService::countersign($owner, $charter);
        $this->fail('Expected ValidationException for a draft charter.');
    } catch (ValidationException $e) {
        expect($e->errors()['charter'][0])->toBe(__('charter.error_charter_not_active'));
    }
});

// ── roster() ─────────────────────────────────────────────

test('the roster pairs approved participants with their signature and excludes others', function () {
    [$owner, $campaign, $charter, $version] = signing_published_charter();
    [$alice] = signing_approved_participant($campaign);
    [$bob] = signing_approved_participant($campaign);

    $aliceConfirmation = CharterSigningService::sign($alice, $charter, signing_valid_input());
    // Bob never signs.

    // A benched participant with an existing signature stays out of the
    // roster, as does an unsigned waitlisted applicant.
    $benched = User::factory()->create();
    CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $benched->id,
        'status' => ParticipantStatus::Benched->value,
    ]);
    SessionZeroConfirmation::factory()->onVersion($version)->create(['user_id' => $benched->id]);
    CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'user_id' => User::factory()->create()->id,
        'status' => ParticipantStatus::Waitlisted->value,
    ]);

    $roster = CharterSigningService::roster($charter);

    expect($roster)->toHaveCount(2);
    $byUser = $roster->keyBy(fn (array $entry): string => (string) $entry['user']->id);
    expect($byUser[$alice->id]['participant'])->toBeInstanceOf(CampaignParticipant::class)
        ->and($byUser[$alice->id]['confirmation']?->id)->toBe($aliceConfirmation->id)
        ->and($byUser[$bob->id]['confirmation'])->toBeNull()
        ->and($byUser)->not->toHaveKey($benched->id);
});

test('the roster is empty while the charter has no published version', function () {
    [$owner, $campaign] = signing_owner_campaign();
    $charter = CharterService::start($owner, $campaign);
    [$participant] = signing_approved_participant($campaign);

    expect(CharterSigningService::roster($charter))->toBeEmpty();
});

// ── statusFor() ──────────────────────────────────────────

test('statusFor resolves the viewer signature and null for unsigned viewers', function () {
    [$owner, $campaign, $charter] = signing_published_charter();
    [$participant] = signing_approved_participant($campaign);

    expect(CharterSigningService::statusFor($charter, $participant))->toBeNull();

    $confirmation = CharterSigningService::sign($participant, $charter, signing_valid_input());

    expect(CharterSigningService::statusFor($charter, $participant)?->is($confirmation))->toBeTrue()
        // The owner never holds a participant signature — they countersign.
        ->and(CharterSigningService::statusFor($charter, $owner))->toBeNull();
});

// ── privateAnswersFor() ──────────────────────────────────

test('private answers are visible only to the campaign owner', function () {
    [$owner, $campaign, $charter, $version] = signing_published_charter();
    [$participant] = signing_approved_participant($campaign);
    CharterSigningService::sign($participant, $charter, signing_valid_input());

    $answers = CharterSigningService::privateAnswersFor($charter, $version, $owner);

    expect($answers)->toHaveCount(1)
        ->and($answers->first()->user_id)->toBe($participant->id)
        ->and($answers->first()->private_answers['note_to_gm'])->toBe('Please keep spider imagery off-screen.');

    // A participant — even their own answers — is refused: only the GM
    // aggregate view exists, per the §2 privacy contract.
    CharterSigningService::privateAnswersFor($charter, $version, $participant);
})->throws(AuthorizationException::class);

test('private answers are refused for an uninvolved user', function () {
    [, , $charter, $version] = signing_published_charter();
    $outsider = User::factory()->create();

    CharterSigningService::privateAnswersFor($charter, $version, $outsider);
})->throws(AuthorizationException::class);

// ── roster() re-agreement distinction (M070/S03-T05) ──

/**
 * Amend the published charter materially (§3 safety edit) through the real
 * lifecycle, resetting every signature to pending re-agreement.
 */
function signing_amend_material(SessionZeroSurvey $charter, string $changelog): void
{
    $fresh = $charter->refresh();
    $content = $fresh->content;
    $content['safety']['signal_how'] = 'Open hand or X-card at any time.';

    // The actor is the charter's campaign owner — the same user every
    // signing_amend_material caller published as.
    CharterService::amend($fresh->campaign->owner, $fresh, (string) $fresh->title, $content, $changelog);
}

test('the roster distinguishes re-agreement pending from never signed', function () {
    [$owner, $campaign, $charter] = signing_published_charter();
    [$alice] = signing_approved_participant($campaign);
    [$bob] = signing_approved_participant($campaign);
    [$carol] = signing_approved_participant($campaign);

    CharterSigningService::sign($alice, $charter, signing_valid_input());
    CharterSigningService::sign($bob, $charter, signing_valid_input());

    // Material safety edit resets both signatures; carol never signed.
    signing_amend_material($charter, 'New safety signal.');

    $roster = CharterSigningService::roster($charter->refresh())->keyBy(
        fn (array $entry): string => (string) $entry['user']->id,
    );

    // Everyone is unsigned on v2 — alice and bob are mid re-agreement
    // (signed earlier, reset by the amendment), carol simply never signed.
    expect($roster[$alice->id]['confirmation'])->toBeNull()
        ->and($roster[$alice->id]['reagreementPending'])->toBeTrue()
        ->and($roster[$bob->id]['confirmation'])->toBeNull()
        ->and($roster[$bob->id]['reagreementPending'])->toBeTrue()
        ->and($roster[$carol->id]['confirmation'])->toBeNull()
        ->and($roster[$carol->id]['reagreementPending'])->toBeFalse();

    // Alice re-signs; bob does not.
    CharterSigningService::sign($alice, $charter->refresh(), signing_valid_input());

    // A minor logistics amendment publishes v3: alice's fresh v2 signature
    // is carried, bob's pending row is not — he still owes re-agreement,
    // now against an even earlier version than v3.
    $fresh = $charter->refresh();
    $content = $fresh->content;
    $content['logistics']['absence_notice'] = '48h';
    CharterService::amend($owner, $fresh, (string) $fresh->title, $content, 'Absence window doubled.');

    $roster = CharterSigningService::roster($charter->refresh())->keyBy(
        fn (array $entry): string => (string) $entry['user']->id,
    );

    expect($roster[$alice->id]['confirmation'])->not->toBeNull()
        ->and($roster[$alice->id]['reagreementPending'])->toBeFalse()
        ->and($roster[$bob->id]['confirmation'])->toBeNull()
        ->and($roster[$bob->id]['reagreementPending'])->toBeTrue()
        ->and($roster[$carol->id]['confirmation'])->toBeNull()
        ->and($roster[$carol->id]['reagreementPending'])->toBeFalse();
});

// ── privateAnswersFor() re-agreement continuity (M070/S03-T05) ──

test('the owner keeps seeing the latest submitted answers while re-agreement is pending', function () {
    [$owner, $campaign, $charter, $v1] = signing_published_charter();
    [$alice] = signing_approved_participant($campaign);
    [$bob] = signing_approved_participant($campaign);

    CharterSigningService::sign($alice, $charter, signing_valid_input());
    CharterSigningService::sign($bob, $charter, signing_valid_input());

    signing_amend_material($charter, 'New safety signal.');
    $v2 = $charter->refresh()->currentVersion();

    // No rows sit on v2 — the owner still sees both participants' latest
    // submitted answers, carried from their reset v1 rows byte-for-byte.
    $answers = CharterSigningService::privateAnswersFor($charter->refresh(), $v2, $owner);

    expect($answers)->toHaveCount(2)
        ->and($answers->firstWhere('user_id', $alice->id)?->session_zero_survey_version_id)->toBe($v1->id)
        ->and($answers->firstWhere('user_id', $alice->id)?->private_answers['note_to_gm'])->toBe('Please keep spider imagery off-screen.')
        ->and($answers->firstWhere('user_id', $bob->id)?->session_zero_survey_version_id)->toBe($v1->id);

    // Alice re-signs with fresh answers: her current-version row takes over
    // (older answers never shadow it); bob's pending answers stay visible
    // until he re-signs too.
    $input = signing_valid_input();
    $input['private_answers']['note_to_gm'] = 'Re-signed after the safety change.';
    CharterSigningService::sign($alice, $charter->refresh(), $input);

    $answers = CharterSigningService::privateAnswersFor($charter->refresh(), $v2, $owner);

    expect($answers)->toHaveCount(2)
        ->and($answers->firstWhere('user_id', $alice->id)?->session_zero_survey_version_id)->toBe($v2->id)
        ->and($answers->firstWhere('user_id', $alice->id)?->private_answers['note_to_gm'])->toBe('Re-signed after the safety change.')
        ->and($answers->firstWhere('user_id', $bob->id)?->private_answers['note_to_gm'])->toBe('Please keep spider imagery off-screen.');
});

test('rows without submitted answers never surface through the continuity fallback', function () {
    [$owner, $campaign, $charter, $v1] = signing_published_charter();
    [$alice] = signing_approved_participant($campaign);

    CharterSigningService::sign($alice, $charter, signing_valid_input());
    signing_amend_material($charter, 'New safety signal.');
    $v2 = $charter->refresh()->currentVersion();

    // A reset participant row that never carried answers (null — e.g. a
    // factory-shaped legacy row) contributes nothing: the fallback only
    // surfaces answers that were actually submitted.
    SessionZeroConfirmation::factory()->onVersion($v1)->pendingReagreement()->create([
        'user_id' => User::factory()->create()->id,
        'role' => SessionZeroConfirmation::ROLE_PARTICIPANT,
        'attestations' => array_fill_keys(CharterContent::AGREEMENT_ITEMS, true),
    ]);

    $answers = CharterSigningService::privateAnswersFor($charter->refresh(), $v2, $owner);

    expect($answers)->toHaveCount(1)
        ->and($answers->first()->user_id)->toBe($alice->id)
        ->and($answers->first()->private_answers['note_to_gm'])->toBe('Please keep spider imagery off-screen.');
});

test('private answers stay owner-gated while re-agreement is pending', function () {
    [$owner, $campaign, $charter] = signing_published_charter();
    [$alice] = signing_approved_participant($campaign);
    [$bob] = signing_approved_participant($campaign);

    CharterSigningService::sign($alice, $charter, signing_valid_input());
    signing_amend_material($charter, 'New safety signal.');
    $v2 = $charter->refresh()->currentVersion();

    CharterSigningService::privateAnswersFor($charter->refresh(), $v2, $bob);
})->throws(AuthorizationException::class);

// ── workspaceHealth() (M070/S04-T03) ─────────────────────

test('workspaceHealth returns the owners charter row with the roster math', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-19 09:00:00'));

    [$owner, $campaign, $charter, $version] = signing_published_charter();
    [$alice] = signing_approved_participant($campaign);
    [$bob] = signing_approved_participant($campaign);

    CharterSigningService::sign($alice, $charter, signing_valid_input());

    // Five days pass with bob's signature still outstanding.
    Carbon::setTestNow(Carbon::parse('2026-10-24 09:00:00'));

    $rows = CharterSigningService::workspaceHealth($owner);

    // The eager-loaded campaign model rides along for route()
    // generation (eloquent-practices convention: models, not ids) —
    // asserted by identity, then excluded from the strict scalar-shape
    // comparison (two live model instances are never ===).
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['campaign']->is($campaign))->toBeTrue()
        ->and(collect($rows[0])->except('campaign')->all())->toBe([
            'campaign_id' => $campaign->id,
            'campaign_name' => $campaign->name,
            'charter_uuid' => $charter->uuid,
            'status' => 'active',
            'current_version' => 1,
            'approvedCount' => 2,
            'signedCount' => 1,
            'pendingCount' => 1,
            'percentSigned' => 50,
            'daysOutstanding' => 5,
            'countersigned' => false,
        ]);

    Carbon::setTestNow();
});

test('workspaceHealth orders by newest ACTIVITY — a later signature outranks an older row save', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-19 09:00:00'));

    [$owner, $campaignA, $charterA] = signing_published_charter();
    [$alice] = signing_approved_participant($campaignA);

    // Charter B publishes a day later — its survey row is the NEWER row.
    Carbon::setTestNow(Carbon::parse('2026-10-20 09:00:00'));
    [$ownerB, $campaignB, $charterB] = signing_published_charter();
    $campaignB->forceFill(['owner_id' => $owner->id])->save();
    $campaignB->refresh();

    // Charter A is signed on day three: the signature is the newest
    // activity in the workspace, even though B's survey row was saved
    // more recently than A's last row-level touch.
    Carbon::setTestNow(Carbon::parse('2026-10-22 09:00:00'));
    CharterSigningService::sign($alice, $charterA, signing_valid_input());

    $rows = CharterSigningService::workspaceHealth($owner);
    $campaignIds = array_map(fn (array $row) => $row['campaign_id'], $rows);

    expect($campaignIds)->toBe([$campaignA->id, $campaignB->id])
        ->and($rows[0]['signedCount'])->toBe(1);

    Carbon::setTestNow();
});

test('workspaceHealth reports a participant-less charter as zero percent, never outstanding', function () {
    [$owner] = signing_owner_campaign();
    $campaign = Campaign::factory()->create([
        'owner_id' => $owner->id,
        'name' => ['en' => 'Quiet Table'],
        'description' => ['en' => 'A campaign nobody joined yet.'],
        'max_players' => 5,
        'session_duration' => 3,
        'recurrence' => 'weekly',
    ]);
    $charter = CharterService::start($owner, $campaign);
    $content = CharterContent::quickStart($campaign)->toArray();
    $content['overview']['expected_shape'] = 'ongoing';
    CharterService::publish($owner, $charter, 'Quiet Table', $content);

    $rows = CharterSigningService::workspaceHealth($owner);

    // approvedCount 0 → percent 0 (the view renders "no participants yet"),
    // and a charter nobody owes a signature is never outstanding.
    expect($rows[0]['approvedCount'])->toBe(0)
        ->and($rows[0]['signedCount'])->toBe(0)
        ->and($rows[0]['pendingCount'])->toBe(0)
        ->and($rows[0]['percentSigned'])->toBe(0)
        ->and($rows[0]['daysOutstanding'])->toBeNull()
        ->and($rows[0]['countersigned'])->toBeFalse();
});

test('workspaceHealth reports 100 percent and the countersign trail once everyone signs', function () {
    [$owner, $campaign, $charter] = signing_published_charter();
    [$alice] = signing_approved_participant($campaign);
    [$bob] = signing_approved_participant($campaign);

    CharterSigningService::sign($alice, $charter, signing_valid_input());
    CharterSigningService::sign($bob, $charter, signing_valid_input());
    CharterSigningService::countersign($owner, $charter);

    $rows = CharterSigningService::workspaceHealth($owner);

    expect($rows[0]['approvedCount'])->toBe(2)
        ->and($rows[0]['signedCount'])->toBe(2)
        ->and($rows[0]['pendingCount'])->toBe(0)
        ->and($rows[0]['percentSigned'])->toBe(100)
        // A fully signed charter is never "outstanding", even days later.
        ->and($rows[0]['daysOutstanding'])->toBeNull()
        ->and($rows[0]['countersigned'])->toBeTrue();
});

test('workspaceHealth loses the countersign trail when a material amendment resets signatures', function () {
    [$owner, $campaign, $charter] = signing_published_charter();
    [$alice] = signing_approved_participant($campaign);

    CharterSigningService::sign($alice, $charter, signing_valid_input());
    CharterSigningService::countersign($owner, $charter);

    signing_amend_material($charter, 'New safety signal.');

    $rows = CharterSigningService::workspaceHealth($owner);

    // v2 is current: no signed rows on it, the v1 countersignature is off
    // the current version — the trail is gone until re-agreement completes.
    expect($rows[0]['current_version'])->toBe(2)
        ->and($rows[0]['signedCount'])->toBe(0)
        ->and($rows[0]['pendingCount'])->toBe(1)
        ->and($rows[0]['percentSigned'])->toBe(0)
        ->and($rows[0]['countersigned'])->toBeFalse();
});

test('workspaceHealth includes draft and archived charters without version math', function () {
    [$owner, $campaign, $charter] = signing_published_charter();
    $charter->archive();

    $second = Campaign::factory()->create([
        'owner_id' => $owner->id,
        'name' => ['en' => 'Unwritten Nights'],
        'description' => ['en' => 'A campaign still being planned.'],
        'max_players' => 5,
        'session_duration' => 3,
        'recurrence' => 'weekly',
    ]);
    CharterService::start($owner, $second);
    signing_approved_participant($second);

    $rows = collect(CharterSigningService::workspaceHealth($owner))->keyBy('campaign_id');

    // Archived keeps its published version (frozen history, nobody signed).
    expect($rows[$campaign->id]['status'])->toBe('archived')
        ->and($rows[$campaign->id]['current_version'])->toBe(1)
        ->and($rows[$campaign->id]['approvedCount'])->toBe(0)
        ->and($rows[$campaign->id]['daysOutstanding'])->toBeNull()
        ->and($rows[$campaign->id]['countersigned'])->toBeFalse();

    // A draft has no version: participants exist, nobody is outstanding
    // against a version that does not exist yet.
    expect($rows[$second->id]['status'])->toBe('draft')
        ->and($rows[$second->id]['current_version'])->toBeNull()
        ->and($rows[$second->id]['approvedCount'])->toBe(1)
        ->and($rows[$second->id]['signedCount'])->toBe(0)
        ->and($rows[$second->id]['pendingCount'])->toBe(1)
        ->and($rows[$second->id]['percentSigned'])->toBe(0)
        ->and($rows[$second->id]['daysOutstanding'])->toBeNull()
        ->and($rows[$second->id]['countersigned'])->toBeFalse();
});

test('workspaceHealth clamps percent and pending when a signer leaves the approved roster', function () {
    [$owner, $campaign, $charter] = signing_published_charter();
    [$alice] = signing_approved_participant($campaign);
    [$bob] = signing_approved_participant($campaign);

    CharterSigningService::sign($alice, $charter, signing_valid_input());
    CharterSigningService::sign($bob, $charter, signing_valid_input());

    // Bob signed, then was benched — his signature stays pinned to the
    // version while he leaves the approved roster.
    CampaignParticipant::query()
        ->where('campaign_id', $campaign->id)
        ->whereBelongsTo($bob)
        ->update(['status' => ParticipantStatus::Benched->value]);

    $rows = CharterSigningService::workspaceHealth($owner);

    expect($rows[0]['approvedCount'])->toBe(1)
        ->and($rows[0]['signedCount'])->toBe(2)
        // Clamped: 200% raw → 100%, -1 pending raw → 0.
        ->and($rows[0]['pendingCount'])->toBe(0)
        ->and($rows[0]['percentSigned'])->toBe(100)
        ->and($rows[0]['daysOutstanding'])->toBeNull();
});

test('workspaceHealth scopes to the owners charters and caps the list at ten newest', function () {
    [$owner] = signing_owner_campaign();

    // Another GM's charter never appears.
    [$otherOwner, $otherCampaign] = signing_owner_campaign();
    CharterService::start($otherOwner, $otherCampaign);

    // A legacy session-zero survey row (no campaign, kind='survey') never
    // appears either — ownership derives from campaigns, not GM profiles.
    SessionZeroSurvey::factory()->create([
        'gm_profile_id' => GMProfile::factory()->create(['user_id' => $owner->id])->id,
    ]);

    // Eleven draft charters churn the same owner; explicit updated_at pins
    // the ordering deterministically (drafts never consume free-tier slots).
    foreach (range(1, 11) as $i) {
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'name' => ['en' => "Health Watch {$i}"],
            'description' => ['en' => "Deterministic campaign {$i}."],
            'max_players' => 5,
            'session_duration' => 3,
            'recurrence' => 'weekly',
        ]);
        $charter = CharterService::start($owner, $campaign);
        $charter->forceFill(['updated_at' => Carbon::parse('2026-10-01 00:00:00')->addMinutes($i)])->save();
    }

    $rows = CharterSigningService::workspaceHealth($owner);

    $names = array_column($rows, 'campaign_name');
    expect($rows)->toHaveCount(10)
        ->and($names[0])->toBe('Health Watch 11') // newest first
        ->and($names)->not->toContain('Health Watch 1') // oldest dropped
        // Scoping: the other GM's charter and the legacy survey stay out.
        ->and($names)->not->toContain('The Threshold Heist');
});

test('workspaceHealth is empty for an owner without charters', function () {
    [$owner] = signing_owner_campaign();

    expect(CharterSigningService::workspaceHealth($owner))->toBe([]);
});

// ── Disabled accounts (review pass 4: consent-surface backstop) ──

test('sign is refused for a disabled participant on a residual session', function () {
    [$owner, $campaign, $charter] = signing_published_charter();
    [$participant] = signing_approved_participant($campaign);
    $participant->update(['is_disabled' => true, 'disabled_at' => now()]);

    try {
        CharterSigningService::sign($participant, $charter, signing_valid_input());
        $this->fail('Expected ValidationException: disabled accounts cannot sign.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('charter')
            ->and($e->errors()['charter'][0])->toBe(__('auth.error_your_account_has_been_disabled'));
    }

    expect($charter->confirmations()->whereBelongsTo($participant)->exists())->toBeFalse();
});

test('countersign is refused for a disabled owner', function () {
    [$owner, $campaign, $charter] = signing_published_charter();
    [$participant] = signing_approved_participant($campaign);
    CharterSigningService::sign($participant, $charter, signing_valid_input());

    $owner->update(['is_disabled' => true, 'disabled_at' => now()]);

    try {
        CharterSigningService::countersign($owner, $charter);
        $this->fail('Expected ValidationException: disabled owners cannot countersign.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('charter')
            ->and($e->errors()['charter'][0])->toBe(__('auth.error_your_account_has_been_disabled'));
    }

    expect($charter->confirmations()->where('role', SessionZeroConfirmation::ROLE_GM)->exists())->toBeFalse();
});
