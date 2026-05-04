<?php

use App\Models\PushSubscription;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('hides encryption keys from array and JSON output', function () {
    $subscription = PushSubscription::factory()->create(['user_id' => $this->user->id]);

    $array = $subscription->toArray();
    $json = $subscription->toJson();

    expect($array)->not->toHaveKey('p256h_key')
        ->and($array)->not->toHaveKey('auth_token')
        ->and($json)->not->toContain('p256h_key')
        ->and($json)->not->toContain('auth_token')
        ->and($array)->toHaveKey('id')
        ->and($array)->toHaveKey('endpoint');
});

it('scopes queries to a specific user via forUser', function () {
    $otherUser = User::factory()->create();

    PushSubscription::factory()->create(['user_id' => $this->user->id]);
    PushSubscription::factory()->create(['user_id' => $this->user->id]);
    PushSubscription::factory()->create(['user_id' => $otherUser->id]);

    $results = PushSubscription::forUser($this->user->id)->get();

    expect($results)->toHaveCount(2)
        ->and($results->pluck('user_id')->unique()->first())->toBe($this->user->id);
});


