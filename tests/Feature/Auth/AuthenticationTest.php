<?php

use App\Models\User;

describe('Authentication', function () {
    test('login screen can be rendered', function () {
        $response = $this->get(route('login'));

        $response->assertStatus(200);
    });

    test('users can authenticate using the login screen', function () {
        $user = User::factory()->create();

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    })->group('smoke');

    test('users can not authenticate with invalid password', function () {
        $user = User::factory()->create();

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    })->group('smoke');

    test('users can logout', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $this->assertGuest();
        $response->assertRedirect(route('root'));
    })->group('smoke');

    test('logout clears site data cache', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect()
            ->assertHeader('Clear-Site-Data', '"cache", "storage"');
    });
});
