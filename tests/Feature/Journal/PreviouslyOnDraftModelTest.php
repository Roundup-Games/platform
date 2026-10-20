<?php

use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameBulletin;
use App\Models\PreviouslyOnDraft;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

// M071/S04-T01 — previously_on_drafts: the draft-first entity behind the
// Previously On digest (D221). A dedicated table, never a GameBulletin
// row: unique game_id (one draft per target session, re-compose =
// update), cascade from campaign/session deletion, nullOnDelete bulletin
// link, and the publish stamps.

/**
 * A scheduled campaign session owned by the campaign owner: the canonical
 * target-session fixture (previously_on_drafts.game_id).
 *
 * @return array{0: User, 1: Campaign, 2: Game}
 */
function draftCampaignWithNextSession(array $sessionOverrides = []): array
{
    ['owner' => $owner, 'campaign' => $campaign] = test()->createCampaignWithOwner();

    $session = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => 'scheduled',
        ...$sessionOverrides,
    ]);

    return [$owner, $campaign, $session];
}

// ── Factory & casts ─────────────────────────────────────

test('factory creates and persists an unpublished draft with a datetime cast', function () {
    $draft = PreviouslyOnDraft::factory()->create();

    $fresh = PreviouslyOnDraft::find($draft->id);

    expect($fresh)->not->toBeNull()
        ->and($fresh->id)->toBeString()
        ->and($fresh->body)->toBeString()
        // Unpublished by default — the compose path's shape.
        ->and($fresh->published_at)->toBeNull()
        ->and($fresh->published_bulletin_id)->toBeNull();
});

test('published state stamps publish time and bulletin link', function () {
    [$owner, $campaign, $session] = draftCampaignWithNextSession();

    $bulletin = GameBulletin::postBy(
        gameId: (string) $session->id,
        userId: (string) $owner->id,
        content: 'digest body',
    );

    $draft = PreviouslyOnDraft::factory()
        ->forSession($campaign, $session)
        ->published($bulletin)
        ->create();

    $fresh = PreviouslyOnDraft::find($draft->id);

    expect($fresh->published_at)->not->toBeNull()
        ->and($fresh->published_at)->toBeInstanceOf(Carbon::class)
        ->and($fresh->published_bulletin_id)->toBe($bulletin->id);
});

// ── Relations ───────────────────────────────────────────

test('relations resolve: campaign, target session, published bulletin', function () {
    [$owner, $campaign, $session] = draftCampaignWithNextSession();

    $bulletin = GameBulletin::postBy(
        gameId: (string) $session->id,
        userId: (string) $owner->id,
        content: 'digest body',
    );

    $draft = PreviouslyOnDraft::factory()
        ->forSession($campaign, $session)
        ->published($bulletin)
        ->create();

    expect($draft->campaign->is($campaign))->toBeTrue()
        ->and($draft->game->is($session))->toBeTrue()
        ->and($draft->publishedBulletin->is($bulletin))->toBeTrue()
        // forSession pins campaign + game; the states won on the rest.
        ->and($draft->campaign_id)->toBe($campaign->id)
        ->and($draft->game_id)->toBe($session->id);
});

test('campaign previouslyOnDrafts and game previouslyOnDraft relations scope correctly', function () {
    [$owner, $campaign, $session] = draftCampaignWithNextSession();
    [, $otherCampaign, $otherSession] = draftCampaignWithNextSession();

    $kept = PreviouslyOnDraft::factory()->forSession($campaign, $session)->create();
    PreviouslyOnDraft::factory()->forSession($otherCampaign, $otherSession)->create();

    expect($campaign->previouslyOnDrafts->pluck('id')->all())->toBe([$kept->id])
        // hasOne on the unique game_id: at most one draft per session.
        ->and($session->previouslyOnDraft?->id)->toBe($kept->id);
});

// ── Unique game_id (D221: one draft per target session) ─

test('the unique game_id constraint rejects a second draft row for the same session', function () {
    [, $campaign, $session] = draftCampaignWithNextSession();

    PreviouslyOnDraft::factory()->forSession($campaign, $session)->create();

    $violation = null;
    try {
        // The savepoint (nested DB::transaction) keeps the aborted
        // statement from poisoning the outer test transaction (pattern:
        // CampaignGoalModelTest).
        DB::transaction(fn () => PreviouslyOnDraft::factory()->forSession($campaign, $session)->create());
        $this->fail('Expected a unique violation on previously_on_drafts.game_id.');
    } catch (QueryException $e) {
        $violation = $e;
    }

    expect($violation->errorInfo[0] ?? null)->toBe('23505');
    expect($violation->getMessage())->toContain('previously_on_drafts_game_id_unique');
});

// ── Delete semantics ────────────────────────────────────

test('deleting the target session or campaign removes the draft (cascade)', function () {
    [$owner, $campaign, $session] = draftCampaignWithNextSession();

    $draft = PreviouslyOnDraft::factory()->forSession($campaign, $session)->create();

    // Canceled/deleted session kills its draft.
    $session->delete();
    expect(PreviouslyOnDraft::find($draft->id))->toBeNull();

    // Drafts die with their campaign too (fresh session: the FK from the
    // first draft's session is gone with the session itself).
    $session2 = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => 'scheduled',
    ]);
    $draft2 = PreviouslyOnDraft::factory()->forSession($campaign, $session2)->create();
    $campaign->delete();
    expect(PreviouslyOnDraft::find($draft2->id))->toBeNull();
});

test('deleting the published bulletin keeps the draft and its publish stamp (nullOnDelete)', function () {
    [$owner, $campaign, $session] = draftCampaignWithNextSession();

    $bulletin = GameBulletin::postBy(
        gameId: (string) $session->id,
        userId: (string) $owner->id,
        content: 'digest body',
    );

    $draft = PreviouslyOnDraft::factory()
        ->forSession($campaign, $session)
        ->published($bulletin)
        ->create();

    $bulletin->delete();

    $fresh = PreviouslyOnDraft::find($draft->id);
    expect($fresh)->not->toBeNull()
        ->and($fresh->published_at)->not->toBeNull()
        // The audit trail survives the bulletin's deletion.
        ->and($fresh->published_bulletin_id)->toBeNull();
});
