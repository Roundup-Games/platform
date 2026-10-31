<?php

use App\Models\User;

//
// R089 — authenticated device-timezone beacon endpoint: auth-gated,
// throttled, writes only on change, never over a manual override, and
// always answers 204 (silent degradation for a fire-and-forget client).
// The throttle budget (10/min per user) is per-user in tests too: every
// test below acts as a fresh user, so no limiter reset is needed.
//

it('rejects unauthenticated beacons', function () {
    $this->post(route('timezone.beacon'), ['timezone' => 'America/New_York'])
        ->assertRedirect(route('login'));
});

it('stores a changed device timezone for the authenticated user', function () {
    $user = User::factory()->create(['timezone' => null]);

    $this->actingAs($user)
        ->post(route('timezone.beacon'), ['timezone' => 'America/New_York'])
        ->assertNoContent();

    expect($user->fresh()->timezone)->toBe('America/New_York');
});

it('accepts tzdata aliases browsers report and stores the canonical form', function () {
    $user = User::factory()->create(['timezone' => null]);

    $this->actingAs($user)
        ->post(route('timezone.beacon'), ['timezone' => 'Asia/Calcutta'])
        ->assertNoContent();

    // Map-first alias resolution: the canonical IANA target, on every
    // runtime (R089).
    expect($user->fresh()->timezone)->toBe('Asia/Kolkata');
});

it('silently ignores invalid timezones and keeps the stored value', function () {
    $user = User::factory()->create(['timezone' => 'Europe/Berlin']);

    $this->actingAs($user)
        ->post(route('timezone.beacon'), ['timezone' => 'Etc/Unknown'])
        ->assertNoContent();

    expect($user->fresh()->timezone)->toBe('Europe/Berlin');

    $this->actingAs($user)
        ->post(route('timezone.beacon'), ['timezone' => 'Mars/Olympus_Mons'])
        ->assertNoContent();

    expect($user->fresh()->timezone)->toBe('Europe/Berlin');
});

it('never overwrites a manually-set timezone', function () {
    $user = User::factory()->create([
        'timezone' => 'Europe/Berlin',
        'timezone_manually_set' => true,
    ]);

    $this->actingAs($user)
        ->post(route('timezone.beacon'), ['timezone' => 'America/New_York'])
        ->assertNoContent();

    expect($user->fresh()->timezone)->toBe('Europe/Berlin')
        ->and($user->fresh()->timezone_manually_set)->toBeTrue();
});

it('throttles beacon floods', function () {
    $user = User::factory()->create(['timezone' => null]);

    // The cookie/localStorage guard means one zone change per load; the
    // limiter (10/min per user) stops a corrupted client from write-storming.
    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($user)
            ->post(route('timezone.beacon'), ['timezone' => 'America/New_York'])
            ->assertNoContent();
    }

    $this->actingAs($user)
        ->post(route('timezone.beacon'), ['timezone' => 'Europe/Berlin'])
        ->assertStatus(429);
});
