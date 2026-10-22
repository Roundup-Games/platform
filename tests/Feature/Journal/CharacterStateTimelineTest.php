<?php

use App\Livewire\Campaigns\CampaignJournal;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\CharacterStateChange;
use App\Models\User;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

//
// M071/S02-T04 — the Journal tab's per-character state timelines and
// the remaining authoring forms: GM state-change logging (players get
// no controls and no writes even via direct action calls), the GM-only
// prep-note form (never rendered for players), and the in-character
// post form that lands players-visible timeline entries. String
// assertions are locale-decoupled via __('…', [], 'en') lookups (K004).
//

/**
 * A campaign with its owner (+ owner participant row).
 *
 * @return array{0: User, 1: Campaign}
 */
function charStateCampaign(): array
{
    ['owner' => $owner, 'campaign' => $campaign] = test()->createCampaignWithOwner();

    return [$owner, $campaign];
}

/**
 * An approved player participant in the campaign.
 *
 * @return array{0: User, 1: CampaignParticipant}
 */
function charStateApprovedPlayer(Campaign $campaign): array
{
    $participant = CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'user_id' => User::factory()->create(['profile_complete' => true])->id,
        'role' => 'player',
        'status' => 'approved',
    ]);

    return [$participant->user, $participant];
}

// ── Reading surface: per-character timelines ────────────

test("a participant's timeline renders newest-first with change-type badges", function () {
    [$owner, $campaign] = charStateCampaign();
    [$player, $participant] = charStateApprovedPlayer($campaign);

    // Four changes, i=1 oldest … i=4 newest; the newest three render in
    // the preview list, the oldest only inside the <details>.
    foreach (range(1, 4) as $i) {
        CharacterStateChange::factory()->forParticipant($participant)->create([
            'change_type' => $i === 3 ? 'item' : 'note',
            'delta' => "CHARSTATE CHANGE {$i}",
            'created_at' => now()->subMinutes(100 - $i),
        ]);
    }

    Livewire::actingAs($player)->test(CampaignJournal::class, ['campaign' => $campaign])
        ->assertSee(__('journal.heading_state_changes', [], 'en'))
        ->assertSee($participant->user->name)
        ->assertSeeInOrder(['CHARSTATE CHANGE 4', 'CHARSTATE CHANGE 3', 'CHARSTATE CHANGE 2'])
        // Change-type badge on a preview row.
        ->assertSee(__('journal.type_item', [], 'en'))
        // The full timeline expands client-side with zero extra requests.
        ->assertSee(__('journal.action_show_all', [], 'en'));
});

test('a participant without state changes sees the empty hint', function () {
    [$owner, $campaign] = charStateCampaign();
    [$player, $participant] = charStateApprovedPlayer($campaign);

    Livewire::actingAs($player)->test(CampaignJournal::class, ['campaign' => $campaign])
        ->assertSee($participant->user->name)
        ->assertSee(__('journal.empty_state_changes', [], 'en'));
});

// ── GM logging ──────────────────────────────────────────

test('the GM logs a state change through the action and the timeline rerenders', function () {
    [$owner, $campaign] = charStateCampaign();
    [, $participant] = charStateApprovedPlayer($campaign);

    Livewire::actingAs($owner)->test(CampaignJournal::class, ['campaign' => $campaign])
        ->set('stateParticipantId', $participant->id)
        ->set('stateChangeType', 'progression')
        ->set('stateDelta', 'CHARSTATE LOGGED reached level 3')
        ->call('logStateChange')
        ->assertSee(__('journal.flash_state_change_logged', [], 'en'))
        // Timeline rerender: the logged delta is on the participant row.
        ->assertSee('CHARSTATE LOGGED reached level 3');

    $this->assertDatabaseHas('character_state_changes', [
        'campaign_id' => $campaign->id,
        'participant_id' => $participant->id,
        'game_id' => null,
        'change_type' => 'progression',
        'delta' => 'CHARSTATE LOGGED reached level 3',
    ]);
});

