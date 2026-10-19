<?php

use App\Enums\ParticipantStatus;
use App\Livewire\Charter\ViewCharter;
use App\Livewire\GM\Charter\CharterComposer;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\SessionZeroConfirmation;
use App\Models\SessionZeroSurvey;
use App\Models\SessionZeroSurveyVersion;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

//
// Charter signing end-to-end smoke test (M070/S02-T05).
//
// Walks the full signing loop through the real surfaces, in the footsteps
// of CharterFlowSmokeTest (S01): the owner quick-starts and publishes v1
// through the composer, two approved participants sign on the public
// /charter/{uuid} page — one with §2 private boundary answers carrying a
// unique marker, one without — and the owner countersigns from the GM
// roster. Asserts the milestone's headline privacy property across real
// page responses (the marker reaches the owner's roster and its own
// signer, never a guest or the other participant), the §8 countersignature
// line appearing on the guest page once present, and v1 pinning of all
// three confirmation rows. Legacy survey confirmations are untouched
// elsewhere (CharterModelsTest); this run stays on the charter surface.
//

/**
 * Campaign owner + campaign with deterministic quick-start inputs
 * (session_duration 3.0 pinned — the quick-start publish gotcha).
 *
 * @return array{0: User, 1: Campaign}
 */
function charter_signing_smoke_owner_campaign(): array
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
        'session_duration' => 3.0,
        'recurrence' => 'weekly',
    ]);

    return [$owner, $campaign];
}

/**
 * A named approved campaign participant (fresh user + row).
 */
function charter_signing_smoke_participant(Campaign $campaign, string $name): User
{
    $user = User::factory()->create([
        'name' => $name,
        'email_verified_at' => now(),
        'profile_complete' => true,
    ]);
    CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $user->id,
        'status' => ParticipantStatus::Approved->value,
    ]);

    return $user;
}

/** Public charter URL (explicit locale per the composer-test pattern). */
function charter_signing_smoke_url(SessionZeroSurvey $charter, string $locale = 'en'): string
{
    return route('charter.view', ['locale' => $locale, 'uuid' => $charter->uuid]);
}

