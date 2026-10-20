<?php

use App\Dto\CharterContent;
use App\Livewire\GM\Charter\CharterComposer;
use App\Models\Campaign;
use App\Models\CharterTemplate;
use App\Models\SessionZeroSurvey;
use App\Models\SessionZeroSurveyVersion;
use App\Models\User;
use App\Services\Charter\CharterService;
use App\Services\Charter\CharterTemplateService;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Traits\CreatesUsers;

uses(CreatesUsers::class);

// M070/S01-T04 — GM charter composer: owner-gated 8-step authoring flow with
// quick-start prefill, per-step validation, autosave on step moves, publish
// to immutable version 1, published read-only state, and the campaign-detail
// entry card. Asserts strings through __() lookups (K004 locale decoupling).

/**
 * Campaign owner + campaign with deterministic quick-start inputs.
 *
 * @return array{0: User, 1: Campaign}
 */
function charter_composer_owner_campaign(): array
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
function charter_composer_other_user(): User
{
    return User::factory()->create([
        'email_verified_at' => now(),
        'profile_complete' => true,
    ]);
}

/**
 * Fully publishable schema-v1 content: quick-start defaults plus the one
 * authoring choice quick-start deliberately leaves empty (expected shape).
 *
 * @return array<string, mixed>
 */
function charter_composer_valid_content(Campaign $campaign): array
{
    $content = CharterContent::quickStart($campaign)->toArray();
    $content['overview']['expected_shape'] = 'ongoing';

    return $content;
}

/**
 * Published v1 charter (status active) for amendment tests.
 *
 * @return array{0: User, 1: Campaign, 2: SessionZeroSurvey}
 */
function charter_composer_published_charter(): array
{
    [$owner, $campaign] = charter_composer_owner_campaign();

    $charter = CharterService::start($owner, $campaign, 'quickstart');
    CharterService::publish($owner, $charter, 'The Threshold Heist Charter', charter_composer_valid_content($campaign));

    return [$owner, $campaign, $charter->refresh()];
}

// ── Access Control ──────────────────────────────────────

describe('CharterComposer Access Control', function () {
    it('redirects unauthenticated users to login', function () {
        [, $campaign] = charter_composer_owner_campaign();

        $this->get(route('campaigns.charter', ['locale' => 'en', 'id' => $campaign->id]))
            ->assertRedirect(route('login', 'en'));
    });

    it('shows 404 for unknown campaign ids', function () {
        $user = charter_composer_other_user();

        $this->actingAs($user)
            ->get(route('campaigns.charter', ['locale' => 'en', 'id' => (string) Str::uuid()]))
            ->assertNotFound();
    });

    it('forbids non-owners even when authenticated', function () {
        [$owner, $campaign] = charter_composer_owner_campaign();
        $other = charter_composer_other_user();

        expect($other->id)->not->toBe($owner->id);

        $this->actingAs($other)
            ->get(route('campaigns.charter', ['locale' => 'en', 'id' => $campaign->id]))
            ->assertForbidden();
    });

    it('admits a global admin alongside the owner (manageCharter + policy before())', function () {
        seedRoles();
        [$owner, $campaign] = charter_composer_owner_campaign();

        $admin = User::factory()->create(['profile_complete' => true]);
        $admin->assignRole('Platform Admin');

        $this->actingAs($admin)
            ->get(route('campaigns.charter', ['locale' => 'en', 'id' => $campaign->id]))
            ->assertOk();
    });
});

// ── Start Screen & Quick Start ──────────────────────────

describe('CharterComposer Start Screen', function () {
    it('shows the start screen to an owner with no charter', function () {
        [$owner, $campaign] = charter_composer_owner_campaign();

        $this->actingAs($owner)
            ->get(route('campaigns.charter', ['locale' => 'en', 'id' => $campaign->id]))
            ->assertOk()
            ->assertSee(__('charter.heading_start', [], 'en'))
            ->assertSee(__('charter.action_start_quickstart', [], 'en'))
            ->assertSee(__('charter.action_start_blank', [], 'en'));
    });

    it('quick start creates a draft charter prefilled from the campaign', function () {
        [$owner, $campaign] = charter_composer_owner_campaign();

        Livewire::actingAs($owner)
            ->test(CharterComposer::class, ['id' => $campaign->id])
            ->call('startCharter', 'quickstart')
            ->assertSet('step', 1)
            ->assertSet('state', 'draft')
            ->assertSee(__('charter.section_overview_short', [], 'en'))
            ->assertSee('A noir intrigue campaign in the city of Threshold.');

        $charter = SessionZeroSurvey::charter()
            ->where('campaign_id', $campaign->id)
            ->first();

        expect($charter)->not->toBeNull()
            ->and($charter->kind)->toBe('charter')
            ->and($charter->status)->toBe('draft')
            ->and($charter->current_version)->toBeNull()
            ->and($charter->content['overview']['pitch'])->toBe('A noir intrigue campaign in the city of Threshold.')
            ->and($charter->content['logistics']['cadence'])->toBe('weekly');
    });
});

