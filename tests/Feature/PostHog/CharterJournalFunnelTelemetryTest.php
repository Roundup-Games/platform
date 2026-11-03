<?php

use App\Dto\CharterContent;
use App\Livewire\Campaigns\CampaignPreviouslyOn;
use App\Livewire\GM\Charter\CharterAudit;
use App\Livewire\GM\Charter\CharterComposer;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\SessionZeroConfirmation;
use App\Models\SessionZeroSurvey;
use App\Models\SessionZeroSurveyVersion;
use App\Models\User;
use App\Services\CampaignJournalService;
use App\Services\Charter\CharterService;
use App\Services\Charter\CharterSigningService;
use App\Services\Charter\CharterTemplateService;
use App\Services\PostHogClient;
use App\Services\PostHogConsentChecker;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Role;
use Tests\Helpers\TestablePostHogClient;
use Tests\Traits\CreatesUsers;

uses(CreatesUsers::class);

// Funnel telemetry for the M069–M071 surfaces (charter lifecycle, member
// upsell impressions, journal adoption). Every capture goes through
// PostHogAnalytics::capture — consent-gated, never throws (MEM864 product
// path). Badges are intentionally absent: badge_awarded already ships via
// the M069 listener.

beforeEach(function () {
    Config::set('posthog.enabled', true);
    Config::set('posthog.api_key', 'phc_test_key');

    $this->posthogClient = new TestablePostHogClient;
    $this->app->instance(PostHogClient::class, $this->posthogClient);

    $checker = test()->mock(PostHogConsentChecker::class);
    $checker->shouldReceive('hasAnalyticsConsent')->andReturn(true);
    app()->instance(PostHogConsentChecker::class, $checker);
});

/**
 * Captured event names in order.
 *
 * @return array<int, string>
 */
function cjt_captured_events(TestablePostHogClient $client): array
{
    return array_column($client->capturedCalls, 'event');
}

/**
 * Properties of the first capture carrying the given event name.
 *
 * @return array<string, mixed>
 */
function cjt_properties(TestablePostHogClient $client, string $event): array
{
    foreach ($client->capturedCalls as $call) {
        if (($call['event'] ?? null) === $event) {
            return $call['properties'] ?? [];
        }
    }

    return [];
}

/**
 * Publishable quick-start content for a campaign.
 *
 * @return array<string, mixed>
 */
function cjt_valid_content(Campaign $campaign): array
{
    $content = CharterContent::quickStart($campaign)->toArray();
    $content['overview']['expected_shape'] = 'ongoing';

    return $content;
}

/**
 * A published charter (v1) on a fresh campaign owned by $owner.
 *
 * @return array{0: Campaign, 1: SessionZeroSurvey}
 */
function cjt_published_charter(User $owner): array
{
    $campaign = Campaign::factory()->create([
        'owner_id' => $owner->id,
        'name' => ['en' => 'The Threshold Heist'],
        'description' => ['en' => 'A noir intrigue campaign in the city of Threshold.'],
        'max_players' => 5,
        'session_duration' => 3,
        'recurrence' => 'weekly',
    ]);
    $charter = CharterService::start($owner, $campaign, 'quickstart');
    CharterService::publish($owner, $charter, 'The Threshold Heist Charter', cjt_valid_content($campaign));

    return [$campaign, $charter->refresh()];
}

describe('CharterJournalFunnelTelemetry — charter lifecycle', function () {
    test('captures started, published, and amended with funnel properties', function () {
        $gm = $this->createSubscribedGm();

        [$campaign, $charter] = cjt_published_charter($gm);

        $amended = cjt_valid_content($campaign);
        $amended['safety']['revisit'] = 'on_request';
        CharterService::amend($gm, $charter, 'The Threshold Heist Charter', $amended, 'We check in on boundaries on request now.');

        $events = cjt_captured_events($this->posthogClient);

        expect($events)->toContain('charter.started')
            ->toContain('charter.published')
            ->toContain('charter.amended')
            ->and(cjt_properties($this->posthogClient, 'charter.started'))->toBe(['campaign_id' => (string) $campaign->id, 'mode' => 'quickstart'])
            ->and(cjt_properties($this->posthogClient, 'charter.published'))->toHaveKey('version', 1)
            ->and(cjt_properties($this->posthogClient, 'charter.amended'))->toMatchArray([
                'campaign_id' => (string) $campaign->id,
                'version' => 2,
                'is_material' => true,
            ]);
    });

    test('captures template_saved through the real template service', function () {
        $gm = $this->createSubscribedGm();

        [, $charter] = cjt_published_charter($gm);
        $this->posthogClient->capturedCalls = [];

        CharterTemplateService::saveFromCharter($gm, $charter, 'Heist template');

        expect(cjt_captured_events($this->posthogClient))->toContain('charter.template_saved')
            ->and(cjt_properties($this->posthogClient, 'charter.template_saved'))->toHaveKey('campaign_id');
    });

    test('captures countersigned once the roster is fully signed', function () {
        $gm = $this->createSubscribedGm();

        [$campaign, $charter] = cjt_published_charter($gm);

        $participant = CampaignParticipant::factory()->create([
            'campaign_id' => $campaign->id,
        ]);

        $v1 = SessionZeroSurveyVersion::query()
            ->where('session_zero_survey_id', $charter->id)
            ->where('version_number', 1)
            ->firstOrFail();

        SessionZeroConfirmation::factory()
            ->onVersion($v1)
            ->create(['user_id' => $participant->user_id]);

        $this->posthogClient->capturedCalls = [];

        CharterSigningService::countersign($gm, $charter);

        expect(cjt_captured_events($this->posthogClient))->toContain('charter.countersigned')
            ->and(cjt_properties($this->posthogClient, 'charter.countersigned'))->toMatchArray([
                'campaign_id' => (string) $campaign->id,
                'version' => 1,
            ]);
    });
});

