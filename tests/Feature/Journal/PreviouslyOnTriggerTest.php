<?php

use App\Enums\JournalEntryKind;
use App\Livewire\Campaigns\AddSessionToCampaign;
use App\Models\Campaign;
use App\Models\CampaignJournalEntry;
use App\Models\Game;
use App\Models\GameBulletin;
use App\Models\PreviouslyOnDraft;
use App\Models\User;
use App\Services\CampaignJournalService;
use App\Services\PreviouslyOnService;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Traits\CreatesUsers;

uses(CreatesUsers::class);

//
// M071/S04-T02 — the D223 membership seam and both D221 composition
// triggers. Trigger 1: a member GM publishing the next campaign session
// (AddSessionToCampaign::save) composes the draft for that session AFTER
// the creating transaction, never blocking the save. Trigger 2: the 24h
// reminder window refreshes (or first composes) the draft at most once
// per session, never touching published rows; dry-run writes nothing.
// Both triggers and CampaignPolicy::managePreviouslyOn gate on the
// campaign owner's membership through the single D223 seam.
//

/**
 * A campaign owned by a member GM (active GM role + subscription via
 * createSubscribedGm) with one completed, recapped session: the minimum
 * journal fixture whose digest is non-empty. writeRecap is the real S01
 * write path (D220 journal-entry source of truth).
 *
 * @return array{0: User, 1: Campaign, 2: Game}
 */
function previouslyOnTriggerCampaign(string $recapBody = 'The party survived the Emberfall heist.'): array
{
    $owner = test()->createSubscribedGm();
    $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

    $completed = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => 'completed',
        'date_time' => now()->subDay(),
    ]);

    app(CampaignJournalService::class)->writeRecap($completed, $owner, $recapBody);

    return [$owner, $campaign, $completed];
}

/**
 * Add a scheduled session through the real component (trigger 1's
 * surface) and return the created Game. games.name is a JSON (i18n)
 * column, so the created session is located by its distinct date_time
 * ($daysAhead), never by name.
 */
function previouslyOnTriggerAddSession(User $owner, Campaign $campaign, int $daysAhead = 7): Game
{
    $when = now()->addDays($daysAhead)->startOfHour();

    Livewire::actingAs($owner)
        ->test(AddSessionToCampaign::class, ['id' => $campaign->id])
        ->set('name', 'Session '.$daysAhead)
        ->set('date_time', $when->format('Y-m-d\TH:i'))
        ->call('save')
        ->assertHasNoErrors();

    return $campaign->refresh()->sessions()
        ->where('date_time', $when)
        ->firstOrFail();
}

/**
 * A scheduled campaign session inside the 24h reminder window with
 * reminder_24h_sent_at still null.
 */
function previouslyOnTriggerWindowSession(User $owner, Campaign $campaign): Game
{
    return Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => 'scheduled',
        'date_time' => now()->addHours(12),
    ]);
}

// ── Trigger 1: session publish (AddSessionToCampaign::save) ────────

test('a member GM adding a session composes the draft for the chronologically next session', function () {
    [$owner, $campaign] = previouslyOnTriggerCampaign('the heist recap body');

    $session2 = previouslyOnTriggerAddSession($owner, $campaign);

    // The new session IS the next one (no competition): the draft
    // targets it, built from the journal (D221).
    $draft = PreviouslyOnDraft::query()->whereBelongsTo($session2)->first();
    expect($draft)->not->toBeNull()
        ->and($draft->campaign_id)->toBe($campaign->id)
        ->and($draft->body)->toContain('the heist recap body')
        ->and($draft->published_at)->toBeNull();

    // A third session FURTHER OUT (the normal recurrence flow: session 2
    // next week, session 3 next month): the compose targets the NEXT
    // session, so session two's draft survives untouched — the panel's
    // draft (CampaignPreviouslyOn::latestDraft resolves the same
    // chronologically-next session) must never be superseded away by
    // adding a later session. (Regression: composing for the NEW session
    // here used to delete session two's draft and blank the panel until
    // the 24h reminder recomposed it.)
    $session3 = previouslyOnTriggerAddSession($owner, $campaign, 8);

    $draft2 = PreviouslyOnDraft::query()->whereBelongsTo($session2)->first();
    expect(PreviouslyOnDraft::query()->count())->toBe(1)
        ->and($draft2)->not->toBeNull()
        ->and($draft2->id)->toBe($draft->id)
        ->and($draft2->body)->toContain('the heist recap body')
        ->and(PreviouslyOnDraft::query()->whereBelongsTo($session3)->first())->toBeNull();

    // Draft-first (D221): one composed draft, zero bulletins — a board
    // row only ever appears after the GM publishes from the panel.
    expect(GameBulletin::query()->count())->toBe(0);
});

