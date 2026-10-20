<?php

use App\Dto\CharterContent;
use App\Models\Campaign;
use App\Models\SessionZeroConfirmation;
use App\Models\SessionZeroSurvey;
use App\Models\SessionZeroSurveyVersion;
use App\Models\User;
use App\Services\Charter\CharterService;
use Illuminate\Support\Str;
use Tests\Traits\CreatesUsers;

uses(CreatesUsers::class);

// M070/S04-T04 — charter audit export: the member-only, print-ready
// rendering of the immutable version + signature history (R085). The
// export IS the audit trail — it must never contain §2 private boundary
// answers (concept §2). Asserts strings through __() lookups (K004).

/**
 * A fresh campaign owned by $owner with deterministic quick-start inputs.
 */
function charter_audit_campaign(User $owner): Campaign
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
 * Fully publishable schema-v1 content: quick-start defaults plus the one
 * authoring choice quick-start deliberately leaves empty.
 *
 * @return array<string, mixed>
 */
function charter_audit_valid_content(Campaign $campaign): array
{
    $content = CharterContent::quickStart($campaign)->toArray();
    $content['overview']['expected_shape'] = 'ongoing';

    return $content;
}

/**
 * Publish v1 on a fresh campaign owned by $owner through the real
 * lifecycle, returning the charter and campaign.
 *
 * @return array{0: Campaign, 1: SessionZeroSurvey}
 */
function charter_audit_published(User $owner): array
{
    $campaign = charter_audit_campaign($owner);
    $charter = CharterService::start($owner, $campaign, 'quickstart');
    CharterService::publish($owner, $charter, 'The Threshold Heist Charter', charter_audit_valid_content($campaign));

    /** @var SessionZeroSurvey $charter */
    $charter = $charter->refresh();

    return [$campaign, $charter];
}

/**
 * The full audit fixture through the real lifecycle: v1 published and
 * signed (participant + GM countersignature, §2 private answers with
 * distinctive texts), then a MATERIAL amendment to v2 — which flips the
 * v1 rows to pending re-agreement in place (CharterService::resetAgreement)
 * — and a fresh participant signature on v2. $secondParticipant is
 * created with an empty display name so the email fallback renders.
 *
 * @return array{0: Campaign, 1: SessionZeroSurvey, 2: User, 3: User}
 */
function charter_audit_signed_history(User $owner): array
{
    [$campaign, $charter] = charter_audit_published($owner);

    $participant = User::factory()->create([
        'email_verified_at' => now(),
        'profile_complete' => true,
        'name' => 'Rosa Anders',
    ]);
    $emailOnly = User::factory()->create([
        'email_verified_at' => now(),
        'profile_complete' => true,
        'name' => '',
        'email' => 'email-only-signer@example.com',
    ]);

    $v1 = SessionZeroSurveyVersion::query()
        ->where('session_zero_survey_id', $charter->id)
        ->where('version_number', 1)
        ->firstOrFail();

    SessionZeroConfirmation::factory()
        ->onVersion($v1)
        ->create([
            'user_id' => $participant->id,
            'private_answers' => [
                'my_lines_text' => 'AUDIT-PRIVATE-LINES-NEVER-RENDER',
                'note_to_gm' => 'AUDIT-PRIVATE-NOTE-NEVER-RENDER',
            ],
        ]);
    SessionZeroConfirmation::factory()
        ->gm()
        ->onVersion($v1)
        ->create(['user_id' => $owner->id]);

    $amended = charter_audit_valid_content($campaign);
    $amended['safety']['revisit'] = 'on_request'; // §3 change — material
    CharterService::amend($owner, $charter, 'The Threshold Heist Charter', $amended, 'We check in on boundaries on request now.');

    $charter = $charter->refresh();
    $v2 = SessionZeroSurveyVersion::query()
        ->where('session_zero_survey_id', $charter->id)
        ->where('version_number', 2)
        ->firstOrFail();

    SessionZeroConfirmation::factory()
        ->onVersion($v2)
        ->create([
            'user_id' => $emailOnly->id,
            'private_answers' => [
                'my_veils_text' => 'AUDIT-PRIVATE-VEILS-NEVER-RENDER',
            ],
        ]);

    return [$campaign, $charter->refresh(), $participant, $emailOnly];
}

