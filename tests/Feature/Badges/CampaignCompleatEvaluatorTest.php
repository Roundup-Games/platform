<?php

use App\Enums\BadgeEventAction;
use App\Enums\BadgeSource;
use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\GameType;
use App\Enums\JournalEntryKind;
use App\Models\BadgeDefinition;
use App\Models\BadgeEvent;
use App\Models\BadgeUser;
use App\Models\Campaign;
use App\Models\CampaignJournalEntry;
use App\Models\Game;
use App\Models\User;
use App\Services\Badges\BadgeRegistry;
use App\Services\Badges\BadgeService;
use App\Services\Badges\CampaignCompleatEvaluator;

/**
 * CampaignCompleatEvaluator (M071/S05-T01, spec §3 GM Craft row,
 * D222): a campaign counts when it is completed, holds >=6 completed
 * sessions (session zero included — a completed Game linked via
 * campaign_id is a real table sitting) and >=80% of those sessions
 * carry a game_id-linked kind=recap journal entry on a completed
 * Game. Integer-safe coverage: covered * 5 >= completed * 4. Tiers at
 * 1/3/5 qualifying completions across campaigns the user owns; any
 * campaign type qualifies (D218 — no game_type filter); the
 * games.recap projection column is never read (D220). Milestone mode
 * never lapses; sweep converges idempotently.
 *
 * Helpers are top-level with a compleat- prefix: sibling test files
 * share the global namespace (BadgesSyncTest's convention).
 */

/**
 * A completed campaign owned by $owner.
 *
 * @param  array<string, mixed>  $attributes
 */
function compleatCampaign(User $owner, array $attributes = []): Campaign
{
    return Campaign::factory()->create(array_merge([
        'owner_id' => $owner->id,
        'status' => CampaignStatus::Completed->value,
    ], $attributes));
}

/**
 * One completed session on the campaign.
 *
 * @param  array<string, mixed>  $attributes
 */
function compleatSession(Campaign $campaign, array $attributes = []): Game
{
    return Game::factory()->create(array_merge([
        'campaign_id' => $campaign->id,
        'owner_id' => $campaign->owner_id,
        'status' => GameStatus::Completed->value,
    ], $attributes));
}

/**
 * A kind=recap journal entry on the session — the D222 coverage fact.
 *
 * @param  array<string, mixed>  $attributes
 */
function compleatRecap(Game $game, array $attributes = []): CampaignJournalEntry
{
    return CampaignJournalEntry::factory()
        ->forCampaignSession($game)
        ->create($attributes);
}

/**
 * A completed campaign for $owner with $completed completed sessions,
 * the first $covered carrying recaps. Defaults are the bronze
 * boundary: six completed, all covered.
 *
 * @param  array<string, mixed>  $attributes
 */
function compleatQualifyingCampaign(User $owner, int $completed = 6, int $covered = 6, array $attributes = []): Campaign
{
    $campaign = compleatCampaign($owner, $attributes);

    for ($i = 0; $i < $completed; $i++) {
        $session = compleatSession($campaign);

        if ($i < $covered) {
            compleatRecap($session);
        }
    }

    return $campaign;
}

/**
 * The owner's campaign_compleat hold row, or null when not held.
 */
function compleatHold(User $owner): ?BadgeUser
{
    return BadgeUser::query()
        ->where('user_id', $owner->id)
        ->whereHas('badgeDefinition', fn ($query) => $query->where('key', 'campaign_compleat'))
        ->first();
}

/**
 * Audit rows on campaign_compleat holds (any action).
 */
function compleatAuditCount(): int
{
    return BadgeEvent::query()
        ->whereHas('badgeUser.badgeDefinition', fn ($query) => $query->where('key', 'campaign_compleat'))
        ->count();
}

beforeEach(function () {
    $this->artisan('badges:sync')->assertSuccessful();
});

