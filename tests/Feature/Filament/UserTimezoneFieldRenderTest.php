<?php

use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use Filament\Facades\Filament;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

//
// R089 — the admin user form's timezone select must surface a stored
// tzdata alias (Asia/Calcutta) that DateTimeZone::listIdentifiers() omits.
// canonical_timezone() accepts aliases browsers report, so users.timezone
// can hold values no canonical-list select offers: without the alias-safe
// options the select renders blank and saving the form silently wipes the
// stored zone. Filament v4 evaluates select options server-side (they are
// not part of the page HTML), so the live component is mounted and the
// field's evaluated option list is asserted directly.
//

beforeEach(function () {
    seedRoles();

    setPermissionsTeamId(null);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->platformAdmin = User::factory()->create();
    $this->platformAdmin->assignRole('Platform Admin');
    $this->platformAdmin->unsetRelations();

    Filament::setCurrentPanel('admin');
});

it('offers a stored tzdata alias as the selected timezone option', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Calcutta']);

    actingAs($this->platformAdmin);

    $page = Livewire\Livewire::test(EditUser::class, ['record' => $user->getKey()]);

    $options = $page->instance()->getSchemaComponent('form.timezone')->getOptions();

    // The alias is present and first, so the select renders the stored
    // zone preselected instead of blank (blank dehydrates to a wipe).
    expect(array_key_first($options))->toBe('Asia/Calcutta')
        ->and($options)->toHaveKey('Europe/Berlin');
});

it('offers the canonical list unchanged for a regular stored zone', function () {
    $user = User::factory()->create(['timezone' => 'Europe/Berlin']);

    actingAs($this->platformAdmin);

    $page = Livewire\Livewire::test(EditUser::class, ['record' => $user->getKey()]);

    $options = $page->instance()->getSchemaComponent('form.timezone')->getOptions();

    // No duplicate/prepend for a canonical value — the plain list.
    expect($options)->toHaveKey('Europe/Berlin')
        ->and(array_key_first($options))->toBe('Africa/Abidjan')
        ->and(count($options))->toBe(count(DateTimeZone::listIdentifiers()));
});

it('pins an admin-chosen timezone against device capture', function () {
    // A device-captured zone (flag false) becomes a manual choice when the
    // admin changes it — capture paths (login, OAuth, beacon) stop writing.
    $user = User::factory()->create(['timezone' => 'America/New_York']);

    actingAs($this->platformAdmin);

    Livewire\Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->fillForm(['timezone' => 'Europe/Berlin'])
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->timezone)->toBe('Europe/Berlin')
        ->and($user->fresh()->timezone_manually_set)->toBeTrue();
});

it('returns an admin-cleared timezone to device capture', function () {
    // Clearing a pinned zone un-pins it — otherwise the user would be stuck
    // at null with every capture path skipped.
    $user = User::factory()->create([
        'timezone' => 'Europe/Berlin',
        'timezone_manually_set' => true,
    ]);

    actingAs($this->platformAdmin);

    Livewire\Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->fillForm(['timezone' => null])
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->timezone)->toBeNull()
        ->and($user->fresh()->timezone_manually_set)->toBeFalse();
});

it('leaves the capture pin untouched when the timezone was not changed', function () {
    // An unrelated admin edit (rename) must not pin the zone: the untouched
    // select round-trips the stored value, so no change is detected.
    $user = User::factory()->create(['timezone' => 'Europe/Berlin']);

    actingAs($this->platformAdmin);

    Livewire\Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->fillForm(['name' => 'Renamed By Admin'])
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->name)->toBe('Renamed By Admin')
        ->and($user->fresh()->timezone)->toBe('Europe/Berlin')
        ->and($user->fresh()->timezone_manually_set)->toBeFalse();
});
