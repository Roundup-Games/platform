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

    it('public layout does not contain offline-indicator', function () {
        $response = get(route('home'));

        $response->assertOk();
        $response->assertDontSee('offlineIndicator()', false);
    });

    it('component contains Alpine x-data for online/offline detection', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        $response = actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('x-data="offlineIndicator()"', false);
        $response->assertSee('x-init="init()"', false);
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

    it('component contains pre-Alpine CSS fallback for offline state', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        $response = actingAs($user)->get(route('dashboard'));

        $response->assertOk();

        // Pre-Alpine fallback uses data-network="offline" selector
        $response->assertSee('data-network="offline"', false);
        $response->assertSee('data-offline-banner', false);
        $response->assertSee('data-online-flash', false);
    });
});
