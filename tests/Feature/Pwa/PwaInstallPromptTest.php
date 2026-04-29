<?php

use App\Enums\RelationshipType;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\Location;
use App\Models\User;
use App\Models\UserAppVisit;
use App\Models\UserRelationship;
use function Pest\Laravel\{actingAs, get};

beforeEach(function () {
    $this->location = Location::factory()->create();
});

// ── Visibility Gating ─────────────────────────────────

describe('Visibility gating', function () {
    it('guest user does not see install prompt on public page', function () {
        $response = get(route('home'));

        $response->assertOk();
        $response->assertDontSee('pwaInstallPrompt()', false);
        $response->assertDontSee('Install Roundup Games', false);
    });

    it('authenticated user with profile + location but 0/3 score sees no prompt', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $this->location->id,
            'email_verified_at' => now(),
        ]);

        $response = actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee('pwaInstallPrompt()', false);
        $response->assertDontSee('Install Roundup Games', false);
    });

    it('authenticated user with 2/3 score sees the install prompt', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $this->location->id,
            'email_verified_at' => now(),
        ]);

        // Signal 1: 2 visit days
        UserAppVisit::factory()->create([
            'user_id' => $user->id,
            'visit_date' => now()->subDay()->toDateString(),
        ]);
        UserAppVisit::factory()->create([
            'user_id' => $user->id,
            'visit_date' => now()->toDateString(),
        ]);

        // Signal 2: approved game participation (past, to avoid trypass)
        $game = Game::factory()->create([
            'date_time' => now()->subDay(),
            'created_at' => now()->subDay(),
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        // Flush any session cache from previous requests
        session()->flush();

        $response = actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('pwaInstallPrompt()', false);
        $response->assertSee('Install Roundup Games', false);
    });

    it('authenticated user with trypass (upcoming game) sees the install prompt', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $this->location->id,
            'email_verified_at' => now(),
        ]);

        Game::factory()->create([
            'owner_id' => $user->id,
            'date_time' => now()->addDays(3),
        ]);

        session()->flush();

        $response = actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('pwaInstallPrompt()', false);
        $response->assertSee('Install Roundup Games', false);
    });

    it('authenticated user without location does not see prompt', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => null,
            'email_verified_at' => now(),
        ]);

        // Even with engagement signals, no location = baseline fails
        UserAppVisit::factory()->create([
            'user_id' => $user->id,
            'visit_date' => now()->subDay()->toDateString(),
        ]);
        UserAppVisit::factory()->create([
            'user_id' => $user->id,
            'visit_date' => now()->toDateString(),
        ]);

        session()->flush();

        $response = actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee('pwaInstallPrompt()', false);
        $response->assertDontSee('Install Roundup Games', false);
    });
});

// ── Trypass Events (HTTP-level) ───────────────────────

describe('Trypass events via HTTP', function () {
    beforeEach(function () {
        $this->user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $this->location->id,
            'email_verified_at' => now(),
        ]);
    });

    it('user with upcoming game within 7 days sees prompt', function () {
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'date_time' => now()->addDays(5),
        ]);

        session()->flush();

        $response = actingAs($this->user)->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('pwaInstallPrompt()', false);
    });

    it('user with game starting in 8 days does not get trypass', function () {
        // Game is 8 days out — beyond 7-day trypass window. No other signals = not eligible.
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'date_time' => now()->addDays(8),
            'created_at' => now()->subDay(),
        ]);

        session()->flush();

        $response = actingAs($this->user)->get(route('dashboard'));
        $response->assertOk();
        $response->assertDontSee('pwaInstallPrompt()', false);
    });

    it('user who just created a game sees prompt via trypass', function () {
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'date_time' => now()->addDays(30),
            'created_at' => now()->subMinutes(2),
        ]);

        session()->flush();

        $response = actingAs($this->user)->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('pwaInstallPrompt()', false);
    });

    it('user who just received a game invitation sees prompt via trypass', function () {
        $host = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create(['owner_id' => $host->id]);
        GameParticipant::create([
            'user_id' => $this->user->id,
            'game_id' => $game->id,
            'status' => 'pending',
            'role' => 'player',
        ]);

        session()->flush();

        $response = actingAs($this->user)->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('pwaInstallPrompt()', false);
    });
});

