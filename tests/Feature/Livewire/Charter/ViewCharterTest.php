<?php

use App\Dto\CharterContent;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\SessionZeroSurvey;
use App\Models\SessionZeroSurveyVersion;
use App\Models\User;
use App\Services\Charter\CharterService;
use App\Services\Charter\CharterSigningService;
use Illuminate\Support\Str;

// M070/S01-T05 — public read-only charter view: active charters render the
// immutable version snapshot for guests, drafts are owner-only (404 hides
// them from everyone else), archived charters keep rendering with a note,
// legacy surveys keep their 404 here, and all stored content renders
// escaped. Asserts strings through __() lookups (K004 locale decoupling).

/**
 * Campaign owner + campaign with deterministic quick-start inputs.
 *
 * @return array{0: User, 1: Campaign}
 */
function charter_view_owner_campaign(): array
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

/** Plain authenticated non-owner for authorization negatives. */
function charter_view_other_user(): User
{
    return User::factory()->create([
        'email_verified_at' => now(),
        'profile_complete' => true,
    ]);
}

/**
 * Publish a quick-start charter enriched so every section card renders,
 * and return the fresh active charter row.
 */
function charter_view_published(Campaign $campaign, User $owner): SessionZeroSurvey
{
    $charter = CharterService::start($owner, $campaign, 'quickstart');

    $content = CharterContent::quickStart($campaign)->toArray();
    $content['overview']['expected_shape'] = 'ongoing';
    $content['overview']['themes'] = ['intrigue', 'heist'];
    $content['boundaries']['intro_note'] = 'Boundaries keep the table fun for everyone.';
    $content['boundaries']['lines_categories'] = ['harm_to_children'];
    $content['boundaries']['lines_text'] = 'No graphic torture scenes.';
    $content['boundaries']['veils_categories'] = ['sexual_content'];
    $content['safety']['signal_how'] = 'Raise a hand or say pause.';
    $content['policies']['etiquette'] = ['phones_away'];
    $content['character']['build_constraints'] = 'Level 1 start, standard array.';
    $content['character']['backstory_requested'] = true;

    CharterService::publish($charter, (string) $campaign->name, $content);

    return $charter->refresh();
}

/** Public charter URL (explicit locale per the composer-test pattern). */
function charter_view_url(SessionZeroSurvey $charter, string $locale = 'en'): string
{
    return route('charter.view', ['locale' => $locale, 'uuid' => $charter->uuid]);
}

// ── Published Charters ──────────────────────────────────

