<?php

use App\Filament\Resources\EventResource;
use App\Livewire\Events\EventAnnouncements;
use App\Livewire\Events\ManageEvent;
use App\Livewire\Events\ManageRegistrations;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Notifications\EventCoOrganizerAdded;
use App\Services\EventDelegationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    seedRoles();

    $this->service = app(EventDelegationService::class);

    // profile_complete + (factory-default) email_verified_at let every
    // actor reach the manage surfaces' auth/verified/profile.complete
    // middleware, so denials come from EventPolicy — not onboarding
    // redirects.
    $this->organizer = User::factory()->create(['profile_complete' => true]);
    $this->coOrganizer = User::factory()->create(['profile_complete' => true]);
    $this->stranger = User::factory()->create(['profile_complete' => true]);
    $this->otherOrganizer = User::factory()->create(['profile_complete' => true]);
    $this->platformAdmin = User::factory()->create(['profile_complete' => true]);

    setPermissionsTeamId(null);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $this->platformAdmin->assignRole('Platform Admin');
    $this->platformAdmin->unsetRelations();

    $this->event = Event::factory()->create([
        'organizer_id' => $this->organizer->id,
        'is_public' => true,
    ]);
    $this->otherEvent = Event::factory()->create([
        'organizer_id' => $this->otherOrganizer->id,
        'is_public' => true,
    ]);
});

// ── Grant invariants ─────────────────────────────────
//
// Consolidated from ten junit cases into four tests. Cases that shared
// an identical setup (a single grant call) now share one setup while
// keeping every assertion; distinct failure modes stay distinct
// expectations inside the refusal test, each under its own comment.

describe('Co-organizer grant', function () {
    test('grants the scoped role with update access to that event only, one pivot row, listed as co-organizer', function () {
        // Old: 'grants the event-scoped Event Admin role and update access to
        // that event only' + 'writes a single scoped pivot row and lists the
        // target as co-organizer'.
        $granted = $this->service->grantCoOrganizer($this->event, $this->coOrganizer, $this->organizer);

        $roleId = Role::where('name', 'Event Admin')->whereNull('team_id')->value('id');

        $assignments = DB::table('model_has_roles')
            ->where('role_id', $roleId)
            ->where('team_id', $this->event->id)
            ->where('model_type', (new User)->getMorphClass())
            ->where('model_id', $this->coOrganizer->id)
            ->get();

        expect($granted)->toBeTrue()
            ->and($this->service->isCoOrganizer($this->coOrganizer, $this->event))->toBeTrue()
            ->and($this->coOrganizer->can('update', $this->event))->toBeTrue()
            // The scope is exactly one event: no access to another event.
            ->and($this->service->isCoOrganizer($this->coOrganizer, $this->otherEvent))->toBeFalse()
            ->and($this->coOrganizer->can('update', $this->otherEvent))->toBeFalse()
            ->and($assignments)->toHaveCount(1)
            ->and($this->service->coOrganizers($this->event)->pluck('id'))
            ->toContain($this->coOrganizer->id)
            ->not->toContain($this->organizer->id);
    });

    test('audit-logs the grant and treats duplicate grants as skipped idempotent no-ops', function () {
        // Old: 'audit-logs the grant with actor, target user, and event id' +
        // 'duplicate grant is idempotent: returns false, no second
        // assignment, no second listing entry' + 'audit-logs the duplicate
        // grant as a skipped no-op'.
        Log::shouldReceive('info')
            ->once()
            ->with('event.co_organizer_granted', Mockery::on(fn (array $ctx) => $ctx['actor_id'] === $this->organizer->id
                && $ctx['target_user_id'] === $this->coOrganizer->id
                && $ctx['event_id'] === $this->event->id
            ));
        Log::shouldReceive('info')
            ->once()
            ->with('event.co_organizer_grant_skipped_duplicate', Mockery::on(fn (array $ctx) => $ctx['target_user_id'] === $this->coOrganizer->id
                && $ctx['event_id'] === $this->event->id
            ));
        // Swallow any incidental log calls.
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $first = $this->service->grantCoOrganizer($this->event, $this->coOrganizer, $this->organizer);
        $second = $this->service->grantCoOrganizer($this->event, $this->coOrganizer, $this->organizer);

        $roleId = Role::where('name', 'Event Admin')->whereNull('team_id')->value('id');

        $assignmentCount = DB::table('model_has_roles')
            ->where('role_id', $roleId)
            ->where('team_id', $this->event->id)
            ->where('model_id', $this->coOrganizer->id)
            ->count();

        expect($first)->toBeTrue()
            ->and($second)->toBeFalse()
            ->and($assignmentCount)->toBe(1)
            ->and($this->service->coOrganizers($this->event))->toHaveCount(1);
    });

    test('refuses unauthorized grantors, self-grants, cascaded delegation, and peer revocation', function () {
        // Old: 'refuses when the actor cannot update the event' (stranger +
        // organizer-of-another-event dataset cases) + 'refuses when the
        // actor is only a co-organizer: delegation does not cascade' +
        // 'refuses granting the organizer as co-organizer' + 'refuses when a
        // co-organizer tries to revoke a fellow co-organizer'.

        // Actors who cannot update the event cannot grant.
        foreach ([$this->stranger, $this->otherOrganizer] as $unauthorizedActor) {
            expect(fn () => $this->service->grantCoOrganizer($this->event, $this->coOrganizer, $unauthorizedActor))
                ->toThrow(AuthorizationException::class);
        }

        // Delegation does not cascade: a co-organizer cannot grant.
        $this->service->grantCoOrganizer($this->event, $this->coOrganizer, $this->organizer);
        expect(fn () => $this->service->grantCoOrganizer($this->event, $this->stranger, $this->coOrganizer))
            ->toThrow(AuthorizationException::class);

        // The organizer cannot be granted as co-organizer of their own event.
        expect(fn () => $this->service->grantCoOrganizer($this->event, $this->organizer, $this->organizer))
            ->toThrow(InvalidArgumentException::class);

        // A co-organizer cannot revoke a fellow co-organizer.
        $this->service->grantCoOrganizer($this->event, $this->stranger, $this->organizer);
        expect(fn () => $this->service->revokeCoOrganizer($this->event, $this->stranger, $this->coOrganizer))
            ->toThrow(AuthorizationException::class);
    });

    test('allows a global admin to grant', function () {
        $granted = $this->service->grantCoOrganizer($this->event, $this->coOrganizer, $this->platformAdmin);

        expect($granted)->toBeTrue()
            ->and($this->service->isCoOrganizer($this->coOrganizer, $this->event))->toBeTrue();
    });
});

