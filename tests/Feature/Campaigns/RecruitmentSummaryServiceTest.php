<?php

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\GoalStatus;
use App\Models\Campaign;
use App\Models\CampaignJournalEntry;
use App\Models\Game;
use App\Models\SessionZeroSurvey;
use App\Models\User;
use App\Services\Badges\CampaignCompleatEvaluator;
use App\Services\CampaignRecruitmentSummaryService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

// M071/S05-T02 — CampaignRecruitmentSummaryService + the Campaign D222
// helpers: the recruitment summary's durable storage (three dedicated
// nullable columns, never the description), the default-safe auto-draft
// (sessions count + charter pitch/tone/themes ONLY), the guarded apply/
// remove write path (journal-untouched invariant), and staleness.

beforeEach(function () {
    app()->setLocale('en');
});

/**
 * An owner + campaign pair (canonical fixture).
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: User, 1: Campaign}
 */
function recruitmentCampaign(array $attributes = []): array
{
    ['owner' => $owner, 'campaign' => $campaign] = test()->createCampaignWithOwner($attributes);

    return [$owner, $campaign];
}

/**
 * One completed session on the campaign (session zero counts the same).
 */
function recruitmentCompletedSession(Campaign $campaign, User $owner): Game
{
    return Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => GameStatus::Completed->value,
    ]);
}

/**
 * The spec §4.1 spoiler-safety checklist, all confirmed.
 *
 * @return array<string, bool>
 */
function recruitmentChecklist(): array
{
    return [
        'review_excerpts' => true,
        'names_removed' => true,
        'visitor_preview' => true,
    ];
}

/**
 * A kind=charter SessionZeroSurvey row on the campaign (M070 factory
 * convention) carrying the given overview fields.
 *
 * @param  array<string, mixed>  $overview
 */
function recruitmentCharter(Campaign $campaign, array $overview): SessionZeroSurvey
{
    return SessionZeroSurvey::factory()->charter()->create([
        'campaign_id' => $campaign->id,
        'content' => ['overview' => $overview],
    ]);
}

// ── draft(): default-safe assembly ────────────────────

test('draft leads with the sessions-played line and includes the charter pitch', function () {
    [$owner, $campaign] = recruitmentCampaign();

    for ($i = 0; $i < 3; $i++) {
        recruitmentCompletedSession($campaign, $owner);
    }

    recruitmentCharter($campaign, [
        'pitch' => 'A noir campaign of shifting loyalties',
        'themes' => ['intrigue', 'horror'],
        'tone_gritty_heroic' => 4,
    ]);

    $draft = app(CampaignRecruitmentSummaryService::class)->draft($campaign);

    expect($draft)->toContain(__('journal.content_recruitment_sessions_played', ['count' => 3], 'en'))
        ->toContain('A noir campaign of shifting loyalties')
        // Tone/theme chips reuse the charter.* vocabulary (never duplicated).
        ->toContain(__('journal.content_recruitment_tone_themes', [
            'themes' => __('charter.field_overview_tone_gritty_heroic_high', [], 'en').', '
                .__('charter.label_theme_intrigue', [], 'en').', '
                .__('charter.label_theme_horror', [], 'en'),
        ], 'en'));
});

test('draft resolves under the campaign language, not the acting UI locale', function () {
    [, $campaign] = recruitmentCampaign(['language' => 'de']);
    recruitmentCompletedSession($campaign, User::factory()->create());

    // An EN-UI GM previewing a DE campaign: the seeded draft is German —
    // the PreviouslyOn/Epilogue contract (public copy is table-language-stable).
    app()->setLocale('en');

    $draft = app(CampaignRecruitmentSummaryService::class)->draft($campaign);

    expect($draft)->toContain(__('journal.content_recruitment_sessions_played', ['count' => 1], 'de'))
        ->and(app()->getLocale())->toBe('en'); // ambient locale restored
});

test('draft is only the sessions-played line when no charter exists', function () {
    [$owner, $campaign] = recruitmentCampaign();
    recruitmentCompletedSession($campaign, $owner);

    $draft = app(CampaignRecruitmentSummaryService::class)->draft($campaign->fresh());

    expect($draft)->toBe(__('journal.content_recruitment_sessions_played', ['count' => 1], 'en'));
});

test('draft clips the charter pitch to a teaser', function () {
    [, $campaign] = recruitmentCampaign();

    recruitmentCharter($campaign, [
        'pitch' => str_repeat('a', 550).'SENTINELTAIL',
        'themes' => [],
    ]);

    $draft = app(CampaignRecruitmentSummaryService::class)->draft($campaign->fresh());

    // Str::limit keeps 500 chars + the default ellipsis — never the tail.
    expect($draft)->toContain(str_repeat('a', 500))
        ->not->toContain(str_repeat('a', 550))
        ->not->toContain('SENTINELTAIL');
});

