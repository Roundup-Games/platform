<?php

use App\Livewire\Discovery\AdventuresDiscovery;
use App\Livewire\Discovery\BoardGamesDiscovery;
use App\Livewire\Discovery\DiscoveryPortal;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use function Pest\Laravel\get;

describe('DiscoveryPortal – rendering', function () {
    it('renders at /discover for guests', function () {
        Livewire\Livewire::test(DiscoveryPortal::class)
            ->assertOk();
    });

    it('is accessible via named route discover', function () {
        get(route('discover'))->assertOk();
    });

    it('shows two track cards', function () {
        Livewire\Livewire::test(DiscoveryPortal::class)
            ->assertSee(__('discovery.field_board_game_night'))
            ->assertSee(__('discovery.field_tabletop_adventures'));
    });

    it('shows upcoming board game count on board game card', function () {
        Game::factory()->count(3)->create([
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
            'visibility' => 'public',
        ]);

        Livewire\Livewire::test(DiscoveryPortal::class)
            ->assertSee('3 ' . __('common.field_upcoming'));
    });

    it('shows adventure count on adventures card', function () {
        $ttrpgSystem = GameSystem::factory()->create(['type' => 'ttrpg']);

        // 2 active public campaigns
        Campaign::factory()->count(2)->create([
            'status' => 'active',
            'visibility' => 'public',
            'game_system_id' => $ttrpgSystem->id,
        ]);

        // 1 upcoming TTRPG game session
        Game::factory()->create([
            'status' => 'scheduled',
            'date_time' => now()->addDay(),
            'visibility' => 'public',
            'game_system_id' => $ttrpgSystem->id,
        ]);

        Livewire\Livewire::test(DiscoveryPortal::class)
            ->assertSee('3 ' . __('discovery.content_looking_for_players'));
    });

    it('board game card links to discover.board-games route', function () {
        Livewire\Livewire::test(DiscoveryPortal::class)
            ->assertSee(route('discover.board-games'));
    });

    it('adventures card links to discover.adventures route', function () {
        Livewire\Livewire::test(DiscoveryPortal::class)
            ->assertSee(route('discover.adventures'));
    });

    it('shows empty state when no games or campaigns exist', function () {
        Livewire\Livewire::test(DiscoveryPortal::class)
            ->assertSee(__('discovery.field_board_game_night'))
            ->assertSee(__('discovery.field_tabletop_adventures'))
            ->assertDontSee(__('common.field_upcoming'))
            ->assertDontSee(__('discovery.content_looking_for_players'));
    });
});

describe('DiscoveryPortal – nav integration', function () {
    it('nav Discover link points to /discover portal', function () {
        // The public-layout nav renders the discover link pointing at the portal route
        get(route('discover'))
            ->assertOk()
            ->assertSee(route('discover'));
    });
});

describe('DiscoveryPortal – cross-track hints', function () {
    it('board games page shows adventure count hint when campaigns exist', function () {
        $ttrpgSystem = GameSystem::factory()->create(['type' => 'ttrpg']);

        Campaign::factory()->create([
            'status' => 'active',
            'visibility' => 'public',
            'game_system_id' => $ttrpgSystem->id,
        ]);

        $expected = trans_choice('discovery.content_also_looking_for_adventures', 1, ['count' => 1]);

        Livewire\Livewire::test(BoardGamesDiscovery::class)
            ->assertViewHas('adventureCount', 1)
            ->assertSee($expected);
    });

    it('board games page hides hint when no campaigns exist', function () {
        Livewire\Livewire::test(BoardGamesDiscovery::class)
            ->assertViewHas('adventureCount', 0);
    });

    it('adventures page shows board game count hint when sessions exist', function () {
        $boardgameSystem = GameSystem::factory()->create(['type' => 'boardgame']);

        Game::factory()->create([
            'status' => 'scheduled',
            'date_time' => now()->addDay(),
            'visibility' => 'public',
            'game_system_id' => $boardgameSystem->id,
        ]);

        $expected = trans_choice('discovery.content_also_into_board_games', 1, ['count' => 1]);

        Livewire\Livewire::test(AdventuresDiscovery::class)
            ->assertViewHas('boardGameSessionCount', 1)
            ->assertSee($expected);
    });

    it('adventures page hides hint when no sessions exist', function () {
        Livewire\Livewire::test(AdventuresDiscovery::class)
            ->assertViewHas('boardGameSessionCount', 0);
    });
});