// ── Stepping, Autosave & Validation ─────────────────────

describe('CharterComposer Stepping', function () {
    it('autosaves the draft when moving to the next step', function () {
        [$owner, $campaign] = charter_composer_owner_campaign();

        Livewire::actingAs($owner)
            ->test(CharterComposer::class, ['id' => $campaign->id])
            ->call('startCharter', 'quickstart')
            ->set('content.overview.expected_shape', 'ongoing')
            ->set('content.overview.pitch', 'An updated pitch written before stepping on.')
            ->call('goToStep', 2)
            ->assertSet('step', 2)
            ->assertSet('draftSaved', true);

        $charter = SessionZeroSurvey::charter()->where('campaign_id', $campaign->id)->first();

        expect($charter->content['overview']['pitch'])->toBe('An updated pitch written before stepping on.')
            ->and($charter->content['overview']['expected_shape'])->toBe('ongoing');
    });

    it('keeps the user on the step with errors when input is invalid', function () {
        [$owner, $campaign] = charter_composer_owner_campaign();

        Livewire::actingAs($owner)
            ->test(CharterComposer::class, ['id' => $campaign->id])
            ->call('startCharter', 'quickstart')
            ->set('content.overview.expected_shape', 'ongoing')
            ->set('content.overview.pitch', '')
            ->call('goToStep', 2)
            ->assertHasErrors(['content.overview.pitch' => 'required'])
            ->assertSet('step', 1);

        // The invalid state never reached the draft (no autosave on failure).
        $charter = SessionZeroSurvey::charter()->where('campaign_id', $campaign->id)->first();
        expect($charter->content['overview']['pitch'])->toBe('A noir intrigue campaign in the city of Threshold.');
    });

    it('allows stepping back without validating the section being left', function () {
        [$owner, $campaign] = charter_composer_owner_campaign();

        Livewire::actingAs($owner)
            ->test(CharterComposer::class, ['id' => $campaign->id])
            ->call('startCharter', 'blank')
            ->set('content.overview.pitch', 'Blank charter pitch.')
            ->set('content.overview.expected_shape', 'short_arc')
            ->call('goToStep', 3) // blank safety tools are empty — but only overview validates
            ->assertSet('step', 3)
            ->set('content.safety.tools', [])
            ->call('goToStep', 2) // backward move autosaves, no validation
            ->assertSet('step', 2)
            ->assertHasNoErrors();
    });
});

// ── Publish ─────────────────────────────────────────────

describe('CharterComposer Publish', function () {
    it('publishes version 1, activates the charter, and shows the share link', function () {
        [$owner, $campaign] = charter_composer_owner_campaign();
        $content = charter_composer_valid_content($campaign);

        Livewire::actingAs($owner)
            ->test(CharterComposer::class, ['id' => $campaign->id])
            ->call('startCharter', 'quickstart')
            ->set('title', 'The Threshold Heist Charter')
            ->set('content', $content)
            ->call('goToStep', 8)
            ->assertSee(__('charter.confirm_read_attestation', [], 'en'))
            ->call('publish')
            ->assertSet('state', 'published');

        $charter = SessionZeroSurvey::charter()->where('campaign_id', $campaign->id)->first();

        expect($charter->status)->toBe('active')
            ->and($charter->current_version)->toBe(1)
            ->and($charter->title)->toBe('The Threshold Heist Charter')
            ->and($charter->content['overview']['expected_shape'])->toBe('ongoing');

        $version = SessionZeroSurveyVersion::where('session_zero_survey_id', $charter->id)->first();

        expect($version)->not->toBeNull()
            ->and($version->version_number)->toBe(1)
            ->and($version->content_schema)->toBe(1)
            ->and($version->content['overview']['expected_shape'])->toBe('ongoing')
            ->and($version->published_at)->not->toBeNull();

        // The published panel surfaces the share link at the stable URL pattern.
        Livewire::actingAs($owner)
            ->test(CharterComposer::class, ['id' => $campaign->id])
            ->assertSee(__('charter.flash_publish_success', [], 'en'))
            ->assertSee(url('/en/charter/'.$charter->uuid))
            ->assertSee(__('charter.action_view_charter', [], 'en'));
    });

    it('jumps back to the safety step and leaves the draft when safety tools are empty', function () {
        [$owner, $campaign] = charter_composer_owner_campaign();
        $content = charter_composer_valid_content($campaign);
        $content['safety']['tools'] = [];

        Livewire::actingAs($owner)
            ->test(CharterComposer::class, ['id' => $campaign->id])
            ->call('startCharter', 'quickstart')
            ->set('content', $content)
            ->call('goToStep', 8)
            ->call('publish')
            ->assertHasErrors(['content.safety.tools'])
            ->assertSet('step', 3);

        $charter = SessionZeroSurvey::charter()->where('campaign_id', $campaign->id)->first();

        expect($charter->status)->toBe('draft')
            ->and(SessionZeroSurveyVersion::where('session_zero_survey_id', $charter->id)->count())->toBe(0);
    });
});

