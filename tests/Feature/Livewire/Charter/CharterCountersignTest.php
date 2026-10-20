<?php

use App\Dto\CharterContent;
use App\Enums\ParticipantStatus;
use App\Livewire\Charter\ViewCharter;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\SessionZeroConfirmation;
use App\Models\SessionZeroSurvey;
use App\Models\SessionZeroSurveyVersion;
use App\Models\User;
use App\Services\Charter\CharterService;
use Illuminate\Support\Str;
use Livewire\Livewire;

// M070/S02-T04 — GM roster, countersignature, and aggregated private answers
// on the public charter view: the campaign owner sees a roster of approved
// participants with signed/pending chips and each participant's §2 private
// answers (read-only, loaded through the owner-gated choke point); the
// countersign button unlocks once every approved participant has signed;
// the §8 countersignature line renders to everyone once present. Non-owners
// never see the roster, the answers, or the control; no HTTP/Livewire path
// mutates an existing confirmation. Asserts strings through __() (K004).

/**
 * Owner + campaign + published v1 charter (deterministic quick-start
 * inputs; session_duration 3.0 and expected_shape per the publish gotchas).
 *
 * @return array{0: User, 1: Campaign, 2: SessionZeroSurvey, 3: SessionZeroSurveyVersion}
 */
function charter_cs_published(): array
{
    $owner = User::factory()->create([
        'name' => 'Greta Gamemaster',
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

    $charter = CharterService::start($owner, $campaign);

    $content = CharterContent::quickStart($campaign)->toArray();
    $content['overview']['expected_shape'] = 'ongoing';
    $content['boundaries']['lines_categories'] = ['harm_to_children'];

    CharterService::publish($owner, $charter, 'The Threshold Heist', $content);

    $charter = $charter->refresh();

    return [$owner, $campaign, $charter, $charter->currentVersion()];
}

/**
 * A named campaign participant in the given status (fresh user + row).
 *
 * @return array{0: User, 1: CampaignParticipant}
 */
function charter_cs_participant(Campaign $campaign, string $name, ParticipantStatus $status = ParticipantStatus::Approved): array
{
    $user = User::factory()->create([
        'name' => $name,
        'email_verified_at' => now(),
        'profile_complete' => true,
    ]);
    $participant = CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $user->id,
        'status' => $status->value,
    ]);

    return [$user, $participant];
}

/** Sign the charter as a participant through the real Livewire surface. */
function charter_cs_sign(
    User $participant,
    SessionZeroSurvey $charter,
    string $noteToGm = '',
    array $linesCategories = [],
    array $veilsCategories = [],
): void {
    Livewire::actingAs($participant)
        ->test(ViewCharter::class, ['uuid' => $charter->uuid])
        ->set('attestations', [
            'read_attestation' => true,
            'safety' => true,
            'policies' => true,
            'ai_tools' => true,
            'logistics' => true,
        ])
        ->set('myLinesCategories', $linesCategories)
        ->set('myVeilsCategories', $veilsCategories)
        ->set('noteToGm', $noteToGm)
        ->call('sign')
        ->assertHasNoErrors();
}

/** Public charter URL (explicit locale per the composer-test pattern). */
function charter_cs_url(SessionZeroSurvey $charter, string $locale = 'en'): string
{
    return route('charter.view', ['locale' => $locale, 'uuid' => $charter->uuid]);
}

/** Count of role='gm' confirmations on the given version. */
function charter_cs_gm_count(SessionZeroSurveyVersion $version): int
{
    return SessionZeroConfirmation::query()
        ->where('session_zero_survey_version_id', $version->id)
        ->where('role', SessionZeroConfirmation::ROLE_GM)
        ->count();
}

// ── Roster visibility ───────────────────────────────────