describe('ViewCharter Published', function () {
    it('renders an active charter publicly for a guest', function () {
        [$owner, $campaign] = charter_view_owner_campaign();
        $charter = charter_view_published($campaign, $owner);

        $this->get(charter_view_url($charter))
            ->assertOk()
            ->assertSee((string) $campaign->name)
            ->assertSee(__('charter.view_description_by_gm', [], 'en'))
            // All eight section headings
            ->assertSee(__('charter.section_overview_title', [], 'en'))
            ->assertSee(__('charter.section_boundaries_title', [], 'en'))
            ->assertSee(__('charter.section_safety_title', [], 'en'))
            ->assertSee(__('charter.section_policies_title', [], 'en'))
            ->assertSee(__('charter.section_ai_tools_title', [], 'en'))
            ->assertSee(__('charter.section_logistics_title', [], 'en'))
            ->assertSee(__('charter.section_character_title', [], 'en'))
            ->assertSee(__('charter.section_agreement_title', [], 'en'))
            // Choice labels, chips, and vocabulary map through charter.*
            ->assertSee(__('charter.label_expected_shape_ongoing', [], 'en'))
            ->assertSee(__('charter.label_cadence_weekly', [], 'en'))
            ->assertSee(__('charter.label_theme_intrigue', [], 'en'))
            ->assertSee(__('charter.label_boundary_harm_to_children', [], 'en'))
            ->assertSee(__('charter.label_etiquette_phones_away', [], 'en'))
            // §8 agreement copy is informational reading content
            ->assertSee(__('charter.view_agreement_intro', [], 'en'))
            ->assertSee(__('charter.confirm_read_attestation', [], 'en'))
            ->assertSee(__('charter.confirm_safety', [], 'en'));
    });

    it('renders for authenticated non-owners and hides the GM strip', function () {
        [$owner, $campaign] = charter_view_owner_campaign();
        $charter = charter_view_published($campaign, $owner);

        app()->setLocale('en');
        $strip = __('charter.content_gm_strip_published', [
            'version' => 1,
            'date' => format_date($charter->currentVersion()->published_at),
        ], 'en');

        $this->actingAs(charter_view_other_user())
            ->get(charter_view_url($charter))
            ->assertOk()
            ->assertDontSee($strip);
    });

    it('shows the owner the GM strip with version, date, and copy link', function () {
        [$owner, $campaign] = charter_view_owner_campaign();
        $charter = charter_view_published($campaign, $owner);

        app()->setLocale('en');
        $strip = __('charter.content_gm_strip_published', [
            'version' => 1,
            'date' => format_date($charter->currentVersion()->published_at),
        ], 'en');

        $this->actingAs($owner)
            ->get(charter_view_url($charter))
            ->assertOk()
            ->assertSee($strip)
            ->assertSee(__('common.action_copy_link', [], 'en'));
    });

    it('renders the immutable version snapshot, not later row mutations', function () {
        [$owner, $campaign] = charter_view_owner_campaign();
        $charter = charter_view_published($campaign, $owner);

        $mutated = $charter->content;
        $mutated['overview']['pitch'] = 'Mutated row pitch that must never render.';
        $charter->forceFill(['content' => $mutated])->save();

        $this->get(charter_view_url($charter))
            ->assertOk()
            ->assertSee('A noir intrigue campaign in the city of Threshold.')
            ->assertDontSee('Mutated row pitch that must never render.');
    });

    it('renders archived charters with an archived note', function () {
        [$owner, $campaign] = charter_view_owner_campaign();
        $charter = charter_view_published($campaign, $owner);
        $charter->archive();

        $this->get(charter_view_url($charter))
            ->assertOk()
            ->assertSee(__('charter.content_archived_note', [], 'en'))
            ->assertSee(__('charter.section_overview_title', [], 'en'));
    });

    it('escapes stored content — script tags never render raw', function () {
        [$owner, $campaign] = charter_view_owner_campaign();
        $charter = CharterService::start($owner, $campaign, 'quickstart');

        $content = CharterContent::quickStart($campaign)->toArray();
        $content['overview']['expected_shape'] = 'ongoing';
        $content['overview']['pitch'] = '<script>alert(1)</script>Safe pitch body.';
        CharterService::publish($charter, (string) $campaign->name, $content);

        $this->get(charter_view_url($charter->refresh()))
            ->assertOk()
            ->assertDontSee('<script>alert', false)
            ->assertSee('Safe pitch body.');
    });

    it('renders German section headings on a de locale request', function () {
        [$owner, $campaign] = charter_view_owner_campaign();
        $charter = charter_view_published($campaign, $owner);

        $this->get(charter_view_url($charter, 'de'))
            ->assertOk()
            ->assertSee(__('charter.section_overview_title', [], 'de'))
            ->assertSee(__('charter.section_safety_title', [], 'de'))
            ->assertSee(__('charter.section_agreement_title', [], 'de'))
            ->assertSee(__('charter.view_agreement_intro', [], 'de'))
            ->assertSee(__('charter.label_expected_shape_ongoing', [], 'de'));
    });
});

// ── Drafts & Access Control ─────────────────────────────