// ── Published State ─────────────────────────────────────

describe('CharterComposer Published State', function () {
    it('shows the published state without editing controls on revisit', function () {
        [$owner, $campaign] = charter_composer_owner_campaign();

        $charter = CharterService::start($owner, $campaign, 'quickstart');
        CharterService::publish($owner, $charter, (string) $campaign->name, charter_composer_valid_content($campaign));

        Livewire::actingAs($owner)
            ->test(CharterComposer::class, ['id' => $campaign->id])
            ->assertSet('state', 'published')
            ->assertSee(__('charter.flash_publish_success', [], 'en'))
            ->assertSee(url('/en/charter/'.$charter->uuid))
            ->assertDontSee(__('charter.action_publish', [], 'en'))
            ->assertDontSee(__('charter.action_save_draft', [], 'en'))
            ->assertDontSee(__('charter.content_draft_banner', [], 'en'));
    });
});

// ── Amendments (M070/S03) ───────────────────────────────

describe('CharterComposer Amendments', function () {
    it('shows the amend button on the published panel for an active charter', function () {
        [$owner, $campaign] = charter_composer_published_charter();

        Livewire::actingAs($owner)
            ->test(CharterComposer::class, ['id' => $campaign->id])
            ->assertSet('state', 'published')
            ->assertSee(__('charter.action_amend_charter', [], 'en'));
    });

    it('hides the amend button for an archived charter', function () {
        [$owner, $campaign, $charter] = charter_composer_published_charter();
        $charter->archive();

        Livewire::actingAs($owner)
            ->test(CharterComposer::class, ['id' => $campaign->id])
            ->assertSet('state', 'published')
            ->assertDontSee(__('charter.action_amend_charter', [], 'en'));
    });

    it('enters the stepper with hydrated content when the amendment starts', function () {
        [$owner, $campaign, $charter] = charter_composer_published_charter();
        $content = charter_composer_valid_content($campaign);

        Livewire::actingAs($owner)
            ->test(CharterComposer::class, ['id' => $campaign->id])
            ->assertSet('amending', false)
            ->call('startAmendment')
            ->assertSet('amending', true)
            ->assertSet('step', 1)
            ->assertSet('title', $charter->title)
            ->assertSet('content.overview.pitch', $content['overview']['pitch'])
            ->assertSee(__('charter.label_amendment_in_progress', [], 'en'))
            ->assertSee(__('charter.action_cancel_amendment', [], 'en'))
            // Amendment mode is never mistaken for a draft.
            ->assertDontSee(__('charter.content_draft_banner', [], 'en'))
            ->assertDontSee(__('charter.action_save_draft', [], 'en'));
    });

    it('keeps the published charter row identical while stepping in amendment mode', function () {
        [$owner, $campaign, $charter] = charter_composer_published_charter();
        $v1 = SessionZeroSurveyVersion::where('session_zero_survey_id', $charter->id)
            ->where('version_number', 1)
            ->firstOrFail();

        Livewire::actingAs($owner)
            ->test(CharterComposer::class, ['id' => $campaign->id])
            ->call('startAmendment')
            ->set('title', 'A session-local title that must not persist')
            ->set('content.overview.pitch', 'A session-local pitch that must not persist.')
            ->call('goToStep', 3)
            ->assertSet('step', 3)
            ->call('saveDraft')
            ->call('goToStep', 8)
            ->assertSet('step', 8)
            ->assertHasNoErrors();

        // Amendment edits are session-local: the row keeps matching v1.
        // Content compares order-insensitively: the charter column (json)
        // preserves insertion order while the version column (jsonb) is
        // key-reordered by PostgreSQL — identical payloads, different order.
        $fresh = $charter->fresh();

        expect($fresh->title)->toBe('The Threshold Heist Charter')
            ->and($fresh->content)->toEqual($v1->content)
            ->and($fresh->current_version)->toBe(1)
            ->and(SessionZeroSurveyVersion::where('session_zero_survey_id', $fresh->id)->count())->toBe(1);
    });

    it('shows the material badge and diff rows on the review step for a safety edit', function () {
        [$owner, $campaign] = charter_composer_published_charter();
        $content = charter_composer_valid_content($campaign);
        $content['safety']['revisit'] = 'on_request'; // §3 change — material

        Livewire::actingAs($owner)
            ->test(CharterComposer::class, ['id' => $campaign->id])
            ->call('startAmendment')
            ->set('content', $content)
            ->call('goToStep', 8)
            ->assertSee(__('charter.label_amendment_material_badge', [], 'en'))
            ->assertSee(__('charter.description_amendment_material', [], 'en'))
            ->assertDontSee(__('charter.label_amendment_minor_badge', [], 'en'))
            ->assertSee(__('charter.heading_diff_changes', [], 'en'))
            // Diff row: revisit field with old and new values localized.
            ->assertSee(__('charter.field_safety_revisit_label', [], 'en'))
            ->assertSee(__('charter.label_revisit_each_session', [], 'en'))
            ->assertSee(__('charter.label_revisit_on_request', [], 'en'))
            ->assertSee(__('charter.field_amendment_changelog_label', [], 'en'));
    });

    it('shows the minor badge for a logistics-only edit', function () {
        [$owner, $campaign] = charter_composer_published_charter();
        $content = charter_composer_valid_content($campaign);
        $content['logistics']['food_drink'] = 'byo'; // §6 logistics only — minor

        Livewire::actingAs($owner)
            ->test(CharterComposer::class, ['id' => $campaign->id])
            ->call('startAmendment')
            ->set('content', $content)
            ->call('goToStep', 8)
            ->assertSee(__('charter.label_amendment_minor_badge', [], 'en'))
            ->assertSee(__('charter.description_amendment_minor', [], 'en'))
            ->assertDontSee(__('charter.label_amendment_material_badge', [], 'en'))
            ->assertSee(__('charter.field_logistics_food_drink_label', [], 'en'));
    });

    it('requires a changelog before an amendment can publish', function () {
        [$owner, $campaign, $charter] = charter_composer_published_charter();

        Livewire::actingAs($owner)
            ->test(CharterComposer::class, ['id' => $campaign->id])
            ->call('startAmendment')
            ->call('goToStep', 8)
            ->call('publish')
            ->assertHasErrors(['amendmentChangelog' => 'required'])
            ->assertSet('amending', true)
            ->assertSet('step', 8);

        expect($charter->fresh()->current_version)->toBe(1)
            ->and(SessionZeroSurveyVersion::where('session_zero_survey_id', $charter->id)->count())->toBe(1);
    });

    it('publishes the amendment as version 2 and returns to the published panel', function () {
        [$owner, $campaign, $charter] = charter_composer_published_charter();
        $content = charter_composer_valid_content($campaign);
        $content['safety']['revisit'] = 'on_request'; // material — §3 change

        Livewire::actingAs($owner)
            ->test(CharterComposer::class, ['id' => $campaign->id])
            ->call('startAmendment')
            ->set('content', $content)
            ->call('goToStep', 8)
            ->set('amendmentChangelog', 'We check in on boundaries on request now.')
            ->call('publish')
            ->assertSet('amending', false)
            ->assertSet('state', 'published')
            ->assertSee(__('charter.action_amend_charter', [], 'en'))
            ->assertSee(__('charter.flash_amendment_published', ['version' => 2], 'en'))
            ->assertHasNoErrors();

        $fresh = $charter->fresh();

        expect($fresh->current_version)->toBe(2)
            ->and($fresh->status)->toBe('active')
            ->and($fresh->content['safety']['revisit'])->toBe('on_request');

        $v2 = SessionZeroSurveyVersion::where('session_zero_survey_id', $fresh->id)
            ->where('version_number', 2)
            ->firstOrFail();

        expect($v2->changelog)->toBe('We check in on boundaries on request now.')
            ->and($v2->is_material)->toBeTrue();
    });

    it('restores the published panel and drops session-local edits on cancel', function () {
        [$owner, $campaign, $charter] = charter_composer_published_charter();
        $publishedContent = $charter->content;

        Livewire::actingAs($owner)
            ->test(CharterComposer::class, ['id' => $campaign->id])
            ->call('startAmendment')
            ->set('title', 'Cancelled session-local title')
            ->set('content.overview.pitch', 'Cancelled session-local pitch.')
            ->set('amendmentChangelog', 'Dropped with the cancel.')
            ->call('cancelAmendment')
            ->assertSet('amending', false)
            ->assertSet('state', 'published')
            ->assertSet('amendmentChangelog', '')
            ->assertSet('title', $charter->title)
            ->assertSet('content.overview.pitch', $publishedContent['overview']['pitch'])
            ->assertDontSee(__('charter.label_amendment_in_progress', [], 'en'))
            ->assertSee(__('charter.action_amend_charter', [], 'en'));

        expect($charter->fresh()->current_version)->toBe(1)
            ->and($charter->fresh()->content)->toBe($publishedContent);
    });
});