describe('GM roster visibility', function () {
    it('shows the owner the roster with signed and pending states', function () {
        [$owner, $campaign, $charter, $version] = charter_cs_published();
        [$alice] = charter_cs_participant($campaign, 'Alice Acheson');
        charter_cs_participant($campaign, 'Bob Brannigan'); // stays pending
        charter_cs_sign($alice, $charter, 'Please keep spiders off-screen.');

        $confirmation = SessionZeroConfirmation::query()
            ->where('session_zero_survey_version_id', $version->id)
            ->whereBelongsTo($alice)
            ->where('role', SessionZeroConfirmation::ROLE_PARTICIPANT)
            ->firstOrFail();

        app()->setLocale('en');

        $this->actingAs($owner)
            ->get(charter_cs_url($charter))
            ->assertOk()
            ->assertSee(__('charter.heading_gm_roster', [], 'en'))
            ->assertSee(__('charter.label_roster_signed_summary', ['signed' => 1, 'total' => 2], 'en'))
            ->assertSee('Alice Acheson')
            ->assertSee('Bob Brannigan')
            ->assertSee(__('charter.label_roster_signed_chip', [
                'version' => 1,
                'date' => format_date($confirmation->confirmed_at, 'datetime'),
            ], 'en'))
            ->assertSee(__('charter.label_roster_pending_chip', [], 'en'))
            ->assertSee(__('charter.action_roster_answers', [], 'en'))
            // Countersign control present, with the pending-count hint.
            ->assertSee(__('charter.action_countersign_charter', [], 'en'))
            ->assertSee(__('charter.description_countersign_pending', ['count' => 1], 'en'))
            ->assertSee(__('charter.description_roster_privacy', [], 'en'))
            // Answers are strictly read-only: no form input anywhere on the
            // owner page (the GM can never edit a participant's answers).
            ->assertDontSee('wire:model=', false);
    });

    it('hides the roster, the answers, and the countersign control from participants, strangers, and guests', function () {
        [$owner, $campaign, $charter] = charter_cs_published();
        [$alice] = charter_cs_participant($campaign, 'Alice Acheson');
        [$bob] = charter_cs_participant($campaign, 'Bob Brannigan');
        $marker = 'PRIVACY-MARKER-'.strtoupper(Str::random(12));
        charter_cs_sign($alice, $charter, $marker);

        app()->setLocale('en');
        $url = charter_cs_url($charter);

        // Another approved participant: signing surface only, never the roster.
        $this->actingAs($bob)
            ->get($url)
            ->assertOk()
            ->assertDontSee(__('charter.heading_gm_roster', [], 'en'))
            ->assertDontSee(__('charter.action_roster_answers', [], 'en'))
            ->assertDontSee(__('charter.action_countersign_charter', [], 'en'))
            ->assertDontSee(__('charter.label_roster_signed_summary', ['signed' => 1, 'total' => 2], 'en'))
            ->assertDontSee($marker);

        // An uninvolved authenticated user.
        $stranger = User::factory()->create(['email_verified_at' => now(), 'profile_complete' => true]);
        $this->actingAs($stranger)
            ->get($url)
            ->assertOk()
            ->assertDontSee(__('charter.heading_gm_roster', [], 'en'))
            ->assertDontSee(__('charter.action_countersign_charter', [], 'en'))
            ->assertDontSee($marker);

        // A guest (actingAs persists across requests, so log out first).
        auth()->logout();

        $this->get($url)
            ->assertOk()
            ->assertDontSee(__('charter.heading_gm_roster', [], 'en'))
            ->assertDontSee(__('charter.action_countersign_charter', [], 'en'))
            ->assertDontSee($marker);
    });

    it('blocks a countersign attempt from a participant over Livewire', function () {
        [$owner, $campaign, $charter, $version] = charter_cs_published();
        [$bob] = charter_cs_participant($campaign, 'Bob Brannigan');
        app()->setLocale('en');

        Livewire::actingAs($bob)
            ->test(ViewCharter::class, ['uuid' => $charter->uuid])
            ->call('countersign')
            ->assertHasErrors(['charter']);

        expect(charter_cs_gm_count($version))->toBe(0);
    });
});

// ── Countersigning ──────────────────────────────────────

