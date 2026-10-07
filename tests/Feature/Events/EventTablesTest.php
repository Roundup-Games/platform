<?php

use App\Livewire\Events\ManageEvent;
use App\Livewire\Games\CreateGame;
use App\Livewire\Games\GameDetail;
use App\Models\Event;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\EventDelegationService;
use App\Services\EventLifecycleService;
use Illuminate\Support\Facades\Gate;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

// ── Helpers ──────────────────────────────────────────────

/**
 * A host-capable user: profile-complete (GamePolicy::create) — event
 * authorization rides on organizer ownership, no Spatie grants needed.
 */
function eventTablesHost(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'profile_complete' => true,
    ], $overrides));
}

/**
 * Drive the CreateGame host-a-table flow: mount with the ?event= context
 * pre-selecting the Gathering type, fill the minimum valid form, save.
 */
function hostTableAtEvent(User $host, Event $event, string $name = 'Founders Table'): Game
{
    Livewire\Livewire::actingAs($host)
        ->withQueryParams(['event' => $event->slug, 'type' => 'gathering'])
        ->test(CreateGame::class)
        ->set('name', $name)
        ->set('game_systems', [GameSystem::factory()->create()->id])
        ->set('date_time', now()->addWeek()->format('Y-m-d\TH:i'))
        ->set('max_players', 12)
        ->call('save')
        ->assertRedirect();

    return Game::where('owner_id', $host->id)->firstOrFail();
}

// ═══════════════════════════════════════════════════════════
// HOST-A-TABLE CREATION FLOW (M063/S05/T02)
// ═══════════════════════════════════════════════════════════

describe('Host-a-table creation flow', function () {
    it('frames the form as hosting at the event and attaches the table on save', function () {
        $organizer = eventTablesHost();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        $component = Livewire\Livewire::actingAs($organizer)
            ->withQueryParams(['event' => $event->slug, 'type' => 'gathering'])
            ->test(CreateGame::class);

        // Header framed as 'Host a Table at <event>', context chip carries
        // the event name — and nothing on the form is pre-filled from it.
        $component
            ->assertSee(__('events.action_host_a_table_at_event', ['event' => $event->name]))
            ->assertSee(__('events.content_host_a_table_at_event_help'));

        $component
            ->set('name', 'Founders Table')
            ->set('game_systems', [GameSystem::factory()->create()->id])
            ->set('date_time', now()->addWeek()->format('Y-m-d\TH:i'))
            ->set('max_players', 12)
            ->call('save')
            ->assertRedirect();

        $game = Game::where('owner_id', $organizer->id)->firstOrFail();
        expect($game->event_id)->toBe($event->id)
            ->and($game->game_type->value)->toBe('gathering');
    });

    it('keeps the regular creation header without an event context', function () {
        $user = eventTablesHost();

        Livewire\Livewire::actingAs($user)
            ->test(CreateGame::class)
            ->assertSee(__('games.action_create_game_session'))
            ->assertDontSee(__('events.content_host_a_table_at_event_help'));
    });

    it('blocks users without event update permission at form load', function () {
        $organizer = eventTablesHost();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        $stranger = eventTablesHost();

        // Full-page request: a stranger must never reach the form (the
        // Livewire test driver does not surface mount exceptions, so the
        // gate is asserted at the HTTP boundary like CreateEventTest).
        actingAs($stranger);
        get(route('games.create', ['event' => $event->slug]))
            ->assertForbidden();
    });

    it('re-checks event authorization at save time when the context changes', function () {
        $organizer = eventTablesHost();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        $stranger = eventTablesHost();

        // The stranger reaches the form without an event context, then a
        // tampered ?event param arrives only at save: save() must authorize
        // the event too, not trust the load-time check — so nothing is
        // persisted. Asserted on the outcome (no game row) because the
        // Livewire test driver does not surface action exceptions.
        Livewire\Livewire::actingAs($stranger)
            ->test(CreateGame::class)
            ->call('selectType', 'gathering')
            ->set('name', 'Sneaky Table')
            ->set('game_systems', [GameSystem::factory()->create()->id])
            ->set('date_time', now()->addWeek()->format('Y-m-d\TH:i'))
            ->set('max_players', 12)
            ->set('event', $event->slug)
            ->call('save');

        expect(Game::where('owner_id', $stranger->id)->count())->toBe(0);
    });
});

