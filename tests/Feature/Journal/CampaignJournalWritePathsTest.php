<?php

use App\Enums\CharacterStateChangeType;
use App\Enums\GoalStatus;
use App\Enums\JournalEntryKind;
use App\Enums\JournalEntryVisibility;
use App\Models\Campaign;
use App\Models\CampaignGoal;
use App\Models\CampaignJournalEntry;
use App\Models\CampaignParticipant;
use App\Models\CharacterStateChange;
use App\Models\Game;
use App\Models\User;
use App\Services\CampaignJournalService;
use Illuminate\Support\Carbon;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

// M071/S02-T02 — CampaignJournalService write paths beyond kind=recap:
// in-character entries (any active participant), GM prep notes (gms
// visibility — a security contract pinned here), campaign goals with
// per-campaign sort, goal status changes stamped with the moving session
// (the S04 Previously On diff source), and GM-logged character state
// changes. Guards are translated LogicExceptions thrown BEFORE any write.

/**
 * A campaign with its owner (+ the owner participant row from
 * createCampaignWithOwner): the canonical fixture for the write paths.
 *
 * @return array{0: User, 1: Campaign}
 */
function journalWriteCampaign(): array
{
    ['owner' => $owner, 'campaign' => $campaign] = test()->createCampaignWithOwner();

    return [$owner, $campaign];
}

/**
 * An approved player participant in the given campaign.
 */
function journalApprovedParticipant(Campaign $campaign): CampaignParticipant
{
    return CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'role' => 'player',
        'status' => 'approved',
    ]);
}

/**
 * The owner's own participant row (created by createCampaignWithOwner).
 */
function journalOwnerParticipant(Campaign $campaign, User $owner): CampaignParticipant
{
    return CampaignParticipant::query()
        ->where('campaign_id', $campaign->id)
        ->where('user_id', $owner->id)
        ->firstOrFail();
}

/**
 * A completed campaign session at the given date — the fixture behind
 * the changeGoalStatus latest-completed-session fallback.
 */
function journalCompletedSession(Campaign $campaign, User $owner, string $dateTime): Game
{
    return Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => 'completed',
        'date_time' => new Carbon($dateTime),
    ]);
}

// ── writeInCharacter ────────────────────────────────────

test('writeInCharacter creates a players-visible interlude entry for an approved participant', function () {
    [$owner, $campaign] = journalWriteCampaign();
    $player = journalApprovedParticipant($campaign);

    $entry = app(CampaignJournalService::class)
        ->writeInCharacter($campaign, $player, 'Ti writes home about the amulet.', '9. Flockentag, 1372 Dal');

    $fresh = CampaignJournalEntry::query()->findOrFail($entry->id);
    expect($fresh->campaign_id)->toBe($campaign->id)
        ->and($fresh->game_id)->toBeNull()
        ->and($fresh->author_user_id)->toBe($player->user_id)
        ->and($fresh->author_participant_id)->toBe($player->id)
        ->and($fresh->kind)->toBe(JournalEntryKind::InCharacter)
        ->and($fresh->visibility)->toBe(JournalEntryVisibility::Players)
        ->and($fresh->body)->toBe('Ti writes home about the amulet.')
        ->and($fresh->in_game_date)->toBe('9. Flockentag, 1372 Dal');
});

test('writeInCharacter accepts the owner participant row and a benched participant', function () {
    [$owner, $campaign] = journalWriteCampaign();

    $ownerEntry = app(CampaignJournalService::class)
        ->writeInCharacter($campaign, journalOwnerParticipant($campaign, $owner), 'The GM also plays.');

    $benched = CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'role' => 'player',
        'status' => 'benched',
    ]);
    $benchedEntry = app(CampaignJournalService::class)
        ->writeInCharacter($campaign, $benched, 'Watching from the bench.');

    expect($ownerEntry->kind)->toBe(JournalEntryKind::InCharacter)
        ->and($benchedEntry->author_participant_id)->toBe($benched->id);
});

