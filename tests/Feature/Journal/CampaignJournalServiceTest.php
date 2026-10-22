<?php

use App\Enums\ActivityType;
use App\Enums\JournalEntryKind;
use App\Enums\JournalEntryVisibility;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\CampaignJournalEntry;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Notifications\RecapPosted;
use App\Services\CampaignJournalService;
use App\Services\RecapService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Traits\CreatesGameInstances;
use Tests\Traits\InjectsConcurrentDuplicates;

uses(CreatesGameInstances::class, InjectsConcurrentDuplicates::class);

// M071/S01-T03 — CampaignJournalService::writeRecap: journal entry as the
// recap source of truth (D220), games.recap synced in the SAME transaction,
// shared recap effects (SessionRecapped activity + RecapPosted notifications)
// delegated to RecapService::announceRecap.

/**
 * A completed campaign session owned by the campaign owner: the canonical
 * fixture for the journal recap composer.
 *
 * @return array{0: User, 1: Campaign, 2: Game}
 */
function journalRecapSession(): array
{
    ['owner' => $owner, 'campaign' => $campaign] = test()->createCampaignWithOwner();

    $session = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => 'completed',
    ]);

    return [$owner, $campaign, $session];
}

// ── Happy path + atomic projection sync ─────────────────

test('writeRecap creates the recap entry and syncs games.recap atomically', function () {
    [$owner, $campaign, $session] = journalRecapSession();

    $entry = app(CampaignJournalService::class)
        ->writeRecap($session, $owner, 'The party uncovered the traitor at last.');

    // Both sides asserted on FRESH models — the transaction must have
    // committed entry and projection together, never one without the other.
    expect($entry->campaign_id)->toBe($campaign->id)
        ->and($entry->game_id)->toBe($session->id)
        ->and($entry->author_user_id)->toBe($owner->id)
        ->and($entry->kind)->toBe(JournalEntryKind::Recap)
        ->and($entry->visibility)->toBe(JournalEntryVisibility::Players)
        ->and($entry->body)->toBe('The party uncovered the traitor at last.');

    $freshGame = Game::query()->findOrFail($session->id);
    expect($freshGame->recap)->toBe('The party uncovered the traitor at last.');

    $freshEntry = CampaignJournalEntry::query()->findOrFail($entry->id);
    expect($freshEntry->game_id)->toBe($session->id)
        ->and($freshEntry->kind)->toBe(JournalEntryKind::Recap);
});

