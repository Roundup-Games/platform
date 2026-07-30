<?php

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\GeocodingService;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

describe('Legacy API redirect compatibility', function () {
    it('redirects /api/geocode to /api/v1/geocode with 301', function () {
        $this->postJson('/api/geocode', ['query' => 'test'])
            ->assertStatus(301);
    });

    it('redirects /api/push/subscribe POST to /api/v1/push/subscribe with 308', function () {
        $this->postJson('/api/push/subscribe', [
            'endpoint' => 'https://example.com/push',
            'keys' => ['p256h' => 'a', 'auth' => 'b'],
        ])->assertStatus(308);
    });

    it('redirects /api/push/subscribe DELETE to /api/v1/push/subscribe with 308', function () {
        $this->deleteJson('/api/push/subscribe', [
            'endpoint' => 'https://example.com/push',
        ])->assertStatus(308);
    });

    it('redirects /api/push/vapid-public-key to /api/v1/push/vapid-public-key with 301', function () {
        Config::set('services.vapid.public_key', 'test-key');

        $this->getJson('/api/push/vapid-public-key')
            ->assertStatus(301);
    });
});

describe('API v1 route authentication', function () {
    it('blocks unauthenticated POST /api/v1/push/subscribe', function () {
        $this->postJson('/api/v1/push/subscribe', [
            'endpoint' => 'https://example.com/push',
            'keys' => ['p256h' => 'key', 'auth' => 'auth'],
        ])->assertUnauthorized();
    })->group('smoke');

    it('blocks unauthenticated DELETE /api/v1/push/subscribe', function () {
        $this->deleteJson('/api/v1/push/subscribe', [
            'endpoint' => 'https://example.com/push',
        ])->assertUnauthorized();
    });

    it('allows unauthenticated GET /api/v1/push/vapid-public-key', function () {
        Config::set('services.vapid.public_key', 'test-key');

        $this->getJson('/api/v1/push/vapid-public-key')
            ->assertOk()
            ->assertJson(['public_key' => 'test-key']);
    });

    it('allows unauthenticated POST /api/v1/geocode', function () {
        $mock = Mockery::mock(GeocodingService::class);
        $mock->shouldReceive('geocode')
            ->once()
            ->andReturn([
                'lat' => 52.52,
                'lng' => 13.40,
                'display_name' => 'Berlin',
                'place_id' => '1',
                'raw' => ['address' => ['city' => 'Berlin', 'country' => 'Germany']],
            ]);
        $this->app->instance(GeocodingService::class, $mock);

        $this->postJson('/api/v1/geocode', ['query' => 'Berlin'])
            ->assertOk();
    });
});

describe('API v1 push endpoint auth:sanctum', function () {
    it('allows authenticated user to subscribe via /api/v1/push/subscribe', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/push/subscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/auth-test-001',
            'keys' => ['p256h' => 'test-key', 'auth' => 'test-auth'],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $user->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/auth-test-001',
        ]);
    });

    it('allows authenticated user to unsubscribe via DELETE /api/v1/push/subscribe', function () {
        $user = User::factory()->create();
        $sub = PushSubscription::factory()->create([
            'user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->deleteJson('/api/v1/push/subscribe', [
                'endpoint' => $sub->endpoint,
            ])
            ->assertNoContent();

        $this->assertDatabaseMissing('push_subscriptions', [
            'id' => $sub->id,
        ]);
    });
});

describe('API v1 disabled-user ejection (auth:sanctum)', function () {
    it('ejects a disabled user from API routes with 401 JSON', function () {
        // auth:sanctum runs in SPA/session mode here (bearer tokens are not
        // usable: users have UUID keys but personal_access_tokens.tokenable_id
        // is bigint). The real exposure is the re-login window — Auth::attempt
        // does not filter is_disabled, so a disabled user can establish a fresh
        // session and reach an API route before any not.disabled web page. The
        // not.disabled alias on this group closes it and, because this is a JSON
        // request, must return a clean 401 rather than a web redirect.
        $user = User::factory()->create();

        $user->update(['is_disabled' => true, 'disabled_at' => now()]);

        // Production re-resolves the user per request; the test guard caches
        // the model, so re-auth with the fresh disabled instance (mirrors the
        // pattern in AuthenticationSmokeTest).
        $this->actingAs($user->fresh())
            ->postJson('/api/v1/push/subscribe', [
                'endpoint' => 'https://example.com/push/disabled',
                'keys' => ['p256h' => 'k', 'auth' => 'a'],
            ])
            ->assertUnauthorized()
            ->assertJson(['message' => __('auth.error_your_account_has_been_disabled')]);
    })->group('smoke');
});