test('logging a state change resets the loaded older-history markers for that character', function () {
    // The unset() pair in logStateChange is load-bearing: a stale
    // olderStateChanges keyset cursor would silently skip the row that
    // just fell out of the eager window, and a stale exhausted flag
    // would hide the "Load earlier changes" affordance. Pin both resets.
    [$owner, $campaign] = charStateCampaign();
    [, $participant] = charStateApprovedPlayer($campaign);

    // 15 rows: the eager window holds the latest 10, so older history
    // exists to load (5 rows, exhausted after one click).
    for ($i = 0; $i < 15; $i++) {
        CharacterStateChange::factory()->forParticipant($participant)->create([
            'delta' => "HISTORY ROW {$i}",
            'created_at' => now()->subMinutes(100 - $i),
        ]);
    }

    $component = Livewire::actingAs($owner)->test(CampaignJournal::class, ['campaign' => $campaign]);

    $component->call('loadOlderStateChanges', $participant->id)
        ->assertSet('olderStateChangeIds.'.$participant->id, fn ($ids) => count($ids) === 5)
        ->assertSet('olderStateChangesExhausted.'.$participant->id, true);

    $component->set('stateParticipantId', $participant->id)
        ->set('stateChangeType', 'progression')
        ->set('stateDelta', 'CHARSTATE MARKER RESET')
        ->call('logStateChange')
        ->assertSet('olderStateChangeIds.'.$participant->id, null)
        ->assertSet('olderStateChangeCursor.'.$participant->id, null)
        ->assertSet('olderStateChangesExhausted.'.$participant->id, null);
});

test('an empty state delta is flashed as an error and nothing is written', function () {
    [$owner, $campaign] = charStateCampaign();
    [, $participant] = charStateApprovedPlayer($campaign);

    Livewire::actingAs($owner)->test(CampaignJournal::class, ['campaign' => $campaign])
        ->set('stateParticipantId', $participant->id)
        ->set('stateChangeType', 'note')
        ->set('stateDelta', '   ')
        ->call('logStateChange')
        ->assertSee(__('journal.error_delta_empty', [], 'en'));

    $this->assertDatabaseMissing('character_state_changes', ['campaign_id' => $campaign->id]);
});

// ── Player: no logging controls, direct action rejected ──

test('a player never sees the log-state controls', function () {
    [$owner, $campaign] = charStateCampaign();
    [$player, $participant] = charStateApprovedPlayer($campaign);

    CharacterStateChange::factory()->forParticipant($participant)->create([
        'delta' => 'CHARSTATE PLAYER VIEW lost the amulet',
    ]);

    Livewire::actingAs($player)->test(CampaignJournal::class, ['campaign' => $campaign])
        // The reading surface is there…
        ->assertSee('CHARSTATE PLAYER VIEW lost the amulet')
        ->assertSee(__('journal.heading_state_changes', [], 'en'))
        // …but the GM logging form never renders.
        ->assertDontSee(__('journal.action_log_state', [], 'en'))
        ->assertDontSee(__('journal.label_state_delta', [], 'en'))
        ->assertDontSee(__('journal.label_state_change_type', [], 'en'));
});

test('a player calling logStateChange directly is rejected and nothing is written', function () {
    [$owner, $campaign] = charStateCampaign();
    [$player, $participant] = charStateApprovedPlayer($campaign);

    Livewire::actingAs($player)->test(CampaignJournal::class, ['campaign' => $campaign])
        ->set('stateParticipantId', $participant->id)
        ->set('stateChangeType', 'item')
        ->set('stateDelta', 'CHARSTATE FORGED gained a crown')
        ->call('logStateChange')
        ->assertSee(__('common.error_not_authorized', [], 'en'));

    $this->assertDatabaseMissing('character_state_changes', ['delta' => 'CHARSTATE FORGED gained a crown']);
});

