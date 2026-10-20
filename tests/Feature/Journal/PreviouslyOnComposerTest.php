<?php

use App\Enums\GoalStatus;
use App\Enums\JournalEntryKind;
use App\Enums\JournalEntryVisibility;
use App\Models\Campaign;
use App\Models\CampaignGoal;
use App\Models\CampaignJournalEntry;
use App\Models\CampaignParticipant;
use App\Models\CharacterStateChange;
use App\Models\Game;
use App\Models\PreviouslyOnDraft;
use App\Models\User;
use App\Services\PreviouslyOnService;
use Illuminate\Support\Facades\App;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

// M071/S04-T01 — PreviouslyOnService: the draft-first digest composer
// (spec §3.1) and the one-click publisher. Composition reads journal
// entries only (D220: never games.recap, never prep-note/in-character
// bodies), keys off campaign_id only (D218: every campaign type
// identically), returns null when there is nothing to say, and ships
// plain text. Publishing creates exactly one GameBulletin whose untouched
// observer delivers the whole channel matrix. Copy assertions resolve
// keys through __() so they stay wording-agnostic (K004).

/**
 * A campaign with a completed session (played yesterday) and a scheduled
 * next session: the canonical Previously On fixture. $completedAt /
 * $scheduledAt override the session date_times for deterministic
 * last-completed resolution.
 *
 * @return array{0: User, 1: Campaign, 2: Game, 3: Game}
 */
function previouslyOnCampaign(?string $gameType = null): array
{
    ['owner' => $owner, 'campaign' => $campaign] = test()->createCampaignWithOwner();

    $sessionOverrides = [];
    if ($gameType !== null) {
        $sessionOverrides['game_type'] = $gameType;
    }

    $completed = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => 'completed',
        'date_time' => now()->subDay(),
        ...$sessionOverrides,
    ]);

    $next = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => 'scheduled',
        'date_time' => now()->addDays(7),
        ...$sessionOverrides,
    ]);

    return [$owner, $campaign, $completed, $next];
}

/**
 * Write a kind=recap journal entry on a session (the D220 source of truth).
 */
function previouslyOnRecap(Game $session, string $body, ?string $createdAt = null): CampaignJournalEntry
{
    $attributes = ['body' => $body, 'kind' => JournalEntryKind::Recap];
    if ($createdAt !== null) {
        $attributes['created_at'] = $createdAt;
    }

    return CampaignJournalEntry::factory()
        ->forCampaignSession($session)
        ->create($attributes);
}

/**
 * An approved player participant in the campaign.
 *
 * @return array{0: User, 1: CampaignParticipant}
 */
function previouslyOnPlayer(Campaign $campaign): array
{
    $participant = CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'user_id' => User::factory()->create(['profile_complete' => true])->id,
        'role' => 'player',
        'status' => 'approved',
    ]);

    return [$participant->user, $participant];
}

// ── Recap excerpt (D220) ────────────────────────────────

test('the excerpt carries the latest recap, truncated to 200 words, with no journal deep-link (D231)', function () {
    [$owner, $campaign, $completed, $next] = previouslyOnCampaign();

    // One recap per session (S01's partial unique index): the older
    // recap lives on an earlier completed session and must lose to the
    // newer one.
    $olderSession = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => 'completed',
        'date_time' => now()->subDays(9),
    ]);
    previouslyOnRecap($olderSession, 'older recap body', now()->subHours(2));

    $longBody = implode(' ', array_map(fn (int $i) => "w{$i}", range(1, 300)));
    previouslyOnRecap($completed, $longBody, now()->subHour());

    $body = app(PreviouslyOnService::class)->composeBody($campaign, $next);

    expect($body)->toBeString()
        // Latest recap wins.
        ->and($body)->toContain('w1 w2')
        // Truncated at 200 words: the 200th word stays, the 201st never
        // reaches the digest.
        ->and($body)->toContain('w200')
        ->and($body)->not->toContain('w201')
        ->and($body)->not->toContain('older recap body')
        // D231: the excerpt stands alone — one bulletin is one body,
        // and a members-only journal link would dead-end for one-off
        // guests. The session-page RSVP CTA (below) closes the digest.
        ->and($body)->not->toContain(route('campaigns.show', ['locale' => 'en', 'id' => $campaign, 'tab' => 'journal']))
        ->and($body)->toContain(route('games.show', ['locale' => 'en', 'id' => $next]));
});