// ═══════════════════════════════════════════════════════════
// MANAGE EVENT → TABLES TAB (M063/S05/T02)
// ═══════════════════════════════════════════════════════════

describe('ManageEvent Tables tab', function () {
    it('shows the empty state and the host-a-table CTA when no tables exist', function () {
        $organizer = eventTablesHost();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        actingAs($organizer);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $event->slug])
            ->set('activeTab', 'tables')
            ->assertSee(__('events.content_no_tables_yet'))
            ->assertSee(__('events.action_host_a_table'))
            // Blade escapes & in the href, so the rendered attribute carries &amp;
            ->assertSeeHtml('games/create?type=gathering&amp;event='.$event->slug);
    });

    it('lists hosted tables with title, host, system chips, and seat state', function () {
        $organizer = eventTablesHost();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        // A table hosted through the real flow: one owner participant seat
        // taken of the 12 Gathering defaults.
        $game = hostTableAtEvent($organizer, $event, 'Founders Table');

        actingAs($organizer);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $event->slug])
            ->set('activeTab', 'tables')
            ->assertSee('Founders Table')
            ->assertSee($organizer->name)
            ->assertSee('1/12')
            ->assertSee($game->gameSystems->first()->name);
    });

    it('does not list games hosted at other events or standalone games', function () {
        $organizer = eventTablesHost();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        $otherEvent = Event::factory()->create(['organizer_id' => $organizer->id]);

        Game::factory()->gathering()->event($otherEvent)->create([
            'owner_id' => $organizer->id,
            'name' => ['en' => 'Other Event Table'],
        ]);
        Game::factory()->gathering()->create([
            'owner_id' => $organizer->id,
            'name' => ['en' => 'Standalone Gathering'],
        ]);

        actingAs($organizer);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $event->slug])
            ->set('activeTab', 'tables')
            ->assertSee(__('events.content_no_tables_yet'))
            ->assertDontSee('Other Event Table')
            ->assertDontSee('Standalone Gathering');
    });

    it('detaches a table, leaving the game itself untouched', function () {
        $organizer = eventTablesHost();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        $table = Game::factory()->gathering()->event($event)->create([
            'owner_id' => $organizer->id,
            'name' => ['en' => 'Founders Table'],
        ]);

        actingAs($organizer);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $event->slug])
            ->set('activeTab', 'tables')
            ->assertSee('Founders Table')
            ->call('detachTable', $table->id);

        // Re-fetch rather than refresh(): the gathering() factory leaves a
        // transient factoryPivotClaimed relation on the instance that
        // refresh() would try to reload as a real relationship.
        $detached = Game::whereKey($table->id)->firstOrFail();
        expect($detached->exists())->toBeTrue()
            ->and($detached->event_id)->toBeNull()
            // The game itself is untouched: status and offered systems stay,
            // only the event link is gone.
            ->and($detached->status->value)->toBe('scheduled')
            ->and($detached->gameSystems)->toHaveCount(2);

        expect($event->tables()->count())->toBe(0);
    });

    it('leaves a table hosted at another event untouched', function () {
        $organizer = eventTablesHost();
        $eventA = Event::factory()->create(['organizer_id' => $organizer->id]);
        $eventB = Event::factory()->create(['organizer_id' => $organizer->id]);

        $foreignTable = Game::factory()->gathering()->event($eventB)->create([
            'owner_id' => $organizer->id,
            'name' => ['en' => 'Foreign Table'],
        ]);

        // Detach is relation-scoped: managing event A can never detach a
        // table hosted under event B's umbrella. Outcome-asserted (the
        // foreign link survives) because the Livewire test driver does not
        // surface the relation 404.
        actingAs($organizer);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $eventA->slug])
            ->set('activeTab', 'tables')
            ->call('detachTable', $foreignTable->id);

        $stillHosted = Game::whereKey($foreignTable->id)->firstOrFail();
        expect($stillHosted->event_id)->toBe($eventB->id)
            ->and($eventB->tables()->count())->toBe(1);
    });
});

