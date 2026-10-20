<?php

use App\Livewire\Campaigns\CampaignJournal;
use App\Models\Campaign;
use App\Models\CampaignGoal;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

//
// M071/S02-T04 — the Journal tab's goal board and its GM authoring
// forms. The board groups by status in the fixed order planned →
// active → achieved → failed → abandoned with sort ordering inside
// groups; players get a read-only board (no controls, no writes even
// when actions are called directly). String assertions are
// locale-decoupled via __('…', [], 'en') lookups (K004).
//

/**
 * A campaign with its owner (+ owner participant row).
 *
 * @return array{0: User, 1: Campaign}
 */
function goalBoardCampaign(): array
{
    ['owner' => $owner, 'campaign' => $campaign] = test()->createCampaignWithOwner();

    return [$owner, $campaign];
}

/**
 * An approved player participant in the campaign.
 *
 * @return array{0: User, 1: CampaignParticipant}
 */
function goalBoardApprovedPlayer(Campaign $campaign): array
{
    $participant = CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'user_id' => User::factory()->create(['profile_complete' => true])->id,
        'role' => 'player',
        'status' => 'approved',
    ]);

    return [$participant->user, $participant];
}

/**
 * A completed campaign session at the given date — the fixture behind
 * the changeGoalStatus latest-completed-session fallback stamp.
 */
function goalBoardCompletedSession(Campaign $campaign, User $owner, string $dateTime): Game
{
    return Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => 'completed',
        'date_time' => new Carbon($dateTime),
    ]);
}

// ── Rendering: grouping, fixed order, intra-group sort ──

test('the board renders goals under status headings in the fixed board order', function () {
    [$owner, $campaign] = goalBoardCampaign();
    [$player] = goalBoardApprovedPlayer($campaign);

    CampaignGoal::factory()->forCampaign($campaign)->create(['title' => 'GOALBOARD PLANNED Defeat the harbormaster', 'status' => 'planned', 'sort' => 1]);
    CampaignGoal::factory()->forCampaign($campaign)->create(['title' => 'GOALBOARD ACTIVE Lift the curse', 'status' => 'active', 'sort' => 1]);
    CampaignGoal::factory()->forCampaign($campaign)->create(['title' => 'GOALBOARD ACHIEVED Find the amulet', 'status' => 'achieved', 'sort' => 1]);

    // Player view: no per-goal selects, so group headings are the only
    // status labels in the DOM — the fixed order and the empty-group
    // suppression are provable here without select-option noise.
    Livewire::actingAs($player)->test(CampaignJournal::class, ['campaign' => $campaign])
        ->assertSee(__('journal.heading_goals', [], 'en'))
        ->assertSee('GOALBOARD PLANNED Defeat the harbormaster')
        ->assertSee('GOALBOARD ACTIVE Lift the curse')
        ->assertSee('GOALBOARD ACHIEVED Find the amulet')
        ->assertSeeInOrder([
            __('journal.status_planned', [], 'en'),
            __('journal.status_active', [], 'en'),
            __('journal.status_achieved', [], 'en'),
        ])
        // Empty groups never render — no "Failed" section padding.
        ->assertDontSee(__('journal.status_failed', [], 'en'))
        ->assertDontSee(__('journal.status_abandoned', [], 'en'));
});

test('goals inside a group render in sort order, not creation order', function () {
    [$owner, $campaign] = goalBoardCampaign();
    [$player] = goalBoardApprovedPlayer($campaign);

    // Created in reverse: sort, not id, must drive the board order.
    CampaignGoal::factory()->forCampaign($campaign)->create(['title' => 'GOALBOARD SORT SECOND', 'status' => 'planned', 'sort' => 2]);
    CampaignGoal::factory()->forCampaign($campaign)->create(['title' => 'GOALBOARD SORT FIRST', 'status' => 'planned', 'sort' => 1]);

    Livewire::actingAs($player)->test(CampaignJournal::class, ['campaign' => $campaign])
        ->assertSeeInOrder(['GOALBOARD SORT FIRST', 'GOALBOARD SORT SECOND']);
});

test('an empty board renders the empty state instead of groups', function () {
    [$owner, $campaign] = goalBoardCampaign();

    Livewire::actingAs($owner)->test(CampaignJournal::class, ['campaign' => $campaign])
        ->assertSee(__('journal.heading_goals', [], 'en'))
        ->assertSee(__('journal.empty_goals', [], 'en'))
        // No goals means no groups — and no per-goal selects either.
        ->assertDontSee(__('journal.status_planned', [], 'en'))
        ->assertDontSee(__('journal.status_active', [], 'en'));
});

// ── GM authoring: create + status change via the forms ──