describe('Charter countersigning', function () {
    it('blocks countersign while an approved participant is still pending', function () {
        [$owner, $campaign, $charter, $version] = charter_cs_published();
        [$alice] = charter_cs_participant($campaign, 'Alice Acheson');
        charter_cs_participant($campaign, 'Bob Brannigan');
        charter_cs_sign($alice, $charter);
        app()->setLocale('en');

        Livewire::actingAs($owner)
            ->test(ViewCharter::class, ['uuid' => $charter->uuid])
            ->call('countersign')
            ->assertHasErrors(['charter'])
            ->assertSee(__('charter.error_countersign_pending_signatures', ['count' => 1], 'en'));

        expect(charter_cs_gm_count($version))->toBe(0);
    });

    it('persists a gm v1 countersignature once everyone signed and shows the line to guests too', function () {
        [$owner, $campaign, $charter, $version] = charter_cs_published();
        [$alice] = charter_cs_participant($campaign, 'Alice Acheson');
        [$bob] = charter_cs_participant($campaign, 'Bob Brannigan');
        charter_cs_sign($alice, $charter);
        charter_cs_sign($bob, $charter);
        app()->setLocale('en');

        $url = charter_cs_url($charter);

        // Before the countersignature: guests see the agreement section,
        // but no countersignature line and no GM name.
        $this->get($url)
            ->assertOk()
            ->assertSee(__('charter.section_agreement_title', [], 'en'))
            ->assertDontSee($owner->name);

        Livewire::actingAs($owner)
            ->test(ViewCharter::class, ['uuid' => $charter->uuid])
            ->call('countersign')
            ->assertHasNoErrors()
            ->assertSee(__('charter.flash_countersigned_success', [], 'en'));

        $gm = SessionZeroConfirmation::query()
            ->where('session_zero_survey_version_id', $version->id)
            ->where('role', SessionZeroConfirmation::ROLE_GM)
            ->firstOrFail();

        expect($gm->user_id)->toBe($owner->id)
            ->and($gm->session_zero_survey_version_id)->toBe($version->id)
            ->and((int) $version->version_number)->toBe(1)
            ->and($gm->private_answers)->toBeNull()
            ->and($gm->attestations)->toEqual([
                'read_attestation' => true,
                'safety' => true,
                'policies' => true,
                'ai_tools' => true,
                'logistics' => true,
            ]);

        // Owner page: countersigned state in the GM strip, control gone.
        $this->actingAs($owner)
            ->get($url)
            ->assertOk()
            ->assertSee(__('charter.content_gm_strip_countersigned', [
                'version' => 1,
                'date' => format_date($gm->confirmed_at, 'datetime'),
            ], 'en'))
            ->assertDontSee(__('charter.action_countersign_charter', [], 'en'));

        // Guests see the §8 countersignature line too.
        auth()->logout();

        $this->get($url)
            ->assertOk()
            ->assertSee(__('charter.content_countersigned_line', [
                'gm' => $owner->name,
                'date' => format_date($gm->confirmed_at, 'datetime'),
            ], 'en'))
            ->assertDontSee(__('charter.heading_gm_roster', [], 'en'));
    });

    it('blocks a second countersign attempt', function () {
        [$owner, $campaign, $charter, $version] = charter_cs_published();
        [$alice] = charter_cs_participant($campaign, 'Alice Acheson');
        [$bob] = charter_cs_participant($campaign, 'Bob Brannigan');
        charter_cs_sign($alice, $charter);
        charter_cs_sign($bob, $charter);
        app()->setLocale('en');

        Livewire::actingAs($owner)
            ->test(ViewCharter::class, ['uuid' => $charter->uuid])
            ->call('countersign')
            ->assertHasNoErrors();

        Livewire::actingAs($owner)
            ->test(ViewCharter::class, ['uuid' => $charter->uuid])
            ->call('countersign')
            ->assertHasErrors(['charter']);

        expect(charter_cs_gm_count($version))->toBe(1);
    });
});

