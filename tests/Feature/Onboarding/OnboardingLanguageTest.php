<?php

use App\Enums\ContentLanguage;
use App\Livewire\Onboarding\CompleteProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('sets preferred_language to En when onboarding with en locale', function () {
    app()->setLocale('en');

    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->set('lat', 52.52)
        ->set('lng', 13.405)
        ->set('locationConfirmed', true)
        ->call('nextStep')
        ->set('gender', 'male')
        ->set('pronouns', 'he/him')
        ->call('nextStep')
        ->call('nextStep')
        ->call('complete')
        ->assertRedirect(route('dashboard'));

    expect($user->fresh()->preferred_language)->toBe(ContentLanguage::En);
});

it('sets preferred_language to De when onboarding with de locale', function () {
    app()->setLocale('de');

    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('city', 'Berlin')
        ->set('lat', 52.52)
        ->set('lng', 13.405)
        ->set('locationConfirmed', true)
        ->call('nextStep')
        ->set('gender', 'female')
        ->set('pronouns', 'she/her')
        ->call('nextStep')
        ->call('nextStep')
        ->call('complete')
        ->assertRedirect(route('dashboard'));

    expect($user->fresh()->preferred_language)->toBe(ContentLanguage::De);
});