test('a divergent games.recap projection never leaks into the body (D220)', function () {
    [, $campaign, $completed, $next] = previouslyOnCampaign();

    previouslyOnRecap($completed, 'the journal source of truth');
    // Column deliberately divergent: the composer reads journal entries,
    // never the synced projection.
    $completed->update(['recap' => 'SECRET PROJECTION TEXT']);

    $body = app(PreviouslyOnService::class)->composeBody($campaign, $next);

    expect($body)->toContain('the journal source of truth')
        ->and($body)->not->toContain('SECRET PROJECTION TEXT');
});

test('prep-note and in-character bodies stay out of the digest even when newest', function () {
    [, $campaign, $completed, $next] = previouslyOnCampaign();

    previouslyOnRecap($completed, 'players-visible recap', now()->subHours(3));

    // Prep note (gms-only) newer than the recap: must never reach the
    // whole-table digest.
    CampaignJournalEntry::factory()->forCampaignSession($completed)->create([
        'kind' => JournalEntryKind::PrepNote,
        'visibility' => JournalEntryVisibility::Gms,
        'body' => 'PREP NOTE SECRET',
        'created_at' => now()->subHour(),
    ]);

    CampaignJournalEntry::factory()->create([
        'campaign_id' => $campaign->id,
        'author_user_id' => $completed->owner_id,
        'kind' => JournalEntryKind::InCharacter,
        'body' => 'IN CHARACTER SECRET',
        'created_at' => now()->subMinutes(30),
    ]);

    $body = app(PreviouslyOnService::class)->composeBody($campaign, $next);

    expect($body)->toContain('players-visible recap')
        ->and($body)->not->toContain('PREP NOTE SECRET')
        ->and($body)->not->toContain('IN CHARACTER SECRET');
});

// ── Goals moved (status_changed_at_game_id stamps) ──────

test('goals moved lists only goals stamped by the last completed session', function () {
    [$owner, $campaign, , $next] = previouslyOnCampaign();

    // Two completed sessions; the one played most recently is the diff
    // source. previouslyOnCampaign's session is "yesterday" — add an
    // older one last week.
    $older = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => 'completed',
        'date_time' => now()->subDays(8),
    ]);
    $latest = $campaign->sessions()
        ->where('status', 'completed')
        ->orderByDesc('date_time')
        ->first();

    CampaignGoal::factory()->forCampaign($campaign)->create([
        'title' => 'Freshly achieved goal',
        'status' => GoalStatus::Achieved,
        'status_changed_at_game_id' => $latest->id,
    ]);
    CampaignGoal::factory()->forCampaign($campaign)->create([
        'title' => 'Freshly failed goal',
        'status' => GoalStatus::Failed,
        'status_changed_at_game_id' => $latest->id,
    ]);
    // Stamped by the OLDER session: outside the diff window.
    CampaignGoal::factory()->forCampaign($campaign)->create([
        'title' => 'Stale moved goal',
        'status' => GoalStatus::Active,
        'status_changed_at_game_id' => $older->id,
    ]);
    // No stamp at all: not "moved" by any session.
    CampaignGoal::factory()->forCampaign($campaign)->create([
        'title' => 'Unstamped goal',
        'status_changed_at_game_id' => null,
    ]);

    $body = app(PreviouslyOnService::class)->composeBody($campaign, $next);

    $goalSection = __('journal.section_goals_moved', [], 'en');
    expect($body)->toContain($goalSection)
        ->and($body)->toContain('Freshly achieved goal')
        ->and($body)->toContain('Freshly failed goal')
        ->and($body)->toContain('✔ '.__('journal.status_achieved', [], 'en'))
        ->and($body)->toContain('✖ '.__('journal.status_failed', [], 'en'))
        // Older stamps and unstamped goals are excluded from the diff.
        ->and($body)->not->toContain('Stale moved goal')
        ->and($body)->not->toContain('Unstamped goal');
});

test('the goals section is omitted when the campaign has no completed sessions', function () {
    ['owner' => $owner, 'campaign' => $campaign] = test()->createCampaignWithOwner();

    // A scheduled next session, an unstamped goal, and an interlude
    // recap (no session link) — but ZERO completed sessions.
    $next = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => 'scheduled',
    ]);

    CampaignGoal::factory()->forCampaign($campaign)->create([
        'title' => 'Never stamped',
        'status_changed_at_game_id' => null,
    ]);

    CampaignJournalEntry::factory()->create([
        'campaign_id' => $campaign->id,
        'author_user_id' => $owner->id,
        'kind' => JournalEntryKind::Recap,
        'body' => 'interlude recap body',
    ]);

    $body = app(PreviouslyOnService::class)->composeBody($campaign, $next);

    expect($body)->toBeString()
        // The recap keeps the digest alive…
        ->and($body)->toContain('interlude recap body')
        // …but the goals section needs a completed session to diff against.
        ->and($body)->not->toContain(__('journal.section_goals_moved', [], 'en'));
});