// ── In-character + prep-note forms ──────────────────────

test('the prep-note form never renders for players while the in-character form does', function () {
    [$owner, $campaign] = charStateCampaign();
    [$player] = charStateApprovedPlayer($campaign);

    Livewire::actingAs($player)->test(CampaignJournal::class, ['campaign' => $campaign])
        ->assertSee(__('journal.action_post_in_character', [], 'en'))
        ->assertSee(__('journal.label_in_game_date', [], 'en'))
        ->assertDontSee(__('journal.action_post_prep_note', [], 'en'))
        ->assertDontSee(__('journal.hint_prep_note', [], 'en'));

    // The GM gets both forms.
    Livewire::actingAs($owner)->test(CampaignJournal::class, ['campaign' => $campaign])
        ->assertSee(__('journal.action_post_in_character', [], 'en'))
        ->assertSee(__('journal.action_post_prep_note', [], 'en'))
        ->assertSee(__('journal.hint_prep_note', [], 'en'));
});

test('the GM saves a prep note through the action and it lands gms-visible', function () {
    [$owner, $campaign] = charStateCampaign();

    Livewire::actingAs($owner)->test(CampaignJournal::class, ['campaign' => $campaign])
        ->set('prepNoteBody', 'CHARSTATE PREP the traitor is the harbormaster')
        ->call('postPrepNote')
        ->assertSee(__('journal.flash_prep_note_posted', [], 'en'));

    $this->assertDatabaseHas('campaign_journal_entries', [
        'campaign_id' => $campaign->id,
        'author_user_id' => $owner->id,
        'kind' => 'prep_note',
        'visibility' => 'gms',
        'body' => 'CHARSTATE PREP the traitor is the harbormaster',
    ]);
});

test('an in-character post from an approved participant is visible to all players', function () {
    [$owner, $campaign] = charStateCampaign();
    [$poster, $posterParticipant] = charStateApprovedPlayer($campaign);
    [$reader] = charStateApprovedPlayer($campaign);

    Livewire::actingAs($poster)->test(CampaignJournal::class, ['campaign' => $campaign])
        ->set('inCharacterBody', 'CHARSTATE INCHAR Ti lights the beacon')
        ->set('inGameDate', '12. Flockentag')
        ->call('postInCharacter')
        ->assertSee(__('journal.flash_in_character_posted', [], 'en'));

    $this->assertDatabaseHas('campaign_journal_entries', [
        'campaign_id' => $campaign->id,
        'author_user_id' => $poster->id,
        'author_participant_id' => $posterParticipant->id,
        'kind' => 'in_character',
        'visibility' => 'players',
        'body' => 'CHARSTATE INCHAR Ti lights the beacon',
        'in_game_date' => '12. Flockentag',
    ]);

    // Another approved player sees the entry in the timeline.
    Livewire::actingAs($reader)->test(CampaignJournal::class, ['campaign' => $campaign])
        ->assertSee('CHARSTATE INCHAR Ti lights the beacon')
        ->assertSee('12. Flockentag');

    // And so does the GM.
    Livewire::actingAs($owner)->test(CampaignJournal::class, ['campaign' => $campaign])
        ->assertSee('CHARSTATE INCHAR Ti lights the beacon');
});

// ── D232: bounded render, on-demand deeper history ──────