// ── Dismissal HTML Structure ──────────────────────────

describe('Dismissal', function () {
    it('eligible response includes localStorage dismissal JS', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $this->location->id,
            'email_verified_at' => now(),
        ]);

        Game::factory()->create([
            'owner_id' => $user->id,
            'date_time' => now()->addDays(3),
        ]);

        session()->flush();

        $response = actingAs($user)->get(route('dashboard'));
        $response->assertOk();

        // Check localStorage key used for 7-day dismissal
        $response->assertSee('pwa_prompt_dismissed', false);
        $response->assertSee('localStorage', false);
    });

    it('eligible response includes dismiss button HTML', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $this->location->id,
            'email_verified_at' => now(),
        ]);

        Game::factory()->create([
            'owner_id' => $user->id,
            'date_time' => now()->addDays(3),
        ]);

        session()->flush();

        $response = actingAs($user)->get(route('dashboard'));
        $response->assertOk();

        // Verify dismiss button text exists
        $response->assertSee('Not now', false);
        $response->assertSee('Got it', false);
    });

    it('eligible response includes 7-day dismissal logic', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $this->location->id,
            'email_verified_at' => now(),
        ]);

        Game::factory()->create([
            'owner_id' => $user->id,
            'date_time' => now()->addDays(3),
        ]);

        session()->flush();

        $response = actingAs($user)->get(route('dashboard'));
        $response->assertOk();

        // The JS checks daysSince < 7
        $response->assertSee('daysSince', false);
        $response->assertSee('1000 * 60 * 60 * 24', false);
    });
});

// ── Layout Integration ────────────────────────────────

describe('Layout integration', function () {
    it('authenticated layout includes pwa-install-prompt component for eligible user', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $this->location->id,
            'email_verified_at' => now(),
        ]);

        Game::factory()->create([
            'owner_id' => $user->id,
            'date_time' => now()->addDays(3),
        ]);

        session()->flush();

        $response = actingAs($user)->get(route('dashboard'));
        $response->assertOk();

        // Verify the component renders with expected Alpine.js data
        $response->assertSee('x-data="pwaInstallPrompt()"', false);
        $response->assertSee('x-init="init()"', false);
    });

    it('public layout does not include pwa-install-prompt component', function () {
        $response = get(route('home'));
        $response->assertOk();

        $response->assertDontSee('x-data="pwaInstallPrompt()"', false);
        $response->assertDontSee('pwaInstallPrompt()', false);
        $response->assertDontSee('Install Roundup Games', false);
    });

    it('guest page renders without any PWA prompt markers', function () {
        $response = get(route('home'));
        $response->assertOk();

        $response->assertDontSee('beforeinstallprompt', false);
        $response->assertDontSee('pwa_prompt_dismissed', false);
        $response->assertDontSee('__pwaDeferredPrompt', false);
        $response->assertDontSee('install result:', false);
    });

    it('eligible response includes early-capture script before Alpine component', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $this->location->id,
            'email_verified_at' => now(),
        ]);

        Game::factory()->create([
            'owner_id' => $user->id,
            'date_time' => now()->addDays(3),
        ]);

        session()->flush();

        $response = actingAs($user)->get(route('dashboard'));
        $response->assertOk();

        $content = $response->getContent();

        // Early capture script must exist
        $this->assertStringContainsString('window.__pwaDeferredPrompt', $content);
        $this->assertStringContainsString('[pwa-prompt] beforeinstallprompt captured (early)', $content);

        // Early script must come BEFORE the Alpine x-data declaration
        $earlyScriptPos = strpos($content, 'window.__pwaDeferredPrompt');
        $alpineInitPos = strpos($content, 'x-data="pwaInstallPrompt()"');
        $this->assertNotFalse($earlyScriptPos, 'Early capture script not found in response');
        $this->assertNotFalse($alpineInitPos, 'Alpine x-data not found in response');
        $this->assertLessThan($alpineInitPos, $earlyScriptPos, 'Early capture script must come before Alpine component');
    });
});

