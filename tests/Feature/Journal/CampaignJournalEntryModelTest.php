<?php

use App\Enums\JournalEntryKind;
use App\Enums\JournalEntryVisibility;
use App\Models\Campaign;
use App\Models\CampaignJournalEntry;
use App\Models\CampaignParticipant;
use App\Models\Game;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

// M071/S01-T01 — journal schema: campaign_journal_entries table, enum
// casts, campaign/game/author relations, the one-recap-per-session race
// backstop and the bilingual enum labels.

// ── Factory & casts ─────────────────────────────────────

test('factory creates and persists a journal entry with enum casts', function () {
    $entry = CampaignJournalEntry::factory()->create();

    $fresh = CampaignJournalEntry::find($entry->id);

    expect($fresh)->not->toBeNull()
        // Factory default is an interlude entry: no session binding (spec §2).
        ->and($fresh->game_id)->toBeNull()
        ->and($fresh->id)->toBeString()
        ->and($fresh->body)->toBeString()
        // Enum casts hydrate the enum instances, not raw strings.
        ->and($fresh->kind)->toBeInstanceOf(JournalEntryKind::class)
        ->and($fresh->kind)->toBe(JournalEntryKind::Recap)
        ->and($fresh->visibility)->toBeInstanceOf(JournalEntryVisibility::class)
        ->and($fresh->visibility)->toBe(JournalEntryVisibility::Players)
        ->and($fresh->in_game_date)->toBeNull();
});

// ── Relations ───────────────────────────────────────────

test('relations resolve for a session-bound entry', function () {
    ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner();
    $session = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
    ]);

    $entry = CampaignJournalEntry::factory()->forCampaignSession($session)->create();

    expect($entry->campaign->is($campaign))->toBeTrue()
        ->and($entry->game->is($session))->toBeTrue()
        ->and($entry->authorUser->is($owner))->toBeTrue()
        ->and($entry->authorParticipant)->toBeNull();
});

test('forCampaignSession state pins campaign, game and author to the session', function () {
    ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner();
    $session = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
    ]);

    $entry = CampaignJournalEntry::factory()->forCampaignSession($session)->create();

    expect($entry->campaign_id)->toBe($campaign->id)
        ->and($entry->game_id)->toBe($session->id)
        // Author defaults to the session's owner — the recap-author rule
        // (spec §2). create() overrides still win over the state.
        ->and($entry->author_user_id)->toBe($owner->id);
});

test('campaign and session journalEntries relations scope to their owner', function () {
    ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner();
    $session = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
    ]);
    $otherCampaign = Campaign::factory()->create();

    $sessionEntry = CampaignJournalEntry::factory()->forCampaignSession($session)->create();
    $interlude = CampaignJournalEntry::factory()->create([
        'campaign_id' => $campaign->id,
        'author_user_id' => $owner->id,
    ]);
    CampaignJournalEntry::factory()->create(['campaign_id' => $otherCampaign->id]);

    expect($campaign->journalEntries->pluck('id')->all())
        ->toEqualCanonicalizing([$sessionEntry->id, $interlude->id])
        // The session relation only sees session-bound entries, never
        // interludes.
        ->and($session->journalEntries->pluck('id')->all())->toBe([$sessionEntry->id]);
});

test('game recapEntry resolves the recap and ignores other kinds', function () {
    ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner();
    $session = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
    ]);

    CampaignJournalEntry::factory()->forCampaignSession($session)->create([
        'kind' => JournalEntryKind::PrepNote,
        'visibility' => JournalEntryVisibility::Gms,
    ]);
    $recap = CampaignJournalEntry::factory()->forCampaignSession($session)->create();

    expect($session->recapEntry)->not->toBeNull()
        ->and($session->recapEntry->is($recap))->toBeTrue();
});

// ── One-recap-per-session race backstop ─────────────────