// ── Access Control ──────────────────────────────────────

describe('CharterAudit Access Control', function () {
    it('redirects unauthenticated users to login', function () {
        $owner = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);
        [$campaign] = charter_audit_published($owner);

        $this->get(route('campaigns.charter.audit', ['locale' => 'en', 'id' => $campaign->id]))
            ->assertRedirect(route('login', 'en'));
    });

    it('shows 404 for unknown campaign ids', function () {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);

        $this->actingAs($user)
            ->get(route('campaigns.charter.audit', ['locale' => 'en', 'id' => (string) Str::uuid()]))
            ->assertNotFound();
    });

    it('forbids non-owners even when authenticated', function () {
        $owner = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);
        [$campaign] = charter_audit_published($owner);

        $other = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);

        expect($other->id)->not->toBe($owner->id);

        $this->actingAs($other)
            ->get(route('campaigns.charter.audit', ['locale' => 'en', 'id' => $campaign->id]))
            ->assertForbidden();
    });

    it('shows 404 for a campaign without a charter', function () {
        $owner = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);
        $campaign = charter_audit_campaign($owner);

        $this->actingAs($owner)
            ->get(route('campaigns.charter.audit', ['locale' => 'en', 'id' => $campaign->id]))
            ->assertNotFound();
    });
});

// ── Member Export (EN + DE) ─────────────────────────────

describe('CharterAudit Member Export', function () {
    it('renders the full version and signature history in English', function () {
        $member = $this->createSubscribedGm();
        [$campaign, $charter, $participant, $emailOnly] = charter_audit_signed_history($member);

        $this->actingAs($member)
            ->get(route('campaigns.charter.audit', ['locale' => 'en', 'id' => $campaign->id]))
            ->assertOk()
            // Page + section chrome
            ->assertSee(__('charter.title_audit_export', [], 'en'))
            ->assertSee(__('charter.heading_version_history', [], 'en'))
            ->assertSee(__('charter.heading_signature_history', [], 'en'))
            ->assertSee(__('charter.action_print_export', [], 'en'))
            // Header: charter title, status, current version
            ->assertSee($charter->title)
            ->assertSee(__('charter.status_active', [], 'en'))
            ->assertSee(__('charter.content_version', ['version' => 2], 'en'))
            // Version history: both versions, the changelog, material chip
            ->assertSee('v1')
            ->assertSee('v2')
            ->assertSee('We check in on boundaries on request now.')
            ->assertSee(__('charter.label_amendment_material_badge', [], 'en'))
            // Signature history: participant name, email fallback, role
            // chips, signed + re-agreement-pending states
            ->assertSee('Rosa Anders')
            ->assertSee('email-only-signer@example.com')
            ->assertSee(__('charter.label_audit_role_participant', [], 'en'))
            ->assertSee(__('charter.label_audit_role_gm', [], 'en'))
            ->assertSee(__('charter.heading_signed_card', [], 'en'))
            ->assertSee(__('charter.label_roster_reagreement_pending', [], 'en'))
            // Privacy contract is stated explicitly
            ->assertSee(__('charter.label_audit_privacy_note', [], 'en'))
            // §2 private boundary answers NEVER render
            ->assertDontSee('AUDIT-PRIVATE-LINES-NEVER-RENDER')
            ->assertDontSee('AUDIT-PRIVATE-NOTE-NEVER-RENDER')
            ->assertDontSee('AUDIT-PRIVATE-VEILS-NEVER-RENDER');
    });

    it('renders the localized audit in German', function () {
        $member = $this->createSubscribedGm();
        [$campaign, $charter] = charter_audit_signed_history($member);

        $this->actingAs($member)
            ->get(route('campaigns.charter.audit', ['locale' => 'de', 'id' => $campaign->id]))
            ->assertOk()
            ->assertSee(__('charter.title_audit_export', [], 'de'))
            ->assertSee(__('charter.heading_version_history', [], 'de'))
            ->assertSee(__('charter.heading_signature_history', [], 'de'))
            ->assertSee(__('charter.action_print_export', [], 'de'))
            ->assertSee(__('charter.label_amendment_material_badge', [], 'de'))
            ->assertSee(__('charter.heading_signed_card', [], 'de'))
            ->assertSee(__('charter.label_roster_reagreement_pending', [], 'de'))
            ->assertSee(__('charter.label_audit_privacy_note', [], 'de'))
            ->assertSee('Rosa Anders')
            ->assertDontSee('AUDIT-PRIVATE-LINES-NEVER-RENDER');
    });
});

