<?php

use App\Models\PushSubscription;
use App\Models\User;
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
    });

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
        $mock = Mockery::mock(\App\Services\GeocodingService::class);
        $mock->shouldReceive('geocode')
            ->once()
            ->andReturn([
                'lat' => 52.52,
                'lng' => 13.40,
                'display_name' => 'Berlin',
                'place_id' => '1',
                'raw' => ['address' => ['city' => 'Berlin', 'country' => 'Germany']],
            ]);
        $this->app->instance(\App\Services\GeocodingService::class, $mock);

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
