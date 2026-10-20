<?php

use App\Enums\JournalEntryKind;
use App\Enums\JournalEntryVisibility;
use App\Livewire\Campaigns\CampaignDetail;
use App\Livewire\Campaigns\CampaignJournal;
use App\Models\Campaign;
use App\Models\CampaignJournalEntry;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\User;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Traits\CreatesGameInstances;

uses(CreatesGameInstances::class);

//
// M071/S02-T03 — the Journal tab member gate. CampaignPolicy::view admits
// any authenticated user (plus share-token/short-link bypasses) to public
// campaigns, so reusing it would publish the journal. viewJournal is the
// stricter member gate (owner + Approved|Benched participants) and this
// matrix pins that strangers, applicants and guests see zero journal
// surface even on PUBLIC campaigns. String assertions are locale-decoupled
// via __('…', [], 'en') lookups (K004).
//

/**
 * A PUBLIC campaign with its owner — public is the worst case for the
 * member gate: every stranger passes CampaignPolicy::view.
 *
 * @return array{0: User, 1: Campaign}
 */
function journalTabCampaign(): array
{
    ['owner' => $owner, 'campaign' => $campaign] = test()->createCampaignWithOwner(['visibility' => 'public']);

    return [$owner, $campaign];
}

/**
 * An approved player participant in the campaign.
 *
 * @return array{0: User, 1: CampaignParticipant}
 */
function journalTabApprovedPlayer(Campaign $campaign): array
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
 * The canonical three-entry journal: a session-bound recap, an
 * in-character post (both players visibility) and a GM prep note
 * (gms visibility). Bodies are unique markers.
 *
 * @return array{game: Game, recap: CampaignJournalEntry, inCharacter: CampaignJournalEntry, prep: CampaignJournalEntry}
 */
function journalTabEntries(Campaign $campaign, User $owner): array
{
    $game = Game::factory()->create([
        'campaign_id' => $campaign->id,
        'owner_id' => $owner->id,
        'status' => 'completed',
        'date_time' => now()->subDays(2),
    ]);

    $recap = CampaignJournalEntry::factory()->forCampaignSession($game)->create([
        'kind' => JournalEntryKind::Recap,
        'visibility' => JournalEntryVisibility::Players,
        'body' => 'JOURNALTAB RECAP the amulet changed hands',
    ]);

    $inCharacter = CampaignJournalEntry::factory()->create([
        'campaign_id' => $campaign->id,
        'author_user_id' => $owner->id,
        'kind' => JournalEntryKind::InCharacter,
        'visibility' => JournalEntryVisibility::Players,
        'body' => 'JOURNALTAB INCHARACTER Ti writes home',
    ]);

    $prep = CampaignJournalEntry::factory()->create([
        'campaign_id' => $campaign->id,
        'author_user_id' => $owner->id,
        'kind' => JournalEntryKind::PrepNote,
        'visibility' => JournalEntryVisibility::Gms,
        'body' => 'JOURNALTAB PREPNOTE the traitor is the harbormaster',
    ]);

    return ['game' => $game, 'recap' => $recap, 'inCharacter' => $inCharacter, 'prep' => $prep];
}

// ── (a) Owner: every kind, GM Notes pill, deep link ──────

test('owner deep-links to ?tab=journal and sees every kind including prep notes', function () {
    [$owner, $campaign] = journalTabCampaign();
    $entries = journalTabEntries($campaign, $owner);

    $response = $this->actingAs($owner)
        ->get(route('campaigns.show', ['id' => $campaign->id, 'tab' => 'journal']));

    $response->assertOk();
    $response->assertSee(__('journal.tab_journal', [], 'en'))
        ->assertSee(__('journal.filter_prep_note', [], 'en'))
        ->assertSee($entries['recap']->body)
        ->assertSee($entries['inCharacter']->body)
        ->assertSee($entries['prep']->body)
        ->assertSee($entries['game']->name);
});

test('overview renders by default and the journal mounts after switching tabs', function () {
    [$owner, $campaign] = journalTabCampaign();
    $entries = journalTabEntries($campaign, $owner);

    $component = Livewire::actingAs($owner)->test(CampaignDetail::class, ['id' => $campaign->id]);

    // Default tab: overview sections, no journal content.
    $component->assertSee(__('journal.tab_overview', [], 'en'))
        ->assertSee(__('journal.tab_journal', [], 'en'))
        ->assertDontSee($entries['recap']->body);

    $component->set('activeTab', 'journal')
        ->assertSee($entries['recap']->body)
        ->assertSee($entries['prep']->body)
        ->assertSee(__('journal.filter_all', [], 'en'));
});

