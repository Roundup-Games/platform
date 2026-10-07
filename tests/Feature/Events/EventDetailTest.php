<?php

use App\Livewire\Events\EventDetail;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
 * M053 / S01 / T06 — Route event & team location display through the
 * disclosure service (no orphans).
 *
 * M063 / S06 / T01 — The tables map of the day on the event detail page:
 * host trust line, honest system chips (R051), seat state, join CTA with
 * the D157 no-spot nudge (never a hard block), the registrant self-state
 * sidebar, and the cancelled-event banner.
 */

describe('EventDetailTest', function () {
    it('renders the event venue and city through the location-display component', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'Grand Venue Tournament'],
            'venue_name' => 'Cafe Meeple',
            'city' => 'Springfield',
            'country' => 'US',
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->assertSee('Grand Venue Tournament')
            // quick-info row composes venue + city as "Cafe Meeple, Springfield, US"
            ->assertSee('Cafe Meeple, Springfield, US');
    });

    it('renders the venue card locality through the location-display component', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'Full Venue Event'],
            'venue_name' => 'Town Hall',
            'venue_address' => '123 Main St',
            'city' => 'Springfield',
            'postal_code' => '12345',
            'country' => 'US',
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $html = Livewire::test(EventDetail::class, ['slug' => $event->slug])->html();

        // Venue card composes every locality field through the component.
        expect($html)->toContain('Town Hall, 123 Main St, Springfield, 12345, US');
    });

    it('renders no location marker when the event has no venue or city fields', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'No Venue Event'],
            'venue_name' => null,
            'venue_address' => null,
            'city' => null,
            'country' => null,
            'postal_code' => null,
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        $html = Livewire::test(EventDetail::class, ['slug' => $event->slug])->html();

        // Fail-closed: empty raw-city set renders nothing — no location line
        // (the <x-location-display> icon) and no stray address/city text.
        expect($html)->toContain('No Venue Event');
        expect($html)->not->toContain('location_on');
    });
});

// ── Helpers ──────────────────────────────────────────────

/**
 * A public, registration-open event with an explicit organizer.
 */
function tablesMapEvent(?User $organizer = null): Event
{
    return Event::factory()->create([
        'organizer_id' => $organizer ?? User::factory(),
        'is_public' => true,
        'status' => 'registration_open',
    ]);
}

/**
 * A table (Gathering) hosted at the event with deterministic systems and
 * seat aggregates: $approved approved + $waitlisted waitlisted players.
 */
function tablesMapTable(Event $event, string $name, int $approved = 0, int $waitlisted = 0, ?int $maxPlayers = 8): Game
{
    $table = Game::factory()
        ->gathering()
        ->event($event)
        ->withGameSystems([GameSystem::factory()->create()->id])
        ->create([
            'name' => $name,
            'max_players' => $maxPlayers,
            'date_time' => now()->addDay()->setTime(10, 0),
        ]);

    GameParticipant::factory()->count($approved)->create([
        'game_id' => $table,
        'status' => 'approved',
    ]);
    GameParticipant::factory()->count($waitlisted)->create([
        'game_id' => $table,
        'status' => 'waitlisted',
    ]);

    return $table;
}

// ═══════════════════════════════════════════════════════
// TABLES SECTION — THE MAP OF THE DAY (M063/S06/T01)
// ═══════════════════════════════════════════════════════