test('writeInCharacter rejects a participant from another campaign', function () {
    [, $campaign] = journalWriteCampaign();
    $stranger = CampaignParticipant::factory()->create();

    try {
        app(CampaignJournalService::class)->writeInCharacter($campaign, $stranger, 'Crash the table.');
        $this->fail('Expected LogicException for a non-participant author.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('journal.error_not_participant'));
    }

    expect(CampaignJournalEntry::query()->where('campaign_id', $campaign->id)->count())->toBe(0);
});

test('writeInCharacter rejects a pending participant', function () {
    [, $campaign] = journalWriteCampaign();
    $pending = CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'role' => 'applicant',
        'status' => 'pending',
    ]);

    try {
        app(CampaignJournalService::class)->writeInCharacter($campaign, $pending, 'Too eager.');
        $this->fail('Expected LogicException for a pending author.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('journal.error_participant_not_active'));
    }

    expect(CampaignJournalEntry::query()->where('campaign_id', $campaign->id)->count())->toBe(0);
});

test('writeInCharacter rejects a waitlisted participant', function () {
    [, $campaign] = journalWriteCampaign();
    $waitlisted = CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'role' => 'player',
        'status' => 'waitlisted',
    ]);

    try {
        app(CampaignJournalService::class)->writeInCharacter($campaign, $waitlisted, 'From the queue.');
        $this->fail('Expected LogicException for a waitlisted author.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('journal.error_participant_not_active'));
    }

    expect(CampaignJournalEntry::query()->where('campaign_id', $campaign->id)->count())->toBe(0);
});

