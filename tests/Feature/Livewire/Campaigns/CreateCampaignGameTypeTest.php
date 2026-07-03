<?php

use App\Livewire\Campaigns\AddSessionToCampaign;
use App\Livewire\Campaigns\CreateCampaign;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;

uses(DatabaseTransactions::class);

// R050: a Campaign gains a nullable game_type so a recurring night is
// distinguishable from a TTRPG campaign, and AddSessionToCampaign propagates
// the type onto each spawned session (instead of hardcoding 'ttrpg').

function createCampaignOwner(): User
{
    return User::factory()->create(['profile_complete' => true]);
}

describe('CreateCampaign — game type (R050)', function () {
    it('persists a gathering game_type when selected', function () {
        $owner = createCampaignOwner();
        $system = GameSystem::factory()->create();

        Livewire\Livewire::actingAs($owner)
            ->test(CreateCampaign::class)
            ->set('name', 'Weekly Board Game Night')
            ->set('game_type', 'gathering')
            ->set('game_system_id', $system->id)
            ->call('save')
            ->assertHasNoErrors();

        $campaign = Campaign::where('owner_id', $owner->id)->first();
        expect($campaign)->not->toBeNull()
            ->and($campaign->game_type->value)->toBe('gathering');
    });

    it('defaults to ttrpg game_type (backward compatible)', function () {
        $owner = createCampaignOwner();

        Livewire\Livewire::actingAs($owner)
            ->test(CreateCampaign::class)
            ->set('name', 'Shadows of Waterdeep')
            ->call('save')
            ->assertHasNoErrors();

        $campaign = Campaign::where('owner_id', $owner->id)->first();
        expect($campaign->game_type->value)->toBe('ttrpg');
    });

    it('rejects an invalid game_type', function () {
        $owner = createCampaignOwner();

        Livewire\Livewire::actingAs($owner)
            ->test(CreateCampaign::class)
            ->set('name', 'Bad Type')
            ->set('game_type', 'larp')
            ->call('save')
            ->assertHasErrors(['game_type']);
    });
});

describe('AddSessionToCampaign — game type propagation (R050)', function () {
    it('spawns a gathering session from a gathering campaign with the 1-element game_systems set', function () {
        $owner = createCampaignOwner();
        $system = GameSystem::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'game_type' => 'gathering',
            'game_system_id' => $system->id,
            'recurrence' => 'weekly',
            'time_of_day' => '19:00',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Night One')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->call('save')
            ->assertHasNoErrors();

        $game = Game::where('campaign_id', $campaign->id)->first();
        expect($game)->not->toBeNull()
            ->and($game->game_type->value)->toBe('gathering')
            // 1-element set (R046 single-system = 1-element set) → valid Gathering
            ->and($game->game_systems)->toBe([$system->id])
            // anchor synced to the set's first element (S01 saving event)
            ->and($game->game_system_id)->toBe($system->id);
    });

    it('spawns a ttrpg session from a ttrpg campaign', function () {
        $owner = createCampaignOwner();
        $system = GameSystem::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'game_type' => 'ttrpg',
            'game_system_id' => $system->id,
            'recurrence' => 'weekly',
            'time_of_day' => '19:00',
        ]);

        Livewire\Livewire::actingAs($owner)
            ->test(AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Session One')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->call('save')
            ->assertHasNoErrors();

        $game = Game::where('campaign_id', $campaign->id)->first();
        expect($game->game_type->value)->toBe('ttrpg')
            ->and($game->game_systems)->toBeNull(); // single-system: no array
    });

    it('treats a legacy campaign with null game_type as ttrpg (backward compatible)', function () {
        $owner = createCampaignOwner();
        $system = GameSystem::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'game_system_id' => $system->id,
            'recurrence' => 'weekly',
            'time_of_day' => '19:00',
        ]);
        // Existing campaigns predate campaigns.game_type → force the legacy null state.
        $campaign->forceFill(['game_type' => null])->save();

        Livewire\Livewire::actingAs($owner)
            ->test(AddSessionToCampaign::class, ['id' => $campaign->id])
            ->set('name', 'Legacy Session')
            ->set('date_time', now()->addDay()->format('Y-m-d\TH:i'))
            ->call('save')
            ->assertHasNoErrors();

        $game = Game::where('campaign_id', $campaign->id)->first();
        expect($game->game_type->value)->toBe('ttrpg');
    });
});