// ═══════════════════════════════════════════════════════
// GAME OWNER DETACH — OWN MANAGE SURFACE (M063/S05/T02)
// ═══════════════════════════════════════════════════════

describe('Game owner detach (own manage surface)', function () {
    it('shows the hosted-at hint and lets the owner detach their own table', function () {
        $organizer = eventTablesHost();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        $host = eventTablesHost();

        // A table owned by a host who holds NO event permission: game
        // ownership alone must gate the owner-side detach (the event-side
        // detachTable stays event-update gated on the ManageEvent surface).
        $table = Game::factory()->gathering()->event($event)->create([
            'owner_id' => $host->id,
            'name' => ['en' => 'Founders Table'],
        ]);

        actingAs($host);
        Livewire\Livewire::test(GameDetail::class, ['id' => $table->id])
            ->assertSee(__('events.content_hosted_at_event_hint', ['event' => $event->name]))
            ->call('detachFromEvent');

        $detached = Game::whereKey($table->id)->firstOrFail();
        expect($detached->exists())->toBeTrue()
            ->and($detached->event_id)->toBeNull()
            ->and($detached->status->value)->toBe('scheduled')
            ->and($detached->gameSystems)->toHaveCount(2);

        expect($event->tables()->count())->toBe(0);
    });

    it('hides the affordance from non-owners and rejects their calls', function () {
        $organizer = eventTablesHost();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        $host = eventTablesHost();
        $stranger = eventTablesHost();

        $table = Game::factory()->gathering()->event($event)->create([
            'owner_id' => $host->id,
            'name' => ['en' => 'Founders Table'],
        ]);

        // Public game: the stranger may view the page but must never see
        // the owner-only detach row. Outcome-asserted for the action gate
        // (the Livewire test driver does not surface action exceptions).
        actingAs($stranger);
        Livewire\Livewire::test(GameDetail::class, ['id' => $table->id])
            ->assertDontSee(__('events.content_hosted_at_event_hint', ['event' => $event->name]))
            ->call('detachFromEvent');

        expect(Game::whereKey($table->id)->firstOrFail()->event_id)->toBe($event->id);
    });
});

// ═════════════════════════════════════════════════════
// TABLE LIFECYCLE SEMANTICS (M063/S05/T03)
// ═════════════════════════════════════════════════════