describe('ViewCharter Drafts and 404s', function () {
    it('returns 404 for an unknown uuid', function () {
        $this->get(route('charter.view', ['locale' => 'en', 'uuid' => (string) Str::uuid()]))
            ->assertNotFound();
    });

    it('returns 404 for a legacy session-zero survey uuid', function () {
        $survey = SessionZeroSurvey::factory()->create();

        expect($survey->isCharter())->toBeFalse();

        $this->get(charter_view_url($survey))
            ->assertNotFound();
    });

    it('returns 404 for a draft uuid for guests and non-owners', function () {
        [$owner, $campaign] = charter_view_owner_campaign();
        $charter = CharterService::start($owner, $campaign, 'quickstart');

        expect($charter->isDraft())->toBeTrue();

        $this->get(charter_view_url($charter))->assertNotFound();

        $this->actingAs(charter_view_other_user())
            ->get(charter_view_url($charter))
            ->assertNotFound();
    });

    it('shows the draft with a banner to the campaign owner', function () {
        [$owner, $campaign] = charter_view_owner_campaign();
        $charter = CharterService::start($owner, $campaign, 'quickstart');

        $this->actingAs($owner)
            ->get(charter_view_url($charter))
            ->assertOk()
            ->assertSee(__('charter.view_draft_banner', [], 'en'))
            ->assertSee('A noir intrigue campaign in the city of Threshold.')
            ->assertSee(__('charter.section_overview_title', [], 'en'));
    });
});

// ── Amendment Re-Agreement Banner (M070/S03-T04) ────────

/**
 * An approved participant of the campaign (fresh user + row).
 *
 * @return array{0: User, 1: CampaignParticipant}
 */
function charter_view_approved_participant(Campaign $campaign): array
{
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'profile_complete' => true,
    ]);
    $participant = CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $user->id,
        'status' => ParticipantStatus::Approved->value,
    ]);

    return [$user, $participant];
}

/** All five §8 attestations ticked — sign() input. */
function charter_view_all_attestations(): array
{
    return array_fill_keys(CharterContent::AGREEMENT_ITEMS, true);
}

/**
 * Publish a material amendment (§3 safety edit) and return the new
 * immutable version — the state a signed participant is reset in.
 */
function charter_view_amend_material(SessionZeroSurvey $charter, string $changelog): SessionZeroSurveyVersion
{
    $fresh = $charter->refresh();
    $content = $fresh->content;
    $content['safety']['signal_how'] = 'Open hand or X-card at any time.';

    return CharterService::amend($fresh, (string) $fresh->title, $content, $changelog);
}