test('a long character timeline renders only the latest ten changes, with on-demand deeper history', function () {
    [$owner, $campaign] = charStateCampaign();
    [$player, $participant] = charStateApprovedPlayer($campaign);

    // i=1 oldest … i=12 newest: i=3..12 are the eager window, i=1..2
    // only load on explicit request. Zero-padded markers — "MARK 01"
    // is never a substring of "MARK 10".
    foreach (range(1, 12) as $i) {
        CharacterStateChange::factory()->forParticipant($participant)->create([
            'change_type' => 'note',
            'delta' => sprintf('CHARSTATE MARK %02d', $i),
            'created_at' => now()->subMinutes(100 - $i),
        ]);
    }

    $component = Livewire::actingAs($player)->test(CampaignJournal::class, ['campaign' => $campaign]);

    $component
        ->assertSee('CHARSTATE MARK 12')
        ->assertSee('CHARSTATE MARK 03')     // 10th of the window — rendered
        ->assertDontSee('CHARSTATE MARK 02') // below the window — fetched only on click
        ->assertDontSee('CHARSTATE MARK 01')
        ->assertSee(__('journal.action_load_earlier_changes', [], 'en'));

    $component
        ->call('loadOlderStateChanges', $participant->id)
        ->assertSee('CHARSTATE MARK 02')
        ->assertSee('CHARSTATE MARK 01');
});

test('loadOlderStateChanges fails closed for a viewer outside the journal gate', function () {
    [$owner, $campaign] = charStateCampaign();
    [$player, $participant] = charStateApprovedPlayer($campaign);

    foreach (range(1, 12) as $i) {
        CharacterStateChange::factory()->forParticipant($participant)->create([
            'change_type' => 'note',
            'delta' => "CHARSTATE CHANGE {$i}",
            'created_at' => now()->subMinutes(100 - $i),
        ]);
    }

    $stranger = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($stranger)
        ->test(CampaignJournal::class, ['campaign' => $campaign])
        ->call('loadOlderStateChanges', $participant->id)
        ->assertSet('olderStateChangeIds', []);
});

// ── Regression guards (2026-10-20 review) ───────────────

test('loadOlderStateChanges rejects a seated participant of a DIFFERENT campaign', function () {
    [$owner, $campaign] = charStateCampaign();
    [$player, $participant] = charStateApprovedPlayer($campaign);

    foreach (range(1, 12) as $i) {
        CharacterStateChange::factory()->forParticipant($participant)->create([
            'change_type' => 'note',
            'delta' => "CHARSTATE CHANGE {$i}",
            'created_at' => now()->subMinutes(100 - $i),
        ]);
    }

    // A genuinely approved player — of another campaign: the campaign-
    // scoped participant resolution must refuse to load their history.
    ['campaign' => $otherCampaign] = test()->createCampaignWithOwner();
    [$otherPlayer] = charStateApprovedPlayer($otherCampaign);

    Livewire::actingAs($otherPlayer)
        ->test(CampaignJournal::class, ['campaign' => $campaign])
        ->call('loadOlderStateChanges', $participant->id)
        ->assertSet('olderStateChangeIds', []);
});

test('each character in a multi-participant campaign gets its own ten-change window', function () {
    [$owner, $campaign] = charStateCampaign();
    [$playerA, $participantA] = charStateApprovedPlayer($campaign);
    [$playerB, $participantB] = charStateApprovedPlayer($campaign);

    // Twelve changes PER character, interleaved in time so a single
    // global top-10 would hand the whole window to one of them — the
    // eager-load limit() trap this test pins: the window is per
    // participant (row_number partition), never a combined query.
    foreach (range(1, 12) as $i) {
        CharacterStateChange::factory()->forParticipant($participantA)->create([
            'change_type' => 'note',
            'delta' => sprintf('CHARSTATE ALPHA %02d', $i),
            'created_at' => now()->subMinutes(100 - $i),
        ]);
        CharacterStateChange::factory()->forParticipant($participantB)->create([
            'change_type' => 'note',
            'delta' => sprintf('CHARSTATE BRAVO %02d', $i),
            'created_at' => now()->subMinutes(100 - $i),
        ]);
    }

    $component = Livewire::actingAs($playerA)->test(CampaignJournal::class, ['campaign' => $campaign]);

    // Both characters show their own window edge (their 10th row) and
    // nothing below it…
    $component
        ->assertSee('CHARSTATE ALPHA 03')
        ->assertDontSee('CHARSTATE ALPHA 02')
        ->assertSee('CHARSTATE BRAVO 03')
        ->assertDontSee('CHARSTATE BRAVO 02');

    // …and BOTH load-earlier affordances are reachable (the button
    // renders inside stateChanges->isNotEmpty() — a starved window
    // used to hide it and strand the history).
    $html = $component->html();
    expect($html)->toContain($participantA->id)->toContain($participantB->id);
    expect(substr_count($html, __('journal.action_load_earlier_changes', [], 'en')))->toBe(2);
});

