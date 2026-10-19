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

// M070/S02-T03 — participant signing panel on the public charter view:
// guests, non-participants, and pending participants see the byte-identical
// read-only S01 page (no panel, no form fields); an approved participant of
// an active charter sees the §2 private-answer inputs inline at the
// boundaries section and the five verbatim §8 attestations below the
// agreement section; sign() delegates to CharterSigningService and flips to
// the signed-state card. PRIVACY CONTRACT: a signer's private answers are
// visible only on their own page — never to guests, other participants, or
// unauthenticated requests. Asserts strings through __() lookups (K004).

/**
 * Campaign owner + campaign with deterministic quick-start inputs.
 *
 * @return array{0: User, 1: Campaign}
 */
function charter_panel_owner_campaign(): array
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
 * Publish a quick-start charter so every section card renders (version 1
 * exists and current_version points at it).
 *
 * @return array{0: User, 1: Campaign, 2: SessionZeroSurvey, 3: SessionZeroSurveyVersion}
 */
function charter_panel_published(): array
{
    [$owner, $campaign] = charter_panel_owner_campaign();
    $charter = CharterService::start($owner, $campaign);

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

    CharterService::publish($charter, 'The Threshold Heist', $content);

    $charter = $charter->refresh();

    return [$owner, $campaign, $charter, $charter->currentVersion()];
}

/**
 * A campaign participant in the given status (fresh user + row).
 *
 * @return array{0: User, 1: CampaignParticipant}
 */