describe('ViewCharter Amendment Banner', function () {
    it('shows the amended banner with changelog and diff to a reset participant', function () {
        [$owner, $campaign] = charter_view_owner_campaign();
        $charter = charter_view_published($campaign, $owner);
        [$participant] = charter_view_approved_participant($campaign);

        CharterSigningService::sign($participant, $charter, ['attestations' => charter_view_all_attestations()]);

        $v2 = charter_view_amend_material($charter, 'We now signal with an open hand or the X-card.');
        app()->setLocale('en');

        $this->actingAs($participant)
            ->get(charter_view_url($charter->refresh()))
            ->assertOk()
            ->assertSee(__('charter.content_amended_banner_title', [], 'en'))
            ->assertSee(__('charter.content_amended_banner_body', [], 'en'))
            ->assertSee(__('charter.content_amended_banner_on', ['date' => format_date($v2->published_at)], 'en'))
            ->assertSee(__('charter.label_amendment_changelog_shown', [], 'en'))
            ->assertSee('We now signal with an open hand or the X-card.')
            ->assertSee(__('charter.action_show_changes', [], 'en'))
            // The diff is against the version they signed: the v1 safety
            // signal renders as the "before" value inside the diff rows.
            ->assertSee(__('charter.heading_diff_changes', [], 'en'))
            ->assertSee(__('charter.field_safety_signal_how_label', [], 'en'))
            ->assertSee(__('charter.label_diff_old', [], 'en'))
            ->assertSee('Raise a hand or say pause.')
            ->assertSee(__('charter.label_diff_new', [], 'en'))
            ->assertSee('Open hand or X-card at any time.');
    });

    it('clears the banner and shows the signed card after re-signing', function () {
        [$owner, $campaign] = charter_view_owner_campaign();
        $charter = charter_view_published($campaign, $owner);
        [$participant] = charter_view_approved_participant($campaign);

        CharterSigningService::sign($participant, $charter, ['attestations' => charter_view_all_attestations()]);
        charter_view_amend_material($charter, 'New safety signal.');
        CharterSigningService::sign($participant, $charter->refresh(), ['attestations' => charter_view_all_attestations()]);

        app()->setLocale('en');

        $this->actingAs($participant)
            ->get(charter_view_url($charter->refresh()))
            ->assertOk()
            ->assertDontSee(__('charter.content_amended_banner_title', [], 'en'))
            ->assertDontSee(__('charter.content_amended_banner_body', [], 'en'))
            ->assertSee(__('charter.heading_signed_card', [], 'en'))
            ->assertDontSee(__('charter.heading_signature_panel', [], 'en'));
    });

    it('shows no banner to a participant whose signature a minor amendment carried', function () {
        [$owner, $campaign] = charter_view_owner_campaign();
        $charter = charter_view_published($campaign, $owner);
        [$participant] = charter_view_approved_participant($campaign);

        CharterSigningService::sign($participant, $charter, ['attestations' => charter_view_all_attestations()]);

        $fresh = $charter->refresh();
        $content = $fresh->content;
        $content['overview']['pitch'] = 'A tightened noir pitch after session two.';
        CharterService::amend($fresh, (string) $fresh->title, $content, 'Pitch tightened.');

        app()->setLocale('en');

        $this->actingAs($participant)
            ->get(charter_view_url($charter->refresh()))
            ->assertOk()
            ->assertDontSee(__('charter.content_amended_banner_title', [], 'en'))
            ->assertSee(__('charter.heading_signed_card', [], 'en'));
    });

    it('never shows the banner to guests or participants who owe no re-agreement', function () {
        [$owner, $campaign] = charter_view_owner_campaign();
        $charter = charter_view_published($campaign, $owner);
        [$participant] = charter_view_approved_participant($campaign);

        CharterSigningService::sign($participant, $charter, ['attestations' => charter_view_all_attestations()]);
        charter_view_amend_material($charter, 'New safety signal.');

        // A guest is not a participant; a fellow approved participant who
        // never signed owes a plain signature — neither inherits the reset
        // viewer's banner state.
        [$newcomer] = charter_view_approved_participant($campaign);
        app()->setLocale('en');

        $this->get(charter_view_url($charter->refresh()))
            ->assertOk()
            ->assertDontSee(__('charter.content_amended_banner_title', [], 'en'));

        $this->actingAs($newcomer)
            ->get(charter_view_url($charter->refresh()))
            ->assertOk()
            ->assertDontSee(__('charter.content_amended_banner_title', [], 'en'))
            ->assertSee(__('charter.heading_signature_panel', [], 'en'));
    });

    it('shows no banner on an un-amended active charter', function () {
        [$owner, $campaign] = charter_view_owner_campaign();
        $charter = charter_view_published($campaign, $owner);
        [$participant] = charter_view_approved_participant($campaign);

        CharterSigningService::sign($participant, $charter, ['attestations' => charter_view_all_attestations()]);
        app()->setLocale('en');

        $this->actingAs($participant)
            ->get(charter_view_url($charter->refresh()))
            ->assertOk()
            ->assertDontSee(__('charter.content_amended_banner_title', [], 'en'))
            ->assertSee(__('charter.heading_signed_card', [], 'en'));
    });

    it('hides the banner once the amended charter is archived', function () {
        [$owner, $campaign] = charter_view_owner_campaign();
        $charter = charter_view_published($campaign, $owner);
        [$participant] = charter_view_approved_participant($campaign);

        CharterSigningService::sign($participant, $charter, ['attestations' => charter_view_all_attestations()]);
        charter_view_amend_material($charter, 'New safety signal.');
        $charter->refresh()->archive();
        app()->setLocale('en');

        $this->actingAs($participant)
            ->get(charter_view_url($charter->refresh()))
            ->assertOk()
            ->assertDontSee(__('charter.content_amended_banner_title', [], 'en'))
            ->assertSee(__('charter.content_archived_note', [], 'en'));
    });
});

// ── Roster Re-Agreement Distinction & Privacy (M070/S03-T05) ──

/** Signing input with distinctive privacy-marker strings in the §2 answers. */
function charter_view_marker_input(): array
{
    return [
        'attestations' => charter_view_all_attestations(),
        'private_answers' => [
            'my_lines_categories' => [],
            'my_veils_categories' => [],
            'my_lines_text' => 'ZIRCON-MARKER-LINES no body horror on screen',
            'my_veils_text' => '',
            'note_to_gm' => 'ZIRCON-MARKER-NOTE please surprise me with lore',
        ],
    ];
}

