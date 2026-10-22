<?php

use App\Dto\CharterContent;
use App\Enums\BadgeEventAction;
use App\Enums\BadgeSource;
use App\Models\BadgeDefinition;
use App\Models\BadgeEvent;
use App\Models\BadgeUser;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\SessionZeroConfirmation;
use App\Models\SessionZeroSurvey;
use App\Models\User;
use App\Services\Badges\BadgeRegistry;
use App\Services\Badges\BadgeService;
use App\Services\Badges\CharteredEvaluator;
use App\Services\Charter\CharterService;
use App\Services\Charter\CharterSigningService;
use Tests\Traits\CreatesUsers;

uses(CreatesUsers::class);

/**
 * CharteredEvaluator (M070/S04-T05, spec §3 GM Craft row, D189): a
 * charter counts when it holds >=1 role=gm confirmation row — the
 * "entire table signed (at any version)" fact whose only producers are
 * countersign() (gated on every approved participant holding a signed
 * row on that version) and carrySignatures() (minor amendments). Tiers
 * at 1/10/50 charters across campaigns the user owns; milestone mode
 * never lapses, so a material amendment's pending-re-agreement flip on
 * the gm row (the fact stands at the earlier version) keeps the award.
 *
 * Fixtures run the REAL lifecycle — CharterService::start/publish,
 * CharterSigningService::sign for two approved participants, then
 * countersign — so the gm-row proof chain itself is what qualifies.
 * The free-tier cap (R084: 2 concurrent active charters) only binds
 * non-members, so the ten-charter silver fixture uses a subscribed GM
 * via the CreatesUsers member seam.
 */

/**
 * A fresh campaign owned by $owner with the deterministic quick-start
 * inputs that make CharterContent::quickStart() fully publishable.
 */
function charteredCampaign(User $owner): Campaign
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
 * Publishable schema-v1 content: quick-start defaults plus the one
 * authoring choice quick-start deliberately leaves empty.
 *
 * @return array<string, mixed>
 */
function charteredContent(Campaign $campaign): array
{
    $content = CharterContent::quickStart($campaign)->toArray();
    $content['overview']['expected_shape'] = 'ongoing';

    return $content;
}

/**
 * Two approved participants sign the charter's current version.
 */
function charteredParticipantsSign(SessionZeroSurvey $charter, int $count = 2): void
{
    for ($i = 0; $i < $count; $i++) {
        $participant = User::factory()->create();

        CampaignParticipant::factory()->create([
            'campaign_id' => $charter->campaign_id,
            'user_id' => $participant->id,
        ]);

        CharterSigningService::sign($participant, $charter, [
            'attestations' => array_fill_keys(CharterContent::AGREEMENT_ITEMS, true),
        ]);
    }
}

/**
 * One fully-signed charter for $owner through the real lifecycle:
 * start → publish → two approved participants sign → countersign.
 * Returns the refreshed charter row.
 */
function charteredFullySigned(User $owner): SessionZeroSurvey
{
    $campaign = charteredCampaign($owner);
    $charter = CharterService::start($owner, $campaign);
    CharterService::publish($owner, $charter, 'The Threshold Heist', charteredContent($campaign));

    charteredParticipantsSign($charter);
    CharterSigningService::countersign($owner, $charter);

    return $charter->fresh();
}

/**
 * The owner's chartered hold row, or null when not held.
 */
function charteredHold(User $owner): ?BadgeUser
{
    return BadgeUser::query()
        ->where('user_id', $owner->id)
        ->whereHas('badgeDefinition', fn ($query) => $query->where('key', 'chartered'))
        ->first();
}

beforeEach(function () {
    $this->artisan('badges:sync')->assertSuccessful();
});

it('pins the registry contract: live, gm-eligible, tiers at 1/10/50', function () {
    $definition = app(BadgeRegistry::class)->find('chartered');

    expect($definition->evaluator)->toBe(CharteredEvaluator::class)
        ->and($definition->isDormant)->toBeFalse()
        ->and($definition->tiers)->toBe(['bronze' => 1, 'silver' => 10, 'gold' => 50])
        // The mirrored runtime row carries the activation too.
        ->and(BadgeDefinition::query()->where('key', 'chartered')->value('is_dormant'))->toBeFalse();
});

it('proposes bronze at exactly one fully-signed charter', function () {
    $owner = User::factory()->create();
    $charter = charteredFullySigned($owner);

    $award = app(CharteredEvaluator::class)->evaluate($owner);

    // One charter = bronze at the boundary; the evidence pointer is the
    // gm confirmation row itself, as the 'session_zero_confirmation'
    // morph alias (uuid key — fits badge_user.evidence_id).
    expect($award)->not->toBeNull()
        ->and($award->badgeKey)->toBe('chartered')
        ->and($award->tierKey)->toBe('bronze')
        ->and($award->evidenceType)->toBe('session_zero_confirmation')
        ->and($award->evidenceId)->toBe(
            $charter->confirmations()
                ->where('role', SessionZeroConfirmation::ROLE_GM)
                ->first()->id,
        );
});

it('proposes nothing before the countersign — participant signatures alone never qualify', function () {
    $owner = User::factory()->create();
    $campaign = charteredCampaign($owner);
    $charter = CharterService::start($owner, $campaign);
    CharterService::publish($owner, $charter, 'The Threshold Heist', charteredContent($campaign));

    // Every approved participant has signed — but no gm row exists yet.
    charteredParticipantsSign($charter);

    expect(SessionZeroConfirmation::query()->where('role', SessionZeroConfirmation::ROLE_GM)->exists())->toBeFalse()
        ->and(app(CharteredEvaluator::class)->evaluate($owner))->toBeNull();
});

