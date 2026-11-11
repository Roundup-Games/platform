<?php

use App\Enums\CharacterStateChangeType;
use App\Enums\GoalStatus;
use App\Enums\JournalEntryKind;
use App\Enums\JournalEntryVisibility;
use App\Enums\ParticipantStatus;
use App\Livewire\Games\GameDetail;
use App\Models\Campaign;
use App\Models\CampaignGoal;
use App\Models\CampaignJournalEntry;
use App\Models\CampaignParticipant;
use App\Models\CharacterStateChange;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

// M071/S01-T04 — session-end journal composer: the write-recap form is
// the campaign-journal composer for campaign sessions (D218/D220: journal
// entry is the recap source of truth, games.recap the synced projection).
// Since M071/S03-T03 (D219) one-shot sessions get the Epilogue bulletin
// instead — no recap form and no column write at all. Copy assertions
// resolve keys through __() so they stay wording-agnostic (locale
// decoupling rule).

beforeEach(function () {
    URL::defaults(['locale' => 'en']);

    $this->owner = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
});

function composerCreateCompletedCampaignSession(User $owner, GameSystem $system, array $overrides = []): Game
{
    $campaign = Campaign::factory()->create(['owner_id' => $owner->id]);

    return Game::factory()->create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'status' => 'completed',
        'campaign_id' => $campaign->id,
        ...$overrides,
    ]);
}

function composerCreateCompletedOneShot(User $owner, GameSystem $system, array $overrides = []): Game
{
    return Game::factory()->create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'status' => 'completed',
        'campaign_id' => null,
        ...$overrides,
    ]);
}

// ── Campaign path: journal composer write ───────────────

test('owner writes a campaign-session recap into the journal via the composer', function () {
    $game = composerCreateCompletedCampaignSession($this->owner, $this->gameSystem);

    Livewire::actingAs($this->owner)
        ->test(GameDetail::class, ['id' => $game->id])
        ->set('recapContent', 'The party uncovered the traitor at last.')
        ->call('writeRecap')
        ->assertHasNoErrors();

    $entry = CampaignJournalEntry::query()->where('game_id', $game->id)->first();

    expect($entry)->not->toBeNull()
        ->and($entry->kind)->toBe(JournalEntryKind::Recap)
        ->and($entry->visibility)->toBe(JournalEntryVisibility::Players)
        ->and($entry->campaign_id)->toBe($game->campaign_id)
        ->and($entry->author_user_id)->toBe($this->owner->id)
        ->and($entry->body)->toBe('The party uncovered the traitor at last.');
});

// ── Composer vs legacy form rendering ───────────────────

test('campaign session page renders the journal composer copy and 10000 limit', function () {
    $game = composerCreateCompletedCampaignSession($this->owner, $this->gameSystem);

    Livewire::actingAs($this->owner)
        ->test(GameDetail::class, ['id' => $game->id])
        ->assertSee(__('journal.title_composer', [], 'en'))
        ->assertSeeHtml('maxlength="10000"')
        ->assertSee('/10000');
});

test('one-shot page renders no recap form since the S03 retirement', function () {
    $game = composerCreateCompletedOneShot($this->owner, $this->gameSystem);

    Livewire::actingAs($this->owner)
        ->test(GameDetail::class, ['id' => $game->id])
        ->assertDontSee(__('journal.title_composer', [], 'en'))
        ->assertDontSeeHtml('id="recap-content"')
        ->assertDontSeeHtml('maxlength="2000"')
        ->assertDontSeeHtml('maxlength="10000"');
});

// ── Authorization and validation guards ─────────────────

test('non-owner approved participant cannot write a journal recap', function () {
    $game = composerCreateCompletedCampaignSession($this->owner, $this->gameSystem);
    $player = User::factory()->create();

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $player->id,
        'status' => ParticipantStatus::Approved,
    ]);

    Livewire::actingAs($player)
        ->test(GameDetail::class, ['id' => $game->id])
        ->set('recapContent', 'Sneaky journal attempt')
        ->call('writeRecap')
        ->assertHasNoErrors();

    expect(CampaignJournalEntry::query()->where('game_id', $game->id)->count())->toBe(0);
});

test('campaign path rejects recaps over 10000 characters', function () {
    $game = composerCreateCompletedCampaignSession($this->owner, $this->gameSystem);

    Livewire::actingAs($this->owner)
        ->test(GameDetail::class, ['id' => $game->id])
        ->set('recapContent', str_repeat('a', 10001))
        ->call('writeRecap')
        ->assertHasErrors(['recapContent' => 'max']);
});

// ── Write-once semantics ────────────────────────────────

test('second writeRecap on a campaign session creates no second entry and flashes an error', function () {
    $game = composerCreateCompletedCampaignSession($this->owner, $this->gameSystem);

    $component = Livewire::actingAs($this->owner)
        ->test(GameDetail::class, ['id' => $game->id]);

    $component->set('recapContent', 'First recap')->call('writeRecap')->assertHasNoErrors();

    $component->set('recapContent', 'Second recap')->call('writeRecap')
        ->assertSee(__('games.error_recap_already_written', [], 'en'));

    expect(CampaignJournalEntry::query()->where('game_id', $game->id)->count())->toBe(1);
});

