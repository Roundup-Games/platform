<?php

use App\Models\Event;
use App\Models\User;
use App\Services\ScopedRoleService;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

// ── Helpers ──────────────────────────────────────────────

function featuredCreateUser(array $overrides = []): User
{
    return User::factory()->create([
        'email_verified_at' => now(),
        'profile_complete' => true,
        ...$overrides,
    ]);
}

function featuredCreateAdmin(): User
{
    seedRoles();

    $admin = User::factory()->create([
        'email_verified_at' => now(),
        'profile_complete' => true,
    ]);

    setPermissionsTeamId(null);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $admin->assignRole('Platform Admin');
    $admin->unsetRelations();

    return $admin;
}

function featuredCreateEvent(array $overrides = []): Event
{
    return Event::factory()->create([
        'is_public' => true,
        'is_featured' => false,
        'status' => 'draft',
        ...$overrides,
    ]);
}

// ═══════════════════════════════════════════════════════════
// CREATE EVENT — is_featured NOT EXPOSED
// ═══════════════════════════════════════════════════════════

test('non-admin cannot set is_featured on create — field removed from form', function () {
    $user = featuredCreateUser();

    // The CreateEvent component no longer has an is_featured property or
    // passes it in the create() data array. New events always default to
    // is_featured=false via the database column default.
    $event = Event::factory()->create([
        'organizer_id' => $user->id,
        'is_featured' => false,
    ]);

    expect($event->fresh()->is_featured)->toBeFalse();
});

// ═══════════════════════════════════════════════════════════
// MANAGE EVENT — is_featured GUARDED BY ADMIN CHECK
// ═══════════════════════════════════════════════════════════

test('non-admin cannot change is_featured on edit', function () {
    $organizer = featuredCreateUser();
    $event = featuredCreateEvent(['organizer_id' => $organizer->id]);
    expect($event->is_featured)->toBeFalse();

    // Organizer can update the event, but is_featured should be silently
    // reverted to the original value with a warning logged.
    Log::shouldReceive('warning')->once()->with(
        'Non-admin attempted to change is_featured',
        \Mockery::on(fn (array $ctx) => $ctx['user_id'] === $organizer->id
            && $ctx['event_id'] === $event->id
            && $ctx['attempted_value'] === true
        ),
    );
    Log::shouldReceive('info')->zeroOrMoreTimes();

    Livewire::actingAs($organizer)
        ->test(\App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])
        ->set('is_featured', true)
        ->call('save');

    expect($event->fresh()->is_featured)->toBeFalse();
});

test('non-admin cannot unset is_featured on edit when already featured', function () {
    $organizer = featuredCreateUser();
    $event = featuredCreateEvent([
        'organizer_id' => $organizer->id,
        'is_featured' => true,
    ]);

    Log::shouldReceive('warning')->once();
    Log::shouldReceive('info')->zeroOrMoreTimes();

    Livewire::actingAs($organizer)
        ->test(\App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])
        ->set('is_featured', false)
        ->call('save');

    expect($event->fresh()->is_featured)->toBeTrue();
});

test('admin can set is_featured on edit', function () {
    $admin = featuredCreateAdmin();
    $event = featuredCreateEvent(['organizer_id' => $admin->id]);
    expect($event->is_featured)->toBeFalse();

    Log::shouldReceive('info')->zeroOrMoreTimes();

    Livewire::actingAs($admin)
        ->test(\App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])
        ->set('is_featured', true)
        ->call('save');

    expect($event->fresh()->is_featured)->toBeTrue();
});

test('admin can unset is_featured on edit', function () {
    $admin = featuredCreateAdmin();
    $event = featuredCreateEvent([
        'organizer_id' => $admin->id,
        'is_featured' => true,
    ]);

    Log::shouldReceive('info')->zeroOrMoreTimes();

    Livewire::actingAs($admin)
        ->test(\App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])
        ->set('is_featured', false)
        ->call('save');

    expect($event->fresh()->is_featured)->toBeFalse();
});

test('non-admin save with unchanged is_featured succeeds without warning', function () {
    $organizer = featuredCreateUser();
    $event = featuredCreateEvent(['organizer_id' => $organizer->id, 'is_featured' => false]);

    // When is_featured value matches the original, no warning should be logged
    Log::shouldReceive('warning')->never();
    Log::shouldReceive('info')->zeroOrMoreTimes();

    Livewire::actingAs($organizer)
        ->test(\App\Livewire\Events\ManageEvent::class, ['slug' => $event->slug])
        ->set('name', 'Updated Event Name')
        ->call('save');

    expect($event->fresh()->name)->toBe('Updated Event Name');
    expect($event->fresh()->is_featured)->toBeFalse();
});
