<?php

use App\Models\User;
use function Pest\Laravel\{actingAs, get};

describe('Offline Indicator Layout Integration', function () {
    it('authenticated layout contains offline-indicator component', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        $response = actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('offlineIndicator()', false);
    });

    it('public layout contains offline-indicator component', function () {
        $response = get(route('home'));

        $response->assertOk();
        $response->assertSee('offlineIndicator()', false);
    });

    it('component renders localized offline text from common.php', function () {
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