test('the digest excerpts the latest session recap, not the latest write (out-of-order backfill)', function () {
    ['owner' => $owner, 'campaign' => $campaign] = test()->createCampaignWithOwner();

    $newerSession = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => 'completed',
        'date_time' => now()->subWeek(),
    ]);
    $olderSession = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => 'completed',
        'date_time' => now()->subWeeks(3),
    ]);

    // The newer session's recap was written FIRST; the older session's
    // recap was backfilled SECOND — write-order (created_at) picks the
    // wrong excerpt, session-order picks the right one.
    CampaignJournalEntry::factory()->create([
        'campaign_id' => $campaign->id,
        'game_id' => $newerSession->id,
        'author_user_id' => $owner->id,
        'kind' => JournalEntryKind::Recap,
        'body' => 'newer session recap body',
        'created_at' => now()->subDays(3),
    ]);
    CampaignJournalEntry::factory()->create([
        'campaign_id' => $campaign->id,
        'game_id' => $olderSession->id,
        'author_user_id' => $owner->id,
        'kind' => JournalEntryKind::Recap,
        'body' => 'backfilled older recap body',
        'created_at' => now()->subDay(),
    ]);

    $next = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => 'scheduled',
    ]);

    $body = app(PreviouslyOnService::class)->composeBody($campaign, $next);

    expect($body)->toContain('newer session recap body')
        ->and($body)->not->toContain('backfilled older recap body');
});

// ── Loose threads (latest condition + latest item per participant) ──

test('loose threads pick the latest condition and latest item change per participant', function () {
    [, $campaign, , $next] = previouslyOnCampaign();
    [$playerA, $participantA] = previouslyOnPlayer($campaign);
    [, $participantB] = previouslyOnPlayer($campaign);

    // Player A: two conditions (older loses) + one item.
    CharacterStateChange::factory()->forParticipant($participantA)->create([
        'change_type' => 'condition',
        'delta' => 'older condition delta',
        'created_at' => now()->subHours(4),
    ]);
    CharacterStateChange::factory()->forParticipant($participantA)->create([
        'change_type' => 'condition',
        'delta' => 'newest condition delta',
        'created_at' => now()->subHour(),
    ]);
    CharacterStateChange::factory()->forParticipant($participantA)->create([
        'change_type' => 'item',
        'delta' => 'borrowed lantern',
        'created_at' => now()->subHours(2),
    ]);
    // Note-type: never a loose thread.
    CharacterStateChange::factory()->forParticipant($participantA)->create([
        'change_type' => 'note',
        'delta' => 'note delta',
        'created_at' => now()->subMinutes(30),
    ]);

    // Player B: one condition.
    CharacterStateChange::factory()->forParticipant($participantB)->create([
        'change_type' => 'condition',
        'delta' => 'cursed by the idol',
        'created_at' => now()->subHours(3),
    ]);

    // Participant-less change: no character to attribute the thread to.
    CharacterStateChange::factory()->create([
        'campaign_id' => $campaign->id,
        'participant_id' => null,
        'change_type' => 'condition',
        'delta' => 'orphan condition delta',
    ]);

    $body = app(PreviouslyOnService::class)->composeBody($campaign, $next);

    $threadsSection = __('journal.section_loose_threads', [], 'en');
    expect($body)->toContain($threadsSection)
        // Newest per (participant, type) wins.
        ->and($body)->toContain('newest condition delta')
        ->and($body)->not->toContain('older condition delta')
        ->and($body)->toContain('borrowed lantern')
        ->and($body)->toContain('cursed by the idol')
        // Participant display name + type label render with the delta.
        ->and($body)->toContain($playerA->name)
        ->and($body)->toContain(__('journal.type_condition', [], 'en'))
        ->and($body)->toContain(__('journal.type_item', [], 'en'))
        // Notes and participant-less changes are not loose threads.
        ->and($body)->not->toContain('note delta')
        ->and($body)->not->toContain('orphan condition delta');
});

// ── Degradation, shape, and type-independence ───────────

