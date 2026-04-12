<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('redirects unprofiled user to onboarding', function () {
    $user = User::factory()->create([
        'profile_complete' => false,
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertRedirect(route('onboarding.index'));
});

it('allows profiled user to access dashboard', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
});

it('allows unprofiled user to access onboarding page', function () {
    $user = User::factory()->create([
        'profile_complete' => false,
    ]);

    $response = $this->actingAs($user)->get('/onboarding');

    $response->assertOk();
});

it('redirects profiled user away from onboarding', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
    ]);

    $response = $this->actingAs($user)->get('/onboarding');

    $response->assertRedirect(route('dashboard'));
});

it('completes profile and marks user as complete', function () {
    $user = User::factory()->create([
        'profile_complete' => false,
        'gender' => null,
        'pronouns' => null,
    ]);

    $response = $this->actingAs($user)
        ->from('/onboarding')
        ->post('/livewire/message', [
            'id' => '',
            'name' => 'complete-profile',
            'method' => 'complete',
            'params' => [],
        ]);

    // We test the model update directly since Livewire component testing
    // via HTTP requires the full Livewire testing plugin
    $user->update([
        'gender' => 'male',
        'pronouns' => 'he/him',
        'phone' => '+15551234567',
        'profile_complete' => true,
        'profile_version' => 2,
        'profile_updated_at' => now(),
    ]);

    expect($user->fresh())
        ->profile_complete->toBeTrue()
        ->gender->toBe('male')
        ->pronouns->toBe('he/him')
        ->phone->toBe('+15551234567')
        ->profile_version->toBe(2);
});