// ── Campaign Detail Entry Card ──────────────────────────

describe('CampaignDetail Charter Entry Card', function () {
    it('shows the create-charter card to the owner when no charter exists', function () {
        [$owner, $campaign] = charter_composer_owner_campaign();

        $this->actingAs($owner)
            ->get(route('campaigns.show', ['locale' => 'en', 'id' => $campaign->id]))
            ->assertOk()
            ->assertSee(__('charter.title_entry', [], 'en'))
            ->assertSee(__('charter.action_create_charter', [], 'en'));
    });

    it('shows the continue card with a draft chip while drafting', function () {
        [$owner, $campaign] = charter_composer_owner_campaign();
        CharterService::start($owner, $campaign, 'quickstart');

        $this->actingAs($owner)
            ->get(route('campaigns.show', ['locale' => 'en', 'id' => $campaign->id]))
            ->assertOk()
            ->assertSee(__('charter.action_continue_charter', [], 'en'))
            ->assertSee(__('charter.label_draft_badge', [], 'en'))
            ->assertDontSee(__('charter.action_create_charter', [], 'en'));
    });

    it('shows the view card with the version chip once published', function () {
        [$owner, $campaign] = charter_composer_owner_campaign();

        $charter = CharterService::start($owner, $campaign, 'quickstart');
        CharterService::publish($owner, $charter, (string) $campaign->name, charter_composer_valid_content($campaign));

        $this->actingAs($owner)
            ->get(route('campaigns.show', ['locale' => 'en', 'id' => $campaign->id]))
            ->assertOk()
            ->assertSee(__('charter.action_view_charter', [], 'en'))
            ->assertSee(__('charter.label_version_badge', ['version' => 1], 'en'))
            ->assertDontSee(__('charter.action_continue_charter', [], 'en'));
    });

    it('hides the charter card from non-owners', function () {
        [$owner, $campaign] = charter_composer_owner_campaign();
        $other = charter_composer_other_user();

        CharterService::start($owner, $campaign, 'quickstart');

        $this->actingAs($other)
            ->get(route('campaigns.show', ['locale' => 'en', 'id' => $campaign->id]))
            ->assertOk()
            ->assertDontSee(__('charter.title_entry', [], 'en'))
            ->assertDontSee(__('charter.action_continue_charter', [], 'en'));
    });
});