it('pins the registry contract: live, gm-eligible, tiers at 1/3/5', function () {
    $definition = app(BadgeRegistry::class)->find('campaign_compleat');

    // The mirrored runtime row carries the activation too, with the
    // jsonb-disordered tier keys normalized (Postgres sorts keys).
    $mirrored = BadgeDefinition::query()->where('key', 'campaign_compleat')->first();
    $mirroredTiers = $mirrored->tiers;
    ksort($mirroredTiers);

    expect($definition->evaluator)->toBe(CampaignCompleatEvaluator::class)
        ->and($definition->isDormant)->toBeFalse()
        ->and($definition->tiers)->toBe(['bronze' => 1, 'silver' => 3, 'gold' => 5])
        ->and($mirrored->is_dormant)->toBeFalse()
        ->and($mirroredTiers)->toBe(['bronze' => 1, 'gold' => 5, 'silver' => 3]);
});

it('proposes nothing below the six-session floor — even at 100% coverage', function () {
    $owner = User::factory()->create();

    // Five completed sessions, every one covered: coverage passes but
    // the session floor does not.
    compleatQualifyingCampaign($owner, completed: 5, covered: 5);

    expect(app(CampaignCompleatEvaluator::class)->evaluate($owner))->toBeNull();
});

it('proposes bronze at exactly one qualifying campaign', function () {
    $owner = User::factory()->create();
    $campaign = compleatQualifyingCampaign($owner);

    $award = app(CampaignCompleatEvaluator::class)->evaluate($owner);

    // Six completed, all covered: bronze at the boundary; the evidence
    // pointer is the qualifying campaign itself, as the 'campaign'
    // morph alias (uuid key — fits badge_user.evidence_id).
    expect($award)->not->toBeNull()
        ->and($award->badgeKey)->toBe('campaign_compleat')
        ->and($award->tierKey)->toBe('bronze')
        ->and($award->evidenceType)->toBe('campaign')
        ->and($award->evidenceId)->toBe($campaign->id);
});

it('tiers silver at three and gold at five distinct qualifying campaigns', function () {
    $owner = User::factory()->create();
    $evaluator = app(CampaignCompleatEvaluator::class);

    compleatQualifyingCampaign($owner);
    compleatQualifyingCampaign($owner);

    expect($evaluator->evaluate($owner)?->tierKey)->toBe('bronze');

    compleatQualifyingCampaign($owner);

    expect($evaluator->evaluate($owner)?->tierKey)->toBe('silver');

    compleatQualifyingCampaign($owner);
    compleatQualifyingCampaign($owner);

    expect($evaluator->evaluate($owner)?->tierKey)->toBe('gold');
});

it('draws the 80% coverage line exactly', function (int $completed, int $covered, ?string $expectedTier) {
    $owner = User::factory()->create();

    compleatQualifyingCampaign($owner, completed: $completed, covered: $covered);

    expect(app(CampaignCompleatEvaluator::class)->evaluate($owner)?->tierKey)->toBe($expectedTier);
})->with([
    'six sessions need five covered' => [6, 5, 'bronze'],
    'six sessions with four covered do not qualify' => [6, 4, null],
    'ten sessions need exactly eight' => [10, 8, 'bronze'],
    'ten sessions with seven do not qualify' => [10, 7, null],
]);

it('counts session zero toward the floor and its recap toward coverage', function () {
    $owner = User::factory()->create();
    $campaign = compleatCampaign($owner);

    // Session zero, styled as the earliest sitting (the table settling
    // in before session one): a completed Game linked via campaign_id —
    // a real table sitting, so D222 counts it.
    $sessionZero = compleatSession($campaign, [
        'name' => ['en' => 'Session Zero — the table settles in'],
        'date_time' => now()->subDays(60),
    ]);
    compleatRecap($sessionZero);

    // Five more completed sessions, four covered — five covered in
    // total INCLUDING session zero's recap. The award proves both
    // inclusions: drop session zero from the floor and 5 < 6 fails;
    // drop its recap from coverage and 4 * 5 < 6 * 4 fails.
    for ($i = 0; $i < 5; $i++) {
        $session = compleatSession($campaign);

        if ($i < 4) {
            compleatRecap($session);
        }
    }

    expect(app(CampaignCompleatEvaluator::class)->evaluate($owner)?->tierKey)->toBe('bronze');
});