// ── Revoke invariants ─────────────────────────────────

describe('Co-organizer revoke', function () {
    test('purges the scoped role and cuts update access immediately while the organizer keeps access', function () {
        // Old: 'purges the scoped role and cuts update access immediately' +
        // 'organizer keeps update access across co-organizer churn'.
        $this->service->grantCoOrganizer($this->event, $this->coOrganizer, $this->organizer);

        $this->service->revokeCoOrganizer($this->event, $this->coOrganizer, $this->organizer);

        expect($this->service->isCoOrganizer($this->coOrganizer, $this->event))->toBeFalse()
            ->and($this->coOrganizer->can('update', $this->event))->toBeFalse()
            ->and($this->service->coOrganizers($this->event))->toBeEmpty()
            ->and($this->organizer->can('update', $this->event))->toBeTrue();
    });

    test('revoking a non-holder is an idempotent no-op', function () {
        $this->service->revokeCoOrganizer($this->event, $this->stranger, $this->organizer);

        expect($this->service->coOrganizers($this->event))->toBeEmpty();
    });

    test('refuses to revoke the organizer and refuses stranger revocation', function () {
        // Old: 'refuses to revoke the organizer' + 'refuses when the actor
        // is a stranger'.
        expect(fn () => $this->service->revokeCoOrganizer($this->event, $this->organizer, $this->organizer))
            ->toThrow(InvalidArgumentException::class);

        $this->service->grantCoOrganizer($this->event, $this->coOrganizer, $this->organizer);
        expect(fn () => $this->service->revokeCoOrganizer($this->event, $this->coOrganizer, $this->stranger))
            ->toThrow(AuthorizationException::class);
    });

    test('audit-logs the revoke with actor, target user, and event id', function () {
        $this->service->grantCoOrganizer($this->event, $this->coOrganizer, $this->organizer);

        Log::shouldReceive('info')
            ->once()
            ->with('event.co_organizer_revoked', Mockery::on(fn (array $ctx) => $ctx['actor_id'] === $this->organizer->id
                && $ctx['target_user_id'] === $this->coOrganizer->id
                && $ctx['event_id'] === $this->event->id
            ));
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->service->revokeCoOrganizer($this->event, $this->coOrganizer, $this->organizer);
    });
});

