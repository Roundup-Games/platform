<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

describe('Email Verification Resend', function () {
    test('unverified user can request a verification email resend', function () {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)
            ->from(route('verification.notice'))
            ->post(route('verification.send'));

        Notification::assertSentTo($user, VerifyEmail::class);
        $response->assertRedirect(route('verification.notice'));
        $response->assertSessionHas('status', 'verification-link-sent');
    });

    test('already verified user is redirected to dashboard', function () {
        Notification::fake();

        $user = User::factory()->create(); // email_verified_at = now() by default

        $response = $this->actingAs($user)
            ->post(route('verification.send'));

        Notification::assertNothingSent();
        $response->assertRedirect(route('dashboard'));
    });

    test('guest is redirected to login', function () {
        $response = $this->post(route('verification.send'));

        $response->assertRedirect(route('login'));
    });
});
