<?php

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Jobs\PublishGameBulletinToDiscord;
use App\Livewire\Campaigns\CampaignPreviouslyOn;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameBulletin;
use App\Models\GameParticipant;
use App\Models\PreviouslyOnDraft;
use App\Models\User;
use App\Notifications\BulletinPosted;
use App\Notifications\Channels\PushChannel;
use App\Policies\GameBulletinPolicy;
use App\Services\CampaignJournalService;
use App\Services\PreviouslyOnService;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Traits\CreatesUsers;

uses(CreatesUsers::class);

//
// M071/S04-T03 — the GM's Previously On panel: draft-first review and
// one-click publish inside the member-gated Journal tab (D221), the
// D185 member gate for non-member GMs via the D223 seam, and the full
// channel-matrix proof through the UNTOUCHED GameBulletinObserver single
// fan-out (board + per-user notification prefs + Discord teaser). Copy
// assertions are locale-decoupled via __('…', [], 'en') lookups (K004);
// Notification::fake only — never Mail::fake (MEM088).
//

/**
 * A member-owner campaign (the D223 seam passes: active GM role +
 * subscription) with one scheduled upcoming session — the publish
 * target.
 *
 * @return array{0: User, 1: Campaign, 2: Game}
 */
function panelCampaign(): array
{
    $owner = test()->createSubscribedGm();
    $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

    $next = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => 'scheduled',
        'date_time' => now()->addDays(3),
    ]);

    return [$owner, $campaign, $next];
}

/**
 * An unpublished draft on the session with a unique body marker (the
 * factory default body is random text — the marker pins assertions).
 */
function panelDraft(Campaign $campaign, Game $next, string $body = 'PANEL DRAFT the amulet resurfaced in the Emberfall vault'): PreviouslyOnDraft
{
    return PreviouslyOnDraft::factory()
        ->forSession($campaign, $next)
        ->create(['body' => $body]);
}

/**
 * An approved game participant on the session — the observer's
 * BulletinPosted audience.
 */
function panelApprovedPlayer(Game $game): User
{
    $user = User::factory()->create(['profile_complete' => true]);

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $user->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    return $user;
}

test('publishing a draft deleted by a concurrent projection sweep converges on the flash error, not a 500', function () {
    [$owner, $campaign, $next] = panelCampaign();
    $draft = panelDraft($campaign, $next);

    // The D230 projection can delete the row between the panel's
    // latestDraft() resolution and the publish lock — the GameObserver
    // status-leave sweep when AutoCompleteGames finishes the session,
    // or the retarget supersedes-delete. ModelNotFoundException is a
    // RuntimeException and would escape the panel's LogicException
    // catch; the locked re-fetch must converge on the same flash path.
    PreviouslyOnDraft::query()->whereKey($draft->id)->delete();

    expect(fn () => app(PreviouslyOnService::class)->publish($draft, $owner))
        ->toThrow(LogicException::class, __('journal.error_previously_on_not_available', [], 'en'));
});

// ── Draft review surface (D221: never posted sight-unseen) ─────────

test('a member owner with a draft sees the panel, body and publish action on the journal tab', function () {
    [$owner, $campaign, $next] = panelCampaign();
    panelDraft($campaign, $next);

    $this->actingAs($owner)
        ->get(route('campaigns.show', ['id' => $campaign->id, 'tab' => 'journal']))
        ->assertOk()
        ->assertSee(__('journal.title_previously_on_panel', [], 'en'))
        ->assertSee(__('journal.content_previously_on_description', [], 'en'))
        ->assertSee(__('journal.label_previously_on_target_session', [], 'en'))
        ->assertSee(__('journal.action_publish_previously_on', [], 'en'))
        ->assertSee('PANEL DRAFT the amulet resurfaced');
});

// ── One-click publish: exactly one bulletin, full channel matrix ───

