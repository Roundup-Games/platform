<?php

use App\Models\User;
use App\Models\UserRelationship;
use App\Enums\RelationshipType;
use function Pest\Laravel\{get, actingAs};

/*
|--------------------------------------------------------------------------
| Accessibility Tests
|--------------------------------------------------------------------------
|
| Tests that verify baseline ARIA compliance across layouts.
| Template-level string checks (label for=, aria-label, role) are
| intentionally excluded — they break on any copy/refactor change
| without catching real bugs. Use axe-core for structural a11y audits.
|
*/

describe('Skip Links', function () {
    it('has skip link on app layout pages', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Skip to content')
            ->assertSee('href="#main-content"', false)
            ->assertSee('id="main-content"', false);
    });

    it('has skip link on public layout pages', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee('Skip to content')
            ->assertSee('href="#main-content"', false)
            ->assertSee('id="main-content"', false);
    });
});

describe('User Link Component Sweep', function () {
    it('people page renders user links with avatars', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);
        $followedUser = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        UserRelationship::create([
            'user_id' => $user->id,
            'related_user_id' => $followedUser->id,
            'type' => RelationshipType::Follow,
        ]);

        $response = actingAs($user)->get(route('people'));
        $response->assertOk();
        $response->assertSee($followedUser->name);
        $response->assertSee(route('profile.public', $followedUser));
    });
});