it('flows from published charter through private answers and signatures to the gm countersignature', function () {
    [$owner, $campaign] = charter_signing_smoke_owner_campaign();
    app()->setLocale('en');

    // Author: quick-start the draft, then publish v1 from the composer
    // (expected_shape filled — the other publish gotcha).
    Livewire::actingAs($owner)
        ->test(CharterComposer::class, ['id' => $campaign->id])
        ->call('startCharter', 'quickstart')
        ->assertSet('state', 'draft');

    Livewire::actingAs($owner)
        ->test(CharterComposer::class, ['id' => $campaign->id])
        ->set('content.overview.expected_shape', 'ongoing')
        ->call('goToStep', 8)
        ->call('publish')
        ->assertSet('state', 'published');

    $charter = SessionZeroSurvey::charter()->where('campaign_id', $campaign->id)->firstOrFail();
    $version = SessionZeroSurveyVersion::where('session_zero_survey_id', $charter->id)->firstOrFail();

    expect($version->version_number)->toBe(1)
        ->and($charter->current_version)->toBe(1);

    $alice = charter_signing_smoke_participant($campaign, 'Alice Acheson');
    $bob = charter_signing_smoke_participant($campaign, 'Bob Brannigan');
    $url = charter_signing_smoke_url($charter);
    $marker = 'PRIVACY-MARKER-'.strtoupper(Str::random(12));

    // Participant A opens the charter page and signs through the panel,
    // with §2 private answers carrying the marker plus category selections.
    $this->actingAs($alice)
        ->get($url)
        ->assertOk()
        ->assertSee(__('charter.heading_signature_panel', [], 'en'));

    Livewire::actingAs($alice)
        ->test(ViewCharter::class, ['uuid' => $charter->uuid])
        ->set('attestations', [
            'read_attestation' => true,
            'safety' => true,
            'policies' => true,
            'ai_tools' => true,
            'logistics' => true,
        ])
        ->set('myLinesCategories', ['harm_to_children'])
        ->set('myVeilsCategories', ['phobias'])
        ->set('noteToGm', $marker)
        ->call('sign')
        ->assertHasNoErrors()
        ->assertSee(__('charter.heading_signed_card', [], 'en'));

    // Participant B signs with the attestations only — no private answers.
    Livewire::actingAs($bob)
        ->test(ViewCharter::class, ['uuid' => $charter->uuid])
        ->set('attestations', [
            'read_attestation' => true,
            'safety' => true,
            'policies' => true,
            'ai_tools' => true,
            'logistics' => true,
        ])
        ->call('sign')
        ->assertHasNoErrors()
        ->assertSee(__('charter.heading_signed_card', [], 'en'));

    // Privacy contract across real page responses: neither the guest page
    // nor the other participant's page ever contains A's marker (actingAs
    // persists across requests inside one test, so log out for the guest).
    auth()->logout();

    $this->get($url)
        ->assertOk()
        ->assertDontSee($marker);

    $this->actingAs($bob)
        ->get($url)
        ->assertOk()
        ->assertSee(__('charter.heading_signed_card', [], 'en'))
        ->assertDontSee($marker);

    // The owner sees the signature roster at 2/2 with A's private answers
    // (marker note + category chips) — GM-only, strictly read-only.
    $this->actingAs($owner)
        ->get($url)
        ->assertOk()
        ->assertSee(__('charter.heading_gm_roster', [], 'en'))
        ->assertSee(__('charter.label_roster_signed_summary', ['signed' => 2, 'total' => 2], 'en'))
        ->assertSee('Alice Acheson')
        ->assertSee('Bob Brannigan')
        ->assertSee($marker)
        ->assertSee(__('charter.label_boundary_harm_to_children', [], 'en'))
        ->assertSee(__('charter.label_boundary_phobias', [], 'en'))
        ->assertDontSee('wire:model=', false);

    // Before the countersignature, the guest agreement section has no
    // countersignature line; the owner then countersigns from the roster.
    auth()->logout();

    $this->get($url)
        ->assertOk()
        ->assertSee(__('charter.section_agreement_title', [], 'en'))
        ->assertDontSee(__('charter.content_countersigned_line', [
            'gm' => $owner->name,
            'date' => format_date(now(), 'datetime'),
        ], 'en'));

    Livewire::actingAs($owner)
        ->test(ViewCharter::class, ['uuid' => $charter->uuid])
        ->call('countersign')
        ->assertHasNoErrors()
        ->assertSee(__('charter.flash_countersigned_success', [], 'en'));

    $gmRow = SessionZeroConfirmation::query()
        ->where('session_zero_survey_version_id', $version->id)
        ->where('role', SessionZeroConfirmation::ROLE_GM)
        ->firstOrFail();

    // The §8 countersignature line now renders on the GUEST page too
    // (Livewire::actingAs above keeps the owner logged in — log out
    // before the guest fetch, CharterCountersignTest pattern).
    auth()->logout();

    $this->get($url)
        ->assertOk()
        ->assertSee(__('charter.section_agreement_title', [], 'en'))
        ->assertSee(__('charter.content_countersigned_line', [
            'gm' => $owner->name,
            'date' => format_date($gmRow->confirmed_at, 'datetime'),
        ], 'en'))
        ->assertDontSee(__('charter.heading_gm_roster', [], 'en'))
        ->assertDontSee($marker);

    // Version-1 pinning: exactly three confirmations exist (2 participants
    // + 1 gm), every one of them pinned to the immutable v1 snapshot.
    $confirmations = $charter->confirmations()->with('version')->get();

    expect($confirmations)->toHaveCount(3)
        ->and($confirmations->where('role', SessionZeroConfirmation::ROLE_PARTICIPANT)->count())->toBe(2)
        ->and($confirmations->where('role', SessionZeroConfirmation::ROLE_GM)->count())->toBe(1);

    $confirmations->each(function (SessionZeroConfirmation $confirmation) use ($version): void {
        expect($confirmation->session_zero_survey_version_id)->toBe($version->id)
            ->and($confirmation->version->version_number)->toBe(1)
            ->and($confirmation->status)->toBe(SessionZeroConfirmation::STATUS_SIGNED);
    });
})->group('smoke');