it("never counts another GM's charters for this owner", function () {
    $owner = User::factory()->create();
    $otherGm = User::factory()->create();

    charteredFullySigned($otherGm);

    expect(app(CharteredEvaluator::class)->evaluate($owner))->toBeNull();

    app(CharteredEvaluator::class)->sweep();

    expect(charteredHold($owner))->toBeNull()
        ->and(charteredHold($otherGm)?->tier)->toBe('bronze');
});

it('is a pure proposal — evaluate() never writes badge state', function () {
    $owner = User::factory()->create();
    charteredFullySigned($owner);

    app(CharteredEvaluator::class)->evaluate($owner);

    expect(BadgeUser::query()->count())->toBe(0)
        ->and(BadgeEvent::query()->count())->toBe(0);
});

it('backfills bronze through the sweep, then upgrades to silver at ten — idempotently', function () {
    // Ten concurrent active charters need the member seam (R084 caps
    // free owners at two) — a subscribed GM signs freely.
    $gm = $this->createSubscribedGm();
    $evaluator = app(CharteredEvaluator::class);

    charteredFullySigned($gm);
    $evaluator->sweep();

    expect(charteredHold($gm)?->tier)->toBe('bronze')
        ->and(BadgeUser::query()->count())->toBe(1)
        ->and(BadgeEvent::query()->where('action', BadgeEventAction::Awarded)->count())->toBe(1);

    for ($i = 1; $i < 10; $i++) {
        charteredFullySigned($gm);
    }

    $evaluator->sweep();

    // Ten charters: silver lands on the same row — one hold, one
    // Awarded plus one Upgraded audit.
    expect(charteredHold($gm)?->tier)->toBe('silver')
        ->and(BadgeUser::query()->count())->toBe(1)
        ->and(BadgeEvent::query()->where('action', BadgeEventAction::Upgraded)->count())->toBe(1);

    // Re-running the night converges: nothing new is written.
    $holds = BadgeUser::query()->count();
    $audits = BadgeEvent::query()->count();

    $evaluator->sweep();

    expect(BadgeUser::query()->count())->toBe($holds)
        ->and(BadgeEvent::query()->count())->toBe($audits);
});

it('milestones never lapse — a holder without charters is untouched', function () {
    $gm = User::factory()->create();
    $admin = User::factory()->create();

    app(BadgeService::class)->award(
        $gm,
        'chartered',
        'bronze',
        null,
        BadgeSource::Manual,
        $admin,
        'Grandfathered before the badge engine shipped',
    );

    // No charter at all: the cohort's holder arm pulls the GM in, but
    // a null proposal must park nothing — the manual award's audit row
    // stays the only one, and no lapse is even attempted.
    app(CharteredEvaluator::class)->sweep();

    $hold = charteredHold($gm);

    expect($hold)->not->toBeNull()
        ->and($hold->tier)->toBe('bronze')
        ->and($hold->lapsed_at)->toBeNull()
        ->and($hold->revoked_at)->toBeNull()
        ->and(BadgeEvent::query()->count())->toBe(1);
});

it('keeps the award through a material amendment — the gm row persists as pending re-agreement', function () {
    $gm = $this->createSubscribedGm();
    $charter = charteredFullySigned($gm);

    app(CharteredEvaluator::class)->sweep();

    expect(charteredHold($gm)?->tier)->toBe('bronze');

    // §4 policies is a material section: the amendment flips every
    // signed row — including the gm countersignature — to pending
    // re-agreement. The fully-signed-at-v1 fact stands ('at any
    // version', D189): the charter keeps counting.
    $content = charteredContent($charter->campaign);
    $content['policies']['pvp'] = 'open';
    CharterService::amend($gm, $charter->fresh(), 'The Threshold Heist', $content, 'Tightened PvP policy');

    $gmRow = $charter->confirmations()
        ->where('role', SessionZeroConfirmation::ROLE_GM)
        ->orderByDesc('confirmed_at')
        ->first();

    expect($gmRow->status)->toBe(SessionZeroConfirmation::STATUS_PENDING_REAGREEMENT);

    $award = app(CharteredEvaluator::class)->evaluate($gm);

    expect($award?->tierKey)->toBe('bronze');

    app(CharteredEvaluator::class)->sweep();

    $hold = charteredHold($gm);

    expect($hold?->tier)->toBe('bronze')
        ->and($hold->lapsed_at)->toBeNull()
        ->and($hold->revoked_at)->toBeNull();
});

it('never counts a gm confirmation row that no participant signed against — a participant-less charter proves nothing', function () {
    // The pre-fix gap, as a regression pin: a gm row alone (no signed
    // participant row on the same version) must not count, so a
    // publish -> countersign -> archive loop on a participant-less
    // campaign cannot farm the badge.
    $owner = User::factory()->create();
    $campaign = charteredCampaign($owner);
    $charter = CharterService::start($owner, $campaign);
    $version = CharterService::publish($owner, $charter, $campaign->getTranslation('name', 'en'), charteredContent($campaign));

    // A gm confirmation row WITHOUT the gate that would have refused it
    // (zero approved participants) — the exact row a stale or misused
    // surface could leave behind.
    $charter->confirmations()->create([
        'session_zero_survey_version_id' => $version->id,
        'user_id' => $owner->id,
        'attestations' => [],
        'private_answers' => null,
        'status' => SessionZeroConfirmation::STATUS_SIGNED,
        'role' => SessionZeroConfirmation::ROLE_GM,
    ]);

    expect(app(CharteredEvaluator::class)->evaluate($owner))->toBeNull();
});