// ── One-shot boundary: write path retired (D219) ───────

test('one-shot completed game rejects writeRecap with no column write and no journal row', function () {
    $game = composerCreateCompletedOneShot($this->owner, $this->gameSystem);

    Livewire::actingAs($this->owner)
        ->test(GameDetail::class, ['id' => $game->id])
        ->set('recapContent', 'A one-shot tale.')
        ->call('writeRecap')
        ->assertSee(__('journal.error_not_campaign_session', [], 'en'));

    expect(CampaignJournalEntry::query()->count())->toBe(0);
});

// ── Existing recap: display block, no composer ──────────

test('a session that already carries a journal recap shows the display block and no composer', function () {
    $game = composerCreateCompletedCampaignSession($this->owner, $this->gameSystem);

    CampaignJournalEntry::factory()
        ->forCampaignSession($game)
        ->create(['body' => 'The chronicle predates the journal.']);

    Livewire::actingAs($this->owner)
        ->test(GameDetail::class, ['id' => $game->id])
        ->assertSee(__('games.title_host_recap', [], 'en'))
        ->assertDontSee(__('journal.title_composer', [], 'en'));
});

// ── Session-end quick journal widgets (D235, spec §5) ──
//
// The goal-status and character-state inline widgets render for the
// campaign GM on completed campaign sessions only, mirror the Journal
// tab's changeGoalStatus/logStateChange gate (campaign ownership, never
// viewJournal), and stamp THE SESSION being ended so the S04 "goals
// moved" diff attributes the change to it.

/**
 * A completed campaign session whose campaign carries the owner
 * participant row (createCampaignWithOwner) — the widget fixture.
 */
function widgetCompletedSession(string $dateTime = '2026-01-10 18:00:00'): array
{
    ['owner' => $owner, 'campaign' => $campaign] = test()->createCampaignWithOwner();

    $session = Game::factory()->create([
        'owner_id' => $owner->id,
        'game_system_id' => test()->gameSystem->id,
        'status' => 'completed',
        'campaign_id' => $campaign->id,
        'date_time' => new Carbon($dateTime),
    ]);

    return ['owner' => $owner, 'campaign' => $campaign, 'session' => $session];
}

/**
 * The owner's own seated campaign participant row (created by
 * createCampaignWithOwner).
 */
function widgetOwnerParticipant(Campaign $campaign, User $owner): CampaignParticipant
{
    return CampaignParticipant::query()
        ->where('campaign_id', $campaign->id)
        ->where('user_id', $owner->id)
        ->firstOrFail();
}

test('the campaign GM moves a goal from the session-end widget, stamped to THIS session', function () {
    ['owner' => $owner, 'campaign' => $campaign, 'session' => $session] = widgetCompletedSession('2026-01-10 18:00:00');

    // A LATER completed session exists: the tab's fallback stamp would
    // resolve to it — the widget's explicit stamp must win over it.
    Game::factory()->create([
        'owner_id' => $owner->id,
        'game_system_id' => $this->gameSystem->id,
        'status' => 'completed',
        'campaign_id' => $campaign->id,
        'date_time' => new Carbon('2026-02-14 18:00:00'),
    ]);

    $goal = CampaignGoal::factory()->forCampaign($campaign)->create(['title' => 'Free the harbour master']);

    Livewire::actingAs($owner)
        ->test(GameDetail::class, ['id' => $session->id])
        ->set('sessionGoalId', $goal->id)
        ->set('sessionGoalStatus', GoalStatus::Achieved->value)
        ->call('sessionEndChangeGoalStatus')
        ->assertHasNoErrors()
        ->assertSee(__('journal.flash_goal_status_changed', [], 'en'));

    $fresh = CampaignGoal::query()->findOrFail($goal->id);
    expect($fresh->status)->toBe(GoalStatus::Achieved)
        ->and($fresh->status_changed_at_game_id)->toBe($session->id);
});

test('the campaign GM logs their own character state from the session-end widget, stamped to the session', function () {
    ['owner' => $owner, 'campaign' => $campaign, 'session' => $session] = widgetCompletedSession();

    // Another seated character exists — the widget must never offer (or
    // write) it; there is no participant field on the form at all.
    $otherPlayer = CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'role' => 'player',
        'status' => 'approved',
    ]);

    Livewire::actingAs($owner)
        ->test(GameDetail::class, ['id' => $session->id])
        ->set('sessionStateType', CharacterStateChangeType::Item->value)
        ->set('sessionStateDelta', 'Lost the amulet')
        ->call('sessionEndLogStateChange')
        ->assertHasNoErrors()
        ->assertSee(__('journal.flash_state_change_logged', [], 'en'));

    $change = CharacterStateChange::query()->where('campaign_id', $campaign->id)->firstOrFail();
    expect($change->participant_id)->toBe(widgetOwnerParticipant($campaign, $owner)->id)
        ->and($change->participant_id)->not->toBe($otherPlayer->id)
        ->and($change->game_id)->toBe($session->id)
        ->and($change->change_type)->toBe(CharacterStateChangeType::Item)
        ->and($change->delta)->toBe('Lost the amulet');
});