test('draft never includes goal titles or recap bodies even when both exist', function () {
    [$owner, $campaign] = recruitmentCampaign();
    $session = recruitmentCompletedSession($campaign, $owner);

    CampaignJournalEntry::factory()->forCampaignSession($session)->create([
        'body' => 'The duchess heir was the traitor all along.',
    ]);

    $campaign->goals()->create([
        'title' => 'Rescue the duchess heir',
        'status' => GoalStatus::Active,
        'sort' => 1,
    ]);

    recruitmentCharter($campaign, ['pitch' => 'Safe pitch', 'themes' => ['mystery']]);

    $draft = app(CampaignRecruitmentSummaryService::class)->draft($campaign->fresh());

    expect($draft)->toContain('Safe pitch')
        ->not->toContain('Rescue the duchess heir')
        ->not->toContain('The duchess heir was the traitor all along.');
});

// ── apply(): guarded write path ───────────────────────

test('apply writes all three columns with a fresh session stamp and never touches journal or description', function () {
    [$owner, $campaign] = recruitmentCampaign();

    $sessions = [];
    for ($i = 0; $i < 4; $i++) {
        $sessions[] = recruitmentCompletedSession($campaign, $owner);
    }

    CampaignJournalEntry::factory()->forCampaignSession($sessions[0])->create();

    $journalBefore = CampaignJournalEntry::query()->where('campaign_id', $campaign->id)->get();
    $descriptionBefore = $campaign->description;
    // timestamp(0) truncates sub-second precision, so the stored stamp
    // can sit a fraction before a microsecond-precise now() — floor the
    // reference to whole seconds.
    $stamp = now()->startOfSecond();

    app(CampaignRecruitmentSummaryService::class)
        ->apply($campaign, $owner, 'Join our long-running intrigue table.', recruitmentChecklist());

    $fresh = $campaign->fresh();

    expect($fresh->recruitment_summary)->toBe('Join our long-running intrigue table.')
        ->and($fresh->recruitment_summary_completed_sessions)->toBe(4)
        ->and($fresh->recruitment_summary_refreshed_at)->not->toBeNull()
        ->and($fresh->recruitment_summary_refreshed_at->greaterThanOrEqualTo($stamp))->toBeTrue()
        // The journal-untouched invariant (R082): byte-identical rows.
        ->and(CampaignJournalEntry::query()->where('campaign_id', $campaign->id)->count())->toBe($journalBefore->count())
        ->and(CampaignJournalEntry::query()->where('campaign_id', $campaign->id)->pluck('body')->all())
        ->toBe($journalBefore->pluck('body')->all())
        // The block is its own column — the description never changes.
        ->and($fresh->description)->toBe($descriptionBefore);
});

