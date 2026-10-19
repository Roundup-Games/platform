<?php

use App\Dto\CharterContent;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\SessionZeroConfirmation;
use App\Models\SessionZeroSurvey;
use App\Models\User;
use App\Services\Charter\CharterService;
use App\Services\Charter\CharterSigningService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

//
// Charter amendment end-to-end smoke test (M070/S03-T05).
//
// Walks the whole amendment lifecycle through the real services: publish
// v1 → everyone signs → GM countersigns → material amendment resets every
// signature (participants and the countersignature) to pending
// re-agreement → the roster shows the distinct pending state with the
// countersign gate re-armed → everyone re-signs → countersign on the new
// version goes green — then a minor amendment carries every signature
// byte-for-byte with zero roster change and no countersign debt. The
// ids-only 'Charter amended' log line is asserted via Log spy (privacy
// contract: logs never carry answer content).
//

/**
 * Campaign owner + campaign with deterministic quick-start inputs
 * (session_duration 3.0 pins a mappable session-length; expected_shape is
 * the one authoring choice quick-start leaves empty — filled before
 * publish).
 *
 * @return array{0: User, 1: Campaign}
 */
function amendment_smoke_owner_campaign(): array
{
    $owner = User::factory()->create([
        'email_verified_at' => now(),
        'profile_complete' => true,
    ]);
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
 * @return array{0: User, 1: Campaign, 2: SessionZeroSurvey}
 */
function amendment_smoke_published_charter(): array
{
    [$owner, $campaign] = amendment_smoke_owner_campaign();
    $charter = CharterService::start($owner, $campaign);
    $content = CharterContent::quickStart($campaign)->toArray();
    $content['overview']['expected_shape'] = 'ongoing';
    CharterService::publish($charter, 'The Threshold Heist', $content);

    return [$owner, $campaign, $charter->refresh()];
}

/** An approved participant (fresh user + CampaignParticipant row). */
function amendment_smoke_participant(Campaign $campaign): User
{
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'profile_complete' => true,
    ]);
    CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $user->id,
    ]);

    return $user;
}

/**
 * Valid sign input carrying a distinctive privacy marker in the §2
 * answers, so the privacy contract can be asserted over carried rows.
 *
 * @return array<string, mixed>
 */
function amendment_smoke_input(string $note = 'Please keep the spider imagery off-screen.'): array
{
    return [
        'attestations' => array_fill_keys(CharterContent::AGREEMENT_ITEMS, true),
        'private_answers' => [
            'my_lines_categories' => [],
            'my_veils_categories' => [],
            'my_lines_text' => 'ZIRCON-SMOKE-LINES no body horror',
            'my_veils_text' => '',
            'note_to_gm' => $note,
        ],
    ];
}

it('resets the whole table on a material amendment and re-signs green end to end', function () {
    [$owner, $campaign, $charter] = amendment_smoke_published_charter();
    $alice = amendment_smoke_participant($campaign);
    $bob = amendment_smoke_participant($campaign);
    $v1 = $charter->currentVersion();

    CharterSigningService::sign($alice, $charter, amendment_smoke_input());
    CharterSigningService::sign($bob, $charter, amendment_smoke_input());
    CharterSigningService::countersign($owner, $charter);

    Log::spy();

    $fresh = $charter->refresh();
    $content = $fresh->content;
    $content['safety']['signal_how'] = 'Open hand or X-card at any time.';
    $v2 = CharterService::amend($fresh, (string) $fresh->title, $content, 'We now signal with an open hand or the X-card.');

    // The amendment log line carries ids only — never answer content.
    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Charter amended'
            && $context === [
                'survey_id' => $charter->id,
                'uuid' => $charter->uuid,
                'campaign_id' => $campaign->id,
                'version' => 2,
                'is_material' => true,
            ]);

    expect($v2->is_material)->toBeTrue()
        ->and($v2->version_number)->toBe(2)
        ->and($v2->changelog)->toBe('We now signal with an open hand or the X-card.')
        // Every earlier row — both participant signatures AND the gm
        // countersignature — flipped to pending re-agreement; v2 is empty.
        ->and(SessionZeroConfirmation::where('session_zero_survey_version_id', $v1->id)
            ->where('status', SessionZeroConfirmation::STATUS_PENDING_REAGREEMENT)->count())->toBe(3)
        ->and(SessionZeroConfirmation::where('session_zero_survey_version_id', $v2->id)->count())->toBe(0);

    // The roster shows the distinct re-agreement-pending state.
    $roster = CharterSigningService::roster($charter->refresh());
    expect($roster)->toHaveCount(2)
        ->and($roster->where('reagreementPending', true)->count())->toBe(2)
        ->and($roster->pluck('confirmation')->filter()->count())->toBe(0);

    // ...in the owner's UI too, with the countersign gate re-armed.
    app()->setLocale('en');
    $this->actingAs($owner)
        ->get(route('charter.view', ['locale' => 'en', 'uuid' => $charter->refresh()->uuid]))
        ->assertOk()
        ->assertSee(__('charter.label_roster_reagreement_pending', [], 'en'));

    try {
        CharterSigningService::countersign($owner, $charter->refresh());
        $this->fail('Expected countersign to be blocked while re-agreement is pending.');
    } catch (ValidationException $e) {
        expect($e->errors()['charter'][0])
            ->toBe(__('charter.error_countersign_pending_signatures', ['count' => 2]));
    }

    CharterSigningService::sign($alice, $charter->refresh(), amendment_smoke_input('Re-signed after the safety change.'));
    CharterSigningService::sign($bob, $charter->refresh(), amendment_smoke_input());
    $gm = CharterSigningService::countersign($owner, $charter->refresh());

    expect($gm->session_zero_survey_version_id)->toBe($v2->id)
        ->and($gm->role)->toBe(SessionZeroConfirmation::ROLE_GM)
        // Roster fully green: everyone signed on the current version.
        ->and(CharterSigningService::roster($charter->refresh())
            ->pluck('confirmation')->filter()->count())->toBe(2)
        // The reset v1 rows stay pending as immutable audit history.
        ->and(SessionZeroConfirmation::where('session_zero_survey_version_id', $v1->id)
            ->where('status', SessionZeroConfirmation::STATUS_PENDING_REAGREEMENT)->count())->toBe(3);
})->group('smoke');