describe('CharterJournalFunnelTelemetry — upsell impressions', function () {
    test('audit export captures audit_exported for a member, upsell_shown for a free owner', function () {
        $member = $this->createSubscribedGm();

        [$memberCampaign] = cjt_published_charter($member);

        $this->posthogClient->capturedCalls = [];

        Livewire::actingAs($member)
            ->test(CharterAudit::class, ['id' => $memberCampaign->id])
            ->assertOk();

        expect(cjt_captured_events($this->posthogClient))->toContain('charter.audit_exported')
            ->and(cjt_properties($this->posthogClient, 'charter.audit_exported'))->toMatchArray([
                'campaign_id' => (string) $memberCampaign->id,
                'version' => 1,
            ]);

        // Free owner: no role/membership — the panel is the upsell surface.
        $free = User::factory()->create(['email_verified_at' => now(), 'profile_complete' => true]);

        [$freeCampaign] = cjt_published_charter($free);

        $this->posthogClient->capturedCalls = [];

        Livewire::actingAs($free)
            ->test(CharterAudit::class, ['id' => $freeCampaign->id])
            ->assertOk();

        expect(cjt_captured_events($this->posthogClient))->toContain('charter.upsell_shown')
            ->and(cjt_properties($this->posthogClient, 'charter.upsell_shown'))->toBe(['surface' => 'audit_export']);
    });

    test('composer start screen captures upsell_shown at the free-tier cap', function () {
        $free = User::factory()->create(['email_verified_at' => now(), 'profile_complete' => true]);
        Role::firstOrCreate(['name' => 'Game Master', 'guard_name' => 'web', 'team_id' => null]);
        $free->assignRole('Game Master');

        // Two active charters = at the R084 cap; the third campaign's
        // composer start screen is the upsell surface.
        cjt_published_charter($free);
        cjt_published_charter($free);
        $third = Campaign::factory()->create(['owner_id' => $free->id]);

        $this->posthogClient->capturedCalls = [];

        Livewire::actingAs($free)
            ->test(CharterComposer::class, ['id' => $third->id])
            ->assertOk();

        expect(cjt_captured_events($this->posthogClient))->toContain('charter.upsell_shown')
            ->and(cjt_properties($this->posthogClient, 'charter.upsell_shown'))->toBe(['surface' => 'charter_limit']);
    });

    test('previously on panel captures upsell_shown at the member gate for a non-member owner', function () {
        $free = User::factory()->create(['email_verified_at' => now(), 'profile_complete' => true]);
        $campaign = Campaign::factory()->create(['owner_id' => $free->id]);

        $this->posthogClient->capturedCalls = [];

        Livewire::actingAs($free)
            ->test(CampaignPreviouslyOn::class, ['campaign' => $campaign])
            ->assertOk();

        expect(cjt_captured_events($this->posthogClient))->toContain('previously_on.upsell_shown')
            ->and(cjt_properties($this->posthogClient, 'previously_on.upsell_shown'))->toBe(['surface' => 'member_gate']);
    });
});

describe('CharterJournalFunnelTelemetry — journal adoption', function () {
    test('captures journal.entry_written for an in-character entry', function () {
        $gm = $this->createSubscribedGm();

        [$campaign] = cjt_published_charter($gm);

        $author = CampaignParticipant::factory()->create([
            'campaign_id' => $campaign->id,
        ]);

        $this->posthogClient->capturedCalls = [];

        app(CampaignJournalService::class)->writeInCharacter($campaign, $author, 'We opened the vault door at last.');

        expect(cjt_captured_events($this->posthogClient))->toContain('journal.entry_written')
            ->and(cjt_properties($this->posthogClient, 'journal.entry_written'))->toMatchArray([
                'campaign_id' => (string) $campaign->id,
                'kind' => 'in_character',
            ]);
    });
});