test('adding a session EARLIER than the current next retargets the draft to it', function () {
    [$owner, $campaign] = previouslyOnTriggerCampaign('the heist recap body');

    $sessionNextMonth = previouslyOnTriggerAddSession($owner, $campaign, 30);
    expect(PreviouslyOnDraft::query()->whereBelongsTo($sessionNextMonth)->first())->not->toBeNull();

    // The new session lands BEFORE the old next one: the draft follows
    // the calendar — the replacement session owns the single unpublished
    // draft and the old next session's draft is superseded away.
    $sessionNextWeek = previouslyOnTriggerAddSession($owner, $campaign, 7);

    $retargeted = PreviouslyOnDraft::query()->whereBelongsTo($sessionNextWeek)->first();
    expect(PreviouslyOnDraft::query()->count())->toBe(1)
        ->and($retargeted)->not->toBeNull()
        ->and($retargeted->body)->toContain('the heist recap body')
        ->and(PreviouslyOnDraft::query()->whereBelongsTo($sessionNextMonth)->first())->toBeNull();
});

test('a non-member owner adds the session with no draft, no errors, and the session still lands', function () {
    $owner = User::factory()->create(['profile_complete' => true]);
    $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

    $completed = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => 'completed',
        'date_time' => now()->subDay(),
    ]);
    app(CampaignJournalService::class)->writeRecap($completed, $owner, 'journal exists but membership does not');

    $session = previouslyOnTriggerAddSession($owner, $campaign);

    expect($session->exists())->toBeTrue()
        ->and(PreviouslyOnDraft::query()->count())->toBe(0);
});

test('an empty journal composes nothing — no junk CTA-only drafts', function () {
    $owner = test()->createSubscribedGm();
    $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

    previouslyOnTriggerAddSession($owner, $campaign);

    expect($campaign->sessions()->count())->toBe(1)
        ->and(PreviouslyOnDraft::query()->count())->toBe(0);
});

test('a composition failure never blocks session creation', function () {
    [$owner, $campaign] = previouslyOnTriggerCampaign();

    app()->instance(PreviouslyOnService::class, new class extends PreviouslyOnService
    {
        public function upsertUnpublished(Campaign $campaign, Game $nextSession): ?PreviouslyOnDraft
        {
            throw new RuntimeException('composition exploded');
        }
    });

    Log::spy();

    $session = previouslyOnTriggerAddSession($owner, $campaign);

    expect($session->exists())->toBeTrue()
        ->and(PreviouslyOnDraft::query()->count())->toBe(0);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => $message === 'previously_on.compose_failed');
});

// ── Trigger 2: the 24h reminder window (SendSessionReminders) ──────

test('the 24h window composes a missing draft for a member campaign', function () {
    [$owner, $campaign] = previouslyOnTriggerCampaign();
    $next = previouslyOnTriggerWindowSession($owner, $campaign);

    $this->artisan('pwa:send-session-reminders')->assertSuccessful();

    $draft = PreviouslyOnDraft::query()->whereBelongsTo($next)->first();
    expect($draft)->not->toBeNull()
        ->and($draft->campaign_id)->toBe($campaign->id)
        ->and($draft->body)->toContain('Emberfall heist')
        ->and($next->fresh()->reminder_24h_sent_at)->not->toBeNull();
});

test('the 24h window composes drafts for multiple campaigns with distinct member owners (strict-mode lazy-load regression)', function () {
    // Two campaigns with DISTINCT subscribed owners: the window query
    // then eagerly hydrates two owner rows, which is what stamps
    // preventsLazyLoading (multi-row hydration only, MEM1310) and made
    // the D223 membership gate's subscribed() property access throw a
    // LazyLoadingViolationException — swallowed by the hook as
    // reminder_refresh_failed warnings with zero drafts composed.
    [$ownerA, $campaignA] = previouslyOnTriggerCampaign('emberfall heist recap body');
    [$ownerB, $campaignB] = previouslyOnTriggerCampaign('lighthouse mystery recap body');

    $nextA = previouslyOnTriggerWindowSession($ownerA, $campaignA);
    $nextB = previouslyOnTriggerWindowSession($ownerB, $campaignB);

    $this->artisan('pwa:send-session-reminders')->assertSuccessful();

    expect(PreviouslyOnDraft::query()->count())->toBe(2)
        ->and(PreviouslyOnDraft::query()->whereBelongsTo($nextA)->first()?->body)->toContain('emberfall heist recap body')
        ->and(PreviouslyOnDraft::query()->whereBelongsTo($nextB)->first()?->body)->toContain('lighthouse mystery recap body')
        ->and($nextA->fresh()->reminder_24h_sent_at)->not->toBeNull()
        ->and($nextB->fresh()->reminder_24h_sent_at)->not->toBeNull();
});