// ── Chrome vs iOS Rendering ───────────────────────────

describe('Browser-specific HTML', function () {
    it('eligible response includes all three browser template blocks', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $this->location->id,
            'email_verified_at' => now(),
        ]);

        Game::factory()->create([
            'owner_id' => $user->id,
            'date_time' => now()->addDays(3),
        ]);

        session()->flush();

        $response = actingAs($user)->get(route('dashboard'));
        $response->assertOk();
        $content = $response->getContent();

        // Chrome mode
        $response->assertSee('install_mobile', false);
        $response->assertSee('Install Roundup Games', false);
        $response->assertSee('Install', false);

        // Firefox Android mode — check for the x-if directive and icon
        $this->assertStringContainsString('x-if="isFirefox"', $content);
        $response->assertSee('more_vert', false);

        // iOS Safari mode
        $response->assertSee('Add to Home Screen', false);
        $response->assertSee('ios_share', false);
        $response->assertSee('Got it', false);
    });

    it('Firefox Android template renders with correct structure', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $this->location->id,
            'email_verified_at' => now(),
        ]);

        Game::factory()->create([
            'owner_id' => $user->id,
            'date_time' => now()->addDays(3),
        ]);

        session()->flush();

        $response = actingAs($user)->get(route('dashboard'));
        $response->assertOk();

        $content = $response->getContent();

        // Firefox template block must be present
        $this->assertStringContainsString('x-if="isFirefox"', $content);

        // Firefox should show install_mobile icon and menu icon
        $this->assertStringContainsString('more_vert', $content);

        // Firefox-specific translated text must be present (HTML-escaped by Blade {{ }})
        app()->setLocale('en');
        $this->assertStringContainsString(e(__('pwa.heading_firefox_install_title')), $content);
        $this->assertStringContainsString(e(__('pwa.content_firefox_install_step_1')), $content);
        $this->assertStringContainsString(e(__('pwa.content_firefox_install_step_2')), $content);
        $this->assertStringContainsString(e(__('pwa.action_firefox_install_dismiss')), $content);
    });

    it('Firefox detection logic excludes desktop Firefox', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $this->location->id,
            'email_verified_at' => now(),
        ]);

        Game::factory()->create([
            'owner_id' => $user->id,
            'date_time' => now()->addDays(3),
        ]);

        session()->flush();

        $response = actingAs($user)->get(route('dashboard'));
        $response->assertOk();

        $content = $response->getContent();

        // Detection logic must require Android for Firefox mode
        $this->assertStringContainsString('isFirefox && isAndroid', $content);
        $this->assertStringContainsString('/Firefox\\//i.test(ua)', $content);
        $this->assertStringContainsString('!/Seamonkey/i.test(ua)', $content);
    });

    it('Chrome detection uses API support check not just UA', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $this->location->id,
            'email_verified_at' => now(),
        ]);

        Game::factory()->create([
            'owner_id' => $user->id,
            'date_time' => now()->addDays(3),
        ]);

        session()->flush();

        $response = actingAs($user)->get(route('dashboard'));
        $response->assertOk();

        $content = $response->getContent();

        // Chrome detection must use BeforeInstallPromptEvent API check
        $this->assertStringContainsString("'BeforeInstallPromptEvent' in window", $content);
        // Must exclude Firefox to avoid false positive
        $this->assertStringContainsString('!this.isFirefox', $content);
    });

    it('eligible response includes structured console logging for observability', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $this->location->id,
            'email_verified_at' => now(),
        ]);

        Game::factory()->create([
            'owner_id' => $user->id,
            'date_time' => now()->addDays(3),
        ]);

        session()->flush();

        $response = actingAs($user)->get(route('dashboard'));
        $response->assertOk();

        // Structured log markers — early capture and Alpine capture paths
        $response->assertSee('[pwa-prompt] beforeinstallprompt captured', false);
        $response->assertSee('__pwaDeferredPrompt', false);
        $response->assertSee('[pwa-prompt] dismissed', false);
        $response->assertSee('[pwa-prompt] install confirmed', false);
    });
});
