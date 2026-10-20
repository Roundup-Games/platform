<?php

use App\Enums\GoalStatus;
use App\Models\Campaign;
use App\Models\CampaignGoal;
use App\Models\Game;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

// M071/S02-T01 — campaign_goals: goal board data foundation with enum
// casts, campaign/status-stamp relations, the CHECK constraint backstop
// and the bilingual status labels.

// ── Factory & casts ─────────────────────────────────────

test('factory creates and persists a goal with enum casts', function () {
    $goal = CampaignGoal::factory()->create();

    $fresh = CampaignGoal::find($goal->id);

    expect($fresh)->not->toBeNull()
        ->and($fresh->id)->toBeString()
        ->and($fresh->title)->toBeString()
        ->and(mb_strlen($fresh->title))->toBeLessThanOrEqual(200)
        ->and($fresh->description)->toBeNull()
        // Enum cast hydrates the enum instance, not a raw string.
        ->and($fresh->status)->toBeInstanceOf(GoalStatus::class)
        ->and($fresh->status)->toBe(GoalStatus::Planned)
        // No session stamp by default; sort casts to int.
        ->and($fresh->status_changed_at_game_id)->toBeNull()
        ->and($fresh->sort)->toBeInt()
        ->and($fresh->sort)->toBe(0);
});

// ── Relations & factory state ───────────────────────────

test('relations resolve for a session-stamped goal', function () {
    ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner();
    $session = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
    ]);

    $goal = CampaignGoal::factory()->forCampaign($campaign)->create([
        'status' => GoalStatus::Achieved,
        'status_changed_at_game_id' => $session->id,
    ]);

    expect($goal->campaign->is($campaign))->toBeTrue()
        ->and($goal->statusChangedAtGame->is($session))->toBeTrue()
        // forCampaign pins the campaign; overrides still won on the rest.
        ->and($goal->campaign_id)->toBe($campaign->id);
});

test('campaign goals relation scopes to their campaign', function () {
    $campaign = Campaign::factory()->create();
    $other = Campaign::factory()->create();

    $kept = CampaignGoal::factory()->count(2)->forCampaign($campaign)->create();
    CampaignGoal::factory()->forCampaign($other)->create();

    expect($campaign->goals->pluck('id')->all())
        ->toEqualCanonicalizing($kept->pluck('id')->all());
});

// ── CHECK constraint ────────────────────────────────────

test('the status check constraint rejects unknown values', function () {
    $campaign = Campaign::factory()->create();

    // Raw insert: the Eloquent enum cast would reject the unknown value
    // before it reaches the database, so the CHECK backstop is proven
    // below the model layer.
    $violation = null;
    try {
        // The savepoint (nested DB::transaction) keeps the aborted
        // statement from poisoning the outer test transaction (pattern:
        // CampaignJournalEntryModelTest).
        DB::transaction(fn () => DB::table('campaign_goals')->insert([
            'id' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'title' => 'invalid status',
            'status' => 'mythic',
        ]));
        $this->fail('Expected a check violation from campaign_goals_status_check.');
    } catch (QueryException $e) {
        $violation = $e;
    }

    expect($violation->errorInfo[0] ?? null)->toBe('23514');
    expect($violation->getMessage())->toContain('campaign_goals_status_check');
});

// ── Delete semantics ────────────────────────────────────

test('deleting the stamped session nulls the stamp but keeps the goal', function () {
    ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner();
    $session = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
    ]);

    $goal = CampaignGoal::factory()->forCampaign($campaign)->create([
        'status_changed_at_game_id' => $session->id,
    ]);

    $session->delete();

    $fresh = CampaignGoal::find($goal->id);
    expect($fresh)->not->toBeNull()
        // The stamp is metadata (nullOnDelete, never cascade): a deleted
        // session must not delete the goal.
        ->and($fresh->status_changed_at_game_id)->toBeNull();

    $campaign->delete();
    expect(CampaignGoal::find($goal->id))->toBeNull();
});

// ── Bilingual labels ────────────────────────────────────

test('enum labels are non-empty in both locales', function () {
    foreach (['en', 'de'] as $locale) {
        App::setLocale($locale);

        foreach (GoalStatus::cases() as $status) {
            expect($status->label())->toBeString()->not->toBe('');
        }
    }

    App::setLocale('en');
});