describe('Hosting lifecycle semantics', function () {
    it('offers the host-a-table CTA while the event is published or open for registration', function (string $status) {
        $organizer = eventTablesHost();
        $event = Event::factory()->create(['organizer_id' => $organizer->id, 'status' => $status]);

        actingAs($organizer);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $event->slug])
            ->set('activeTab', 'tables')
            ->assertSee(__('events.action_host_a_table'))
            ->assertSeeHtml('games/create?type=gathering');
    })->with(['published' => 'published', 'registration open' => 'registration_open']);

    it('hides the host-a-table CTA for draft, closed, completed, and cancelled events', function (string $status) {
        $organizer = eventTablesHost();
        $event = Event::factory()->create(['organizer_id' => $organizer->id, 'status' => $status]);

        actingAs($organizer);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $event->slug])
            ->set('activeTab', 'tables')
            ->assertDontSee(__('events.action_host_a_table'))
            ->assertDontSeeHtml('games/create?type=gathering');
    })->with(['draft' => 'draft', 'registration closed' => 'registration_closed', 'completed' => 'completed', 'cancelled' => 'cancelled']);

    it('rejects attaching a table to a closed, completed, or cancelled event with a validation error', function (string $status) {
        $organizer = eventTablesHost();
        $event = Event::factory()->create(['organizer_id' => $organizer->id, 'status' => $status]);

        // Otherwise-valid form: the event's status is the only failing
        // input, so the validation error is attributable to the lifecycle
        // gate — and nothing is persisted on the failure path.
        Livewire\Livewire::actingAs($organizer)
            ->withQueryParams(['event' => $event->slug, 'type' => 'gathering'])
            ->test(CreateGame::class)
            ->set('name', 'Ghost Table')
            ->set('game_systems', [GameSystem::factory()->create()->id])
            ->set('date_time', now()->addWeek()->format('Y-m-d\TH:i'))
            ->set('max_players', 12)
            ->call('save')
            ->assertHasErrors('event');

        expect(Game::where('owner_id', $organizer->id)->count())->toBe(0);
    })->with(['cancelled' => 'cancelled', 'registration closed' => 'registration_closed', 'completed' => 'completed']);

    it('leaves hosted tables intact when the event is cancelled', function () {
        $organizer = eventTablesHost();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        $table = hostTableAtEvent($organizer, $event, 'Founders Table');

        app(EventLifecycleService::class)->cancel($event);

        // Lifecycle-neutral link (R059): cancellation never touches the
        // table — it survives still linked, still scheduled, seat still
        // held, and the cancelled event still lists it.
        $survivor = Game::whereKey($table->id)->firstOrFail();
        expect($survivor->exists())->toBeTrue()
            ->and($survivor->event_id)->toBe($event->id)
            ->and($survivor->status->value)->toBe('scheduled')
            ->and($survivor->participants()->count())->toBe(1)
            ->and($event->tables()->count())->toBe(1);
    });

    it('leaves hosted tables intact when the event completes', function () {
        $organizer = eventTablesHost();
        $event = Event::factory()->create(['organizer_id' => $organizer->id, 'status' => 'in_progress']);

        // Attached while the event was still accepting tables (the flow
        // now rejects attaches past registration_open, so the link is
        // arranged via the factory — the state a real table is in).
        $table = Game::factory()->gathering()->event($event)->create([
            'owner_id' => $organizer->id,
            'name' => ['en' => 'Founders Table'],
        ]);

        // Completion through the real manage surface: a valid
        // in_progress → completed transition via the status select.
        actingAs($organizer);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $event->slug])
            ->set('status', 'completed')
            ->call('save');

        expect($event->fresh()->status->value)->toBe('completed');

        $survivor = Game::whereKey($table->id)->firstOrFail();
        expect($survivor->exists())->toBeTrue()
            ->and($survivor->event_id)->toBe($event->id)
            ->and($survivor->status->value)->toBe('scheduled');
    });
});

// ═════════════════════════════════════════════════════
// ATTACH AUTHORIZATION MATRIX (M063/S05/T03)
// ═════════════════════════════════════════════════════

describe('Attach authorization matrix', function () {
    it('lets the organizer and a delegated co-organizer host a table, but not a stranger', function () {
        seedRoles();

        $organizer = eventTablesHost();
        $coOrganizer = eventTablesHost();
        $stranger = eventTablesHost();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        // Organizer: ownership passes EventPolicy::update.
        $organizerTable = hostTableAtEvent($organizer, $event, 'Organizer Table');
        expect($organizerTable->event_id)->toBe($event->id);

        // Co-organizer: the S04 event-scoped 'Event Admin' grant is the
        // only authority they hold — granted through the real delegation
        // service so the matrix cell is the actual production grant path.
        app(EventDelegationService::class)->grantCoOrganizer($event, $coOrganizer, $organizer);

        $coOrganizerTable = hostTableAtEvent($coOrganizer, $event, 'Co-organizer Table');
        expect($coOrganizerTable->event_id)->toBe($event->id);

        // Stranger: EventPolicy::update denies, so every attach surface
        // stays closed (the HTTP boundary is proven above; this is the
        // authorization decision that both attach checks ride on).
        expect(Gate::forUser($stranger)->denies('update', $event))->toBeTrue()
            ->and($event->tables()->count())->toBe(2);
    });
});

// ═════════════════════════════════════════════════════
// TABLE INTEGRITY (M063/S05/T03)
// ═════════════════════════════════════════════════════

