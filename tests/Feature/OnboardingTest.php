<?php

use App\Livewire\Onboarding\CompleteProfile;
use App\Models\GameSystem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// ── Middleware: EnsureProfileComplete ──────────────────

it('redirects unprofiled user to onboarding from dashboard', function () {
    $user = User::factory()->create([
        'profile_complete' => false,
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertRedirect(route('onboarding.index'));
});

it('allows unprofiled user to access profile edit (needed for onboarding)', function () {
    $user = User::factory()->create([
        'profile_complete' => false,
        'email_verified_at' => now(),
    ]);

    // Profile routes are allowed through the middleware so users can
    // still update their profile even if not fully complete
    $response = $this->actingAs($user)->get('/profile');

    $response->assertOk();
});

it('allows profiled user to access dashboard', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
});

it('does not redirect unauthenticated users', function () {
    $response = $this->get('/dashboard');

    // Should redirect to login, not onboarding
    $response->assertRedirect('/login');
});

// ── Onboarding page access ────────────────────────────

it('allows unprofiled user to access onboarding page', function () {
    $user = User::factory()->create([
        'profile_complete' => false,
    ]);

    $response = $this->actingAs($user)->get('/onboarding');

    $response->assertOk();
});

it('redirects profiled user away from onboarding to dashboard', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
    ]);

    $response = $this->actingAs($user)->get('/onboarding');

    $response->assertRedirect(route('dashboard'));
});

// ── Registration sets profile_complete=false ──────────

it('sets profile_complete to false on email registration', function () {
    $this->post('/register', [
        'name' => 'New User',
        'email' => 'new@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertDatabaseHas('users', [
        'email' => 'new@example.com',
        'profile_complete' => false,
    ]);
});

it('redirects to onboarding after registration', function () {
    $response = $this->post('/register', [
        'name' => 'New User',
        'email' => 'new@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect(route('onboarding.index'));
});

// ── Profile completion (direct model) ─────────────────

it('marks profile complete after updating profile fields', function () {
    $user = User::factory()->create([
        'profile_complete' => false,
        'gender' => null,
        'pronouns' => null,
    ]);

    $user->update([
        'gender' => 'non-binary',
        'pronouns' => 'they/them',
        'phone' => '+15551234567',
        'profile_complete' => true,
        'profile_version' => $user->profile_version + 1,
        'profile_updated_at' => now(),
    ]);

    $fresh = $user->fresh();
    expect($fresh)->profile_complete->toBeTrue()
        ->and($fresh)->gender->toBe('non-binary')
        ->and($fresh)->pronouns->toBe('they/them')
        ->and($fresh)->phone->toBe('+15551234567')
        ->and($fresh)->profile_version->toBe(1);
});

it('increments profile_version on completion', function () {
    $user = User::factory()->create([
        'profile_complete' => false,
        'profile_version' => 0,
    ]);

    $user->update([
        'gender' => 'female',
        'pronouns' => 'she/her',
        'profile_complete' => true,
        'profile_version' => $user->profile_version + 1,
        'profile_updated_at' => now(),
    ]);

    expect($user->fresh()->profile_version)->toBe(1);
});

it('sets profile_updated_at on completion', function () {
    $user = User::factory()->create([
        'profile_complete' => false,
        'profile_updated_at' => null,
    ]);

    $user->update([
        'gender' => 'male',
        'pronouns' => 'he/him',
        'profile_complete' => true,
        'profile_version' => $user->profile_version + 1,
        'profile_updated_at' => now(),
    ]);

    expect($user->fresh()->profile_updated_at)->not->toBeNull();
});

it('allows profiled user to access profile edit without redirect', function () {
    $user = User::factory()->create([
        'profile_complete' => true,
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)->get('/profile');

    $response->assertOk();
});

// ── Livewire Component: Multi-step flow ───────────────

it('renders step 1 by default', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->assertSet('step', 1)
        ->assertSee('Tell us about yourself')
        ->assertSee('Gender')
        ->assertSee('Pronouns');
});

it('redirects profiled user on mount', function () {
    $user = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->assertRedirect(route('dashboard'));
});

it('advances to step 2 with valid gender and pronouns', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('gender', 'non-binary')
        ->set('pronouns', 'they/them')
        ->call('nextStep')
        ->assertSet('step', 2)
        ->assertSee('Contact information');
});

it('validates gender is required on step 1', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('gender', '')
        ->set('pronouns', 'they/them')
        ->call('nextStep')
        ->assertHasErrors('gender')
        ->assertSet('step', 1);
});

it('validates pronouns is required on step 1', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('gender', 'male')
        ->set('pronouns', '')
        ->call('nextStep')
        ->assertHasErrors('pronouns')
        ->assertSet('step', 1);
});

it('advances to step 3 from step 2 with optional phone', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('gender', 'female')
        ->set('pronouns', 'she/her')
        ->call('nextStep')
        ->assertSet('step', 2)
        ->set('phone', '+15551234567')
        ->call('nextStep')
        ->assertSet('step', 3)
        ->assertSee('Game preferences');
});