function charter_panel_participant(Campaign $campaign, ParticipantStatus $status = ParticipantStatus::Approved): array
{
    $user = User::factory()->create([
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

/** Public charter URL (explicit locale per the composer-test pattern). */
function charter_panel_url(SessionZeroSurvey $charter, string $locale = 'en'): string
{
    return route('charter.view', ['locale' => $locale, 'uuid' => $charter->uuid]);
}

/** The five §8 attestations, all ticked. */
function charter_panel_all_attestations(): array
{
    return [
        'read_attestation' => true,
        'safety' => true,
        'policies' => true,
        'ai_tools' => true,
        'logistics' => true,
    ];
}

// ── Panel visibility ────────────────────────────────────

describe('Charter signing panel visibility', function () {
    it('renders no panel and no form fields for a guest', function () {
        [, , $charter] = charter_panel_published();
        app()->setLocale('en');

        $this->get(charter_panel_url($charter))
            ->assertOk()
            ->assertDontSee(__('charter.heading_signature_panel', [], 'en'))
            ->assertDontSee(__('charter.heading_private_answers', [], 'en'))
            ->assertDontSee(__('charter.action_sign_charter', [], 'en'))
            ->assertDontSee(__('charter.heading_signed_card', [], 'en'))
            // No Livewire-managed inputs anywhere on the read-only page.
            ->assertDontSee('wire:model=', false);
    });

    it('renders no panel for an authenticated non-participant', function () {
        [, $campaign, $charter] = charter_panel_published();
        $outsider = User::factory()->create(['email_verified_at' => now(), 'profile_complete' => true]);
        app()->setLocale('en');

        $this->actingAs($outsider)
            ->get(charter_panel_url($charter))
            ->assertOk()
            ->assertDontSee(__('charter.heading_signature_panel', [], 'en'))
            ->assertDontSee(__('charter.heading_private_answers', [], 'en'))
            ->assertDontSee(__('charter.action_sign_charter', [], 'en'))
            ->assertDontSee('wire:model=', false);
    });

    it('renders no panel for a pending participant', function () {
        [, $campaign, $charter] = charter_panel_published();
        [$pending] = charter_panel_participant($campaign, ParticipantStatus::Pending);
        app()->setLocale('en');

        $this->actingAs($pending)
            ->get(charter_panel_url($charter))
            ->assertOk()
            ->assertDontSee(__('charter.heading_signature_panel', [], 'en'))
            ->assertDontSee(__('charter.heading_private_answers', [], 'en'))
            ->assertDontSee(__('charter.action_sign_charter', [], 'en'))
            ->assertDontSee('wire:model=', false);
    });

    it('shows an eligible participant the five verbatim attestations and private inputs', function () {
        [, $campaign, $charter] = charter_panel_published();
        [$participant] = charter_panel_participant($campaign);
        app()->setLocale('en');

        $this->actingAs($participant)
            ->get(charter_panel_url($charter))
            ->assertOk()
            ->assertSee(__('charter.heading_signature_panel', [], 'en'))
            ->assertSee(__('charter.heading_private_answers', [], 'en'))
            ->assertSee(__('charter.field_note_to_gm_label', [], 'en'))
            ->assertSee(__('charter.description_privacy_note', [], 'en'))
            // The five checkbox labels are the S01 confirm_* copy, verbatim.
            ->assertSee(__('charter.confirm_read_attestation', [], 'en'))
            ->assertSee(__('charter.confirm_safety', [], 'en'))
            ->assertSee(__('charter.confirm_policies', [], 'en'))
            ->assertSee(__('charter.confirm_ai_tools', [], 'en'))
            ->assertSee(__('charter.confirm_logistics', [], 'en'))
            // Form wiring: attestation checkboxes + private-answer fields.
            ->assertSee('wire:model="attestations.safety"', false)
            ->assertSee('wire:model="myLinesCategories"', false)
            ->assertSee('wire:model="noteToGm"', false);
    });

    it('pins the reactive markup contract of the sign button enable', function () {
        [, $campaign, $charter] = charter_panel_published();
        [$participant] = charter_panel_participant($campaign);
        app()->setLocale('en');

        // MARKUP CONTRACT ONLY (M070/S05-T01): pins the rendered Alpine wiring
        // that makes the sign button reactive — a reactive x-data flag, one
        // delegated change listener that recomputes it, and a single disabled
        // binding on that flag (S03 browser UAT TC3 regression guard).
        // Rendered-output assertions cannot execute JS: the behavioral proof
        // that ticking all five deferred checkboxes enables the button is
        // browser UAT F1 at slice closeout.
        $this->actingAs($participant)
            ->get(charter_panel_url($charter))
            ->assertOk()
            ->assertSee('x-data="{ ready: false }"', false)
            ->assertSee('x-on:change', false)
            ->assertSee('x-bind:disabled="!ready"', false)
            // The former non-reactive dot-form $refs reads must not come back:
            // the reactive listener uses the bracket form
            // $refs['confirm_' + k], so this absence guard is unambiguous.
            ->assertDontSee('$refs.confirm_', false);
    });

    it('renders no signing UI on a draft preview or an archived charter', function () {
        [$owner, $campaign] = charter_panel_owner_campaign();
        $draft = CharterService::start($owner, $campaign);
        app()->setLocale('en');

        $this->actingAs($owner)
            ->get(charter_panel_url($draft))
            ->assertOk()
            ->assertDontSee(__('charter.heading_signature_panel', [], 'en'))
            ->assertDontSee('wire:model=', false);

        [, $campaign, $charter] = charter_panel_published();
        [$participant] = charter_panel_participant($campaign);
        $charter->archive();

        $this->actingAs($participant)
            ->get(charter_panel_url($charter))
            ->assertOk()
            ->assertDontSee(__('charter.heading_signature_panel', [], 'en'))
            ->assertDontSee(__('charter.action_sign_charter', [], 'en'))
            ->assertDontSee('wire:model=', false);
    });
});

// ── Signing flow ────────────────────────────────────────

describe('Charter signing flow', function () {
    it('persists a v1-pinned confirmation with attestations and private answers and shows the signed card', function () {
        [, $campaign, $charter, $version] = charter_panel_published();
        [$participant] = charter_panel_participant($campaign);
        app()->setLocale('en');

        $component = Livewire::actingAs($participant)
            ->test(ViewCharter::class, ['uuid' => $charter->uuid])
            ->set('attestations', charter_panel_all_attestations())
            ->set('myLinesCategories', ['violence'])
            ->set('myVeilsCategories', ['phobias'])
            ->set('myLinesText', 'No graphic torture.')
            ->set('myVeilsText', '')
            ->set('noteToGm', 'Please keep spiders off-screen.')
            ->call('sign')
            ->assertHasNoErrors();

        $confirmation = SessionZeroConfirmation::query()
            ->where('user_id', $participant->id)
            ->firstOrFail();

        expect($confirmation->session_zero_survey_version_id)->toBe($version->id)
            ->and($confirmation->role)->toBe(SessionZeroConfirmation::ROLE_PARTICIPANT)
            ->and($confirmation->status)->toBe(SessionZeroConfirmation::STATUS_SIGNED)
            // toEqual, not toBe: a fresh jsonb fetch reorders object keys
            // (length-then-bytewise), so key order is not part of the contract.
            ->and($confirmation->attestations)->toEqual(charter_panel_all_attestations())
            // '' text normalizes to null through the real surface.
            ->and($confirmation->private_answers)->toEqual([
                'my_lines_categories' => ['violence'],
                'my_veils_categories' => ['phobias'],
                'my_lines_text' => 'No graphic torture.',
                'my_veils_text' => null,
                'note_to_gm' => 'Please keep spiders off-screen.',
            ]);

        $component
            ->assertSee(__('charter.flash_signed_success', [], 'en'))
            ->assertSee(__('charter.heading_signed_card', [], 'en'))
            ->assertSee(__('charter.content_signed_card', [
                'version' => 1,
                'date' => format_date($confirmation->confirmed_at, 'datetime'),
            ], 'en'))
            // Own answers read back, with the privacy note.
            ->assertSee('Please keep spiders off-screen.')
            ->assertSee(__('charter.description_privacy_note', [], 'en'))
            // The form is gone once signed.
            ->assertDontSee(__('charter.action_sign_charter', [], 'en'));
    });

    it('returns a validation error and persists nothing when one attestation is unticked', function () {
        [, $campaign, $charter] = charter_panel_published();
        [$participant] = charter_panel_participant($campaign);
        app()->setLocale('en');

        $attestations = charter_panel_all_attestations();
        $attestations['safety'] = false;

        Livewire::actingAs($participant)
            ->test(ViewCharter::class, ['uuid' => $charter->uuid])
            ->set('attestations', $attestations)
            ->set('noteToGm', 'Should never persist.')
            ->call('sign')
            ->assertHasErrors(['attestations.safety']);

        expect(SessionZeroConfirmation::query()->where('user_id', $participant->id)->count())->toBe(0);
    });

    it('rejects an overlong private answer text through the component', function () {
        [, $campaign, $charter] = charter_panel_published();
        [$participant] = charter_panel_participant($campaign);
        app()->setLocale('en');

        Livewire::actingAs($participant)
            ->test(ViewCharter::class, ['uuid' => $charter->uuid])
            ->set('attestations', charter_panel_all_attestations())
            ->set('myLinesText', str_repeat('a', 2001))
            ->call('sign')
            ->assertHasErrors(['private_answers.my_lines_text']);

        expect(SessionZeroConfirmation::query()->where('user_id', $participant->id)->count())->toBe(0);
    });

    it('blocks a re-sign attempt by an already-signed participant', function () {
        [, $campaign, $charter] = charter_panel_published();
        [$participant] = charter_panel_participant($campaign);
        app()->setLocale('en');

        Livewire::actingAs($participant)
            ->test(ViewCharter::class, ['uuid' => $charter->uuid])
            ->set('attestations', charter_panel_all_attestations())
            ->call('sign')
            ->assertHasNoErrors();

        // A stale second request hits the service guard: one signature per
        // (version, user, role).
        Livewire::actingAs($participant)
            ->test(ViewCharter::class, ['uuid' => $charter->uuid])
            ->set('attestations', charter_panel_all_attestations())
            ->call('sign')
            ->assertHasErrors(['charter']);

        expect(SessionZeroConfirmation::query()->where('user_id', $participant->id)->count())->toBe(1);
    });
});

// ── §2 privacy contract ─────────────────────────────────

describe('Charter private answers privacy contract', function () {
    it('shows a signer their own answers and leaks them to nobody else', function () {
        [, $campaign, $charter] = charter_panel_published();
        [$alice] = charter_panel_participant($campaign);
        [$bob] = charter_panel_participant($campaign);
        $marker = 'PRIVACY-MARKER-'.strtoupper(Str::random(12));
        app()->setLocale('en');

        Livewire::actingAs($alice)
            ->test(ViewCharter::class, ['uuid' => $charter->uuid])
            ->set('attestations', charter_panel_all_attestations())
            ->set('noteToGm', $marker)
            ->call('sign')
            ->assertHasNoErrors();

        $url = charter_panel_url($charter);

        // Alice's own page shows her answers read back on the signed card.
        $this->actingAs($alice)
            ->get($url)
            ->assertOk()
            ->assertSee($marker)
            ->assertSee(__('charter.heading_signed_card', [], 'en'));

        // Guest request: raw HTML must not contain the marker. actingAs()
        // persists across requests inside one test, so log out first
        // (CharterFlowSmokeTest pattern).
        auth()->logout();

        $this->get($url)
            ->assertOk()
            ->assertDontSee($marker);

        // Another approved participant sees their own signing form, never
        // Alice's answers.
        $this->actingAs($bob)
            ->get($url)
            ->assertOk()
            ->assertSee(__('charter.heading_signature_panel', [], 'en'))
            ->assertDontSee($marker);

        // A second unauthenticated request confirms the leak is not
        // session-order dependent.
        auth()->logout();

        $this->get($url)
            ->assertOk()
            ->assertDontSee($marker);
    });
});
