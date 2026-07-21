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
    // Regression guard: previously planOneShot() redirected to
    // route('games.create', ['type' => 'board_game']), which (a) silently
    // assumed every one-time session is a board game and (b) caused
    // CreateGame::mount() to skip its type-selector cards. A one-time session
    // can be any of the three GameType values — the copy itself says
    // "board game night, a one-shot adventure, or a casual gathering" (see
    // lang/en/plan.php 'content_one_time_desc') — so the host must pick.
    //
    // assertRedirect(route('games.create')) is an EXACT URL match (see
    // Livewire TestsRedirects::assertRedirect): a redirect to the type-scoped
    // route would carry '?type=board_game' and fail this assertion. Verified
    // by reverting the fix and watching this test go red.
    it('redirects to games.create with no pre-selected type', function () {
        actingAs(planSomethingCreateUser());

        Livewire\Livewire::test(PlanSomething::class)
            ->call('planOneShot')
            ->assertRedirect(route('games.create'));
    });
});

// ═══════════════════════════════════════════════════════════
// RECURRING EVENT
// ═══════════════════════════════════════════════════════════

describe('PlanSomething — planRecurring', function () {
    it('redirects to campaigns.create', function () {
        actingAs(planSomethingCreateUser());

        Livewire\Livewire::test(PlanSomething::class)
            ->call('planRecurring')
            ->assertRedirect(route('campaigns.create'));
    });
});