test('an approved player cannot use the session-end journal widgets', function () {
    ['owner' => $owner, 'campaign' => $campaign, 'session' => $session] = widgetCompletedSession();
    $goal = CampaignGoal::factory()->forCampaign($campaign)->create(['title' => 'Guarded']);

    $player = User::factory()->create();
    CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'user_id' => $player->id,
        'role' => 'player',
        'status' => 'approved',
    ]);
    GameParticipant::create([
        'game_id' => $session->id,
        'user_id' => $player->id,
        'status' => ParticipantStatus::Approved,
    ]);

    $component = Livewire::actingAs($player)
        ->test(GameDetail::class, ['id' => $session->id])
        ->assertDontSee(__('journal.title_session_quick_updates', [], 'en'));

    // The render gate is not the boundary — the actions re-check it: a
    // forged widget call must not write (the viewJournal member gate is
    // NOT the write gate; ownership is).
    $component
        ->set('sessionGoalId', $goal->id)
        ->set('sessionGoalStatus', GoalStatus::Failed->value)
        ->call('sessionEndChangeGoalStatus')
        ->assertSee(__('common.error_not_authorized', [], 'en'));

    $component
        ->set('sessionStateType', CharacterStateChangeType::Note->value)
        ->set('sessionStateDelta', 'Sneaky self-log')
        ->call('sessionEndLogStateChange')
        ->assertSee(__('common.error_not_authorized', [], 'en'));

    expect($goal->fresh()->status)->toBe(GoalStatus::Planned)
        ->and(CharacterStateChange::query()->where('campaign_id', $campaign->id)->count())->toBe(0);
});

test('a one-shot session renders neither quick journal widget and refuses forged calls', function () {
    $game = composerCreateCompletedOneShot($this->owner, $this->gameSystem);

    Livewire::actingAs($this->owner)
        ->test(GameDetail::class, ['id' => $game->id])
        ->assertDontSee(__('journal.title_session_quick_updates', [], 'en'))
        ->set('sessionStateType', CharacterStateChangeType::Note->value)
        ->set('sessionStateDelta', 'No campaign')
        ->call('sessionEndLogStateChange')
        ->assertSee(__('common.error_not_authorized', [], 'en'));

    expect(CharacterStateChange::query()->count())->toBe(0);
});

test('the goal widget hides when the campaign has no goals; the state widget still renders', function () {
    ['owner' => $owner, 'campaign' => $campaign, 'session' => $session] = widgetCompletedSession();

    // No goals; the owner still has their own seated participant row, so
    // only the goal widget hides (empty state = hide, the least noisy
    // option — the Journal tab stays the authoring surface).
    Livewire::actingAs($owner)
        ->test(GameDetail::class, ['id' => $session->id])
        ->assertSee(__('journal.title_session_quick_updates', [], 'en'))
        ->assertDontSee(__('journal.label_session_goal_widget', [], 'en'))
        ->assertSee(__('journal.label_session_state_widget', [], 'en'));
});

test('the state widget hides when the GM has no seated character row on the campaign', function () {
    ['owner' => $owner, 'campaign' => $campaign, 'session' => $session] = widgetCompletedSession();
    CampaignGoal::factory()->forCampaign($campaign)->create(['title' => 'Still tracked']);

    // The GM's own participant row is gone (e.g. left the table): the
    // state widget hides, and a forged call is refused at the seam.
    CampaignParticipant::query()
        ->where('campaign_id', $campaign->id)
        ->where('user_id', $owner->id)
        ->delete();

    Livewire::actingAs($owner)
        ->test(GameDetail::class, ['id' => $session->id])
        ->assertSee(__('journal.label_session_goal_widget', [], 'en'))
        ->assertDontSee(__('journal.label_session_state_widget', [], 'en'))
        ->set('sessionStateType', CharacterStateChangeType::Note->value)
        ->set('sessionStateDelta', 'No character row')
        ->call('sessionEndLogStateChange')
        ->assertSee(__('journal.error_participant_not_seated', [], 'en'));

    expect(CharacterStateChange::query()->where('campaign_id', $campaign->id)->count())->toBe(0);
});

test('the whole quick-updates section hides when there is nothing to write', function () {
    ['owner' => $owner, 'campaign' => $campaign, 'session' => $session] = widgetCompletedSession();

    // No goals AND no own character row: nothing to write — the section
    // hides entirely instead of rendering empty shells.
    CampaignParticipant::query()
        ->where('campaign_id', $campaign->id)
        ->where('user_id', $owner->id)
        ->delete();

    Livewire::actingAs($owner)
        ->test(GameDetail::class, ['id' => $session->id])
        ->assertDontSee(__('journal.title_session_quick_updates', [], 'en'));
});
