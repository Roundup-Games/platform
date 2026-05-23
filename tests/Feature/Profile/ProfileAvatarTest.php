<?php

use App\Livewire\Profile\Show;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

// ── Avatar Management ─────────────────────────────────

it('can remove avatar', function () {
    $user = User::factory()->create(['profile_complete' => true]);

    Log::shouldReceive('info')
        ->with('Avatar removed', \Mockery::on(fn ($ctx) => $ctx['user_id'] === $user->id))
        ->once();

    Livewire::actingAs($user)
        ->test(Show::class)
        ->call('removeAvatar');
});