test('an empty journal composes null — no junk CTA-only drafts', function () {
    [, $campaign, , $next] = previouslyOnCampaign();

    // Campaign has a scheduled next session but zero journal content.
    $body = app(PreviouslyOnService::class)->composeBody($campaign, $next);

    expect($body)->toBeNull();
});

test('the composed body is plain text with no HTML or markdown added', function () {
    [, $campaign, $completed, $next] = previouslyOnCampaign();

    previouslyOnRecap($completed, 'recap body with entities and symbols');

    $body = app(PreviouslyOnService::class)->composeBody($campaign, $next);

    expect($body)->toBeString()
        // The composer adds no tags or markdown anywhere in the digest —
        // headings, bullets and links are plain text lines (the board
        // escapes whatever it renders).
        ->and($body)->not->toContain('<')
        ->and($body)->not->toContain('>')
        // Line breaks are \n, the board's escaped-plain-text contract.
        ->and($body)->toContain("\n");
});

test('composition keys off campaign_id only — a gathering campaign composes identically (D218)', function () {
    [, $campaign, $completed, $next] = previouslyOnCampaign(gameType: 'gathering');

    previouslyOnRecap($completed, 'the crew restocked the ship');

    $body = app(PreviouslyOnService::class)->composeBody($campaign, $next);

    expect($body)->toBeString()
        ->and($body)->toContain('the crew restocked the ship')
        ->and($body)->toContain(__('journal.title_previously_on', [
            'campaign' => (string) $campaign->getTranslation('name', 'en'),
            'session' => 1,
        ], 'en'));
});

// ── Locale ──────────────────────────────────────────────

test('a German-language session composes German copy and restores the app locale', function () {
    [, $campaign, $completed, $next] = previouslyOnCampaign();
    $next->update(['language' => 'de']);

    previouslyOnRecap($completed, 'deutsche Zusammenfassung');

    App::setLocale('en');

    $body = app(PreviouslyOnService::class)->composeBody($campaign, $next);

    expect($body)->toBeString()
        ->and($body)->toContain(__('journal.title_previously_on', [
            'campaign' => (string) $campaign->getTranslation('name', 'de'),
            'session' => 1,
        ], 'de'))
        // German copy throughout: the next-session section label is
        // the direct evidence next to the German excerpt.
        ->and($body)->toContain(__('journal.section_next_session', [], 'de'))
        ->and($body)->not->toContain(__('journal.section_next_session', [], 'en'))
        // The composer never leaks its locale switch into the caller.
        ->and(App::getLocale())->toBe('en');
});

// ── upsertUnpublished (D221: refresh-unpublished-or-compose) ──

test('upsertUnpublished creates a draft, then refreshes it in place — never a duplicate row', function () {
    $service = app(PreviouslyOnService::class);
    [$owner, $campaign, $completed, $next] = previouslyOnCampaign();

    previouslyOnRecap($completed, 'first composition');

    $first = $service->upsertUnpublished($campaign, $next);
    expect($first)->toBeInstanceOf(PreviouslyOnDraft::class)
        ->and($first->published_at)->toBeNull()
        ->and(PreviouslyOnDraft::query()->count())->toBe(1);

    // A further session completes and gets its recap before the 24h
    // refresh window (one recap per session — the new content rides a
    // new session, S01's partial unique index).
    $newerSession = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => 'completed',
        'date_time' => now(),
    ]);
    previouslyOnRecap($newerSession, 'second composition', now()->addMinutes(5));

    $second = $service->upsertUnpublished($campaign, $next);
    expect($second->id)->toBe($first->id)
        ->and($second->body)->toContain('second composition')
        ->and($second->published_at)->toBeNull()
        ->and(PreviouslyOnDraft::query()->count())->toBe(1);
});

test('upsertUnpublished never touches a published draft', function () {
    $service = app(PreviouslyOnService::class);
    [$owner, $campaign, $completed, $next] = previouslyOnCampaign();

    previouslyOnRecap($completed, 'frozen composition');

    $draft = $service->upsertUnpublished($campaign, $next);
    $service->publish($draft, $owner);

    // Later journal data (a new session's recap) must not restamp or
    // rewrite the published draft.
    $newerSession = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => 'completed',
        'date_time' => now(),
    ]);
    previouslyOnRecap($newerSession, 'post-publish composition', now()->addMinutes(10));
    $result = $service->upsertUnpublished($campaign, $next);

    $fresh = PreviouslyOnDraft::find($draft->id);
    expect($result)->toBeNull()
        ->and($fresh->body)->toContain('frozen composition')
        ->and($fresh->body)->not->toContain('post-publish composition')
        ->and($fresh->published_at)->not->toBeNull();
});