// ── Listing ───────────────────────────────────────────

describe('Co-organizer listing', function () {
    test('lists co-organizers ordered by name, excluding the organizer, scoped to the event', function () {
        // Old: 'lists co-organizers ordered by name and excludes the
        // organizer' + 'is scoped per event: another event sees no
        // co-organizers'.
        $zeta = User::factory()->create(['name' => 'Zeta']);
        $alpha = User::factory()->create(['name' => 'Alpha']);

        $this->service->grantCoOrganizer($this->event, $zeta, $this->organizer);
        $this->service->grantCoOrganizer($this->event, $alpha, $this->organizer);

        $names = $this->service->coOrganizers($this->event)->pluck('name');

        expect($names->all())->toBe(['Alpha', 'Zeta'])
            ->and($this->service->coOrganizers($this->otherEvent))->toBeEmpty();
    });
});

// ── Manage-surface access matrix (HTTP) ─────────────
//
// The three Livewire manage surfaces resolve the event by slug and
// authorize EventPolicy::update in mount(). These tests exercise the
// REAL routes (auth/verified/profile.complete middleware included),
// proving the delegation grant flows through the unchanged authorize
// path — no route or policy changes were needed for co-organizers
// (slice contract M063/S04).
//
// Consolidated from eleven dataset-fan-out cases into one test per
// manage surface: each role cell (granted co-organizer, stranger,
// revoked co-organizer) that used to be its own junit case is one
// clearly-commented assertion block inside its surface's test, so the
// per-cell intent survives one HTTP boot per cell instead of one
// dataset rebuild per cell.

describe('Manage surface access matrix (HTTP)', function () {
    test('events.manage gates by role: granted co-organizer, other event, stranger, revoked, organizer', function () {
        // A granted co-organizer can open the surface.
        $this->service->grantCoOrganizer($this->event, $this->coOrganizer, $this->organizer);
        actingAs($this->coOrganizer);
        get(route('events.manage', ['slug' => $this->event->slug]))->assertOk();

        // A co-organizer of one event cannot manage another event.
        get(route('events.manage', ['slug' => $this->otherEvent->slug]))->assertForbidden();

        // A stranger is forbidden.
        actingAs($this->stranger);
        get(route('events.manage', ['slug' => $this->event->slug]))->assertForbidden();

        // A revoked co-organizer is forbidden immediately.
        $this->service->revokeCoOrganizer($this->event, $this->coOrganizer, $this->organizer);
        actingAs($this->coOrganizer);
        get(route('events.manage', ['slug' => $this->event->slug]))->assertForbidden();

        // The organizer is unaffected by co-organizer churn.
        actingAs($this->organizer);
        get(route('events.manage', ['slug' => $this->event->slug]))->assertOk();
    });

    test('events.manage-registrations gates by role: granted co-organizer, stranger, revoked co-organizer', function () {
        $this->service->grantCoOrganizer($this->event, $this->coOrganizer, $this->organizer);
        actingAs($this->coOrganizer);
        get(route('events.manage-registrations', ['slug' => $this->event->slug]))->assertOk();

        actingAs($this->stranger);
        get(route('events.manage-registrations', ['slug' => $this->event->slug]))->assertForbidden();

        $this->service->revokeCoOrganizer($this->event, $this->coOrganizer, $this->organizer);
        actingAs($this->coOrganizer);
        get(route('events.manage-registrations', ['slug' => $this->event->slug]))->assertForbidden();
    });

    test('events.announcements gates by role: granted co-organizer, stranger, revoked co-organizer', function () {
        $this->service->grantCoOrganizer($this->event, $this->coOrganizer, $this->organizer);
        actingAs($this->coOrganizer);
        get(route('events.announcements', ['slug' => $this->event->slug]))->assertOk();

        actingAs($this->stranger);
        get(route('events.announcements', ['slug' => $this->event->slug]))->assertForbidden();

        $this->service->revokeCoOrganizer($this->event, $this->coOrganizer, $this->organizer);
        actingAs($this->coOrganizer);
        get(route('events.announcements', ['slug' => $this->event->slug]))->assertForbidden();
    });
});

// ── Manage-surface write actions (POST matrix) ───────

