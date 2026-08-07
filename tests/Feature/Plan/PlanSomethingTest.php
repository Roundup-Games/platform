<?php

use App\Livewire\PlanSomething;
use App\Models\User;

use function Pest\Laravel\actingAs;

function planSomethingCreateUser(array $overrides = []): User
{
    return User::factory()->create(['profile_complete' => true, ...$overrides]);
}

// ═══════════════════════════════════════════════════════════
// RENDERING
// ═══════════════════════════════════════════════════════════

describe('PlanSomething — Rendering', function () {
    it('renders the frequency choice for an authenticated, profile-complete user', function () {
        actingAs($user = planSomethingCreateUser());

        Livewire\Livewire::test(PlanSomething::class)
            ->assertSee(__('plan.content_one_time'))
            ->assertSee(__('plan.content_recurring'))
            ->assertOk();
    })->group('smoke');
});

// ═══════════════════════════════════════════════════════════
// ONE-TIME SESSION — must NOT pre-select a game type
// ═══════════════════════════════════════════════════════════

describe('PlanSomething — planOneShot', function () {
    // Regression guard: the one-time card previously fired planOneShot(),
    // which redirected to route('games.create', ['type' => 'board_game']) —
    // silently assuming every one-time session is a board game and causing
    // CreateGame::mount() to skip its type-selector cards. A one-time session
    // can be any of the three GameType values (the copy says "board game night,
    // a one-shot adventure, or a casual gathering"), so the host must pick.
    //
    // The card is now a plain <a wire:navigate> straight to games.create with
    // NO ?type= query. assertRedirect no longer applies (there is no Livewire
    // round-trip), so we assert the rendered anchor carries the bare create
    // URL and no type query.
    it('links to games.create with no pre-selected type', function () {
        actingAs(planSomethingCreateUser());

        $html = Livewire\Livewire::test(PlanSomething::class)->html();

        expect($html)->toContain('/games/create')
            ->and($html)->not->toContain('?type=');
    });
});

// ═══════════════════════════════════════════════════════════
// RECURRING EVENT
// ═══════════════════════════════════════════════════════════

describe('PlanSomething — planRecurring', function () {
    it('links to campaigns.create', function () {
        actingAs(planSomethingCreateUser());

        $html = Livewire\Livewire::test(PlanSomething::class)->html();

        expect($html)->toContain('/campaigns/create');
    });
});