test('writeRecap rethrows a lost one-recap-per-session race as the already-written error — the partial-index backstop', function () {
    // The application-level recapEntry()->exists() check narrows the
    // race; the partial unique index is the unconditional backstop. This
    // pins the LOSER path: a competing recap row lands between the check
    // and the insert (the injection fires via the creating event, inside
    // that exact window), the real 23505 surfaces through the real
    // partial index, and the catch converts it to the one error shape
    // callers know.
    [$owner, $campaign, $session] = journalRecapSession();

    $disarm = $this->injectDuplicateOnCreate(CampaignJournalEntry::class, fn (CampaignJournalEntry $entry): array => [
        'id' => (string) Str::uuid(),
        'campaign_id' => (string) $entry->campaign_id,
        'game_id' => (string) $entry->game_id,
        'author_user_id' => (string) $entry->author_user_id,
        'kind' => 'recap',
        'visibility' => 'players',
        'body' => 'The write that won the race.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    try {
        app(CampaignJournalService::class)->writeRecap($session, $owner, 'The write that lost.');
        $this->fail('Expected LogicException for the lost recap race.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('games.error_recap_already_written'));
    } finally {
        $disarm();
    }

    // The loser wrote nothing: the savepoint rollback removed both the
    // duplicate and the losing insert, and the projection stayed clean.
    expect(CampaignJournalEntry::query()->whereBelongsTo($session)->count())->toBe(0)
        ->and($session->fresh()->recap)->toBeNull();
});

test('writeRecap accepts a body at the 10000-character ceiling', function () {
    [$owner, , $session] = journalRecapSession();

    $body = str_repeat('a', 10000);

    $entry = app(CampaignJournalService::class)->writeRecap($session, $owner, $body);

    expect(mb_strlen($entry->body))->toBe(10000)
        ->and($session->fresh()->recap)->toBe($body);
});

// ── Shared recap effects (delegated to RecapService::announceRecap) ──

test('writeRecap keeps the persisted recap when the announce tail throws', function () {
    [$owner, , $session] = journalRecapSession();

    // The announce tail (activity log + notifications) runs AFTER the
    // entry and projection commit — its failure must never 500 the
    // write: the recap IS written and a retry would only hit the
    // already-written refusal.
    $recapService = Mockery::mock(RecapService::class);
    $recapService->shouldReceive('announceRecap')->andThrow(new RuntimeException('activity log down'));
    app()->instance(RecapService::class, $recapService);

    $entry = app(CampaignJournalService::class)
        ->writeRecap($session, $owner, 'Written while the tail failed.');

    expect($entry)->toBeInstanceOf(CampaignJournalEntry::class)
        ->and($entry->body)->toBe('Written while the tail failed.')
        ->and($session->fresh()->recap)->toBe('Written while the tail failed.')
        ->and(CampaignJournalEntry::where('game_id', $session->id)->where('kind', 'recap')->count())->toBe(1);
});

test('writeRecap logs the SessionRecapped activity', function () {
    [$owner, , $session] = journalRecapSession();

    app(CampaignJournalService::class)
        ->writeRecap($session, $owner, 'Another chapter closes.');

    $log = ActivityLog::query()
        ->where('user_id', $owner->id)
        ->where('subject_type', (new Game)->getMorphClass())
        ->where('subject_id', $session->id)
        ->where('event_type', ActivityType::SessionRecapped)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->properties['game_id'])->toBe($session->id)
        ->and($log->properties['author_id'])->toBe($owner->id);
});

test('writeRecap notifies approved participants but not the author or waitlisted', function () {
    Notification::fake();

    [$owner, , $session] = journalRecapSession();
    $player = User::factory()->create();
    $waitlisted = User::factory()->create();

    GameParticipant::factory()->create([
        'game_id' => $session->id,
        'user_id' => $player->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);
    GameParticipant::factory()->create([
        'game_id' => $session->id,
        'user_id' => $waitlisted->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Waitlisted->value,
    ]);

    app(CampaignJournalService::class)
        ->writeRecap($session, $owner, 'The cliffhanger lands.');

    Notification::assertSentTo(
        $player,
        RecapPosted::class,
        fn (RecapPosted $notification) => $notification->game->is($session)
                && $notification->author->is($owner),
    );
    Notification::assertNotSentTo([$owner], RecapPosted::class);
    Notification::assertNotSentTo([$waitlisted], RecapPosted::class);
});

// ── Validation failures: no entry, no projection ────────

test('writeRecap rejects a game that is not completed', function () {
    [$owner, , $session] = journalRecapSession();
    $session->update(['status' => 'scheduled']);

    try {
        app(CampaignJournalService::class)->writeRecap($session, $owner, 'Premature.');
        $this->fail('Expected LogicException for a not-completed game.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('games.error_recap_game_not_completed'));
    }

    expect(CampaignJournalEntry::query()->where('game_id', $session->id)->count())->toBe(0)
        ->and($session->fresh()->recap)->toBeNull();
});

test('writeRecap rejects a one-shot game without a campaign', function () {
    $owner = User::factory()->create();
    $oneShot = Game::factory()->create([
        'owner_id' => $owner->id,
        'campaign_id' => null,
        'status' => 'completed',
    ]);

    try {
        app(CampaignJournalService::class)->writeRecap($oneShot, $owner, 'One-shot recap.');
        $this->fail('Expected LogicException for a one-shot game.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('journal.error_not_campaign_session'));
    }

    expect(CampaignJournalEntry::query()->where('game_id', $oneShot->id)->count())->toBe(0)
        ->and($oneShot->fresh()->recap)->toBeNull();
});

test('writeRecap rejects an author who is not the host', function () {
    [$owner, , $session] = journalRecapSession();
    $stranger = User::factory()->create();

    try {
        app(CampaignJournalService::class)->writeRecap($session, $stranger, 'Not my table.');
        $this->fail('Expected LogicException for a non-host author.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('games.error_recap_not_host'));
    }

    expect(CampaignJournalEntry::query()->where('game_id', $session->id)->count())->toBe(0)
        ->and($session->fresh()->recap)->toBeNull();
});

test('writeRecap rejects an empty body', function () {
    [$owner, , $session] = journalRecapSession();

    try {
        app(CampaignJournalService::class)->writeRecap($session, $owner, "   \n\t ");
        $this->fail('Expected LogicException for an empty body.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('games.error_recap_empty'));
    }

    expect(CampaignJournalEntry::query()->where('game_id', $session->id)->count())->toBe(0)
        ->and($session->fresh()->recap)->toBeNull();
});

test('writeRecap rejects a body over 10000 characters', function () {
    [$owner, , $session] = journalRecapSession();

    try {
        app(CampaignJournalService::class)->writeRecap($session, $owner, str_repeat('a', 10001));
        $this->fail('Expected LogicException for an over-long body.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('journal.error_recap_too_long'));
    }

    expect(CampaignJournalEntry::query()->where('game_id', $session->id)->count())->toBe(0)
        ->and($session->fresh()->recap)->toBeNull();
});

test('writeRecap rejects a second recap for the same session', function () {
    [$owner, , $session] = journalRecapSession();

    app(CampaignJournalService::class)->writeRecap($session, $owner, 'First recap wins.');

    try {
        app(CampaignJournalService::class)->writeRecap($session, $owner, 'Second recap loses.');
        $this->fail('Expected LogicException for a second recap.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('games.error_recap_already_written'));
    }

    expect(CampaignJournalEntry::query()->where('game_id', $session->id)->count())->toBe(1)
        ->and($session->fresh()->recap)->toBe('First recap wins.');
});

test('writeRecap rejects a session whose legacy recap was backfilled into the journal', function () {
    [$owner, , $session] = journalRecapSession();

    // Backfill shape (LegacyRecapJournalBackfill): a kind=recap entry already
    // exists. games.recap stays NULL here so the entry arm of the
    // already-written check is what rejects — not the legacy column.
    CampaignJournalEntry::factory()->forCampaignSession($session)->create();

    try {
        app(CampaignJournalService::class)->writeRecap($session, $owner, 'Rewrite attempt.');
        $this->fail('Expected LogicException for a backfilled recap.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('games.error_recap_already_written'));
    }

    expect(CampaignJournalEntry::query()->where('game_id', $session->id)->count())->toBe(1)
        ->and($session->fresh()->recap)->toBeNull();
});