// ── Free-Tier Limit & Polite Upsell (M070/S04) ─────────

/**
 * A fresh campaign owned by $owner with deterministic quick-start inputs.
 */
function charter_composer_limit_campaign(User $owner): Campaign
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
function charter_composer_active_charter(User $owner): SessionZeroSurvey
{
    $campaign = charter_composer_limit_campaign($owner);
    $charter = CharterService::start($owner, $campaign, 'quickstart');
    CharterService::publish($owner, $charter, (string) $campaign->name, charter_composer_valid_content($campaign));

    return $charter->refresh();
}

describe('CharterComposer Free-Tier Limit', function () {
    it('shows the polite upsell instead of the start buttons at the cap', function () {
        $owner = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);
        charter_composer_active_charter($owner);
        charter_composer_active_charter($owner);
        $third = charter_composer_limit_campaign($owner);

        Livewire::actingAs($owner)
            ->test(CharterComposer::class, ['id' => $third->id])
            ->assertSet('state', 'start')
            ->assertSee(__('charter.content_upsell_limit_title', [], 'en'))
            ->assertSee(__('charter.content_upsell_limit_body', ['limit' => 2], 'en'))
            ->assertSee(__('charter.label_active_charter_limit', ['limit' => 2], 'en'))
            ->assertSee(route('pricing'))
            ->assertSee(route('membership'))
            ->assertDontSee(__('charter.action_start_quickstart', [], 'en'))
            ->assertDontSee(__('charter.action_start_blank', [], 'en'));
    });

    it('refuses startCharter at the cap through the real action', function () {
        $owner = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);
        charter_composer_active_charter($owner);
        charter_composer_active_charter($owner);
        $third = charter_composer_limit_campaign($owner);

        Livewire::actingAs($owner)
            ->test(CharterComposer::class, ['id' => $third->id])
            ->call('startCharter', 'quickstart')
            ->assertHasErrors(['charter'])
            ->assertSet('state', 'start');

        expect(SessionZeroSurvey::charter()->where('campaign_id', $third->id)->exists())->toBeFalse();
    });

    it('shows the normal start screen to a member at the cap', function () {
        $member = $this->createSubscribedGm();
        charter_composer_active_charter($member);
        charter_composer_active_charter($member);
        $third = charter_composer_limit_campaign($member);

        Livewire::actingAs($member)
            ->test(CharterComposer::class, ['id' => $third->id])
            ->assertSet('state', 'start')
            ->assertSee(__('charter.action_start_quickstart', [], 'en'))
            ->assertSee(__('charter.action_start_blank', [], 'en'))
            ->assertDontSee(__('charter.content_upsell_limit_title', [], 'en'));
    });

    it('never consults membership on the draft loop: an owner at the cap keeps editing their draft', function () {
        $owner = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);

        // The draft predates the cap being hit.
        $draftCampaign = charter_composer_limit_campaign($owner);
        CharterService::start($owner, $draftCampaign, 'quickstart');
        charter_composer_active_charter($owner);
        charter_composer_active_charter($owner);

        Livewire::actingAs($owner)
            ->test(CharterComposer::class, ['id' => $draftCampaign->id])
            ->assertSet('state', 'draft')
            ->assertDontSee(__('charter.content_upsell_limit_title', [], 'en'))
            // The one authoring choice quick-start deliberately leaves
            // empty — set it so the forward step move validates cleanly
            // (same idiom as the autosave test).
            ->set('content.overview.expected_shape', 'ongoing')
            ->call('goToStep', 2)
            ->assertSet('step', 2)
            ->assertHasNoErrors();
    });
});