describe('Manage surface write actions', function () {
    test('a co-organizer can use every manage-surface write action', function () {
        // Old: 'a co-organizer can save event details' + 'a co-organizer can
        // approve a pending registration' + 'a co-organizer can publish an
        // announcement'.
        $this->service->grantCoOrganizer($this->event, $this->coOrganizer, $this->organizer);

        actingAs($this->coOrganizer);

        Livewire\Livewire::test(ManageEvent::class, ['slug' => $this->event->slug])
            ->set('venue_name', 'Venue set by co-organizer')
            ->call('save')
            ->assertHasNoErrors();

        expect($this->event->fresh()->venue_name)->toBe('Venue set by co-organizer');

        $registration = EventRegistration::factory()->create(['event_id' => $this->event->id]);
        Livewire\Livewire::test(ManageRegistrations::class, ['slug' => $this->event->slug])
            ->call('approve', $registration->id);

        $this->assertDatabaseHas('event_registrations', [
            'id' => $registration->id,
            'status' => 'confirmed',
        ]);

        Livewire\Livewire::test(EventAnnouncements::class, ['slug' => $this->event->slug])
            ->set('title', 'Update from the co-organizer')
            ->set('content', 'Published through the delegated manage surface.')
            ->set('is_published', true)
            ->call('save')
            ->assertHasNoErrors();

        $announcement = $this->event->announcements()->first();

        expect($announcement)->not->toBeNull()
            ->and($announcement->is_published)->toBeTrue();
    });

    test('strangers and revoked co-organizers cannot reach the write actions', function () {
        // Old: 'a stranger cannot reach the save action' + 'a revoked
        // co-organizer cannot reach the save action'.
        actingAs($this->stranger);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $this->event->slug])
            ->assertForbidden();

        $this->service->grantCoOrganizer($this->event, $this->coOrganizer, $this->organizer);
        $this->service->revokeCoOrganizer($this->event, $this->coOrganizer, $this->organizer);

        actingAs($this->coOrganizer);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $this->event->slug])
            ->assertForbidden();
    });
});

// ── Delegation authority over the UI surface ─────────
//
// Service-level authority is proven above (stranger/co-organizer
// refused, global admin allowed). These close the UI half of the
// matrix: the global admin CAN delegate through the Team tab, and a
// co-organizer cannot revoke a fellow co-organizer through it. The
// co-organizer-cannot-invite case lives in EventManagementTest
// ("refuses a delegation attempt by a co-organizer").

describe('Delegation authority (UI surface)', function () {
    test('a global admin can grant from the team tab and the target is notified once', function () {
        Notification::fake();

        actingAs($this->platformAdmin);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $this->event->slug])
            ->set('coOrganizerInvite', $this->coOrganizer->email)
            ->call('inviteCoOrganizer')
            ->assertHasNoErrors();

        Notification::assertSentTo($this->coOrganizer, EventCoOrganizerAdded::class, 1);
        expect($this->service->isCoOrganizer($this->coOrganizer, $this->event))->toBeTrue();
    });

    test('a co-organizer cannot revoke a fellow co-organizer from the team tab', function () {
        $this->service->grantCoOrganizer($this->event, $this->coOrganizer, $this->organizer);
        $this->service->grantCoOrganizer($this->event, $this->stranger, $this->organizer);

        actingAs($this->coOrganizer);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $this->event->slug])
            ->call('revokeCoOrganizer', $this->stranger->id);

        expect($this->service->isCoOrganizer($this->stranger, $this->event))->toBeTrue();
    });
});

// ── Filament scoping (any-scope leak fix, M063/S04/T03) ──

describe('Filament EventResource canAccess', function () {
    test('only global admins can access the Filament events resource', function () {
        // Old: 'a global admin can access the Filament events resource' +
        // 'a scoped-only co-organizer cannot access the Filament events
        // resource' + 'the organizer (non-admin) and guests cannot access
        // the Filament events resource'.
        actingAs($this->platformAdmin);
        expect(EventResource::canAccess())->toBeTrue();

        $this->service->grantCoOrganizer($this->event, $this->coOrganizer, $this->organizer);
        actingAs($this->coOrganizer);
        expect(EventResource::canAccess())->toBeFalse();

        actingAs($this->organizer);
        expect(EventResource::canAccess())->toBeFalse();

        auth()->logout();
        expect(EventResource::canAccess())->toBeFalse();
    });
});
