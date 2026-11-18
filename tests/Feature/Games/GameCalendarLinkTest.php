<?php

use App\Livewire\Games\GameDetail;
use App\Models\Game;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

/**
 * D287 zero-OAuth calendar tier — the game-page affordance (M081/S07/T02).
 *
 * GameDetail renders a stateless "Add to calendar" action row: a Google
 * Calendar template URL (action=TEMPLATE) with UTC basic-format dates, the
 * localized game title, and a details deep link, plus a secondary link to
 * the Settings calendar section (the personal webcal feed stays an explicit
 * Settings action — this page never lazily mints a feed token). Undated
 * games are not a reachable page state (games.date_time is NOT NULL); the
 * URL builder's null path is covered DB-free by the Discord card suite.
 */
describe('game page add-to-calendar link', function () {
    it('renders a Google-template link with UTC basic-format dates, title and settings link', function () {
        app()->setLocale('en');

        $owner = User::factory()->create();
        // A fixed wall time in UTC so the basic-format pin is derivable from
        // the fixture instant; the date is future-relative so the page (and
        // any windowing) never ages out.
        $start = Carbon::parse(now()->addDays(7)->toDateString().' 18:00:00', 'UTC');
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => $start,
            'expected_duration' => 3,
            'name' => ['en' => 'KickoffOneShot'],
            'status' => 'scheduled',
        ]);

        Livewire::actingAs($owner)
            ->test(GameDetail::class, ['id' => $game->id])
            // Label via key resolution, no literal pin.
            ->assertSee(__('games.action_add_to_calendar'))
            // The href params (asserted unescaped; the & separators are
            // HTML-escaped in the attribute and the dates separator is
            // percent-encoded by the query builder, so each param is pinned
            // alone).
            ->assertSee('action=TEMPLATE', false)
            ->assertSee(
                'dates='.$start->format('Ymd\THis\Z').'%2F'.$start->copy()->addHours(3)->format('Ymd\THis\Z'),
                false,
            )
            ->assertSee('text=KickoffOneShot', false)
            // Secondary affordance: the webcal subscribe surface stays in
            // Settings — linked, never minted here.
            ->assertSee(route('settings.show', ['locale' => 'en']), false);
    });

    it('localizes the label and the event title for DE', function () {
        app()->setLocale('de');

        $owner = User::factory()->create();
        $start = Carbon::parse(now()->addDays(7)->toDateString().' 18:00:00', 'UTC');
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'date_time' => $start,
            'expected_duration' => 3,
            'name' => ['en' => 'KickoffOneShot', 'de' => 'Auftaktrunde'],
            'status' => 'scheduled',
        ]);

        Livewire::actingAs($owner)
            ->test(GameDetail::class, ['id' => $game->id])
            ->assertSee(__('games.action_add_to_calendar'))
            ->assertSee('text=Auftaktrunde', false)
            ->assertDontSee('text=KickoffOneShot', false);
    });
});