// ── Archive (M070/S04) ─────────────────────────────────

describe('CharterComposer Archive', function () {
    it('shows the archive button on the published panel for an active charter', function () {
        [$owner, $campaign] = charter_composer_published_charter();

        Livewire::actingAs($owner)
            ->test(CharterComposer::class, ['id' => $campaign->id])
            ->assertSet('state', 'published')
            ->assertSee(__('charter.action_archive_charter', [], 'en'))
            ->assertSee(__('charter.action_amend_charter', [], 'en'));
    });

    it('archives through the service and re-renders the read-only archived state', function () {
        [$owner, $campaign, $charter] = charter_composer_published_charter();

        Livewire::actingAs($owner)
            ->test(CharterComposer::class, ['id' => $campaign->id])
            ->call('archive')
            ->assertSet('state', 'published')
            ->assertSee(__('charter.flash_charter_archived', [], 'en'))
            ->assertSee(__('charter.status_archived', [], 'en'))
            ->assertDontSee(__('charter.action_amend_charter', [], 'en'))
            ->assertDontSee(__('charter.action_archive_charter', [], 'en'));

        expect($charter->fresh()->status)->toBe('archived');
    });
});

// ── Member conveniences: templates & start-from-previous (M070/S04) ──

/**
 * Publish an ACTIVE charter on a fresh campaign owned by $member through
 * the real lifecycle, returning the campaign and charter.
 *
 * @return array{0: Campaign, 1: SessionZeroSurvey}
 */
