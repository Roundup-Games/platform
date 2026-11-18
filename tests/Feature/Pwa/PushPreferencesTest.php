<?php

use App\Models\PushSubscription;
use App\Models\User;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);

    $this->user = User::factory()->create([
        'notification_settings' => null,
    ]);
    $this->actingAs($this->user);
});

describe('push subscription management', function () {
    it('subscribe endpoint creates PushSubscription when data is valid', function () {
        $payload = [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-pref-sub-001',
            'keys' => [
                'p256h' => rtrim(strtr(base64_encode(hex2bin('04'.bin2hex(random_bytes(32)).bin2hex(random_bytes(32)))), '+/', '-_'), '='),
                'auth' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
            ],
        ];

        $response = $this->postJson('/api/v1/push/subscribe', $payload);

        $response->assertCreated();
        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $this->user->id,
            'endpoint' => $payload['endpoint'],
        ]);
    });

    it('unsubscribe removes subscriptions for current user', function () {
        $sub = PushSubscription::factory()->create([
            'user_id' => $this->user->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-unsub-001',
        ]);

        $response = $this->deleteJson('/api/v1/push/subscribe', [
            'endpoint' => $sub->endpoint,
        ]);

        $response->assertSuccessful();
        $this->assertDatabaseMissing('push_subscriptions', [
            'id' => $sub->id,
        ]);
    });

});