test('one publish click creates exactly one bulletin and delivers the composed channel matrix', function () {
    Notification::fake();
    Queue::fake();

    [$owner, $campaign, $next] = panelCampaign();
    $draft = panelDraft($campaign, $next);

    // Default prefs: SessionContent ships database+push+discord, mail OFF
    // (MEM856/940). The second player explicitly opts mail in.
    $defaultPrefPlayer = panelApprovedPlayer($next);

    $mailOptInPlayer = panelApprovedPlayer($next);
    $mailOptInPlayer->forceFill(['notification_settings' => [
        'session_content' => ['mail' => true],
    ]])->save();

    Livewire::actingAs($owner)
        ->test(CampaignPreviouslyOn::class, ['campaign' => $campaign])
        ->call('publish')
        ->assertHasNoErrors()
        ->assertSee(__('journal.flash_previously_on_published', [], 'en'));

    // Exactly one bulletin on the session: postBy authorship (the
    // campaign owner), 7-day expiry off the session's date_time, unpinned.
    $this->assertDatabaseCount('game_bulletins', 1);

    $bulletin = GameBulletin::first();
    expect($bulletin->game_id)->toBe($next->id)
        ->and($bulletin->user_id)->toBe($owner->id)
        ->and($bulletin->content)->toBe($draft->body)
        ->and($bulletin->is_pinned)->toBeFalse()
        ->and($bulletin->expires_at->timestamp)->toBe($next->date_time->copy()->addDays(7)->timestamp);

    // The draft is stamped immutable (published_at + bulletin link).
    $stamped = PreviouslyOnDraft::find($draft->id);
    expect($stamped->published_at)->not->toBeNull()
        ->and($stamped->published_bulletin_id)->toBe($bulletin->id);

    // Untouched observer fan-out: the Discord teaser job dispatched
    // exactly once (retry-idempotency is the job's own concern).
    Queue::assertPushed(PublishGameBulletinToDiscord::class, 1);

    // The composed per-user channel matrix: default prefs carry database
    // + push but never mail; an explicit mail opt-in adds MailChannel.
    Notification::assertSentTo(
        $defaultPrefPlayer,
        BulletinPosted::class,
        fn (BulletinPosted $notification, array $channels): bool => in_array(DatabaseChannel::class, $channels, true)
            && in_array(PushChannel::class, $channels, true)
            && ! in_array(MailChannel::class, $channels, true)
    );

    Notification::assertSentTo(
        $mailOptInPlayer,
        BulletinPosted::class,
        fn (BulletinPosted $notification, array $channels): bool => in_array(MailChannel::class, $channels, true)
    );

    // The author never receives their own digest.
    Notification::assertNotSentTo($owner, BulletinPosted::class);

    // Audience: the published digest is readable on the session board by
    // approved game participants (viewBoard requires Approved — benched
    // campaign players stay outside the bulletin audience; accepted per
    // research, GameBulletinPolicy untouched). The board resolves the
    // policy directly, same as GameBulletinBoard::canViewBoard.
    expect(app(GameBulletinPolicy::class)->viewBoard($defaultPrefPlayer, $next))->toBeTrue();
});

test('after the target session stops being scheduled, the panel falls back to the published confirmation', function () {
    Notification::fake();
    Queue::fake();

    [$owner, $campaign, $next] = panelCampaign();
    panelDraft($campaign, $next);
    panelApprovedPlayer($next);

    Livewire::actingAs($owner)
        ->test(CampaignPreviouslyOn::class, ['campaign' => $campaign])
        ->call('publish')
        ->assertHasNoErrors();

    // The session runs (or is canceled): nextSession() now resolves
    // null, and latestDraft must fall back to the latest PUBLISHED
    // draft — the confirmation line, never an unpublishable draft.
    $next->update(['status' => 'completed']);

    Livewire::actingAs($owner)
        ->test(CampaignPreviouslyOn::class, ['campaign' => $campaign])
        ->assertSee(__('journal.label_previously_on_published_at', [
            'date' => format_date(PreviouslyOnDraft::query()->first()->published_at, 'datetime'),
        ], 'en'))
        ->assertDontSee(__('journal.action_publish_previously_on', [], 'en'));
});

test('a second publish call flashes the already-published error and creates no second bulletin', function () {
    [$owner, $campaign, $next] = panelCampaign();
    panelDraft($campaign, $next);

    $component = Livewire::actingAs($owner)
        ->test(CampaignPreviouslyOn::class, ['campaign' => $campaign]);

    // First click publishes; the panel flips to the published
    // confirmation with the board link.
    $component->call('publish')
        ->assertSee(__('journal.flash_previously_on_published', [], 'en'))
        ->assertSee(route('games.show', $next->id));

    // Second click: the service's lock-guarded exactly-once rule flashes
    // the translated error — still exactly one bulletin.
    $component->call('publish')
        ->assertSee(__('journal.error_draft_already_published', [], 'en'));

    $this->assertDatabaseCount('game_bulletins', 1);
});

// ── The D185 member gate via the D223 seam ─────────────────────────

test('a non-member owner sees the member gate, never the draft, and cannot publish', function () {
    $owner = User::factory()->create(['profile_complete' => true]);
    $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

    $next = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => 'scheduled',
        'date_time' => now()->addDays(3),
    ]);

    // Composed while a member, membership since lapsed — the same policy
    // guard covers the between-compose-and-publish lapse.
    panelDraft($campaign, $next);

    $component = Livewire::actingAs($owner)
        ->test(CampaignPreviouslyOn::class, ['campaign' => $campaign]);

    $component->assertSee(__('journal.title_previously_on_gate', [], 'en'))
        ->assertSee(__('journal.content_previously_on_gate', [], 'en'))
        ->assertSee(__('journal.action_view_membership', [], 'en'))
        ->assertSee(route('membership'))
        ->assertDontSee('PANEL DRAFT the amulet resurfaced')
        ->assertDontSee(__('journal.content_previously_on_description', [], 'en'));

    $component->call('publish')
        ->assertSee(__('journal.error_previously_on_not_available', [], 'en'));

    $this->assertDatabaseMissing('game_bulletins', ['game_id' => $next->id]);
});

