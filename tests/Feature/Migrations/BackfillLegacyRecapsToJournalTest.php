<?php

use App\Enums\JournalEntryKind;
use App\Enums\JournalEntryVisibility;
use App\Models\CampaignJournalEntry;
use App\Models\Game;
use App\Services\LegacyRecapJournalBackfill;
use Tests\Traits\CapturesLogRecords;
use Tests\Traits\CreatesGameInstances;

uses(CapturesLogRecords::class, CreatesGameInstances::class);

/*
 * Verifies the legacy-recap journal backfill (the journal-table migration
 * calls LegacyRecapJournalBackfill::run() after creating
 * campaign_journal_entries — GmailCanonicalInviteBackfill pattern):
 *
 *   - a campaign session's legacy recap becomes a kind=recap players entry
 *     authored by the session owner, timestamped from game.updated_at
 *   - re-runs insert nothing (NOT EXISTS guard)
 *   - one-shot games (campaign_id null) are never migrated (S03 decision)
 *   - empty-string/null recaps produce no rows
 *   - sessions journalled post-migration are not duplicated
 *
 * Fixtures write games.recap directly (factory/builder) — NOT via
 * RecapService — to reproduce the legacy pre-journal data shape.
 */

it('backfills a legacy campaign-session recap as a players-visible recap entry', function () {
    ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner();
    $session = Game::factory()->create([
        'owner_id' => $owner->id,
        'campaign_id' => $campaign->id,
        'status' => 'completed',
        'recap' => 'Die Runde endete am Lagerfeuer.',
    ]);

    // Pin the recap write time: game.updated_at is the timeline-order proxy
    // the entry must inherit (games carry no ended_at). Builder update —
    // no model events, no automatic timestamp touch.
    $recapWrittenAt = now()->subDays(3)->startOfSecond();
    Game::query()->whereKey($session->id)->update(['updated_at' => $recapWrittenAt]);

    $this->captureLogRecords();

    $inserted = app(LegacyRecapJournalBackfill::class)->run();

    expect($inserted)->toBe(1);

    $entry = CampaignJournalEntry::query()->sole();
    expect($entry->campaign_id)->toBe($campaign->id)
        ->and($entry->game_id)->toBe($session->id)
        ->and($entry->author_user_id)->toBe($owner->id)
        ->and($entry->kind)->toBe(JournalEntryKind::Recap)
        ->and($entry->visibility)->toBe(JournalEntryVisibility::Players)
        ->and($entry->body)->toBe('Die Runde endete am Lagerfeuer.')
        ->and($entry->created_at->format('Y-m-d H:i:s'))->toBe($recapWrittenAt->format('Y-m-d H:i:s'))
        ->and($entry->updated_at->format('Y-m-d H:i:s'))->toBe($recapWrittenAt->format('Y-m-d H:i:s'))
        // Read-only on games: the recap column stays (D220 projection).
        ->and($session->refresh()->recap)->toBe('Die Runde endete am Lagerfeuer.');

    // Structured summary line at info level (observability).
    $summary = $this->logRecord('backfill.legacy_recap_journal');
    expect($summary)->not->toBeNull()
        ->and($summary['level_name'])->toBe('INFO')
        ->and($summary['context'])->toBe(['inserted' => 1]);
});

it('is idempotent — a re-run inserts nothing', function () {
    ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner();
    Game::factory()->create([
        'owner_id' => $owner->id,
        'campaign_id' => $campaign->id,
        'status' => 'completed',
        'recap' => 'First run migrates this.',
    ]);

    $first = app(LegacyRecapJournalBackfill::class)->run();
    $second = app(LegacyRecapJournalBackfill::class)->run();

    expect($first)->toBe(1)
        ->and($second)->toBe(0)
        ->and(CampaignJournalEntry::query()->count())->toBe(1);
});

it('never migrates one-shot games (campaign_id null)', function () {
    ['owner' => $owner, 'game' => $game] = $this->createGameWithOwner([
        'status' => 'completed',
        'recap' => 'One-shot recap stays column-only until S03.',
    ]);

    $inserted = app(LegacyRecapJournalBackfill::class)->run();

    expect($inserted)->toBe(0)
        ->and(CampaignJournalEntry::query()->count())->toBe(0)
        ->and($game->refresh()->recap)->toBe('One-shot recap stays column-only until S03.');
});

it('skips empty-string and null recaps', function () {
    ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner();
    Game::factory()->create([
        'owner_id' => $owner->id, 'campaign_id' => $campaign->id,
        'status' => 'completed', 'recap' => '',
    ]);
    Game::factory()->create([
        'owner_id' => $owner->id, 'campaign_id' => $campaign->id,
        'status' => 'completed', 'recap' => null,
    ]);

    expect(app(LegacyRecapJournalBackfill::class)->run())->toBe(0)
        ->and(CampaignJournalEntry::query()->count())->toBe(0);
});

it('does not duplicate sessions already journalled post-migration', function () {
    ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner();

    // Post-migration shape A: CampaignJournalService wrote the entry and the
    // projection was later cleared — empty recap + existing journal entry
    // must not be re-created.
    $cleared = Game::factory()->create([
        'owner_id' => $owner->id, 'campaign_id' => $campaign->id,
        'status' => 'completed', 'recap' => null,
    ]);
    CampaignJournalEntry::factory()->forCampaignSession($cleared)->create();

    // Post-migration shape B: entry written through the service while the
    // recap projection stays in sync — non-empty recap + existing entry
    // must be skipped by the NOT EXISTS guard.
    $synced = Game::factory()->create([
        'owner_id' => $owner->id, 'campaign_id' => $campaign->id,
        'status' => 'completed', 'recap' => 'Already journalled — projection kept.',
    ]);
    CampaignJournalEntry::factory()->forCampaignSession($synced)->create();

    expect(app(LegacyRecapJournalBackfill::class)->run())->toBe(0)
        ->and(CampaignJournalEntry::query()->whereBelongsTo($cleared)->count())->toBe(1)
        ->and(CampaignJournalEntry::query()->whereBelongsTo($synced)->count())->toBe(1);
});
