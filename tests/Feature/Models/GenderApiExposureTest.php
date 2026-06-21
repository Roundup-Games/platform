<?php

use App\Models\User;

// Proves `gender` and `gender_consent` are in the User::$hidden array so they
// can never leak through any serialization path (JSON, toArray, API resources).
// The other callers (toArray/toJson/nested) all flow through the same $hidden
// mechanism — testing each one separately just exercises Laravel's serializer.
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
