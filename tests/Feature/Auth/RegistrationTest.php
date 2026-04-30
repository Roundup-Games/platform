<?php

describe('Registration', function () {
    test('registration screen can be rendered', function () {
        $response = $this->get(route('register'));

        $response->assertStatus(200);
    });

    test('new users can register and are redirected to onboarding', function () {
        $response = $this->post(route('register'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'profile_complete' => false,
        ]);
        $response->assertRedirect(route('onboarding.index'));
    })->group('smoke');
});