describe('EventDetail tables map', function () {
    it('renders each table with host trust line, honest system chips and seat state', function () {
        $event = tablesMapEvent();
        $table = tablesMapTable($event, 'Founders Table', approved: 2, waitlisted: 1, maxPlayers: 8);

        $component = Livewire::test(EventDetail::class, ['slug' => $event->slug]);

        $component
            ->assertSee(__('events.content_tables_at_this_get_together'))
            ->assertSee('Founders Table')
            // Host name links the public profile (user-link trust line)
            ->assertSee($table->owner->name)
            ->assertSee(route('profile.public', ['locale' => app()->getLocale(), 'user' => $table->owner]))
            // Honest multi-system rendering: every offered system is chipped
            ->assertSee($table->gameSystems->first()->name)
            // Seat state: approved/max + waitlist depth
            ->assertSee('2/8 seats')
            ->assertSee('1 waitlisted')
            // The table title routes into the game's existing join flow
            ->assertSee(route('games.detail', ['locale' => app()->getLocale(), 'id' => $table]));
    });

    it('switches the join CTA to the waitlist variant when the table is full', function () {
        $event = tablesMapEvent();
        $user = User::factory()->create();
        EventRegistration::factory()->free()->create(['event_id' => $event, 'user_id' => $user]);
        tablesMapTable($event, 'Packed Table', approved: 4, waitlisted: 0, maxPlayers: 4);

        actingAs($user);

        Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->assertSee(__('events.action_join_waitlist'));
    });

    it('shows guests the sign-up variant instead of the join flow', function () {
        $event = tablesMapEvent();
        tablesMapTable($event, 'Members Only Table');

        Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->assertSee(__('events.content_sign_up_free_to_join_this_table'))
            ->assertSee(route('register'))
            ->assertDontSee(__('events.action_join_table'));
    });

    it('caps the map at twelve rows behind a show-all toggle', function () {
        $event = tablesMapEvent();
        for ($i = 1; $i <= 13; $i++) {
            Game::factory()
                ->gathering()
                ->event($event)
                ->create([
                    'name' => sprintf('Map Table %02d', $i),
                    'date_time' => now()->addDay()->setTime(10, 0)->addMinutes($i),
                ]);
        }

        $component = Livewire::test(EventDetail::class, ['slug' => $event->slug]);

        $component
            ->assertSee('Map Table 12')
            ->assertDontSee('Map Table 13')
            ->assertSee(__('events.action_show_all_n_tables', ['count' => 13]));

        // Expanding reveals the hidden row and offers the collapse toggle.
        $component
            ->set('showAllTables', true)
            ->assertSee('Map Table 13')
            ->assertSee(__('events.action_show_fewer_tables'))
            ->assertDontSee(__('events.action_show_all_n_tables', ['count' => 13]));
    });

    it('shows the public still-being-planned empty state to visitors', function () {
        $event = tablesMapEvent();

        Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->assertSee(__('events.content_tables_still_being_planned'))
            ->assertDontSee(__('events.action_host_a_table'));
    });

    it('shows managers the bring-a-community empty state and the host-a-table CTA', function () {
        $organizer = User::factory()->create();
        $event = tablesMapEvent($organizer);

        actingAs($organizer);

        Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->assertSee(__('events.content_no_tables_yet_manager'))
            ->assertSee(__('events.action_host_a_table'))
            ->assertSee(route('games.create', ['type' => 'gathering', 'event' => $event->slug]));
    });

    it('loads the map in one eager pass without per-table queries', function () {
        $event = tablesMapEvent();
        for ($i = 1; $i <= 5; $i++) {
            tablesMapTable($event, sprintf('Eager Table %d', $i), approved: 2, waitlisted: 1);
        }

        DB::enableQueryLog();
        Livewire::test(EventDetail::class, ['slug' => $event->slug])->html();
        $queries = array_map(
            fn (array $entry): string => str_replace(['"', '`'], '', $entry['query']),
            DB::getQueryLog()
        );
        DB::disableQueryLog();

        // One tables query (the seat aggregates ride along as subqueries)...
        $gamesQueries = array_filter(
            $queries,
            fn (string $query): bool => str_contains($query, 'from games where games.event_id')
        );
        expect($gamesQueries)->toHaveCount(1);

        // ...no standalone participant loads (lazy access would emit these)...
        $participantQueries = array_filter(
            $queries,
            fn (string $query): bool => str_starts_with($query, 'select * from game_participants')
        );
        expect($participantQueries)->toHaveCount(0);

        // ...and owners load in one IN query, not one per table (5 lazy
        // owner loads + the eager query would exceed the bound below).
        $userQueries = array_filter(
            $queries,
            fn (string $query): bool => str_starts_with($query, 'select * from users')
        );
        expect(count($userQueries))->toBeLessThan(4);
    });
});

// ═══════════════════════════════════════════════════════
// D157 JOIN NUDGE — SOFT GATE, NEVER A HARD BLOCK
// ═══════════════════════════════════════════════════════

