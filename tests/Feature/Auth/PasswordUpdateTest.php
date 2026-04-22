<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

describe('Password Update', function () {
    test('password can be updated', function () {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.show'))
            ->put(route('password.update'), [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.show'));

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    });

    test('correct password must be provided to update password', function () {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.show'))
            ->put(route('password.update'), [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('updatePassword', 'current_password')
            ->assertRedirect(route('profile.show'));
    });
});
