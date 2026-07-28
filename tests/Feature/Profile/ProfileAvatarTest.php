<?php

use App\Livewire\Profile\Show;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

// ── Avatar Management ─────────────────────────────────
//
// removeAvatar()'s real behaviour is clearing the 'avatar' media collection
// (User::clearMediaCollection('avatar')). The previous version asserted only
// Log::shouldReceive('info') — which passes if clearing is broken but the log
// line remains. These tests assert the collection is actually emptied.

it('removes the avatar from the media collection when requested', function () {
    Storage::fake('public');

    $user = User::factory()->create(['profile_complete' => true]);

    // Precondition: user has an avatar.
    $user->addMedia(UploadedFile::fake()->image('avatar.jpg', 200, 200))
        ->toMediaCollection('avatar');
    expect($user->fresh()->getFirstMedia('avatar'))->not->toBeNull();

    Livewire::actingAs($user)
        ->test(Show::class)
        ->call('removeAvatar');

    // The behaviour that actually matters: the avatar is gone.
    expect($user->fresh()->getFirstMedia('avatar'))->toBeNull();
});

it('does not throw when removing an avatar the user never had', function () {
    Storage::fake('public');

    $user = User::factory()->create(['profile_complete' => true]);
    expect($user->getFirstMedia('avatar'))->toBeNull();

    Livewire::actingAs($user)
        ->test(Show::class)
        ->call('removeAvatar')
        ->assertHasNoErrors();

    expect($user->fresh()->getFirstMedia('avatar'))->toBeNull();
});
