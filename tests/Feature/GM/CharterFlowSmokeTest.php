<?php

use App\Livewire\GM\Charter\CharterComposer;
use App\Models\Campaign;
use App\Models\SessionZeroSurvey;
use App\Models\SessionZeroSurveyVersion;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

//
// Charter end-to-end flow smoke test (M070/S01).
//
// Walks the whole authoring lifecycle through the real surfaces: owner
// reaches the composer from campaign detail, quick-starts a draft
// prefilled from the campaign, edits a field with autosave-on-step-move,
// publishes immutable v1 (with the structured log line), then a guest
// reads the published version at the share URL and the owner sees the
// View-charter card on campaign detail. No GM role/subscription is
// involved anywhere (R084: charter authoring is free-tier safety).
//

/**
 * Campaign owner + campaign with deterministic quick-start inputs.
 *
 * @return array{0: User, 1: Campaign}
 */
function charter_smoke_owner_campaign(): array
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

it('flows from campaign detail to a published charter a guest can read', function () {
    [$owner, $campaign] = charter_smoke_owner_campaign();

    // The owner reaches the composer from campaign detail.
    $this->actingAs($owner)
        ->get(route('campaigns.charter', ['locale' => 'en', 'id' => $campaign->id]))
        ->assertOk()
        ->assertSee(__('charter.heading_start', [], 'en'));

    // Quick start creates the draft row, prefilled from the campaign.
    Livewire::actingAs($owner)
        ->test(CharterComposer::class, ['id' => $campaign->id])
        ->call('startCharter', 'quickstart')
        ->assertSet('state', 'draft');

    $charter = SessionZeroSurvey::charter()->where('campaign_id', $campaign->id)->first();

    expect($charter)->not->toBeNull()
        ->and($charter->status)->toBe('draft')
        ->and($charter->current_version)->toBeNull()
        ->and($charter->content['overview']['pitch'])->toBe('A noir intrigue campaign in the city of Threshold.')
        ->and($charter->content['logistics']['cadence'])->toBe('weekly');

    // Spy from here on: the start log line is already behind us, publish logs next.
    Log::spy();

    // Edit a field and move on — the step move autosaves the draft.
    Livewire::actingAs($owner)
        ->test(CharterComposer::class, ['id' => $campaign->id])
        ->set('content.overview.expected_shape', 'ongoing')
        ->set('content.overview.system_notes', 'D&D 5E (2024 rules), PHB only.')
        ->call('goToStep', 2)
        ->assertSet('step', 2);

    $charter->refresh();

    expect($charter->content['overview']['system_notes'])->toBe('D&D 5E (2024 rules), PHB only.')
        ->and($charter->content['overview']['expected_shape'])->toBe('ongoing');

    // Publish from the review step: immutable v1 snapshot + active charter.
    Livewire::actingAs($owner)
        ->test(CharterComposer::class, ['id' => $campaign->id])
        ->call('goToStep', 8)
        ->call('publish')
        ->assertSet('state', 'published');

    $charter->refresh();

    $version = SessionZeroSurveyVersion::where('session_zero_survey_id', $charter->id)->first();

    expect($version)->not->toBeNull()
        ->and($version->version_number)->toBe(1)
        ->and($version->content_schema)->toBe(1)
        ->and($version->content['overview']['system_notes'])->toBe('D&D 5E (2024 rules), PHB only.')
        ->and($charter->status)->toBe('active')
        ->and($charter->current_version)->toBe(1);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Charter published'
            && ($context['version'] ?? null) === 1
            && ($context['campaign_id'] ?? null) === $campaign->id);

    // A guest reads the published v1 snapshot at the share URL.
    auth()->logout();

    $this->get(route('charter.view', ['locale' => 'en', 'uuid' => $charter->uuid]))
        ->assertOk()
        ->assertSee('D&D 5E (2024 rules), PHB only.')
        ->assertSee(__('charter.section_overview_title', [], 'en'));

    // Campaign detail shows the owner the View-charter card with the version chip.
    $this->actingAs($owner)
        ->get(route('campaigns.show', ['locale' => 'en', 'id' => $campaign->id]))
        ->assertOk()
        ->assertSee(__('charter.action_view_charter', [], 'en'))
        ->assertSee(__('charter.label_version_badge', ['version' => 1], 'en'));
})->group('smoke');
