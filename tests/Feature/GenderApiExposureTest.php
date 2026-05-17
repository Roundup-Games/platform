<?php

use App\Models\User;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

it('excludes gender from user model JSON serialization', function () {
    $user = User::factory()->create([
        'gender' => 'female',
        'gender_consent' => true,
    ]);

    $json = json_encode($user->toArray());

    expect($json)->not->toContain('"gender"')
        ->and($json)->not->toContain('"gender_consent"')
        ->and($json)->not->toContain('female');
});

it('excludes gender from user model toArray output', function () {
    $user = User::factory()->create([
        'gender' => 'male',
        'gender_consent' => true,
    ]);

    $array = $user->toArray();

    expect($array)->not->toHaveKey('gender')
        ->and($array)->not->toHaveKey('gender_consent');
});

it('excludes gender from user model toJson output', function () {
    $user = User::factory()->create([
        'gender' => 'non_binary',
        'gender_consent' => true,
    ]);

    $json = $user->toJson();

    expect($json)->not->toContain('"gender"')
        ->and($json)->not->toContain('"gender_consent"');
});

it('excludes gender from nested user serialization in relationships', function () {
    $user = User::factory()->create([
        'gender' => 'female',
        'gender_consent' => true,
    ]);

    // Simulate embedding user data as a relation would
    $payload = ['participant' => $user->toArray()];

    expect($payload['participant'])->not->toHaveKey('gender')
        ->and($payload['participant'])->not->toHaveKey('gender_consent');
});