test('the 24h window refreshes an unpublished draft when the journal moved on', function () {
    [$owner, $campaign, $completed] = previouslyOnTriggerCampaign('first composition');
    $next = previouslyOnTriggerWindowSession($owner, $campaign);

    $draft = app(PreviouslyOnService::class)->upsertUnpublished($campaign, $next);
    expect($draft->body)->toContain('first composition');

    // Newer recap on a newer completed session after the first compose
    // (one recap per session — S01's partial unique index).
    $newer = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => 'completed',
        'date_time' => now(),
    ]);
    CampaignJournalEntry::factory()->forCampaignSession($newer)->create([
        'kind' => JournalEntryKind::Recap,
        'body' => 'second composition',
        'created_at' => now()->addMinutes(5),
    ]);

    $this->artisan('pwa:send-session-reminders')->assertSuccessful();

    $fresh = PreviouslyOnDraft::find($draft->id);
    expect($fresh->body)->toContain('second composition')
        ->and($fresh->body)->not->toContain('first composition')
        ->and($fresh->published_at)->toBeNull()
        ->and(PreviouslyOnDraft::query()->count())->toBe(1);
});

test('the 24h window never overwrites a published draft', function () {
    $service = app(PreviouslyOnService::class);
    [$owner, $campaign] = previouslyOnTriggerCampaign('frozen composition');
    $next = previouslyOnTriggerWindowSession($owner, $campaign);

    $draft = $service->upsertUnpublished($campaign, $next);
    $service->publish($draft, $owner);

    // Journal moves on after the GM already published.
    $newer = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => 'completed',
        'date_time' => now(),
    ]);
    CampaignJournalEntry::factory()->forCampaignSession($newer)->create([
        'kind' => JournalEntryKind::Recap,
        'body' => 'post-publish composition',
        'created_at' => now()->addMinutes(10),
    ]);

    $this->artisan('pwa:send-session-reminders')->assertSuccessful();

    $fresh = PreviouslyOnDraft::find($draft->id);
    expect($fresh->body)->toContain('frozen composition')
        ->and($fresh->body)->not->toContain('post-publish composition')
        ->and($fresh->published_at)->not->toBeNull()
        ->and($fresh->published_bulletin_id)->not->toBeNull();
});

test('dry-run composes no drafts and stamps nothing', function () {
    [$owner, $campaign] = previouslyOnTriggerCampaign();
    $next = previouslyOnTriggerWindowSession($owner, $campaign);

    $this->artisan('pwa:send-session-reminders --dry-run')->assertSuccessful();

    expect(PreviouslyOnDraft::query()->count())->toBe(0)
        ->and($next->fresh()->reminder_24h_sent_at)->toBeNull();
});

test('a non-campaign game in the window composes no draft', function () {
    $owner = test()->createSubscribedGm();

    Game::factory()->create([
        'owner_id' => $owner->id,
        'campaign_id' => null,
        'status' => 'scheduled',
        'date_time' => now()->addHours(12),
    ]);

    $this->artisan('pwa:send-session-reminders')->assertSuccessful();

    expect(PreviouslyOnDraft::query()->count())->toBe(0);
});

test('the 24h window composes nothing for a non-member campaign owner', function () {
    $owner = User::factory()->create(['profile_complete' => true]);
    $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

    $completed = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => 'completed',
        'date_time' => now()->subDay(),
    ]);
    app(CampaignJournalService::class)->writeRecap($completed, $owner, 'journaled but not a member');

    $next = previouslyOnTriggerWindowSession($owner, $campaign);

    $this->artisan('pwa:send-session-reminders')->assertSuccessful();

    // No draft — but the reminder itself is unaffected by the gate.
    expect(PreviouslyOnDraft::query()->count())->toBe(0)
        ->and($next->fresh()->reminder_24h_sent_at)->not->toBeNull();
});

