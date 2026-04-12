<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can update profile name and email', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->patch('/profile', [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
        ]);

    $response->assertSessionHasNoErrors();

    expect($user->fresh())
        ->name->toBe('Updated Name')
        ->email->toBe('updated@example.com');
});

it('can update profile extended fields', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ])->refresh(); // refresh to pick up DB defaults like profile_version=1

    $originalVersion = $user->profile_version;

    $user->gender = 'non-binary';
    $user->pronouns = 'they/them';
    $user->phone = '+15559876543';
    $user->profile_version = $originalVersion + 1;
    $user->profile_updated_at = now();
    $user->save();

    $fresh = $user->fresh();
    expect($fresh->gender)->toBe('non-binary');
    expect($fresh->pronouns)->toBe('they/them');
    expect($fresh->phone)->toBe('+15559876543');
    expect($fresh->profile_version)->toBe($originalVersion + 1);
});

it('can delete own account with correct password', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->delete('/profile', [
            'password' => 'password',
        ]);

    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
});

it('cannot delete account with wrong password', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->from('/profile')
        ->delete('/profile', [
            'password' => 'wrong-password',
        ]);

    $response->assertRedirect('/profile');
    $this->assertDatabaseHas('users', ['id' => $user->id]);
});
