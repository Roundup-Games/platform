<?php

use App\Enums\RelationshipType;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\Location;
use App\Models\User;
use App\Models\UserAppVisit;
use App\Models\UserRelationship;
use App\Services\PwaEligibilityService;
use function Pest\Laravel\{actingAs, get};

beforeEach(function () {
    $this->location = Location::factory()->create();
    $this->service = new PwaEligibilityService;
});

// ── Full Eligibility Flow ─────────────────────────────

describe('Full eligibility flow', function () {
    it('new user progresses from ineligible to eligible via engagement', function () {
        $user = User::factory()->create([
            'profile_complete' => false,
            'location_id' => null,
            'email_verified_at' => now(),
        ]);

        // Step 1: No profile, no location → not eligible
        session()->flush();
        $result = $this->service->isEligible($user);
        expect($result->eligible)->toBeFalse()
            ->and($result->reason)->toBe('baseline_missing');

        // Step 2: Complete profile, add location → passes baseline but 0/3 score
        $user->update([
            'profile_complete' => true,
            'location_id' => $this->location->id,
        ]);
        $user->refresh();
        session()->flush();

        $result = $this->service->isEligible($user);
        expect($result->eligible)->toBeFalse()
            ->and($result->reason)->toBe('score_too_low');

        // Step 3: Add 2 visit days → 1/3 score
        UserAppVisit::factory()->create([
            'user_id' => $user->id,
            'visit_date' => now()->subDay()->toDateString(),
        ]);
        UserAppVisit::factory()->create([
            'user_id' => $user->id,
            'visit_date' => now()->toDateString(),
        ]);
        session()->flush();

        $result = $this->service->isEligible($user);
        expect($result->eligible)->toBeFalse()
            ->and($result->reason)->toBe('score_too_low');

        // Step 4: Join a game (approved) → 2/3 score → eligible via score gate
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
        session()->flush();

        $result = $this->service->isEligible($user);
        expect($result->eligible)->toBeTrue()
            ->and($result->source)->toBe('baseline+score')
            ->and($result->reason)->toBe('engagement_threshold_met');

        // Step 5: Verify the prompt actually appears in the HTTP response
        $response = actingAs($user)->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('pwaInstallPrompt()', false);
        $response->assertSee('Install Roundup Games', false);
    });

    it('trypass shortcuts eligibility without needing score gate', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $this->location->id,
            'email_verified_at' => now(),
        ]);

        // No engagement signals at all — score would be 0/3
        // But creating a game within the last 5 minutes triggers trypass
        Game::factory()->create([
            'owner_id' => $user->id,
            'date_time' => now()->addDays(30),
            'created_at' => now()->subMinutes(2),
        ]);

        session()->flush();

        $result = $this->service->isEligible($user);
        expect($result->eligible)->toBeTrue()
            ->and($result->source)->toBe('trypass')
            ->and($result->reason)->toBe('trypass_first_game_created');

        // Verify prompt appears in HTTP response
        $response = actingAs($user)->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('pwaInstallPrompt()', false);
    });
});

// ── Session Caching Integration ───────────────────────

describe('Session caching across requests', function () {
    it('second HTTP request uses cached eligibility without re-query', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $this->location->id,
            'email_verified_at' => now(),
        ]);

        // No signals → not eligible
        session()->flush();

        $response1 = actingAs($user)->get(route('dashboard'));
        $response1->assertOk();
        $response1->assertDontSee('pwaInstallPrompt()', false);

        // Add a qualifying signal (trypass via follow received)
        $follower = User::factory()->create();
        UserRelationship::create([
            'user_id' => $follower->id,
            'related_user_id' => $user->id,
            'type' => RelationshipType::Follow,
        ]);

        // Second request should still show no prompt (session cache not expired)
        $response2 = actingAs($user)->get(route('dashboard'));
        $response2->assertOk();
        $response2->assertDontSee('pwaInstallPrompt()', false);
    });

    it('stale session cache causes re-evaluation on next request', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $this->location->id,
            'email_verified_at' => now(),
        ]);

        // First request — not eligible (cached)
        session()->flush();
        $this->service->isEligible($user);

        // Manually expire the session cache
        $cacheKey = "pwa_eligibility_{$user->id}";
        $cached = session($cacheKey);
        $cached['expires'] = now()->subMinute()->timestamp;
        session([$cacheKey => $cached]);

        // Add qualifying signal
        Game::factory()->create([
            'owner_id' => $user->id,
            'date_time' => now()->addDays(3),
        ]);

        // Now re-check via HTTP — should see the prompt
        $response = actingAs($user)->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('pwaInstallPrompt()', false);
    });
});

// ── Cross-request Consistency ─────────────────────────

describe('Cross-request consistency', function () {
    it('eligible user sees prompt consistently across different pages', function () {
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

        // Dashboard
        $response = actingAs($user)->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('pwaInstallPrompt()', false);

        // Profile page (also uses app layout)
        $response = actingAs($user)->get(route('profile.show'));
        $response->assertOk();
        $response->assertSee('pwaInstallPrompt()', false);
    });

    it('ineligible user never sees prompt across different pages', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $this->location->id,
            'email_verified_at' => now(),
        ]);

        // No engagement signals
        session()->flush();

        // Dashboard
        $response = actingAs($user)->get(route('dashboard'));
        $response->assertOk();
        $response->assertDontSee('pwaInstallPrompt()', false);

        // Profile page
        $response = actingAs($user)->get(route('profile.show'));
        $response->assertOk();
        $response->assertDontSee('pwaInstallPrompt()', false);
    });
});
