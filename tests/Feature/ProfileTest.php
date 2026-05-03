<?php

use App\Models\User;

describe('Profile page', function () {
    test('profile page is displayed', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('profile.show'));

        $response->assertOk();
    })->group('smoke');

    test('profile.edit route still resolves to show page', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    })->group('smoke');
});

describe('Auth gates', function () {
    test('profile page redirects unauthenticated users to login', function () {
        $response = $this->get(route('profile.show'));
        $response->assertRedirect(route('login'));
    })->group('smoke');

    test('profile edit page requires authentication', function () {
        $response = $this->get(route('profile.edit'));
        $response->assertRedirect(route('login'));
    })->group('smoke');
});