// ── (b) Approved player: players rows only, no GM Notes pill ──

test('approved player sees players entries but never prep notes or the GM Notes pill', function () {
    [$owner, $campaign] = journalTabCampaign();
    $entries = journalTabEntries($campaign, $owner);
    [$player] = journalTabApprovedPlayer($campaign);

    $response = $this->actingAs($player)
        ->get(route('campaigns.show', ['id' => $campaign->id, 'tab' => 'journal']));

    $response->assertOk();
    $response->assertSee(__('journal.tab_journal', [], 'en'))
        ->assertSee($entries['recap']->body)
        ->assertSee($entries['inCharacter']->body)
        ->assertDontSee($entries['prep']->body)
        ->assertDontSee(__('journal.filter_prep_note', [], 'en'));
});

test('the gms prep note never appears to a player in any filter', function () {
    [$owner, $campaign] = journalTabCampaign();
    $entries = journalTabEntries($campaign, $owner);
    [$player] = journalTabApprovedPlayer($campaign);

    $journal = Livewire::actingAs($player)->test(CampaignJournal::class, ['campaign' => $campaign]);

    $journal->assertSee($entries['recap']->body)
        ->assertSee($entries['inCharacter']->body)
        ->assertDontSee($entries['prep']->body);

    // A player-supplied prep_note filter value is a no-op, not a leak: the
    // visibility gate empties the result and the empty state renders.
    foreach (['all', 'recap', 'in_character', 'prep_note'] as $filter) {
        $journal->set('kindFilter', $filter)
            ->assertDontSee($entries['prep']->body);
    }

    $journal->assertSee(__('journal.empty_journal', [], 'en'));
});

// ── (c) Pending, waitlisted, stranger, guest: zero journal surface ──

test('pending, waitlisted and stranger never see the journal on a public campaign', function () {
    [$owner, $campaign] = journalTabCampaign();
    $entries = journalTabEntries($campaign, $owner);

    $pending = CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'user_id' => User::factory()->create(['profile_complete' => true])->id,
        'role' => 'applicant',
        'status' => 'pending',
    ]);
    $waitlisted = CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'user_id' => User::factory()->create(['profile_complete' => true])->id,
        'role' => 'player',
        'status' => 'waitlisted',
        'waitlisted_at' => now(),
    ]);
    $stranger = User::factory()->create(['profile_complete' => true]);

    foreach ([$pending->user, $waitlisted->user, $stranger] as $viewer) {
        // Overview page renders (public campaign passes view), but the
        // journal tab label never appears.
        $this->actingAs($viewer)
            ->get(route('campaigns.show', ['id' => $campaign->id]))
            ->assertOk()
            ->assertDontSee(__('journal.tab_journal', [], 'en'));

        // Forcing ?tab=journal falls back to the overview content — the
        // journal never renders.
        $this->actingAs($viewer)
            ->get(route('campaigns.show', ['id' => $campaign->id, 'tab' => 'journal']))
            ->assertOk()
            ->assertDontSee(__('journal.tab_journal', [], 'en'))
            ->assertDontSee($entries['recap']->body)
            ->assertDontSee($entries['inCharacter']->body)
            ->assertDontSee($entries['prep']->body);

        // Direct nested mount fails closed: a nothing state, never content
        // (not even the empty state — that already leaks surface).
        Livewire::actingAs($viewer)
            ->test(CampaignJournal::class, ['campaign' => $campaign])
            ->assertDontSee(__('journal.empty_journal', [], 'en'))
            ->assertDontSee(__('journal.filter_all', [], 'en'))
            ->assertDontSee($entries['recap']->body)
            ->assertDontSee($entries['inCharacter']->body)
            ->assertDontSee($entries['prep']->body);
    }
});

test('a guest is redirected away from the dashboard campaign page', function () {
    [, $campaign] = journalTabCampaign();

    $this->get(route('campaigns.show', ['id' => $campaign->id]))->assertRedirect();
});

// ── (d) Kind filter + cursor pagination reset ────────────

