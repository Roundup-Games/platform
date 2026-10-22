<?php

use App\Enums\JournalEntryKind;
use App\Models\Campaign;
use App\Models\CampaignJournalEntry;
use App\Models\Game;
use App\Models\User;

//
// backfill:legacy-recaps (D218/D220): the re-runnable operator surface
// for promoting legacy campaign-session recaps into journal entries —
// the go-live migration's embedded run must never require a destructive
// rollback+re-migrate to repeat. Idempotency is the whole contract: a
// second run inserts nothing, journal-written recaps are skipped, and
// one-shot recaps stay column-only (service scope).
//
// Shared Postgres testcontainer: bootstrap migrates once, transactions
// isolate — no RefreshDatabase.
//

it('promotes legacy campaign-session recaps and is idempotent across runs', function () {
    $owner = User::factory()->create();
    $campaign = Campaign::factory()->create();

    $session = Game::factory()->create([
        'owner_id' => $owner,
        'campaign_id' => $campaign,
        'recap' => 'The company regrouped at the inn.',
    ]);

    $this->artisan('backfill:legacy-recaps')->assertSuccessful();

    expect(CampaignJournalEntry::query()
        ->where('game_id', $session->id)
        ->where('kind', JournalEntryKind::Recap->value)
        ->count())->toBe(1);

    // Second run: the NOT EXISTS guard + partial unique index make it a no-op.
    $this->artisan('backfill:legacy-recaps')->assertSuccessful();

    expect(CampaignJournalEntry::query()
        ->where('kind', JournalEntryKind::Recap->value)
        ->count())->toBe(1);
});

it('never touches one-shot recaps — campaign sessions are the whole scope', function () {
    $owner = User::factory()->create();

    Game::factory()->create([
        'owner_id' => $owner,
        'campaign_id' => null,
        'recap' => 'Column-only by design until the S03 decision.',
    ]);

    $this->artisan('backfill:legacy-recaps')->assertSuccessful();

    expect(CampaignJournalEntry::query()->count())->toBe(0);
});

it('stamps a NULL-updated_at legacy row with the run time instead of pinning it to the top of the timeline', function () {
    // Raw-imported legacy rows can carry NULL updated_at. Postgres sorts
    // NULLS FIRST under ORDER BY created_at DESC, so a NULL-stamped entry
    // would render as the newest journal entry forever.
    $owner = User::factory()->create();
    $campaign = Campaign::factory()->create();

    $session = Game::factory()->create([
        'owner_id' => $owner,
        'campaign_id' => $campaign,
        'recap' => 'The oldest recap in the campaign.',
        'updated_at' => null,
    ]);

    $this->artisan('backfill:legacy-recaps')->assertSuccessful();

    $entry = CampaignJournalEntry::query()->where('game_id', $session->id)->sole();

    expect($entry->created_at)->not->toBeNull()
        ->and($entry->created_at->isToday())->toBeTrue();
});