describe('EventDetail join nudge', function () {
    it('nudges authenticated non-registrants with register-first and join-anyway, never a block', function () {
        $event = tablesMapEvent();
        $table = tablesMapTable($event, 'Open Table');
        $stranger = User::factory()->create();

        actingAs($stranger);

        Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->assertSee(__('events.content_join_nudge_no_event_spot'))
            ->assertSee(__('events.action_register_first'))
            ->assertSee(__('events.action_join_anyway'))
            // Register-first deep-links the event's register flow...
            ->assertSee(route('events.register', ['slug' => $event->slug]))
            // ...and join-anyway still reaches the game — the soft gate
            // never blocks the table's own join flow.
            ->assertSee(route('games.detail', ['locale' => app()->getLocale(), 'id' => $table]));
    });

    it('skips the nudge for registrants', function () {
        $event = tablesMapEvent();
        tablesMapTable($event, 'Regulars Table');
        $registrant = User::factory()->create();
        EventRegistration::factory()->free()->create(['event_id' => $event, 'user_id' => $registrant]);

        actingAs($registrant);

        Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->assertDontSee(__('events.content_join_nudge_no_event_spot'))
            ->assertSee(__('events.action_join_table'));
    });

    it('does not nudge guests — they get the sign-up variant instead', function () {
        $event = tablesMapEvent();
        tablesMapTable($event, 'Public Table');

        Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->assertDontSee(__('events.content_join_nudge_no_event_spot'))
            ->assertDontSee(__('events.action_join_anyway'))
            ->assertSee(__('events.content_sign_up_free_to_join_this_table'));
    });
});

// ═══════════════════════════════════════════════════════
// REGISTRANT SELF-STATE (63-UI-SPEC §3.5)
// ═══════════════════════════════════════════════════════

describe('EventDetail registrant self-state', function () {
    it('replaces the register CTA with the you-are-registered state for confirmed registrants', function () {
        $event = tablesMapEvent();
        $registrant = User::factory()->create();
        EventRegistration::factory()->free()->create(['event_id' => $event, 'user_id' => $registrant]);

        actingAs($registrant);

        Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->assertSee(__('events.content_you_re_registered'))
            ->assertDontSee(__('events.action_register_now'));
    });

    it('shows the payment-pending variant with the instructions line', function () {
        $event = tablesMapEvent();
        $pendingRegistrant = User::factory()->create();
        EventRegistration::factory()->pending()->create(['event_id' => $event, 'user_id' => $pendingRegistrant]);

        actingAs($pendingRegistrant);

        Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->assertSee(__('events.content_your_spot_is_reserved_payment_pending'))
            ->assertSee(__('events.content_payment_pending_hint'))
            ->assertDontSee(__('events.content_you_re_registered'))
            ->assertDontSee(__('events.action_register_now'));
    });

    it('treats a cancelled registration as no registration at all', function () {
        $event = tablesMapEvent();
        $formerRegistrant = User::factory()->create();
        EventRegistration::factory()->cancelled()->create(['event_id' => $event, 'user_id' => $formerRegistrant]);

        actingAs($formerRegistrant);

        Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->assertDontSee(__('events.content_you_re_registered'))
            ->assertSee(__('events.action_register_now'));
    });

    it('still offers the register CTA to authenticated non-registrants', function () {
        $event = tablesMapEvent();

        actingAs(User::factory()->create());

        Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->assertSee(__('events.action_register_now'))
            ->assertSee(route('events.register', ['slug' => $event->slug]));
    });
});

// ═══════════════════════════════════════════════════════
// CANCELLED-EVENT BANNER
// ═══════════════════════════════════════════════════════

describe('EventDetail cancelled banner', function () {
    it('tells registrants their registration was released with refund info', function () {
        $event = tablesMapEvent();
        $event->update(['status' => 'cancelled']);
        $registrant = User::factory()->create();
        EventRegistration::factory()->paid()->create(['event_id' => $event, 'user_id' => $registrant]);

        actingAs($registrant);

        Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->assertSee(__('events.content_this_get_together_was_cancelled'))
            ->assertSee(__('events.content_cancelled_your_registration_released'))
            ->assertDontSee(__('events.content_you_re_registered'));
    });

    it('shows the general release copy to visitors', function () {
        $event = tablesMapEvent();
        $event->update(['status' => 'cancelled']);

        Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->assertSee(__('events.content_this_get_together_was_cancelled'))
            ->assertSee(__('events.content_cancelled_registrations_released'))
            ->assertDontSee(__('events.content_cancelled_your_registration_released'));
    });
});

// ═══════════════════════════════════════════════════════
// HERO OFFERING SUMMARY (M063/S06/T02)
// ═══════════════════════════════════════════════════════

