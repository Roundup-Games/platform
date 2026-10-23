<?php

use App\Livewire\Games\GameDetail;
use App\Models\Campaign;
use App\Models\CampaignJournalEntry;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use Livewire\Livewire;

// M071/S03-T03 (D219): the one-shot recap write path was retired — one-shots
// get the Epilogue bulletin instead of the write-once recap form. The three
// legacy write-behavior tests that lived here (owner writes via Livewire,
// 2000-char limit, non-owner rejection) were replaced by their D219
// counterparts; the same coverage survives for campaign sessions in
// SessionEndJournalComposerTest and CampaignJournalServiceTest.

beforeEach(function () {
    URL::defaults(['locale' => 'en']);

    $this->owner = User::factory()->create();
    $this->gameSystem = GameSystem::factory()->create();
});

function recapCreateCompletedGame(User $owner, GameSystem $system, array $overrides = []): Game
{
    return Game::factory()->create([
        'owner_id' => $owner->id,
        'game_system_id' => $system->id,
        'status' => 'completed',
        'campaign_id' => null,
        ...$overrides,
    ]);
}

describe('retired one-shot recap write path', function () {
    it('renders no recap form on a completed one-shot session-end', function () {
        $game = recapCreateCompletedGame($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertDontSee(__('journal.title_composer', [], 'en'))
            ->assertDontSee(__('journal.action_composer_submit', [], 'en'))
            ->assertDontSeeHtml('id="recap-content"')
            ->assertDontSeeHtml('maxlength="2000"');
    });

    it('still renders the journal composer for a completed campaign session', function () {
        $campaign = Campaign::factory()->create(['owner_id' => $this->owner->id]);
        $game = recapCreateCompletedGame($this->owner, $this->gameSystem, ['campaign_id' => $campaign->id]);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertSee(__('journal.title_composer', [], 'en'))
            ->assertSeeHtml('id="recap-content"');
    });

    it('writeRecap on a one-shot writes no recap column and no journal row', function () {
        $game = recapCreateCompletedGame($this->owner, $this->gameSystem);

        Livewire::actingAs($this->owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->set('recapContent', 'A one-shot tale.')
            ->call('writeRecap')
            ->assertSee(__('journal.error_not_campaign_session', [], 'en'));

        expect(CampaignJournalEntry::query()->where('game_id', $game->id)->count())->toBe(0);
    });
});