describe('ViewCharter Roster Re-Agreement', function () {
    it('shows the owner distinct roster copy and carried answers during re-agreement', function () {
        [$owner, $campaign] = charter_view_owner_campaign();
        $charter = charter_view_published($campaign, $owner);
        [$alice] = charter_view_approved_participant($campaign);
        [$bob] = charter_view_approved_participant($campaign);

        CharterSigningService::sign($alice, $charter, [
            'attestations' => charter_view_all_attestations(),
            'private_answers' => [
                'my_lines_categories' => [],
                'my_veils_categories' => [],
                'my_lines_text' => '',
                'my_veils_text' => '',
                'note_to_gm' => 'Please keep the spider imagery off-screen.',
            ],
        ]);
        // Bob never signs.

        charter_view_amend_material($charter, 'New safety signal.');
        app()->setLocale('en');

        $this->actingAs($owner)
            ->get(charter_view_url($charter->refresh()))
            ->assertOk()
            // Alice: unsigned on v2 but mid re-agreement — distinct chip, and
            // her carried answers stay visible through the choke point.
            ->assertSee(__('charter.label_roster_reagreement_pending', [], 'en'))
            ->assertSee('Please keep the spider imagery off-screen.')
            // Bob: never signed — the plain not-signed copy, no re-agreement.
            ->assertSee(__('charter.label_roster_not_signed', [], 'en'))
            // The roster summary still counts both as unsigned on v2.
            ->assertSee(__('charter.label_roster_signed_summary', ['signed' => 0, 'total' => 2], 'en'));
    });

    it('hides private-answer markers from guests across a material amend and a minor carry', function () {
        [$owner, $campaign] = charter_view_owner_campaign();
        $charter = charter_view_published($campaign, $owner);
        [$alice] = charter_view_approved_participant($campaign);

        CharterSigningService::sign($alice, $charter, charter_view_marker_input());

        // Material amendment: the markers now sit on the pending v1 rows.
        charter_view_amend_material($charter, 'New safety signal.');

        $this->get(charter_view_url($charter->refresh()))
            ->assertOk()
            ->assertDontSee('ZIRCON-MARKER-LINES')
            ->assertDontSee('ZIRCON-MARKER-NOTE');

        // Minor amendment carries the markers onto the new version's rows
        // byte-for-byte — still invisible to a guest.
        $fresh = $charter->refresh();
        $content = $fresh->content;
        $content['logistics']['absence_notice'] = '48h';
        CharterService::amend($fresh, (string) $fresh->title, $content, 'Absence window doubled.');

        $this->get(charter_view_url($charter->refresh()))
            ->assertOk()
            ->assertDontSee('ZIRCON-MARKER-LINES')
            ->assertDontSee('ZIRCON-MARKER-NOTE');
    });

    it('hides private-answer markers from other participants and shows them only to the owner', function () {
        [$owner, $campaign] = charter_view_owner_campaign();
        $charter = charter_view_published($campaign, $owner);
        [$alice] = charter_view_approved_participant($campaign);
        [$bob] = charter_view_approved_participant($campaign);

        CharterSigningService::sign($alice, $charter, charter_view_marker_input());
        CharterSigningService::sign($bob, $charter, ['attestations' => charter_view_all_attestations()]);

        charter_view_amend_material($charter, 'New safety signal.');

        // Another participant never sees alice's markers — original or
        // carried.
        $this->actingAs($bob)
            ->get(charter_view_url($charter->refresh()))
            ->assertOk()
            ->assertDontSee('ZIRCON-MARKER-LINES')
            ->assertDontSee('ZIRCON-MARKER-NOTE');

        // ...while the owner does: the markers render only inside the
        // owner-gated roster (carried answers during re-agreement).
        $this->actingAs($owner)
            ->get(charter_view_url($charter->refresh()))
            ->assertOk()
            ->assertSee('ZIRCON-MARKER-LINES')
            ->assertSee('ZIRCON-MARKER-NOTE');
    });
});