describe('Table integrity', function () {
    it('detaches but never destroys tables when the event is deleted', function () {
        $organizer = eventTablesHost();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        $hosted = Game::factory()->gathering()->event($event)->create([
            'owner_id' => $organizer->id,
            'name' => ['en' => 'Survivor Table'],
        ]);
        $standalone = Game::factory()->gathering()->create([
            'owner_id' => $organizer->id,
            'name' => ['en' => 'Unrelated Gathering'],
        ]);

        $event->delete();

        // FK nullOnDelete (R059): the umbrella's disappearance detaches the
        // table; the game itself — and unrelated games — survive untouched.
        $detached = Game::whereKey($hosted->id)->firstOrFail();
        expect($detached->exists())->toBeTrue()
            ->and($detached->event_id)->toBeNull()
            ->and($detached->status->value)->toBe('scheduled')
            ->and(Game::whereKey($standalone->id)->exists())->toBeTrue();
    });

    it('counts tables through the withCount aggregate and the fallback query', function () {
        $organizer = eventTablesHost();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        Game::factory()->count(3)->gathering()->event($event)->create([
            'owner_id' => $organizer->id,
        ]);

        // Card-grid path: withCount('tables') loads the aggregate in the
        // same query as the events listing.
        expect(Event::withCount('tables')->whereKey($event->id)->firstOrFail()->tablesCount())->toBe(3);

        // Fallback path: no aggregate selected → live count query.
        expect(Event::whereKey($event->id)->firstOrFail()->tablesCount())->toBe(3);
    });

    it('gives a hosted table the same seat state as an identical standalone gathering', function () {
        $organizer = eventTablesHost();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        $hosted = hostTableAtEvent($organizer, $event, 'Hosted Table');

        // Same creation flow without the event context: an otherwise
        // identical standalone Gathering by the same host.
        Livewire\Livewire::actingAs($organizer)
            ->withQueryParams(['type' => 'gathering'])
            ->test(CreateGame::class)
            ->set('name', 'Standalone Gathering')
            ->set('game_systems', [GameSystem::factory()->create()->id])
            ->set('date_time', now()->addWeek()->format('Y-m-d\TH:i'))
            ->set('max_players', 12)
            ->call('save')
            ->assertRedirect();

        $standalone = Game::where('owner_id', $organizer->id)->whereNull('event_id')->firstOrFail();

        // The event link changes nothing about seats: both hold exactly
        // the owner seat out of 12, and both sit at scheduled.
        expect($hosted->participants()->count())->toBe(1)
            ->and($standalone->participants()->count())->toBe(1)
            ->and($hosted->max_players)->toBe(12)
            ->and($standalone->max_players)->toBe(12)
            ->and($hosted->status->value)->toBe('scheduled')
            ->and($standalone->status->value)->toBe('scheduled');
    });
});

// ═══════════════════════════════════════════════════════════
// DERIVED OFFERING FLUSH ON HOST-A-TABLE (M063/S06/T02)
// ═══════════════════════════════════════════════════════════
//
// Event::offeredSystems() (the cached union of every table's systems) must
// not outlive the host-a-table flow: the pivot sync inside CreateGame fires
// no model events, so the flow flushes the umbrella's cache explicitly next
// to its sync().

describe('Host-a-table derived offering flush', function () {
    it('updates the derived offering after hosting a table through the real flow', function () {
        $organizer = eventTablesHost();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        // Warm the cache while the event is still tableless (empty union).
        expect(Event::find($event->id)->offeredSystems())->toBeEmpty();

        hostTableAtEvent($organizer, $event, 'Fresh Table');

        // The end-to-end contract a visitor sees: the derived offering on a
        // fresh read includes the new table's systems immediately — no stale
        // empty union pinned for the cache TTL.
        $fresh = Event::find($event->id);
        expect($fresh->offeredSystems())->toHaveCount(1)
            ->and($fresh->offeredSystems()->first()->id)
            ->toBe(Game::where('owner_id', $organizer->id)->firstOrFail()->gameSystems->first()->id);
    });
});