test('a second recap for the same session violates the partial unique index', function () {
    ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner();
    $session = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
    ]);

    CampaignJournalEntry::factory()->forCampaignSession($session)->create();

    $caught = null;
    try {
        // The savepoint (nested DB::transaction) keeps the aborted
        // statement from poisoning the outer test transaction (pattern:
        // RegistrationDuplicateCheckTest).
        DB::transaction(fn () => CampaignJournalEntry::factory()->forCampaignSession($session)->create());
        $this->fail('Expected a unique violation from campaign_journal_entries_one_recap_per_session.');
    } catch (QueryException $e) {
        $caught = $e;
    }

    // Postgres: SQLSTATE 23505 naming the partial index.
    expect($caught->errorInfo[0] ?? null)->toBe('23505');
    expect($caught->getMessage())->toContain('campaign_journal_entries_one_recap_per_session');

    // Exactly one recap survives the "race"...
    expect(CampaignJournalEntry::query()
        ->where('game_id', $session->id)
        ->where('kind', JournalEntryKind::Recap->value)
        ->count())->toBe(1);

    // ...and the partial predicate exempts other kinds on the same session.
    CampaignJournalEntry::factory()->forCampaignSession($session)->create([
        'kind' => JournalEntryKind::InCharacter,
    ]);

    expect(CampaignJournalEntry::query()->where('game_id', $session->id)->count())->toBe(2);
});

// ── CHECK constraints ───────────────────────────────────

test('kind and visibility check constraints reject unknown values', function () {
    $campaign = Campaign::factory()->create();

    // Raw inserts: the Eloquent enum casts would reject unknown values
    // before they reach the database, so the CHECK backstop is proven
    // below the model layer.
    $kindViolation = null;
    try {
        DB::transaction(fn () => DB::table('campaign_journal_entries')->insert([
            'id' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'author_user_id' => $campaign->owner_id,
            'kind' => 'warlord',
            'body' => 'invalid kind',
            'visibility' => 'players',
        ]));
        $this->fail('Expected a check violation from campaign_journal_entries_kind_check.');
    } catch (QueryException $e) {
        $kindViolation = $e;
    }

    expect($kindViolation->errorInfo[0] ?? null)->toBe('23514');
    expect($kindViolation->getMessage())->toContain('campaign_journal_entries_kind_check');

    $visibilityViolation = null;
    try {
        DB::transaction(fn () => DB::table('campaign_journal_entries')->insert([
            'id' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'author_user_id' => $campaign->owner_id,
            'kind' => 'recap',
            'body' => 'invalid visibility',
            'visibility' => 'everyone',
        ]));
        $this->fail('Expected a check violation from campaign_journal_entries_visibility_check.');
    } catch (QueryException $e) {
        $visibilityViolation = $e;
    }

    expect($visibilityViolation->errorInfo[0] ?? null)->toBe('23514');
    expect($visibilityViolation->getMessage())->toContain('campaign_journal_entries_visibility_check');
});

// ── Cascades ────────────────────────────────────────────

test('deleting a campaign or session cascades to its journal entries', function () {
    ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner();
    $session = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
    ]);

    $sessionEntry = CampaignJournalEntry::factory()->forCampaignSession($session)->create();
    $interlude = CampaignJournalEntry::factory()->create(['campaign_id' => $campaign->id]);

    $session->delete();
    expect(CampaignJournalEntry::find($sessionEntry->id))->toBeNull()
        // Interlude entries survive the session delete — they belong to
        // the campaign.
        ->and(CampaignJournalEntry::find($interlude->id))->not->toBeNull();

    $campaign->delete();
    expect(CampaignJournalEntry::find($interlude->id))->toBeNull();
});

test('removing an author participant nulls the link but never deletes entries', function () {
    ['owner' => $owner, 'campaign' => $campaign] = $this->createCampaignWithOwner();

    // createCampaignWithOwner already ensured the owner's participant row
    // (unique per campaign+user) — reuse it as the entry's character link.
    $participant = CampaignParticipant::query()
        ->where('campaign_id', $campaign->id)
        ->where('user_id', $owner->id)
        ->firstOrFail();

    $entry = CampaignJournalEntry::factory()->create([
        'campaign_id' => $campaign->id,
        'author_user_id' => $owner->id,
        'author_participant_id' => $participant->id,
        'kind' => JournalEntryKind::InCharacter,
    ]);

    $participant->delete();

    $fresh = CampaignJournalEntry::find($entry->id);
    expect($fresh)->not->toBeNull()
        ->and($fresh->author_participant_id)->toBeNull()
        // Durable attribution survives participant removal.
        ->and($fresh->authorUser->is($owner))->toBeTrue();
});

// ── Bilingual labels ────────────────────────────────────

test('enum labels are non-empty in both locales', function () {
    foreach (['en', 'de'] as $locale) {
        App::setLocale($locale);

        foreach (JournalEntryKind::cases() as $kind) {
            expect($kind->label())->toBeString()->not->toBe('');
        }

        foreach (JournalEntryVisibility::cases() as $visibility) {
            expect($visibility->label())->toBeString()->not->toBe('');
        }
    }

    App::setLocale('en');
});