describe('EventDetail hero offering summary', function () {
    it('shows the derived union of every table\'s systems with the tables count', function () {
        $event = tablesMapEvent();
        $shared = GameSystem::factory()->create();
        $onlyTableOne = GameSystem::factory()->create();
        $onlyTableTwo = GameSystem::factory()->create();

        Game::factory()->gathering()->event($event)->withGameSystems([$shared->id, $onlyTableOne->id])->create([
            'name' => 'Alpha Table',
            'date_time' => now()->addDay()->setTime(10, 0),
            'max_players' => 6,
        ]);
        Game::factory()->gathering()->event($event)->withGameSystems([$shared->id, $onlyTableTwo->id])->create([
            'name' => 'Beta Table',
            'date_time' => now()->addDay()->setTime(11, 0),
            'max_players' => 6,
        ]);

        Livewire::test(EventDetail::class, ['slug' => $event->slug])
            // "3 games on offer" — the deduped union, not the per-table sets
            ->assertSee(trans_choice('games.content_n_games_on_offer', 3))
            // "· 2 tables" — the map-of-the-day count beside it (UI spec §3.3)
            ->assertSee(trans_choice('events.content_n_tables', 2));
    });

    it('shows no offering summary when the event has no tables', function () {
        $event = tablesMapEvent();

        Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->assertDontSee(__('games.content_n_games_on_offer'));
    });
});

// ═══════════════════════════════════════════════════════
// DERIVED OFFERING CACHE INVALIDATION (M063/S06/T02)
// ═══════════════════════════════════════════════════════
//
// Event::offeredSystems() caches the union per event (discovery TTL). A
// cached offering MUST NOT outlive the underlying tables: GameObserver
// flushes on saved/deleted (covering attach via the current id and detach
// via the pre-save original id), and the host-a-table sync site flushes
// explicitly (pivot syncs fire no model events).

describe('EventDetail derived offering cache invalidation', function () {
    it('recomputes when a hosted table\'s systems change and it saves', function () {
        $event = tablesMapEvent();
        $table = Game::factory()->gathering()->event($event)->withGameSystems([
            GameSystem::factory()->create()->id,
        ])->create(['date_time' => now()->addDay(), 'max_players' => 6]);

        // Warm the cache with the one-system offering.
        expect(Event::find($event->id)->offeredSystems())->toHaveCount(1);

        // Change the table's systems, then save — observer flushes the key.
        $table->gameSystems()->sync([
            GameSystem::factory()->create()->id,
            GameSystem::factory()->create()->id,
        ]);
        $table->save();

        expect(Event::find($event->id)->offeredSystems())->toHaveCount(2);
    });

    it('recomputes when a table detaches from the event (pre-save original id)', function () {
        $event = tablesMapEvent();
        $table = Game::factory()->gathering()->event($event)->withGameSystems([
            GameSystem::factory()->create()->id,
        ])->create(['date_time' => now()->addDay(), 'max_players' => 6]);

        expect(Event::find($event->id)->offeredSystems())->toHaveCount(1);

        // Detach: event_id goes uuid → null; the flush must use the
        // PRE-SAVE id (getOriginal inside the saved hook).
        $table->event()->dissociate();
        $table->save();

        expect(Event::find($event->id)->offeredSystems())->toBeEmpty();
    });

    it('recomputes when a hosted table is deleted', function () {
        $event = tablesMapEvent();
        $table = Game::factory()->gathering()->event($event)->withGameSystems([
            GameSystem::factory()->create()->id,
        ])->create(['date_time' => now()->addDay(), 'max_players' => 6]);

        expect(Event::find($event->id)->offeredSystems())->toHaveCount(1);

        $table->delete();

        expect(Event::find($event->id)->offeredSystems())->toBeEmpty();
    });

    it('drops the cache key entirely, not just a stale value', function () {
        $event = tablesMapEvent();
        $table = Game::factory()->gathering()->event($event)->withGameSystems([
            GameSystem::factory()->create()->id,
        ])->create(['date_time' => now()->addDay(), 'max_players' => 6]);

        expect(Event::find($event->id)->offeredSystems())->toHaveCount(1);
        expect(Cache::has(Event::offeredSystemsCacheKey($event->id)))->toBeTrue();

        $table->delete();

        expect(Cache::has(Event::offeredSystemsCacheKey($event->id)))->toBeFalse();
    });
});