function charter_composer_member_charter(User $member): array
{
    $campaign = charter_composer_limit_campaign($member);
    $charter = CharterService::start($member, $campaign, 'quickstart');
    CharterService::publish($member, $charter, (string) $campaign->name, charter_composer_valid_content($campaign));

    return [$campaign, $charter->refresh()];
}

/**
 * Recursive key-sorted copy: deep-equality on stored charter content must
 * not depend on key order — session_zero_surveys stores order-preserving
 * json while charter_templates stores canonical-order jsonb (like
 * session_zero_survey_versions), so strict === across both engines needs
 * the keys normalized first.
 *
 * @param  array<string, mixed>  $content
 * @return array<string, mixed>
 */
function charter_composer_sorted(array $content): array
{
    ksort($content);

    foreach ($content as $key => $value) {
        if (is_array($value)) {
            $content[$key] = charter_composer_sorted($value);
        }
    }

    return $content;
}

describe('CharterComposer Member Conveniences', function () {
    it('hides both start cards from a free owner entirely', function () {
        [$owner, $campaign] = charter_composer_owner_campaign();

        Livewire::actingAs($owner)
            ->test(CharterComposer::class, ['id' => $campaign->id])
            ->assertSet('state', 'start')
            ->assertSee(__('charter.action_start_quickstart', [], 'en'))
            ->assertDontSee(__('charter.heading_start_from_template', [], 'en'))
            ->assertDontSee(__('charter.title_template_card', [], 'en'))
            ->assertDontSee(__('charter.title_previous_card', [], 'en'))
            ->assertDontSee(__('charter.action_start_from_template', [], 'en'))
            ->assertDontSee(__('charter.action_start_from_previous', [], 'en'));
    });

    it('shows the start cards to a member, with the empty label when nothing is reusable yet', function () {
        $member = $this->createSubscribedGm();
        $campaign = charter_composer_limit_campaign($member);

        Livewire::actingAs($member)
            ->test(CharterComposer::class, ['id' => $campaign->id])
            ->assertSet('state', 'start')
            ->assertSee(__('charter.heading_start_from_template', [], 'en'))
            ->assertSee(__('charter.title_template_card', [], 'en'))
            ->assertSee(__('charter.title_previous_card', [], 'en'))
            ->assertSee(__('charter.label_template_empty', [], 'en'));
    });

    it('member starts from a saved template through the real action', function () {
        $member = $this->createSubscribedGm();
        [, $source] = charter_composer_member_charter($member);
        $template = CharterTemplateService::saveFromCharter($member, $source, 'Standard charter');

        $target = charter_composer_limit_campaign($member);

        Livewire::actingAs($member)
            ->test(CharterComposer::class, ['id' => $target->id])
            ->assertSet('state', 'start')
            ->assertSee($template->name)
            ->assertSee(__('charter.action_start_from_template', [], 'en'))
            ->set('templateId', $template->id)
            ->call('startCharter', 'template', $template->id)
            ->assertSet('state', 'draft');

        $draft = SessionZeroSurvey::query()->charter()->where('campaign_id', $target->id)->first();
        expect($draft)->not->toBeNull()
            ->and($draft->content)->toBe($template->content);
    });

    it('member starts from a previous charter through the real action', function () {
        $member = $this->createSubscribedGm();
        [$sourceCampaign, $source] = charter_composer_member_charter($member);

        $target = charter_composer_limit_campaign($member);

        Livewire::actingAs($member)
            ->test(CharterComposer::class, ['id' => $target->id])
            ->assertSet('state', 'start')
            ->assertSee($source->title)
            ->assertSee(__('charter.action_start_from_previous', [], 'en'))
            ->set('previousCharterId', $source->id)
            ->call('startCharter', 'previous', $source->id)
            ->assertSet('state', 'draft');

        $draft = SessionZeroSurvey::query()->charter()->where('campaign_id', $target->id)->first();
        expect($draft)->not->toBeNull()
            ->and($draft->campaign_id)->toBe($target->id)
            ->and($draft->content)->toBe($source->content);
    });

    it('excludes the current campaign\'s own charter from the previous select', function () {
        $member = $this->createSubscribedGm();
        [$sourceCampaign, $source] = charter_composer_member_charter($member);

        // Campaign B already has its own draft charter — the select must
        // offer the OTHER campaign's charter, never the current one's.
        $target = charter_composer_limit_campaign($member);
        $own = CharterService::start($member, $target, 'quickstart');

        $component = Livewire::actingAs($member)
            ->test(CharterComposer::class, ['id' => $target->id]);

        expect($component->instance()->previousCharters->pluck('id'))
            ->toContain($source->id)
            ->not->toContain($own->id);
    });

    it('refuses a foreign template id through the real action', function () {
        $otherMember = $this->createSubscribedGm();
        [, $otherSource] = charter_composer_member_charter($otherMember);
        $template = CharterTemplateService::saveFromCharter($otherMember, $otherSource, 'Not yours');

        $member = $this->createSubscribedGm();
        $target = charter_composer_limit_campaign($member);

        Livewire::actingAs($member)
            ->test(CharterComposer::class, ['id' => $target->id])
            ->call('startCharter', 'template', $template->id)
            ->assertHasErrors(['charter'])
            ->assertSet('state', 'start');

        expect(SessionZeroSurvey::charter()->where('campaign_id', $target->id)->exists())->toBeFalse();
    });

    it('shows save-as-template on the published panel to a member only', function () {
        $member = $this->createSubscribedGm();
        [$memberCampaign] = charter_composer_member_charter($member);

        Livewire::actingAs($member)
            ->test(CharterComposer::class, ['id' => $memberCampaign->id])
            ->assertSet('state', 'published')
            ->assertSee(__('charter.action_save_as_template', [], 'en'));

        [$freeOwner, $freeCampaign, $freeCharter] = charter_composer_published_charter();

        Livewire::actingAs($freeOwner)
            ->test(CharterComposer::class, ['id' => $freeCampaign->id])
            ->assertSet('state', 'published')
            ->assertDontSee(__('charter.action_save_as_template', [], 'en'));
    });

    it('saves the published charter as a template through the real action', function () {
        $member = $this->createSubscribedGm();
        [$campaign, $charter] = charter_composer_member_charter($member);

        Livewire::actingAs($member)
            ->test(CharterComposer::class, ['id' => $campaign->id])
            ->set('templateName', '  My standard  ')
            ->call('saveAsTemplate')
            ->assertHasNoErrors()
            ->assertSee(__('charter.flash_template_saved', [], 'en'))
            ->assertSet('templateName', '');

        $template = CharterTemplate::query()->whereBelongsTo($member)->first();
        expect($template)->not->toBeNull()
            ->and($template->name)->toBe('My standard')
            ->and(charter_composer_sorted($template->content))->toBe(charter_composer_sorted($charter->content));
    });

    it('validates the template name through the real action', function () {
        $member = $this->createSubscribedGm();
        [$campaign] = charter_composer_member_charter($member);

        Livewire::actingAs($member)
            ->test(CharterComposer::class, ['id' => $campaign->id])
            ->set('templateName', '   ')
            ->call('saveAsTemplate')
            ->assertHasErrors(['templateName']);

        expect(CharterTemplate::query()->whereBelongsTo($member)->count())->toBe(0);
    });
});