test('reminder_24h_sent_at stamps exactly once — the refresh runs at most once per session', function () {
    [$owner, $campaign] = previouslyOnTriggerCampaign('first composition');
    $next = previouslyOnTriggerWindowSession($owner, $campaign);

    $this->artisan('pwa:send-session-reminders')->assertSuccessful();

    $stampedAt = $next->fresh()->reminder_24h_sent_at;
    expect($stampedAt)->not->toBeNull();

    // The journal moves on, but the stamped game is skipped entirely on
    // the second run — its draft body stays at the first refresh.
    $newer = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => 'completed',
        'date_time' => now(),
    ]);
    CampaignJournalEntry::factory()->forCampaignSession($newer)->create([
        'kind' => JournalEntryKind::Recap,
        'body' => 'second composition',
        'created_at' => now()->addMinutes(5),
    ]);

    $this->artisan('pwa:send-session-reminders')
        ->assertSuccessful()
        ->expectsOutputToContain('[24-hour] Found 0 game(s)');

    expect($next->fresh()->reminder_24h_sent_at->timestamp)->toBe($stampedAt->timestamp)
        ->and(PreviouslyOnDraft::query()->whereBelongsTo($next)->first()->body)->toContain('first composition')
        ->and(PreviouslyOnDraft::query()->whereBelongsTo($next)->first()->body)->not->toContain('second composition');
});

test('a refresh failure is logged and never fails the reminder run', function () {
    [$owner, $campaign] = previouslyOnTriggerCampaign();
    $next = previouslyOnTriggerWindowSession($owner, $campaign);

    app()->instance(PreviouslyOnService::class, new class extends PreviouslyOnService
    {
        public function upsertUnpublished(Campaign $campaign, Game $nextSession): ?PreviouslyOnDraft
        {
            throw new RuntimeException('refresh exploded');
        }
    });

    Log::spy();

    $this->artisan('pwa:send-session-reminders')->assertSuccessful();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => $message === 'previously_on.reminder_refresh_failed');

    // The reminder stamp still happened — composition is not load-bearing.
    expect($next->fresh()->reminder_24h_sent_at)->not->toBeNull();
});

// ── managePreviouslyOn policy matrix (D185 gate via D223) ──────────

test('managePreviouslyOn admits only member campaign owners and global admins', function () {
    [$owner, $campaign] = previouslyOnTriggerCampaign();

    $nonMemberOwner = User::factory()->create(['profile_complete' => true]);
    $nonMemberCampaign = Campaign::factory()->create(['owner_id' => $nonMemberOwner->id]);

    $memberNonOwner = test()->createSubscribedGm();

    expect($owner->can('managePreviouslyOn', $campaign))->toBeTrue()
        ->and($nonMemberOwner->can('managePreviouslyOn', $nonMemberCampaign))->toBeFalse()
        ->and($memberNonOwner->can('managePreviouslyOn', $campaign))->toBeFalse();

    seedRoles();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $admin = User::factory()->create(['profile_complete' => true]);
    $admin->assignRole('Platform Admin');

    expect($admin->can('managePreviouslyOn', $campaign))->toBeTrue();
});

// ── D230: the draft is a projection of its session ──────

test('a session date edit recomposes the unpublished draft in place', function () {
    app()->setLocale('en');
    [$owner, $campaign, $completed] = previouslyOnTriggerCampaign();
    $session = previouslyOnTriggerAddSession($owner, $campaign, 7);

    $draft = PreviouslyOnDraft::query()->whereBelongsTo($session)->firstOrFail();
    $oldWhen = $session->date_time;
    expect($draft->body)->toContain(format_date($oldWhen, 'weekday_datetime'));

    // The GM reschedules; the draft (whose CTA carries that date) is a
    // projection and recomposes with the save — no staleness window.
    $newWhen = now()->addDays(9)->startOfHour();
    $session->update(['date_time' => $newWhen]);

    $fresh = $draft->fresh();

    expect($fresh)->not->toBeNull()
        ->and($fresh->id)->toBe($draft->id)
        ->and($fresh->body)->toContain(format_date($newWhen, 'weekday_datetime'))
        ->and($fresh->body)->not->toContain(format_date($oldWhen, 'weekday_datetime'))
        ->and($fresh->published_at)->toBeNull();
});

