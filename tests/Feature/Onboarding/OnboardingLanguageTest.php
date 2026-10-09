<?php

use App\Enums\ContentLanguage;
use App\Livewire\Onboarding\CompleteProfile;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('sets preferred_language to De when onboarding with de locale', function () {
    // Complete-profile geocodes the submitted city; keep it off the network.
    Http::fake(['*nominatim*' => Http::response([[
        'lat' => '52.5200',
        'lon' => '13.4050',
        'display_name' => 'Berlin, Germany',
        'place_id' => 12345,
        'address' => ['city' => 'Berlin'],
    ]])]);
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