// ── Audit export link (M070/S04-T04) ────────────────────

describe('CharterComposer Audit Export Link', function () {
    it('shows the export-audit link on the published panel to a member only', function () {
        $member = $this->createSubscribedGm();
        [$memberCampaign] = charter_composer_member_charter($member);

        Livewire::actingAs($member)
            ->test(CharterComposer::class, ['id' => $memberCampaign->id])
            ->assertSet('state', 'published')
            ->assertSee(__('charter.action_export_audit', [], 'en'))
            ->assertSee(route('campaigns.charter.audit', $memberCampaign));

        [$freeOwner, $freeCampaign] = charter_composer_published_charter();

        Livewire::actingAs($freeOwner)
            ->test(CharterComposer::class, ['id' => $freeCampaign->id])
            ->assertSet('state', 'published')
            ->assertDontSee(__('charter.action_export_audit', [], 'en'));
    });

    it('keeps the export-audit link on an archived charter — frozen history is the point', function () {
        $member = $this->createSubscribedGm();
        [$campaign, $charter] = charter_composer_member_charter($member);
        CharterService::archive($member, $charter);

        Livewire::actingAs($member)
            ->test(CharterComposer::class, ['id' => $campaign->id])
            ->assertSet('state', 'published')
            ->assertSee(__('charter.action_export_audit', [], 'en'))
            ->assertDontSee(__('charter.action_amend_charter', [], 'en'));
    });
});
