<?php

use App\Models\User;
use function Pest\Laravel\{actingAs, get};

describe('PWA Translation Completeness', function () {
    it('all PWA Blade template __() keys resolve in :locale', function (string $locale) {
        app()->setLocale($locale);
        $label = $locale === 'en' ? 'EN' : 'DE';

        $pwaKeys = [
            'pwa.install_title',
            'pwa.install_description',
            'pwa.install_button',
            'pwa.install_dismiss',
            'pwa.ios_install_title',
            'pwa.ios_install_step_1',
            'pwa.ios_install_step_2',
            'pwa.ios_install_step_3',
            'pwa.ios_install_dismiss',
            'pwa.heading_firefox_install_title',
            'pwa.content_firefox_install_step_1',
            'pwa.content_firefox_install_step_2',
            'pwa.action_firefox_install_dismiss',
        ];

        foreach ($pwaKeys as $key) {
            $translated = __($key);
            expect($translated)->not->toBe($key, "{$label} translation missing for {$key}");
        }
    })->with(['en', 'de']);

    it('install prompt template uses __() for all user-facing text', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
            'location_id' => \App\Models\Location::factory()->create()->id,
        ]);

        // Make user eligible for PWA prompt via trypass
        \App\Models\Game::factory()->create([
            'owner_id' => $user->id,
            'date_time' => now()->addDays(3),
        ]);

        session()->flush();

        $response = actingAs($user)->get(route('dashboard'));
        $response->assertOk();

        // Verify the rendered text matches the translation keys (not hardcoded English)
        app()->setLocale('en');
        $response->assertSee(__('pwa.install_title'), false);
        $response->assertSee(__('pwa.install_button'), false);
        $response->assertSee(__('pwa.install_dismiss'), false);
        $response->assertSee(__('pwa.ios_install_title'), false);
    });

    it('offline indicator uses __() for all text', function () {
        app()->setLocale('en');

        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        $response = actingAs($user)->get(route('dashboard'));
        $response->assertOk();

        // Verify the localized text from pwa.php is present (HTML-escaped via {{ }})
        $response->assertSee(__('pwa.offline_indicator'));
        $response->assertSee(__('pwa.back_online'));
    });
});