test('the GM creates a goal through the form action and the board rerenders', function () {
    [$owner, $campaign] = goalBoardCampaign();

    $component = Livewire::actingAs($owner)->test(CampaignJournal::class, ['campaign' => $campaign]);

    $component
        ->set('goalTitle', 'GOALBOARD NEW Recover the ship logs')
        ->set('goalDescription', 'Before the harbormaster burns them.')
        ->call('createGoal')
        ->assertSee(__('journal.flash_goal_created', [], 'en'))
        // Board rerender: the new goal lands in the planned group.
        ->assertSee('GOALBOARD NEW Recover the ship logs')
        ->assertSee(__('journal.status_planned', [], 'en'));

    $this->assertDatabaseHas('campaign_goals', [
        'campaign_id' => $campaign->id,
        'title' => 'GOALBOARD NEW Recover the ship logs',
        'description' => 'Before the harbormaster burns them.',
        'status' => 'planned',
        'sort' => 1,
        'status_changed_at_game_id' => null,
    ]);

    // Form props reset on the SAME instance after a successful write.
    expect($component->get('goalTitle'))->toBe('')
        ->and($component->get('goalDescription'))->toBe('');
});

test('the GM changes a goal status through the per-goal control and the moving session is stamped', function () {
    [$owner, $campaign] = goalBoardCampaign();

    goalBoardCompletedSession($campaign, $owner, '2026-10-05 18:00:00');
    $later = goalBoardCompletedSession($campaign, $owner, '2026-10-12 18:00:00');

    $goal = CampaignGoal::factory()->forCampaign($campaign)->create(['title' => 'GOALBOARD MOVE Escort the caravan', 'status' => 'planned', 'sort' => 1]);

    Livewire::actingAs($owner)->test(CampaignJournal::class, ['campaign' => $campaign])
        ->set("goalStatuses.{$goal->id}", 'achieved')
        ->call('changeGoalStatus', $goal->id)
        ->assertSee(__('journal.flash_goal_status_changed', [], 'en'));

    // No explicit session was passed: the fallback stamps the campaign's
    // LATEST completed session — the S04 Previously On diff source.
    $this->assertDatabaseHas('campaign_goals', [
        'id' => $goal->id,
        'status' => 'achieved',
        'status_changed_at_game_id' => $later->id,
    ]);
});

test('an empty goal title is flashed as an error and nothing is written', function () {
    [$owner, $campaign] = goalBoardCampaign();

    Livewire::actingAs($owner)->test(CampaignJournal::class, ['campaign' => $campaign])
        ->set('goalTitle', '   ')
        ->call('createGoal')
        ->assertSee(__('journal.error_title_required', [], 'en'));

    $this->assertDatabaseMissing('campaign_goals', ['campaign_id' => $campaign->id]);
});

// ── Player: read-only board, direct action calls rejected ──

test('a player sees the board read-only with no authoring controls', function () {
    [$owner, $campaign] = goalBoardCampaign();
    [$player] = goalBoardApprovedPlayer($campaign);

    CampaignGoal::factory()->forCampaign($campaign)->create(['title' => 'GOALBOARD PLAYER VIEW Find the amulet', 'status' => 'active', 'sort' => 1]);

    Livewire::actingAs($player)->test(CampaignJournal::class, ['campaign' => $campaign])
        ->assertSee('GOALBOARD PLAYER VIEW Find the amulet')
        ->assertSee(__('journal.status_active', [], 'en'))
        // No goal authoring controls: no status-change select/submit, no
        // add-goal form.
        ->assertDontSee(__('journal.action_change_status', [], 'en'))
        ->assertDontSee(__('journal.action_add_goal', [], 'en'))
        ->assertDontSee(__('journal.label_goal_title', [], 'en'));
});

test('a player calling createGoal or changeGoalStatus directly is flashed unauthorized and nothing is written', function () {
    [$owner, $campaign] = goalBoardCampaign();
    [$player] = goalBoardApprovedPlayer($campaign);

    $goal = CampaignGoal::factory()->forCampaign($campaign)->create(['title' => 'GOALBOARD GUARD Break the siege', 'status' => 'planned', 'sort' => 1]);

    Livewire::actingAs($player)->test(CampaignJournal::class, ['campaign' => $campaign])
        ->set('goalTitle', 'GOALBOARD FORGED Escape the guard')
        ->call('createGoal')
        ->assertSee(__('common.error_not_authorized', [], 'en'));

    $this->assertDatabaseMissing('campaign_goals', ['title' => 'GOALBOARD FORGED Escape the guard']);

    Livewire::actingAs($player)->test(CampaignJournal::class, ['campaign' => $campaign])
        ->set("goalStatuses.{$goal->id}", 'achieved')
        ->call('changeGoalStatus', $goal->id)
        ->assertSee(__('common.error_not_authorized', [], 'en'));

    $this->assertDatabaseHas('campaign_goals', [
        'id' => $goal->id,
        'status' => 'planned',
        'status_changed_at_game_id' => null,
    ]);
});