// ── Free Tier: Polite Member-Feature Panel ──────────────

describe('CharterAudit Free Tier', function () {
    it('shows the member-feature panel instead of any history to a free owner', function () {
        $owner = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);
        [$campaign, $charter, $participant] = charter_audit_signed_history($owner);

        $this->actingAs($owner)
            ->get(route('campaigns.charter.audit', ['locale' => 'en', 'id' => $campaign->id]))
            ->assertOk()
            ->assertSee(__('charter.title_audit_export', [], 'en'))
            ->assertSee(__('charter.content_upsell_audit', [], 'en'))
            ->assertSee(route('pricing'))
            ->assertSee(route('membership'))
            ->assertDontSee(__('charter.heading_version_history', [], 'en'))
            ->assertDontSee(__('charter.heading_signature_history', [], 'en'))
            ->assertDontSee(__('charter.action_print_export', [], 'en'))
            ->assertDontSee('We check in on boundaries on request now.')
            ->assertDontSee('Rosa Anders')
            ->assertDontSee('AUDIT-PRIVATE-LINES-NEVER-RENDER');
    });

    it('shows the member-feature panel to a free owner with a draft charter too', function () {
        $owner = User::factory()->create([
            'email_verified_at' => now(),
            'profile_complete' => true,
        ]);
        $campaign = charter_audit_campaign($owner);
        CharterService::start($owner, $campaign, 'quickstart');

        $this->actingAs($owner)
            ->get(route('campaigns.charter.audit', ['locale' => 'en', 'id' => $campaign->id]))
            ->assertOk()
            ->assertSee(__('charter.content_upsell_audit', [], 'en'))
            ->assertDontSee(__('charter.heading_version_history', [], 'en'))
            ->assertDontSee(__('charter.label_audit_not_published', [], 'en'));
    });
});

// ── Draft & Archived Lifecycle ──────────────────────────

describe('CharterAudit Lifecycle', function () {
    it('shows the not-published note to a member whose charter is still a draft', function () {
        $member = $this->createSubscribedGm();
        $campaign = charter_audit_campaign($member);
        CharterService::start($member, $campaign, 'quickstart');

        $this->actingAs($member)
            ->get(route('campaigns.charter.audit', ['locale' => 'en', 'id' => $campaign->id]))
            ->assertOk()
            ->assertSee(__('charter.label_audit_not_published', [], 'en'))
            ->assertSee(__('charter.status_draft', [], 'en'))
            ->assertDontSee(__('charter.heading_version_history', [], 'en'))
            ->assertDontSee(__('charter.heading_signature_history', [], 'en'))
            ->assertDontSee(__('charter.action_print_export', [], 'en'));
    });

    it('keeps an archived charter fully exportable — frozen history is the point', function () {
        $member = $this->createSubscribedGm();
        [$campaign, $charter, $participant] = charter_audit_signed_history($member);
        CharterService::archive($member, $charter->refresh());

        $this->actingAs($member)
            ->get(route('campaigns.charter.audit', ['locale' => 'en', 'id' => $campaign->id]))
            ->assertOk()
            ->assertSee(__('charter.status_archived', [], 'en'))
            ->assertSee(__('charter.heading_version_history', [], 'en'))
            ->assertSee(__('charter.heading_signature_history', [], 'en'))
            ->assertSee('We check in on boundaries on request now.')
            ->assertSee('Rosa Anders')
            ->assertSee(__('charter.label_roster_reagreement_pending', [], 'en'))
            ->assertDontSee('AUDIT-PRIVATE-NOTE-NEVER-RENDER');
    });
});