test('older history loads in bounded chunks until exhausted, then the affordance disappears', function () {
    [$owner, $campaign] = charStateCampaign();
    [$player, $participant] = charStateApprovedPlayer($campaign);

    // i=1 oldest … i=40 newest: the window holds 31..40, leaving 30
    // older rows — one over the 25-row chunk, so the second click is
    // the exhaustive one.
    foreach (range(1, 40) as $i) {
        CharacterStateChange::factory()->forParticipant($participant)->create([
            'change_type' => 'note',
            'delta' => sprintf('CHARSTATE MARK %02d', $i),
            'created_at' => now()->subMinutes(100 - $i),
        ]);
    }

    $component = Livewire::actingAs($player)->test(CampaignJournal::class, ['campaign' => $campaign]);

    // First click: the newest 25 older rows (30 … 06) — bounded, not
    // the whole history.
    $component
        ->call('loadOlderStateChanges', $participant->id)
        ->assertSee('CHARSTATE MARK 30')
        ->assertSee('CHARSTATE MARK 06')
        ->assertDontSee('CHARSTATE MARK 05')
        ->assertSee(__('journal.action_load_earlier_changes', [], 'en'));

    // Second click: the remaining five, and the affordance disappears.
    $component
        ->call('loadOlderStateChanges', $participant->id)
        ->assertSee('CHARSTATE MARK 05')
        ->assertSee('CHARSTATE MARK 01')
        ->assertDontSee(__('journal.action_load_earlier_changes', [], 'en'));

    // The loaded history is a bounded id set, never hydrated models or
    // display tuples — the payload stays kilobytes even on deep dives.
    expect($component->get('olderStateChangeIds')[$participant->id])->each->toBeString();
    expect(count($component->get('olderStateChangeIds')[$participant->id]))->toBe(30);
    expect($component->get('olderStateChangesExhausted')[$participant->id])->toBeTrue();
});

test('loaded older history is hard-capped — the affordance retires with an honest note, not unbounded state', function () {
    // 200 older rows: 8 chunks of 25 exist, but component state stops at
    // 125 ids (5 chunks). The sixth click is a no-op and the cap note
    // replaces the button — one GM's deep dive never pins megabytes of
    // deltas into every later round-trip.
    [$owner, $campaign] = charStateCampaign();
    [, $participant] = charStateApprovedPlayer($campaign);

    for ($i = 0; $i < 200; $i++) {
        CharacterStateChange::factory()->forParticipant($participant)->create([
            'delta' => "DEEP ROW {$i}",
            'created_at' => now()->subMinutes(500 - $i),
        ]);
    }

    $component = Livewire::actingAs($owner)->test(CampaignJournal::class, ['campaign' => $campaign]);

    for ($click = 0; $click < 5; $click++) {
        $component->call('loadOlderStateChanges', $participant->id);
    }

    $component
        ->assertSet('olderStateChangesCapped.'.$participant->id, true)
        ->assertSet('olderStateChangesExhausted.'.$participant->id, null)
        ->assertDontSee(__('journal.action_load_earlier_changes', [], 'en'))
        ->assertSee(__('journal.label_history_capped', ['count' => 125], 'en'));

    expect(count($component->get('olderStateChangeIds')[$participant->id]))->toBe(125);

    // The capped surface is inert: another click changes nothing.
    $component->call('loadOlderStateChanges', $participant->id);

    expect(count($component->get('olderStateChangeIds')[$participant->id]))->toBe(125);
});