it('never qualifies an active campaign — completion status is required', function () {
    $owner = User::factory()->create();

    $campaign = compleatCampaign($owner, ['status' => CampaignStatus::Active->value]);

    for ($i = 0; $i < 6; $i++) {
        compleatRecap(compleatSession($campaign));
    }

    expect(app(CampaignCompleatEvaluator::class)->evaluate($owner))->toBeNull();
});

it('never counts in-character or prep-note entries toward coverage', function () {
    $owner = User::factory()->create();
    $campaign = compleatCampaign($owner);

    // Six completed sessions, every one carrying journal entries of the
    // wrong kinds — none is the recap coverage fact.
    $kinds = [JournalEntryKind::InCharacter, JournalEntryKind::PrepNote];

    for ($i = 0; $i < 6; $i++) {
        $session = compleatSession($campaign);

        foreach ($kinds as $kind) {
            CampaignJournalEntry::factory()
                ->forCampaignSession($session)
                ->create(['kind' => $kind]);
        }
    }

    expect(app(CampaignCompleatEvaluator::class)->evaluate($owner))->toBeNull();
});

it('never counts a recap on a scheduled session', function () {
    $owner = User::factory()->create();
    $campaign = compleatCampaign($owner);

    // Six completed sessions with four covered (below the line), plus a
    // seventh SCHEDULED session carrying a recap — if that recap
    // counted, coverage would reach 5/6 and qualify. It never does.
    for ($i = 0; $i < 6; $i++) {
        $session = compleatSession($campaign);

        if ($i < 4) {
            compleatRecap($session);
        }
    }

    compleatRecap(compleatSession($campaign, ['status' => GameStatus::Scheduled->value]));

    expect(app(CampaignCompleatEvaluator::class)->evaluate($owner))->toBeNull();
});

it('never counts an interlude recap — game_id null never covers a session', function () {
    $owner = User::factory()->create();
    $campaign = compleatCampaign($owner);

    for ($i = 0; $i < 6; $i++) {
        $session = compleatSession($campaign);

        if ($i < 4) {
            compleatRecap($session);
        }
    }

    // An interlude recap on the campaign (game_id null): below the line
    // stays below the line.
    CampaignJournalEntry::factory()->create([
        'campaign_id' => $campaign->id,
        'author_user_id' => $owner->id,
        'kind' => JournalEntryKind::Recap,
    ]);

    expect(app(CampaignCompleatEvaluator::class)->evaluate($owner))->toBeNull();
});

it('never reads the games.recap projection column (D220)', function () {
    $owner = User::factory()->create();
    $campaign = compleatCampaign($owner);

    // Six completed sessions, each carrying legacy projection text on
    // the games.recap column but NO journal entry: the journal is the
    // source of truth, and coverage stays zero.
    for ($i = 0; $i < 6; $i++) {
        compleatSession($campaign, ['recap' => 'Legacy text recap from before the journal existed.']);
    }

    expect(Game::query()->whereNotNull('recap')->count())->toBe(6)
        ->and(CampaignJournalEntry::query()->count())->toBe(0)
        ->and(app(CampaignCompleatEvaluator::class)->evaluate($owner))->toBeNull();
});

it("never counts recaps authored on someone else's campaign for this owner", function () {
    $owner = User::factory()->create();
    $otherGm = User::factory()->create();

    // A fully qualifying campaign owned by the other GM, whose session
    // recaps are authored by THIS user (a guest GM writing journal
    // entries): coverage belongs to the campaign owner, never to the
    // author.
    $campaign = compleatCampaign($otherGm);

    for ($i = 0; $i < 6; $i++) {
        compleatRecap(compleatSession($campaign), ['author_user_id' => $owner->id]);
    }

    expect(app(CampaignCompleatEvaluator::class)->evaluate($owner))->toBeNull()
        ->and(app(CampaignCompleatEvaluator::class)->evaluate($otherGm)?->tierKey)->toBe('bronze');
});

