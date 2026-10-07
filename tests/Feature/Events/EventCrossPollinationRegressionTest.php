<?php

use App\Models\Event;
use App\Models\Game;
use App\Models\Location;
use App\Services\CityDirectoryService;
use Illuminate\Support\Facades\Cache;

/*
 * M063 / S06 / T04 — Cross-pollination regression (city-hub half).
 *
 * A "table" is an ordinary Game with games.event_id set (S05). The surfaces
 * the event work touches must therefore behave identically whether or not a
 * game happens to be hosted at an event:
 *
 *  - CityDirectoryService: the hub feed keeps tagging events as
 *    hub_item_type 'event' whether or not they host tables, and tables flow
 *    through the sessions feed as ordinary 'game' items (public-visibility
 *    semantics unchanged).
 *
 * The Discord-dimension half (DiscordCardRenderer / DiscordPublisher with
 * attached tables present) lives at tests/Feature/Discord/
 * DiscordPublisherTest.php, and the full ordinary-game Discord suites at
 * tests/Feature/Services/Discord/*PublisherTest.php.
 */

beforeEach(function () {
    Cache::flush();
    // Off during fixture setup so GameObserver never dispatches the (sync
    // queue) publish job mid-factory — mirrors DiscordPublisherTest's leak
    // guard.
    config(['services.discord.publishing_enabled' => false]);
});

describe('CityDirectoryService feed with event tables', function () {
    it('keeps the hub event feed intact when an event hosts tables', function () {
        $berlin = Location::factory()->create([
            'city' => 'Berlin',
            'country' => 'DEU',
            'latitude' => 52.5200,
            'longitude' => 13.4050,
        ]);

        $event = Event::factory()->create([
            'location_id' => $berlin->id,
            'name' => ['en' => 'Berlin Game Day'],
            'status' => 'registration_open',
            'is_public' => true,
            'start_date' => now()->addDays(10),
        ]);
        $tableOne = Game::factory()
            ->gathering()
            ->event($event)
            ->create(['location_id' => $berlin->id, 'date_time' => now()->addDays(10)->setTime(11, 0)]);
        $tableTwo = Game::factory()
            ->gathering()
            ->event($event)
            ->create(['location_id' => $berlin->id, 'date_time' => now()->addDays(10)->setTime(13, 0)]);

        $service = app(CityDirectoryService::class);
        $summary = $service->resolveCity('berlin');

        // The umbrella counts once as an event; its tables are ordinary
        // public games and count in the games feed — feed semantics are
        // untouched by the event_id link.
        expect($summary->upcomingEventsCount)->toBe(1)
            ->and($summary->upcomingGamesCount)->toBe(2);

        $sessions = $service->upcomingSessions($summary);
        $eventItems = $sessions->where('hub_item_type', 'event');

        // The event stays tagged 'event' (never re-typed or duplicated per
        // table) and both tables surface as their own 'game' items.
        expect($eventItems)->toHaveCount(1)
            ->and($eventItems->first()->id)->toBe($event->id);

        $gameItemIds = $sessions->where('hub_item_type', 'game')->pluck('id')->all();
        expect($gameItemIds)->toContain($tableOne->id)
            ->toContain($tableTwo->id);
    });

    it('does not let non-public tables alter the event feed', function () {
        $berlin = Location::factory()->create([
            'city' => 'Berlin',
            'country' => 'DEU',
            'latitude' => 52.5200,
            'longitude' => 13.4050,
        ]);

        $event = Event::factory()->create([
            'location_id' => $berlin->id,
            'status' => 'registration_open',
            'is_public' => true,
            'start_date' => now()->addDays(10),
        ]);
        Game::factory()
            ->gathering()
            ->event($event)
            ->create([
                'location_id' => $berlin->id,
                'visibility' => 'protected',
                'date_time' => now()->addDays(10)->setTime(12, 0),
            ]);

        $service = app(CityDirectoryService::class);
        $summary = $service->resolveCity('berlin');

        // Fail-closed feed: a non-public table neither feeds the games count
        // nor disturbs the (still-listed) public event item.
        expect($summary->upcomingGamesCount)->toBe(0)
            ->and($summary->upcomingEventsCount)->toBe(1);

        $sessions = $service->upcomingSessions($summary);
        expect($sessions)->toHaveCount(1)
            ->and($sessions->first()->hub_item_type)->toBe('event')
            ->and($sessions->first()->id)->toBe($event->id);
    });
});
