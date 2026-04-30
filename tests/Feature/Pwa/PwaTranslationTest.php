<?php

use App\Models\User;
use function Pest\Laravel\{actingAs, get};

describe('PWA Translation Completeness', function () {
    it('all keys in lang/en/pwa.php exist in lang/de/pwa.php', function () {
        $en = include base_path('lang/en/pwa.php');
        $de = include base_path('lang/de/pwa.php');

        // Filter out comment-only entries (keys starting with // or containing only comments)
        $enKeys = array_keys(array_filter($en, fn ($value, $key) => is_string($value), ARRAY_FILTER_USE_BOTH));
        $deKeys = array_keys(array_filter($de, fn ($value, $key) => is_string($value), ARRAY_FILTER_USE_BOTH));

        $missingInDe = array_diff($enKeys, $deKeys);
        expect($missingInDe)->toBeEmpty('Keys present in EN but missing in DE: ' . implode(', ', $missingInDe));
    });

    it('all keys in lang/de/pwa.php exist in lang/en/pwa.php', function () {
        $en = include base_path('lang/en/pwa.php');
        $de = include base_path('lang/de/pwa.php');

        $enKeys = array_keys(array_filter($en, fn ($value, $key) => is_string($value), ARRAY_FILTER_USE_BOTH));
        $deKeys = array_keys(array_filter($de, fn ($value, $key) => is_string($value), ARRAY_FILTER_USE_BOTH));

        $missingInEn = array_diff($deKeys, $enKeys);
        expect($missingInEn)->toBeEmpty('Keys present in DE but missing in EN: ' . implode(', ', $missingInEn));
    });

    it('DE values are not copies of EN values (cognates excepted)', function () {
        $en = include base_path('lang/en/pwa.php');
        $de = include base_path('lang/de/pwa.php');

        $cognates = ['manifest_name', 'manifest_short_name']; // Brand names — same in both locales

        $identicalNonCognates = [];
        foreach ($en as $key => $enValue) {
            if (! is_string($enValue)) {
                continue;
            }
            if (in_array($key, $cognates, true)) {
                continue;
            }
            if (! isset($de[$key]) || ! is_string($de[$key])) {
                continue;
            }
            if ($enValue === $de[$key]) {
                $identicalNonCognates[] = $key;
            }
        }

        expect($identicalNonCognates)->toBeEmpty(
            'DE values identical to EN (likely untranslated): ' . implode(', ', $identicalNonCognates)
        );
    });

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

    it('manifest-related meta tags present in authenticated layout', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        $response = actingAs($user)->get(route('dashboard'));
        $response->assertOk();

        $response->assertSee('<link rel="manifest" href="/manifest.json">', false);
        $response->assertSee('<meta name="theme-color"', false);
        $response->assertSee('apple-mobile-web-app-capable', false);
    });

    it('manifest-related meta tags present in public layout', function () {
        $response = get(route('home'));
        $response->assertOk();

        $response->assertSee('<link rel="manifest" href="/manifest.json">', false);
        $response->assertSee('<meta name="theme-color"', false);
        $response->assertSee('apple-mobile-web-app-capable', false);
    });
});
