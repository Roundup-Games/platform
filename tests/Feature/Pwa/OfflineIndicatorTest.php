<?php

use App\Models\User;
use function Pest\Laravel\{actingAs};

describe('Offline Indicator Layout Integration', function () {
    it('renders localized offline text from pwa.php', function () {
        app()->setLocale('en');

        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        $response = actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee(__('pwa.offline_indicator'));
        $response->assertSee(__('pwa.back_online'));
    });
});
