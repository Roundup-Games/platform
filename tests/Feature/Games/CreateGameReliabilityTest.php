<?php

use App\Livewire\Games\CreateGame;
use App\Models\User;
use function Pest\Laravel\{actingAs};

// ── Helpers ──────────────────────────────────────────────

function createGameReliabilityUser(array $overrides = []): User
{
    return User::factory()->create(['profile_complete' => true, ...$overrides]);
}

// ═══════════════════════════════════════════════════════════
// CREATE GAME — RELIABILITY PREFERENCE
// ═══════════════════════════════════════════════════════════

describe('CreateGame — Reliability Preference', function () {
    it('shows reliability preference field on create page', function () {
        $user = createGameReliabilityUser();

        actingAs($user);
        Livewire\Livewire::test(CreateGame::class)
            ->assertStatus(200)
            ->assertSee('Attendance Preference');
    });

    it('has min_reliability_preference property', function () {
        $user = createGameReliabilityUser();

        actingAs($user);
        Livewire\Livewire::test(CreateGame::class)
            ->set('min_reliability_preference', '80')
            ->assertSet('min_reliability_preference', '80');
    });

    it('validates min_reliability_preference accepts valid value', function () {
        $user = createGameReliabilityUser();

        actingAs($user);
        $component = Livewire\Livewire::test(CreateGame::class);
        $rules = $component->instance()->rules();

        expect($rules)->toHaveKey('min_reliability_preference')
            ->and($rules['min_reliability_preference'])->toContain('nullable', 'numeric', 'min:0', 'max:100');
    });

    it('allows null reliability preference', function () {
        $user = createGameReliabilityUser();

        actingAs($user);
        Livewire\Livewire::test(CreateGame::class)
            ->set('min_reliability_preference', '')
            ->assertSet('min_reliability_preference', '')
            ->assertHasNoErrors('min_reliability_preference');
    });

    it('rejects reliability preference above 100 via validation rules', function () {
        $user = createGameReliabilityUser();

        actingAs($user);
        $rules = $user->can('create', \App\Models\Game::class)
            ? $component = Livewire\Livewire::test(CreateGame::class)
            : Livewire\Livewire::test(CreateGame::class);

        // Verify the max:100 rule is present
        expect($rules->instance()->rules()['min_reliability_preference'])->toContain('max:100');
    });

    it('create game blade includes reliability preference input field', function () {
        $user = createGameReliabilityUser();

        actingAs($user);
        $response = $this->get(route('games.create'));
        $response->assertStatus(200);
        $response->assertSee('game-reliability');
        $response->assertSee('min_reliability_preference');
    });
});