// ── Fail-closed visibility for players and empty states ────────────

test('an approved player on the journal tab never sees panel markup or draft content', function () {
    [$owner, $campaign, $next] = panelCampaign();
    panelDraft($campaign, $next);

    $player = User::factory()->create(['profile_complete' => true]);
    CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $player->id,
        'role' => 'player',
        'status' => 'approved',
    ]);

    $this->actingAs($player)
        ->get(route('campaigns.show', ['id' => $campaign->id, 'tab' => 'journal']))
        ->assertOk()
        ->assertDontSee(__('journal.title_previously_on_panel', [], 'en'))
        ->assertDontSee(__('journal.action_publish_previously_on', [], 'en'))
        ->assertDontSee(__('journal.title_previously_on_gate', [], 'en'))
        ->assertDontSee('PANEL DRAFT the amulet resurfaced');
});

test('a member owner without any draft renders nothing — no panel, no gate', function () {
    [$owner, $campaign] = panelCampaign();

    Livewire::actingAs($owner)
        ->test(CampaignPreviouslyOn::class, ['campaign' => $campaign])
        ->assertDontSee(__('journal.title_previously_on_panel', [], 'en'))
        ->assertDontSee(__('journal.action_publish_previously_on', [], 'en'))
        ->assertDontSee(__('journal.title_previously_on_gate', [], 'en'));
});

// ── D230: projection targeting + explicit refresh ───────

test('the panel targets the next scheduled session — an older unpublished draft renders nothing', function () {
    [$owner, $campaign, $later] = panelCampaign();

    // The draft belongs to the LATER session; a SOONER scheduled session
    // exists without a draft yet. The panel targets the sooner session,
    // so the stale later-session draft never masquerades as current.
    Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => 'scheduled',
        'date_time' => now()->addDays(2),
    ]);

    panelDraft($campaign, $later);

    Livewire::actingAs($owner)
        ->test(CampaignPreviouslyOn::class, ['campaign' => $campaign])
        ->assertDontSee('PANEL DRAFT')
        ->assertDontSee(__('journal.action_publish_previously_on', [], 'en'));
});

test('refresh recomposes the draft from journal writes made after the last compose', function () {
    [$owner, $campaign, $next] = panelCampaign();
    $draft = panelDraft($campaign, $next);

    // A late recap lands after composition (the two automatic triggers
    // already ran — session create and, later, the 24h reminder).
    $done = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => 'completed',
        'date_time' => now()->subDay(),
    ]);
    app(CampaignJournalService::class)->writeRecap($done, $owner, 'LATE RECAP the vault door was a decoy all along');

    Livewire::actingAs($owner)
        ->test(CampaignPreviouslyOn::class, ['campaign' => $campaign])
        ->assertSee('PANEL DRAFT')
        ->call('refresh')
        ->assertSee(__('journal.flash_previously_on_refreshed', [], 'en'));

    $fresh = $draft->fresh();

    expect($fresh->body)->toContain('LATE RECAP')
        ->and($fresh->published_at)->toBeNull();
});

test('refresh is a no-op for a non-owner', function () {
    [$owner, $campaign, $next] = panelCampaign();
    $draft = panelDraft($campaign, $next);
    $player = panelApprovedPlayer($next);

    Livewire::actingAs($player)
        ->test(CampaignPreviouslyOn::class, ['campaign' => $campaign])
        ->call('refresh');

    expect($draft->fresh()->body)->toContain('PANEL DRAFT')
        ->and(PreviouslyOnDraft::query()->count())->toBe(1);
});

test('the draft panel states the delivery audience and offers refresh (D230/D231 affordances)', function () {
    [$owner, $campaign, $next] = panelCampaign();
    panelDraft($campaign, $next);

    Livewire::actingAs($owner)
        ->test(CampaignPreviouslyOn::class, ['campaign' => $campaign])
        ->assertSee(__('journal.content_previously_on_audience', [], 'en'))
        ->assertSee(__('journal.action_refresh_previously_on', [], 'en'))
        ->assertSee(__('journal.label_previously_on_composed_at', ['date' => format_date(
            PreviouslyOnDraft::query()->whereBelongsTo($next)->firstOrFail()->updated_at,
            'datetime',
        )], 'en'));
});
