<?php

use App\Enums\ContentLanguage;
use App\Livewire\Onboarding\CompleteProfile;
use App\Models\User;
use Livewire\Livewire;

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
        ->assertRedirect('/de/dashboard');

    expect($user->fresh()->preferred_language)->toBe(ContentLanguage::De);
});
