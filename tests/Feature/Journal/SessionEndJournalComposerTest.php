<?php

use App\Enums\JournalEntryKind;
use App\Enums\JournalEntryVisibility;
use App\Enums\ParticipantStatus;
use App\Livewire\Games\GameDetail;
use App\Models\Campaign;
use App\Models\CampaignJournalEntry;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use Livewire\Livewire;

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