test('a null composition leaves an existing unpublished draft untouched', function () {
    $service = app(PreviouslyOnService::class);
    [, $campaign, $completed, $next] = previouslyOnCampaign();

    previouslyOnRecap($completed, 'the only content');
    $draft = $service->upsertUnpublished($campaign, $next);

    // The journal empties (GM deletes the recap); nothing to say does not
    // mean "blank the draft".
    CampaignJournalEntry::query()->where('campaign_id', $campaign->id)->delete();

    $result = $service->upsertUnpublished($campaign, $next);

    expect($result->id)->toBe($draft->id)
        ->and($result->body)->toBe($draft->body)
        ->and(PreviouslyOnDraft::query()->count())->toBe(1);
});

// ── publish (one bulletin, stamped, guarded) ────────────

test('publish creates exactly one unpinned bulletin expiring a week after the session and stamps the draft', function () {
    $service = app(PreviouslyOnService::class);
    [$owner, $campaign, $completed, $next] = previouslyOnCampaign();

    previouslyOnRecap($completed, 'the digest recap');

    $draft = $service->upsertUnpublished($campaign, $next);
    $bulletin = $service->publish($draft, $owner);

    expect($next->bulletins()->count())->toBe(1)
        ->and($bulletin->game_id)->toBe($next->id)
        ->and($bulletin->user_id)->toBe($campaign->owner_id)
        ->and($bulletin->content)->toBe($draft->body)
        // Pre-session digest: not pinned — the completion sweep may
        // expire it once the session runs.
        ->and($bulletin->is_pinned)->toBeFalse()
        // Expiry = session date_time + 7 days (± a minute of slop).
        ->and($bulletin->expires_at)->not->toBeNull()
        ->and($bulletin->expires_at->between(
            $next->date_time->copy()->addDays(7)->subMinute(),
            $next->date_time->copy()->addDays(7)->addMinute(),
        ))->toBeTrue();

    $fresh = PreviouslyOnDraft::find($draft->id);
    expect($fresh->published_at)->not->toBeNull()
        ->and($fresh->published_bulletin_id)->toBe($bulletin->id);
});

test('double publish throws and leaves exactly one bulletin', function () {
    $service = app(PreviouslyOnService::class);
    [$owner, $campaign, $completed, $next] = previouslyOnCampaign();

    previouslyOnRecap($completed, 'the digest recap');

    $draft = $service->upsertUnpublished($campaign, $next);
    $service->publish($draft, $owner);

    $second = null;
    try {
        $service->publish($draft, $owner);
        $this->fail('Expected the double publish to be refused.');
    } catch (LogicException $e) {
        $second = $e;
    }

    expect($second->getMessage())->toBe(__('journal.error_draft_already_published', [], 'en'))
        ->and($next->bulletins()->count())->toBe(1);
});

test('a canceled session takes its unpublished draft with it, and publish still refuses non-scheduled sessions', function () {
    $service = app(PreviouslyOnService::class);
    [$owner, $campaign, $completed, $next] = previouslyOnCampaign();

    previouslyOnRecap($completed, 'the digest recap');

    $draft = $service->upsertUnpublished($campaign, $next);

    // D230: the session leaving Scheduled takes its unpublished draft
    // with it (GameObserver hook) — an unpublishable draft cannot
    // linger for the panel to show.
    $next->update(['status' => 'canceled']);

    expect(PreviouslyOnDraft::find($draft->id))->toBeNull()
        ->and($next->bulletins()->count())->toBe(0);

    // Direct service misuse on a hand-crafted draft row for the
    // canceled session must still be refused at publish time.
    $orphan = PreviouslyOnDraft::factory()->create([
        'campaign_id' => $campaign->id,
        'game_id' => $next->id,
        'body' => 'stale draft',
    ]);

    $refusal = null;
    try {
        $service->publish($orphan, $owner);
        $this->fail('Expected the publish to be refused for a canceled session.');
    } catch (LogicException $e) {
        $refusal = $e;
    }

    expect($refusal->getMessage())->toBe(__('journal.error_session_not_publishable', [], 'en'))
        ->and($next->bulletins()->count())->toBe(0)
        ->and($orphan->fresh()->published_at)->toBeNull();
});