test('writeInCharacter rejects an empty body', function () {
    [, $campaign] = journalWriteCampaign();
    $player = journalApprovedParticipant($campaign);

    try {
        app(CampaignJournalService::class)->writeInCharacter($campaign, $player, "  \n\t ");
        $this->fail('Expected LogicException for an empty body.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('journal.error_body_empty'));
    }

    expect(CampaignJournalEntry::query()->where('campaign_id', $campaign->id)->count())->toBe(0);
});

test('writeInCharacter rejects a body over 10000 characters', function () {
    [, $campaign] = journalWriteCampaign();
    $player = journalApprovedParticipant($campaign);

    try {
        app(CampaignJournalService::class)->writeInCharacter($campaign, $player, str_repeat('a', 10001));
        $this->fail('Expected LogicException for an over-long body.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('journal.error_body_too_long'));
    }

    expect(CampaignJournalEntry::query()->where('campaign_id', $campaign->id)->count())->toBe(0);
});

test('writeInCharacter rejects an in-game date over 100 characters', function () {
    [, $campaign] = journalWriteCampaign();
    $player = journalApprovedParticipant($campaign);

    try {
        app(CampaignJournalService::class)->writeInCharacter($campaign, $player, 'Body.', str_repeat('d', 101));
        $this->fail('Expected LogicException for an over-long in-game date.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('journal.error_in_game_date_too_long'));
    }

    expect(CampaignJournalEntry::query()->where('campaign_id', $campaign->id)->count())->toBe(0);
});

// ── writePrepNote ───────────────────────────────────────

test('writePrepNote creates a gms-visible interlude entry for the owner', function () {
    [$owner, $campaign] = journalWriteCampaign();

    $entry = app(CampaignJournalService::class)
        ->writePrepNote($campaign, $owner, 'The traitor is the harbormaster.', 'Day 12');

    $fresh = CampaignJournalEntry::query()->findOrFail($entry->id);
    // The gms default IS the security contract: prep notes are invisible
    // to players in every filter. Pinned on the persisted row.
    expect($fresh->campaign_id)->toBe($campaign->id)
        ->and($fresh->game_id)->toBeNull()
        ->and($fresh->author_user_id)->toBe($owner->id)
        ->and($fresh->author_participant_id)->toBeNull()
        ->and($fresh->kind)->toBe(JournalEntryKind::PrepNote)
        ->and($fresh->visibility)->toBe(JournalEntryVisibility::Gms)
        ->and($fresh->body)->toBe('The traitor is the harbormaster.')
        ->and($fresh->in_game_date)->toBe('Day 12');
});

test('writePrepNote rejects a non-owner author', function () {
    [$owner, $campaign] = journalWriteCampaign();
    $stranger = User::factory()->create();

    try {
        app(CampaignJournalService::class)->writePrepNote($campaign, $stranger, 'Sneaky prep.');
        $this->fail('Expected LogicException for a non-owner author.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('journal.error_not_campaign_owner'));
    }

    expect(CampaignJournalEntry::query()->where('campaign_id', $campaign->id)->count())->toBe(0);
});

test('writePrepNote rejects an empty body', function () {
    [$owner, $campaign] = journalWriteCampaign();

    try {
        app(CampaignJournalService::class)->writePrepNote($campaign, $owner, '   ');
        $this->fail('Expected LogicException for an empty body.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('journal.error_body_empty'));
    }

    expect(CampaignJournalEntry::query()->where('campaign_id', $campaign->id)->count())->toBe(0);
});

test('writePrepNote rejects a body over 10000 characters', function () {
    [$owner, $campaign] = journalWriteCampaign();

    try {
        app(CampaignJournalService::class)->writePrepNote($campaign, $owner, str_repeat('a', 10001));
        $this->fail('Expected LogicException for an over-long body.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('journal.error_body_too_long'));
    }

    expect(CampaignJournalEntry::query()->where('campaign_id', $campaign->id)->count())->toBe(0);
});

test('writePrepNote rejects an in-game date over 100 characters', function () {
    [$owner, $campaign] = journalWriteCampaign();

    try {
        app(CampaignJournalService::class)->writePrepNote($campaign, $owner, 'Body.', str_repeat('d', 101));
        $this->fail('Expected LogicException for an over-long in-game date.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('journal.error_in_game_date_too_long'));
    }

    expect(CampaignJournalEntry::query()->where('campaign_id', $campaign->id)->count())->toBe(0);
});

// ── createGoal ──────────────────────────────────────────

test('createGoal creates a planned goal with sort 1 by default', function () {
    [$owner, $campaign] = journalWriteCampaign();

    $goal = app(CampaignJournalService::class)->createGoal($campaign, $owner, 'Break the smuggler ring');

    $fresh = CampaignGoal::query()->findOrFail($goal->id);
    expect($fresh->campaign_id)->toBe($campaign->id)
        ->and($fresh->title)->toBe('Break the smuggler ring')
        ->and($fresh->description)->toBeNull()
        ->and($fresh->status)->toBe(GoalStatus::Planned)
        ->and($fresh->status_changed_at_game_id)->toBeNull()
        ->and($fresh->sort)->toBe(1);
});

test('createGoal honors an explicit description and status', function () {
    [$owner, $campaign] = journalWriteCampaign();

    $goal = app(CampaignJournalService::class)
        ->createGoal($campaign, $owner, 'Lift the curse', 'Ask the hermit first.', GoalStatus::Active);

    expect($goal->description)->toBe('Ask the hermit first.')
        ->and($goal->status)->toBe(GoalStatus::Active);
});

test('createGoal increments sort per campaign without cross-campaign bleed', function () {
    [$owner, $campaign] = journalWriteCampaign();
    [$otherOwner, $other] = journalWriteCampaign();

    $first = app(CampaignJournalService::class)->createGoal($campaign, $owner, 'First');
    $second = app(CampaignJournalService::class)->createGoal($campaign, $owner, 'Second');
    $otherCampaignGoal = app(CampaignJournalService::class)->createGoal($other, $otherOwner, 'Other campaign');

    expect($first->sort)->toBe(1)
        ->and($second->sort)->toBe(2)
        ->and($otherCampaignGoal->sort)->toBe(1);
});

test('createGoal rejects a non-owner author', function () {
    [, $campaign] = journalWriteCampaign();
    $stranger = User::factory()->create();

    try {
        app(CampaignJournalService::class)->createGoal($campaign, $stranger, 'Not my goal.');
        $this->fail('Expected LogicException for a non-owner author.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('journal.error_not_campaign_owner'));
    }

    expect(CampaignGoal::query()->where('campaign_id', $campaign->id)->count())->toBe(0);
});

test('createGoal rejects an empty title', function () {
    [$owner, $campaign] = journalWriteCampaign();

    try {
        app(CampaignJournalService::class)->createGoal($campaign, $owner, '  ');
        $this->fail('Expected LogicException for an empty title.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('journal.error_title_required'));
    }

    expect(CampaignGoal::query()->where('campaign_id', $campaign->id)->count())->toBe(0);
});

test('createGoal rejects a title over 200 characters', function () {
    [$owner, $campaign] = journalWriteCampaign();

    try {
        app(CampaignJournalService::class)->createGoal($campaign, $owner, str_repeat('t', 201));
        $this->fail('Expected LogicException for an over-long title.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('journal.error_title_too_long'));
    }

    expect(CampaignGoal::query()->where('campaign_id', $campaign->id)->count())->toBe(0);
});

test('createGoal rejects a description over 1000 characters', function () {
    [$owner, $campaign] = journalWriteCampaign();

    try {
        app(CampaignJournalService::class)->createGoal($campaign, $owner, 'Title', str_repeat('d', 1001));
        $this->fail('Expected LogicException for an over-long description.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('journal.error_description_too_long'));
    }

    expect(CampaignGoal::query()->where('campaign_id', $campaign->id)->count())->toBe(0);
});

// ── changeGoalStatus ────────────────────────────────────

test('changeGoalStatus stamps the explicit game and updates the status', function () {
    [$owner, $campaign] = journalWriteCampaign();
    $session = journalCompletedSession($campaign, $owner, '2026-01-10 18:00:00');
    $goal = app(CampaignJournalService::class)->createGoal($campaign, $owner, 'Find the informant');

    $updated = app(CampaignJournalService::class)
        ->changeGoalStatus($goal, $owner, GoalStatus::Achieved, $session);

    $fresh = CampaignGoal::query()->findOrFail($goal->id);
    expect($fresh->status)->toBe(GoalStatus::Achieved)
        ->and($fresh->status_changed_at_game_id)->toBe($session->id)
        ->and($updated->id)->toBe($goal->id);
});

test('changeGoalStatus falls back to the latest completed session when no game is given', function () {
    [$owner, $campaign] = journalWriteCampaign();
    $earlier = journalCompletedSession($campaign, $owner, '2026-01-10 18:00:00');
    $later = journalCompletedSession($campaign, $owner, '2026-02-14 18:00:00');
    $goal = app(CampaignJournalService::class)->createGoal($campaign, $owner, 'Chase the leads');

    app(CampaignJournalService::class)->changeGoalStatus($goal, $owner, GoalStatus::Active);

    $fresh = CampaignGoal::query()->findOrFail($goal->id);
    expect($fresh->status_changed_at_game_id)->toBe($later->id)
        ->and($fresh->status_changed_at_game_id)->not->toBe($earlier->id);
});

test('changeGoalStatus stamps null when the campaign has no completed sessions', function () {
    [$owner, $campaign] = journalWriteCampaign();
    $goal = app(CampaignJournalService::class)->createGoal($campaign, $owner, 'Plan heist');

    Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => 'scheduled',
    ]);

    app(CampaignJournalService::class)->changeGoalStatus($goal, $owner, GoalStatus::Abandoned);

    $fresh = CampaignGoal::query()->findOrFail($goal->id);
    expect($fresh->status)->toBe(GoalStatus::Abandoned)
        ->and($fresh->status_changed_at_game_id)->toBeNull();
});

test('changeGoalStatus is a no-op when the status is unchanged', function () {
    [$owner, $campaign] = journalWriteCampaign();
    $stamp = journalCompletedSession($campaign, $owner, '2026-01-10 18:00:00');
    $other = journalCompletedSession($campaign, $owner, '2026-02-14 18:00:00');
    $goal = CampaignGoal::factory()->forCampaign($campaign)->create([
        'status' => GoalStatus::Active,
        'status_changed_at_game_id' => $stamp->id,
    ]);
    $updatedAt = $goal->updated_at;

    $returned = app(CampaignJournalService::class)
        ->changeGoalStatus($goal, $owner, GoalStatus::Active, $other);

    $fresh = CampaignGoal::query()->findOrFail($goal->id);
    // No-op: the stamp must NOT be restamped to $other, and the row must
    // not even be touched (updated_at unchanged proves the early return).
    expect($returned->status)->toBe(GoalStatus::Active)
        ->and($fresh->status_changed_at_game_id)->toBe($stamp->id)
        ->and($fresh->updated_at->equalTo($updatedAt))->toBeTrue();
});

test('changeGoalStatus rejects a non-owner author', function () {
    [$owner, $campaign] = journalWriteCampaign();
    $stranger = User::factory()->create();
    $goal = app(CampaignJournalService::class)->createGoal($campaign, $owner, 'Guarded');

    try {
        app(CampaignJournalService::class)->changeGoalStatus($goal, $stranger, GoalStatus::Failed);
        $this->fail('Expected LogicException for a non-owner author.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('journal.error_not_campaign_owner'));
    }

    expect($goal->fresh()->status)->toBe(GoalStatus::Planned)
        ->and($goal->fresh()->status_changed_at_game_id)->toBeNull();
});

// ── logStateChange ──────────────────────────────────────

test('logStateChange records a session-bound state change for a player character', function () {
    [$owner, $campaign] = journalWriteCampaign();
    $ti = journalApprovedParticipant($campaign);
    $session = journalCompletedSession($campaign, $owner, '2026-01-10 18:00:00');

    $change = app(CampaignJournalService::class)->logStateChange(
        $campaign,
        $owner,
        $ti,
        CharacterStateChangeType::Progression,
        'Ti reached level 3',
        $session,
    );

    $fresh = CharacterStateChange::query()->findOrFail($change->id);
    expect($fresh->campaign_id)->toBe($campaign->id)
        ->and($fresh->participant_id)->toBe($ti->id)
        ->and($fresh->game_id)->toBe($session->id)
        ->and($fresh->change_type)->toBe(CharacterStateChangeType::Progression)
        ->and($fresh->delta)->toBe('Ti reached level 3');
});

test('logStateChange records an interlude state change without a game', function () {
    [$owner, $campaign] = journalWriteCampaign();
    $ti = journalApprovedParticipant($campaign);

    $change = app(CampaignJournalService::class)->logStateChange(
        $campaign,
        $owner,
        $ti,
        CharacterStateChangeType::Condition,
        'Cursed by the amulet',
    );

    $fresh = CharacterStateChange::query()->findOrFail($change->id);
    expect($fresh->campaign_id)->toBe($campaign->id)
        ->and($fresh->participant_id)->toBe($ti->id)
        ->and($fresh->game_id)->toBeNull()
        ->and($fresh->change_type)->toBe(CharacterStateChangeType::Condition)
        ->and($fresh->delta)->toBe('Cursed by the amulet');
});

test('logStateChange rejects a non-owner author', function () {
    [, $campaign] = journalWriteCampaign();
    $ti = journalApprovedParticipant($campaign);
    $stranger = User::factory()->create();

    try {
        app(CampaignJournalService::class)->logStateChange(
            $campaign,
            $stranger,
            $ti,
            CharacterStateChangeType::Note,
            'Self-logged.',
        );
        $this->fail('Expected LogicException for a non-owner author.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('journal.error_not_campaign_owner'));
    }

    expect(CharacterStateChange::query()->where('campaign_id', $campaign->id)->count())->toBe(0);
});

test('logStateChange rejects a participant row from another campaign', function () {
    [$owner, $campaignA] = journalWriteCampaign();
    [, $campaignB] = journalWriteCampaign();
    $foreignOwnerRow = journalOwnerParticipant($campaignB, User::query()->findOrFail($campaignB->owner_id));

    try {
        app(CampaignJournalService::class)->logStateChange(
            $campaignA,
            $owner,
            $foreignOwnerRow,
            CharacterStateChangeType::Item,
            'Wrong table.',
        );
        $this->fail('Expected LogicException for a foreign participant row.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('journal.error_not_participant'));
    }

    expect(CharacterStateChange::query()->where('campaign_id', $campaignA->id)->count())->toBe(0);
});

test('logStateChange rejects an empty delta', function () {
    [$owner, $campaign] = journalWriteCampaign();
    $ti = journalApprovedParticipant($campaign);

    try {
        app(CampaignJournalService::class)->logStateChange(
            $campaign,
            $owner,
            $ti,
            CharacterStateChangeType::Note,
            '   ',
        );
        $this->fail('Expected LogicException for an empty delta.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('journal.error_delta_empty'));
    }

    expect(CharacterStateChange::query()->where('campaign_id', $campaign->id)->count())->toBe(0);
});

test('logStateChange rejects a delta over 2000 characters', function () {
    [$owner, $campaign] = journalWriteCampaign();
    $ti = journalApprovedParticipant($campaign);

    try {
        app(CampaignJournalService::class)->logStateChange(
            $campaign,
            $owner,
            $ti,
            CharacterStateChangeType::Note,
            str_repeat('d', 2001),
        );
        $this->fail('Expected LogicException for an over-long delta.');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe(__('journal.error_delta_too_long'));
    }

    expect(CharacterStateChange::query()->where('campaign_id', $campaign->id)->count())->toBe(0);
});