it('allows empty phone on step 2', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('gender', 'male')
        ->set('pronouns', 'he/him')
        ->call('nextStep')
        ->set('phone', '')
        ->call('nextStep')
        ->assertSet('step', 3);
});

it('goes back to previous step', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('gender', 'male')
        ->set('pronouns', 'he/him')
        ->call('nextStep')
        ->assertSet('step', 2)
        ->call('previousStep')
        ->assertSet('step', 1);
});

it('does not go below step 1', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->call('previousStep')
        ->assertSet('step', 1);
});

// ── Completion ────────────────────────────────────────

it('completes profile and redirects to dashboard', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('gender', 'non-binary')
        ->set('pronouns', 'they/them')
        ->call('nextStep')
        ->set('phone', '+15551234567')
        ->call('nextStep')
        ->call('complete')
        ->assertRedirect(route('dashboard'));

    $fresh = $user->fresh();
    expect($fresh->profile_complete)->toBeTrue()
        ->and($fresh->gender)->toBe('non-binary')
        ->and($fresh->pronouns)->toBe('they/them')
        ->and($fresh->phone)->toBe('+15551234567')
        ->and($fresh->profile_version)->toBe(1)
        ->and($fresh->profile_updated_at)->not->toBeNull();
});

it('stores phone as null when empty', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('gender', 'female')
        ->set('pronouns', 'she/her')
        ->call('nextStep')
        ->set('phone', '')
        ->call('nextStep')
        ->call('complete');

    expect($user->fresh()->phone)->toBeNull();
});

it('syncs favorite game systems on completion', function () {
    $user = User::factory()->create(['profile_complete' => false]);
    $gs1 = GameSystem::create(['name' => 'D&D 5e', 'slug' => 'dnd-5e']);
    $gs2 = GameSystem::create(['name' => 'Pathfinder', 'slug' => 'pathfinder']);
    $gs3 = GameSystem::create(['name' => 'Call of Cthulhu', 'slug' => 'call-of-cthulhu']);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('gender', 'male')
        ->set('pronouns', 'he/him')
        ->call('nextStep')
        ->call('nextStep')
        ->set('favoriteGameSystemIds', [$gs1->id, $gs3->id])
        ->call('complete');

    $fresh = $user->fresh();
    expect($fresh->favoriteGameSystems->pluck('id')->sort()->values()->toArray())
        ->toBe([$gs1->id, $gs3->id]);
});

it('handles no game system selections gracefully', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('gender', 'prefer-not-to-say')
        ->set('pronouns', 'prefer-not-to-say')
        ->call('nextStep')
        ->call('nextStep')
        ->set('favoriteGameSystemIds', [])
        ->call('complete')
        ->assertRedirect(route('dashboard'));

    expect($user->fresh()->profile_complete)->toBeTrue();
    expect($user->fresh()->gameSystemPreferences)->toHaveCount(0);
});

it('logs onboarding completion event', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    Log::shouldReceive('info')
        ->once()
        ->with('Onboarding completed', \Mockery::on(function ($context) use ($user) {
            return $context['user_id'] === $user->id
                && $context['gender'] === 'male'
                && $context['game_systems_count'] === 0;
        }));

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('gender', 'male')
        ->set('pronouns', 'he/him')
        ->call('nextStep')
        ->call('nextStep')
        ->call('complete');
});

it('validates all steps when completing from step 3 with invalid step 1 data', function () {
    $user = User::factory()->create(['profile_complete' => false]);

    // Go through steps with valid data, then change step 1 to invalid
    $component = Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('gender', 'male')
        ->set('pronouns', 'he/him')
        ->call('nextStep')
        ->call('nextStep')
        ->assertSet('step', 3);

    // Manually clear gender (simulating tampered state)
    $component->set('gender', '')
        ->call('complete')
        ->assertHasErrors('gender');

    // Profile should NOT be complete
    expect($user->fresh()->profile_complete)->toBeFalse();
});

it('pre-fills existing user data from OAuth on mount', function () {
    $user = User::factory()->create([
        'profile_complete' => false,
        'gender' => 'non-binary',
        'pronouns' => 'they/them',
    ]);

    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->assertSet('gender', 'non-binary')
        ->assertSet('pronouns', 'they/them');
});

it('replaces game system preferences on re-sync', function () {
    $user = User::factory()->create(['profile_complete' => false]);
    $gs1 = GameSystem::create(['name' => 'D&D 5e', 'slug' => 'dnd-5e']);
    $gs2 = GameSystem::create(['name' => 'Pathfinder', 'slug' => 'pathfinder']);

    // First attach gs1
    $user->gameSystemPreferences()->attach($gs1->id, ['preference_type' => 'favorite']);

    // Now complete with gs2 only — gs1 should be removed
    Livewire::actingAs($user)
        ->test(CompleteProfile::class)
        ->set('gender', 'male')
        ->set('pronouns', 'he/him')
        ->call('nextStep')
        ->call('nextStep')
        ->set('favoriteGameSystemIds', [$gs2->id])
        ->call('complete');

    $fresh = $user->fresh();
    $prefIds = $fresh->favoriteGameSystems->pluck('id')->toArray();
    expect($prefIds)->toBe([$gs2->id]);
});