test('a venue-details-only edit (the location jsonb, no FK change) recomposes the draft', function () {
    app()->setLocale('en');
    [$owner, $campaign, $completed] = previouslyOnTriggerCampaign();
    $session = previouslyOnTriggerAddSession($owner, $campaign, 7);
    $session->update(['location' => ['details' => 'Old side entrance']]);

    $draft = PreviouslyOnDraft::query()->whereBelongsTo($session)->firstOrFail();
    expect($draft->body)->toContain('Old side entrance');

    // The GM corrects the address without switching location_id — the
    // jsonb-only change still recomposes (wasChanged covers 'location').
    $session->update(['location' => ['details' => 'New main entrance']]);

    $fresh = $draft->fresh();

    expect($fresh->id)->toBe($draft->id)
        ->and($fresh->body)->toContain('New main entrance')
        ->and($fresh->body)->not->toContain('Old side entrance');
});

test('a reorder edit retargets the draft when a sibling moves ahead of the draft holder', function () {
    app()->setLocale('en');
    [$owner, $campaign, $completed] = previouslyOnTriggerCampaign();
    $a = previouslyOnTriggerAddSession($owner, $campaign, 7);  // next, holds the draft
    $b = previouslyOnTriggerAddSession($owner, $campaign, 14); // later sibling

    expect(PreviouslyOnDraft::query()->whereBelongsTo($campaign)->whereNull('published_at')->count())->toBe(1);

    // The GM moves B BEFORE A: B is now the next session. B has no
    // draft of its own, but A's stale draft must be superseded by a
    // fresh compose for the new head — not left stranded invisible
    // (the panel targets the new head and neither refresh nor publish
    // can recover from a draft for a non-next session).
    $b->update(['date_time' => now()->addDays(3)->startOfHour()]);

    $unpublished = PreviouslyOnDraft::query()
        ->whereBelongsTo($campaign)
        ->whereNull('published_at')
        ->get();

    expect($unpublished)->toHaveCount(1)
        ->and($unpublished->first()->game_id)->toBe($b->id)
        ->and($unpublished->first()->body)->toContain(format_date($b->fresh()->date_time, 'weekday_datetime'));
});

test('a reorder edit supersedes the draft when its own holder moves behind a sibling', function () {
    app()->setLocale('en');
    [$owner, $campaign, $completed] = previouslyOnTriggerCampaign();
    $a = previouslyOnTriggerAddSession($owner, $campaign, 7);  // next, holds the draft
    $b = previouslyOnTriggerAddSession($owner, $campaign, 14); // later sibling

    // The GM pushes A (the draft holder) AFTER B: the campaign's
    // unpublished draft must follow the new head (B), superseding A's.
    $a->update(['date_time' => now()->addDays(21)->startOfHour()]);

    $unpublished = PreviouslyOnDraft::query()
        ->whereBelongsTo($campaign)
        ->whereNull('published_at')
        ->get();

    expect($unpublished)->toHaveCount(1)
        ->and($unpublished->first()->game_id)->toBe($b->id);
});

test('an unrelated edit of a non-next session never composes or retargets the draft', function () {
    app()->setLocale('en');
    [$owner, $campaign, $completed] = previouslyOnTriggerCampaign();
    $a = previouslyOnTriggerAddSession($owner, $campaign, 7);  // next, holds the draft
    $b = previouslyOnTriggerAddSession($owner, $campaign, 14); // later sibling

    $draft = PreviouslyOnDraft::query()->whereBelongsTo($campaign)->whereNull('published_at')->firstOrFail();

    // B stays behind A — the edit changes nothing about which session
    // is next, so no draft is composed for B and A's draft is left
    // untouched (composition stays owned by the product triggers).
    $b->update(['date_time' => now()->addDays(15)->startOfHour()]);

    $fresh = $draft->fresh();

    expect($fresh)->not->toBeNull()
        ->and($fresh->id)->toBe($draft->id)
        ->and($fresh->game_id)->toBe($a->id)
        ->and(PreviouslyOnDraft::query()->whereBelongsTo($campaign)->whereNull('published_at')->count())->toBe(1);
});

test('a composition failure never blocks the session save (observer isolation)', function () {
    [$owner, $campaign, $completed] = previouslyOnTriggerCampaign();
    $session = previouslyOnTriggerAddSession($owner, $campaign, 7);

    $this->partialMock(PreviouslyOnService::class)
        ->shouldReceive('upsertUnpublished')
        ->once()
        ->andThrow(new RuntimeException('composer exploded'));

    $movedTo = now()->addDays(10)->startOfHour();
    $session->update(['date_time' => $movedTo]);

    // The AddSessionToCampaign isolation precedent: the recompose throw
    // is logged and swallowed — the reschedule itself lands.
    expect($session->fresh()->date_time->toDateTimeString())
        ->toBe($movedTo->toDateTimeString());
});