test('kind filter narrows the timeline and switching filters resets pagination', function () {
    [$owner, $campaign] = journalTabCampaign();

    // 25 recaps with strictly descending created_at (marker 25 newest),
    // plus one in-character post newest of all.
    foreach (range(1, 25) as $i) {
        CampaignJournalEntry::factory()->create([
            'campaign_id' => $campaign->id,
            'author_user_id' => $owner->id,
            'kind' => JournalEntryKind::Recap,
            'visibility' => JournalEntryVisibility::Players,
            'body' => "JOURNALTAB RECAP MARKER {$i}",
            'created_at' => now()->subMinutes(100 - $i),
        ]);
    }
    CampaignJournalEntry::factory()->create([
        'campaign_id' => $campaign->id,
        'author_user_id' => $owner->id,
        'kind' => JournalEntryKind::InCharacter,
        'visibility' => JournalEntryVisibility::Players,
        'body' => 'JOURNALTAB INCHARACTER MARKER',
        'created_at' => now(),
    ]);

    $journal = Livewire::actingAs($owner)->test(CampaignJournal::class, ['campaign' => $campaign]);

    // Default 'all' shows both kinds, page 1 = the 20 newest rows.
    $journal->assertSee('JOURNALTAB INCHARACTER MARKER')
        ->assertSee('JOURNALTAB RECAP MARKER 25')
        ->assertDontSee('JOURNALTAB RECAP MARKER 5');

    // Filtering to recaps hides the in-character entry.
    $journal->set('kindFilter', 'recap')
        ->assertSee('JOURNALTAB RECAP MARKER 25')
        ->assertDontSee('JOURNALTAB INCHARACTER MARKER');

    // Page 2 of the recap filter starts at marker 5. Build the cursor the
    // way the paginator does: after marker 6, the last row of page 1.
    $lastOfPageOne = CampaignJournalEntry::query()
        ->where('body', 'JOURNALTAB RECAP MARKER 6')
        ->firstOrFail();
    $cursor = (new Cursor([
        'created_at' => $lastOfPageOne->created_at,
        'id' => $lastOfPageOne->id,
    ], pointsToNextItems: true))->encode();

    $journal->call('setPage', $cursor, 'cursor')
        ->assertSee('JOURNALTAB RECAP MARKER 5')
        ->assertSee('JOURNALTAB RECAP MARKER 1')
        ->assertDontSee('JOURNALTAB RECAP MARKER 25');

    // Switching the filter while paginated resets the cursor to page 1.
    $journal->set('kindFilter', 'all')
        ->assertSee('JOURNALTAB INCHARACTER MARKER')
        ->assertSee('JOURNALTAB RECAP MARKER 25')
        ->assertDontSee('JOURNALTAB RECAP MARKER 5');
});

// ── (e) Direct policy assertions ─────────────────────────

test('viewJournal grants only owner, seated participants and global admins', function () {
    [$owner, $campaign] = journalTabCampaign();
    [, $otherCampaign] = journalTabCampaign();

    [$approved] = journalTabApprovedPlayer($campaign);
    $benched = CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'role' => 'player',
        'status' => 'benched',
    ]);
    $pending = CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'role' => 'applicant',
        'status' => 'pending',
    ]);
    $waitlisted = CampaignParticipant::factory()->create([
        'campaign_id' => $campaign->id,
        'role' => 'player',
        'status' => 'waitlisted',
    ]);
    $stranger = User::factory()->create(['profile_complete' => true]);
    // A member of a DIFFERENT campaign is a stranger here — participation
    // must not bleed across campaigns.
    $otherCampaignMember = User::query()->findOrFail($otherCampaign->owner_id);

    seedRoles();
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $globalAdmin = User::factory()->create(['profile_complete' => true]);
    $globalAdmin->assignRole('Platform Admin');

    expect($owner->can('viewJournal', $campaign))->toBeTrue()
        ->and($approved->can('viewJournal', $campaign))->toBeTrue()
        ->and($benched->user->can('viewJournal', $campaign))->toBeTrue()
        ->and($globalAdmin->can('viewJournal', $campaign))->toBeTrue()
        ->and($pending->user->can('viewJournal', $campaign))->toBeFalse()
        ->and($waitlisted->user->can('viewJournal', $campaign))->toBeFalse()
        ->and($stranger->can('viewJournal', $campaign))->toBeFalse()
        ->and($otherCampaignMember->can('viewJournal', $campaign))->toBeFalse()
        // Guests never reach the policy: the non-nullable User signature
        // denies them at the Gate.
        ->and(Gate::forUser(null)->allows('viewJournal', $campaign))->toBeFalse();
});
