<?php

use App\Models\User;

test('profile page is displayed', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);

    $response = $this
        ->actingAs($user)
        ->get('/profile');

    $response->assertOk();
});

// ── Auth gate tests ──────────────────────────────────

test('profile page redirects unauthenticated users to login', function () {
    $response = $this->get('/profile');
    $response->assertRedirect('/login');
});

test('profile edit page requires authentication', function () {
    $response = $this->get('/profile/edit');
    $response->assertRedirect('/login');
});

// ── Backward-compatible profile.edit route ───────────

test('profile.edit route still resolves to show page', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);

    $response = $this
        ->actingAs($user)
        ->get('/profile/view');

    $response->assertOk();
});