// ── Roster private answers (privacy contract) ───────────

describe('GM roster private answers', function () {
    it('lets the owner read a signed participant answers read-only while nobody else sees them', function () {
        [$owner, $campaign, $charter] = charter_cs_published();
        [$alice] = charter_cs_participant($campaign, 'Alice Acheson');
        [$bob] = charter_cs_participant($campaign, 'Bob Brannigan');
        $marker = 'SPIDERS-'.strtoupper(Str::random(10));
        charter_cs_sign($alice, $charter, $marker, ['violence'], ['phobias']);
        charter_cs_sign($bob, $charter);
        app()->setLocale('en');

        $url = charter_cs_url($charter);

        // Owner: the roster detail carries Alice's full answers (chips +
        // note), Bob's empty answers carry the no-answers note.
        $this->actingAs($owner)
            ->get($url)
            ->assertOk()
            ->assertSee($marker)
            ->assertSee(__('charter.label_boundary_violence', [], 'en'))
            ->assertSee(__('charter.label_boundary_phobias', [], 'en'))
            ->assertSee(__('charter.label_roster_answer_note', [], 'en'))
            ->assertSee(__('charter.label_roster_no_answers', [], 'en'))
            ->assertDontSee('wire:model=', false);

        // Bob's own page: signed card only — never Alice's answers.
        $this->actingAs($bob)
            ->get($url)
            ->assertOk()
            ->assertDontSee($marker)
            ->assertDontSee(__('charter.label_roster_answer_lines', [], 'en'));

        // Guest page source never contains the marker.
        auth()->logout();

        $this->get($url)
            ->assertOk()
            ->assertDontSee($marker);
    });

    it('never mutates an existing confirmation private_answers', function () {
        [$owner, $campaign, $charter, $version] = charter_cs_published();
        [$alice] = charter_cs_participant($campaign, 'Alice Acheson');
        [$bob] = charter_cs_participant($campaign, 'Bob Brannigan');
        charter_cs_sign($alice, $charter, 'Keep spiders off-screen.', ['violence']);
        charter_cs_sign($bob, $charter);
        app()->setLocale('en');

        $before = SessionZeroConfirmation::query()
            ->where('session_zero_survey_version_id', $version->id)
            ->whereBelongsTo($alice)
            ->where('role', SessionZeroConfirmation::ROLE_PARTICIPANT)
            ->firstOrFail();

        // Stale re-sign attempt from the participant.
        Livewire::actingAs($alice)
            ->test(ViewCharter::class, ['uuid' => $charter->uuid])
            ->set('attestations', [
                'read_attestation' => true,
                'safety' => true,
                'policies' => true,
                'ai_tools' => true,
                'logistics' => true,
            ])
            ->set('noteToGm', 'TAMPERED ANSWER CONTENT')
            ->call('sign')
            ->assertHasErrors(['charter']);

        // Countersign and a double-countersign attempt.
        Livewire::actingAs($owner)
            ->test(ViewCharter::class, ['uuid' => $charter->uuid])
            ->call('countersign')
            ->assertHasNoErrors();

        Livewire::actingAs($owner)
            ->test(ViewCharter::class, ['uuid' => $charter->uuid])
            ->call('countersign')
            ->assertHasErrors(['charter']);

        $after = SessionZeroConfirmation::query()
            ->where('session_zero_survey_version_id', $version->id)
            ->whereBelongsTo($alice)
            ->where('role', SessionZeroConfirmation::ROLE_PARTICIPANT)
            ->firstOrFail();

        expect($after->id)->toBe($before->id)
            ->and($after->private_answers)->toEqual([
                'my_lines_categories' => ['violence'],
                'my_veils_categories' => [],
                'my_lines_text' => null,
                'my_veils_text' => null,
                'note_to_gm' => 'Keep spiders off-screen.',
            ])
            ->and(SessionZeroConfirmation::query()->whereBelongsTo($alice)->count())->toBe(1)
            ->and(charter_cs_gm_count($version))->toBe(1);
    });
});