it('carries every signature byte-for-byte through a minor amendment with zero roster change', function () {
    [$owner, $campaign, $charter] = amendment_smoke_published_charter();
    $alice = amendment_smoke_participant($campaign);
    $bob = amendment_smoke_participant($campaign);
    $v1 = $charter->currentVersion();

    CharterSigningService::sign($alice, $charter, amendment_smoke_input('First signature with markers.'));
    CharterSigningService::sign($bob, $charter, amendment_smoke_input('Second signature with markers.'));
    CharterSigningService::countersign($owner, $charter);

    $rosterBefore = CharterSigningService::roster($charter->refresh());

    Log::spy();

    $fresh = $charter->refresh();
    $content = $fresh->content;
    $content['logistics']['absence_notice'] = '48h';
    $v2 = CharterService::amend($fresh, (string) $fresh->title, $content, 'Absence window doubled to 48 hours.');

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Charter amended'
            && $context === [
                'survey_id' => $charter->id,
                'uuid' => $charter->uuid,
                'campaign_id' => $campaign->id,
                'version' => 2,
                'is_material' => false,
            ]);

    expect($v2->is_material)->toBeFalse()
        ->and($v2->version_number)->toBe(2);

    // Every signature — both participants and the countersignature — was
    // carried onto v2 byte-for-byte (attestations + private answers).
    $carried = SessionZeroConfirmation::where('session_zero_survey_version_id', $v2->id)->get();
    expect($carried)->toHaveCount(3);

    foreach ($carried as $row) {
        $original = SessionZeroConfirmation::where('session_zero_survey_version_id', $v1->id)
            ->where('user_id', $row->user_id)
            ->where('role', $row->role)
            ->first();

        expect($original)->not->toBeNull()
            ->and($row->status)->toBe(SessionZeroConfirmation::STATUS_SIGNED)
            ->and($row->attestations)->toBe($original->attestations)
            ->and($row->private_answers)->toBe($original->private_answers);
    }

    // Zero roster change: same approved table, everyone still signed on the
    // current version, nobody owes re-agreement.
    $rosterAfter = CharterSigningService::roster($charter->refresh());
    expect($rosterAfter)->toHaveCount($rosterBefore->count())
        ->and($rosterAfter->pluck('confirmation')->filter()->count())->toBe(2)
        ->and($rosterAfter->where('reagreementPending', true))->toBeEmpty();

    // No countersign debt: the carried gm row already covers v2.
    try {
        CharterSigningService::countersign($owner, $charter->refresh());
        $this->fail('Expected countersign to be refused — the carry already covers v2.');
    } catch (ValidationException $e) {
        expect($e->errors()['charter'][0])->toBe(__('charter.error_already_countersigned'));
    }
})->group('smoke');