it('awards board-game campaigns identically — no game_type branching (D218)', function () {
    $owner = User::factory()->create();

    compleatQualifyingCampaign($owner, attributes: ['game_type' => GameType::BoardGame->value]);

    expect(app(CampaignCompleatEvaluator::class)->evaluate($owner)?->tierKey)->toBe('bronze');
});

it('points the evidence at the latest qualifying campaign', function () {
    $owner = User::factory()->create();

    $older = compleatQualifyingCampaign($owner);
    $older->forceFill(['updated_at' => now()->subDays(10)])->saveQuietly();

    $newer = compleatQualifyingCampaign($owner);
    $newer->forceFill(['updated_at' => now()])->saveQuietly();

    $award = app(CampaignCompleatEvaluator::class)->evaluate($owner);

    expect($award?->evidenceId)->toBe($newer->id)
        ->and($award->evidenceId)->not->toBe($older->id);
});

it('is a pure proposal — evaluate() never writes badge state', function () {
    $owner = User::factory()->create();
    compleatQualifyingCampaign($owner);

    app(CampaignCompleatEvaluator::class)->evaluate($owner);

    expect(BadgeUser::query()->count())->toBe(0)
        ->and(BadgeEvent::query()->count())->toBe(0);
});

it('backfills bronze through the nightly sweep exactly once — and parks below-threshold owners', function () {
    $owner = User::factory()->create();
    $laggard = User::factory()->create();

    compleatQualifyingCampaign($owner);
    // Completed but below the coverage line (6 completed, 3 covered):
    // the laggard sits in the cohort via the completed-campaign arm
    // and must park without a single write.
    compleatQualifyingCampaign($laggard, completed: 6, covered: 3);

    $this->artisan('badges:sweep')->assertSuccessful();

    expect(compleatHold($owner)?->tier)->toBe('bronze')
        ->and(compleatHold($laggard))->toBeNull()
        ->and(compleatAuditCount())->toBe(1);

    // The second night converges: no new hold rows, no new audits.
    $this->artisan('badges:sweep')->assertSuccessful();

    expect(compleatHold($owner)?->tier)->toBe('bronze')
        ->and(BadgeUser::query()
            ->where('user_id', $owner->id)
            ->whereHas('badgeDefinition', fn ($query) => $query->where('key', 'campaign_compleat'))
            ->count())->toBe(1)
        ->and(compleatHold($laggard))->toBeNull()
        ->and(compleatAuditCount())->toBe(1);
});

it('milestones never lapse — a holder without qualifying campaigns is untouched', function () {
    $gm = User::factory()->create();
    $admin = User::factory()->create();

    app(BadgeService::class)->award(
        $gm,
        'campaign_compleat',
        'bronze',
        null,
        BadgeSource::Manual,
        $admin,
        'Grandfathered before the evaluator shipped',
    );

    // No campaign at all: the cohort's holder arm pulls the GM in, but
    // a null proposal must park nothing — the manual award's audit row
    // stays the only one, and no lapse is even attempted.
    app(CampaignCompleatEvaluator::class)->sweep();

    $hold = compleatHold($gm);

    expect($hold)->not->toBeNull()
        ->and($hold->tier)->toBe('bronze')
        ->and($hold->lapsed_at)->toBeNull()
        ->and($hold->revoked_at)->toBeNull()
        ->and(compleatAuditCount())->toBe(1)
        ->and(BadgeEvent::query()
            ->where('action', BadgeEventAction::Lapsed)
            ->whereHas('badgeUser.badgeDefinition', fn ($query) => $query->where('key', 'campaign_compleat'))
            ->exists())->toBeFalse();
});