test('apply rejects a non-owner with zero writes', function () {
    [, $campaign] = recruitmentCampaign();
    $stranger = User::factory()->create();

    try {
        app(CampaignRecruitmentSummaryService::class)
            ->apply($campaign, $stranger, 'Not my table.', recruitmentChecklist());
        $this->fail('Expected LogicException for a non-owner.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('journal.error_recruitment_unauthorized', [], 'en'));
    }

    $this->assertDatabaseHas('campaigns', [
        'id' => $campaign->id,
        'recruitment_summary' => null,
        'recruitment_summary_completed_sessions' => null,
        'recruitment_summary_refreshed_at' => null,
    ]);
});

test('a global admin passes the same policy the composer gate resolves — the service never refuses the offered affordance', function () {
    [$owner, $campaign] = recruitmentCampaign();
    recruitmentCompletedSession($campaign, $owner);

    $admin = User::factory()->create();
    Role::firstOrCreate(['name' => 'Platform Admin', 'guard_name' => 'web', 'team_id' => null]);
    setPermissionsTeamId(null);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $admin->assignRole('Platform Admin');
    $admin->unsetRelations();

    // CampaignPolicy::before() lets global admins through every ability,
    // and manageRecruitmentSummary is the ability the composer's manage
    // affordances resolve — the service guard must resolve the SAME
    // rule (regression: it used to hand-roll owner_id ===, refusing an
    // actor the UI had just offered the composer to).
    expect($admin->can('manageRecruitmentSummary', $campaign))->toBeTrue();

    app(CampaignRecruitmentSummaryService::class)
        ->apply($campaign, $admin, 'The admin approved this block.', recruitmentChecklist());

    $this->assertDatabaseHas('campaigns', [
        'id' => $campaign->id,
        'recruitment_summary' => 'The admin approved this block.',
    ]);
});

test('apply rejects an unconfirmed spoiler-safety checklist with zero writes', function () {
    [$owner, $campaign] = recruitmentCampaign();

    $checklist = recruitmentChecklist();
    $checklist['names_removed'] = false;

    try {
        app(CampaignRecruitmentSummaryService::class)
            ->apply($campaign, $owner, 'Summary.', $checklist);
        $this->fail('Expected LogicException for an incomplete checklist.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('journal.error_recruitment_checklist_incomplete', [], 'en'));
    }

    $this->assertDatabaseHas('campaigns', [
        'id' => $campaign->id,
        'recruitment_summary' => null,
        'recruitment_summary_completed_sessions' => null,
        'recruitment_summary_refreshed_at' => null,
    ]);
});

test('apply rejects an empty draft with zero writes', function () {
    [$owner, $campaign] = recruitmentCampaign();

    try {
        app(CampaignRecruitmentSummaryService::class)
            ->apply($campaign, $owner, "   \n\t ", recruitmentChecklist());
        $this->fail('Expected LogicException for an empty draft.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('journal.error_recruitment_draft_required', [], 'en'));
    }

    $this->assertDatabaseHas('campaigns', [
        'id' => $campaign->id,
        'recruitment_summary' => null,
        'recruitment_summary_completed_sessions' => null,
        'recruitment_summary_refreshed_at' => null,
    ]);
});

test('apply rejects a draft over 10000 characters with zero writes', function () {
    [$owner, $campaign] = recruitmentCampaign();

    try {
        app(CampaignRecruitmentSummaryService::class)
            ->apply($campaign, $owner, str_repeat('a', 10001), recruitmentChecklist());
        $this->fail('Expected LogicException for an over-long draft.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('journal.error_recruitment_draft_too_long', [], 'en'));
    }

    $this->assertDatabaseHas('campaigns', [
        'id' => $campaign->id,
        'recruitment_summary' => null,
        'recruitment_summary_completed_sessions' => null,
        'recruitment_summary_refreshed_at' => null,
    ]);
});

// ── remove(): full reversibility ──────────────────────

test('remove nulls all three columns, leaves the journal untouched, and rejects non-owners', function () {
    [$owner, $campaign] = recruitmentCampaign();
    $session = recruitmentCompletedSession($campaign, $owner);

    CampaignJournalEntry::factory()->forCampaignSession($session)->create();

    $service = app(CampaignRecruitmentSummaryService::class);
    $service->apply($campaign, $owner, 'Published block.', recruitmentChecklist());

    $journalBefore = CampaignJournalEntry::query()->where('campaign_id', $campaign->id)->get();

    $stranger = User::factory()->create();

    try {
        $service->remove($campaign->fresh(), $stranger);
        $this->fail('Expected LogicException for a non-owner.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('journal.error_recruitment_unauthorized', [], 'en'));
    }

    $service->remove($campaign->fresh(), $owner);

    $fresh = $campaign->fresh();

    expect($fresh->recruitment_summary)->toBeNull()
        ->and($fresh->recruitment_summary_completed_sessions)->toBeNull()
        ->and($fresh->recruitment_summary_refreshed_at)->toBeNull()
        ->and(CampaignJournalEntry::query()->where('campaign_id', $campaign->id)->count())->toBe($journalBefore->count())
        ->and(CampaignJournalEntry::query()->where('campaign_id', $campaign->id)->pluck('body')->all())
        ->toBe($journalBefore->pluck('body')->all());
});

// ── staleness (spec §4.1: nudge after 3 new sessions) ─

test('staleness counts completed sessions since the apply stamp', function () {
    [$owner, $campaign] = recruitmentCampaign();
    $service = app(CampaignRecruitmentSummaryService::class);

    // No published block: never stale.
    expect($campaign->recruitmentSummaryStaleSessions())->toBe(0)
        ->and($service->isStale($campaign))->toBeFalse();

    for ($i = 0; $i < 6; $i++) {
        recruitmentCompletedSession($campaign, $owner);
    }

    $service->apply($campaign->fresh(), $owner, 'Stamped at six.', recruitmentChecklist());

    // Two more completed sessions: 2 stale — below the threshold.
    for ($i = 0; $i < 2; $i++) {
        recruitmentCompletedSession($campaign, $owner);
    }

    expect($campaign->fresh()->recruitmentSummaryStaleSessions())->toBe(2)
        ->and($service->isStale($campaign->fresh()))->toBeFalse();

    // The third new session crosses it.
    recruitmentCompletedSession($campaign, $owner);

    expect($campaign->fresh()->recruitmentSummaryStaleSessions())->toBe(3)
        ->and($service->isStale($campaign->fresh()))->toBeTrue();
});

// ── D222 cross-consistency with CampaignCompleatEvaluator ──

test('the session and coverage helpers agree with CampaignCompleatEvaluator on D222', function () {
    [$owner, $campaign] = recruitmentCampaign([
        'status' => CampaignStatus::Completed->value,
    ]);

    for ($i = 0; $i < 6; $i++) {
        $session = recruitmentCompletedSession($campaign, $owner);

        if ($i < 5) {
            CampaignJournalEntry::factory()->forCampaignSession($session)->create();
        }
    }

    $fresh = $campaign->fresh();

    expect($fresh->completedSessionsCount())->toBe(6)
        ->and($fresh->recapCoveredSessionsCount())->toBe(5);

    $award = app(CampaignCompleatEvaluator::class)->evaluate($owner);

    expect($award)->not->toBeNull()
        ->and($award->badgeKey)->toBe('campaign_compleat')
        ->and($award->tierKey)->toBe('bronze')
        ->and($award->evidenceId)->toBe($campaign->id);
});
