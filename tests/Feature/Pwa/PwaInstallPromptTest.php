<?php

use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\Location;
use App\Models\User;
use App\Models\UserAppVisit;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use function Pest\Laravel\{actingAs, get};

beforeEach(function () {
    $this->location = Location::factory()->create();
});

// ── Visibility Gating ─────────────────────────────────

describe('Visibility gating', function () {
    it('guest user does not see install prompt', function () {
        $response = get(route('home'));

        $response->assertOk();
        $response->assertDontSee('Install Roundup Games', false);
    });

    it('authenticated user with 0/3 score sees no prompt', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $this->location->id,
            'email_verified_at' => now(),
        ]);

        $response = actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee('Install Roundup Games', false);
    });

    it('authenticated user with 2/3 score sees the install prompt', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $this->location->id,
            'email_verified_at' => now(),
        ]);

        // Signal 1: 2 visit days
        UserAppVisit::factory()->create([
            'user_id' => $user->id,
            'visit_date' => now()->subDay()->toDateString(),
        ]);
        UserAppVisit::factory()->create([
            'user_id' => $user->id,
            'visit_date' => now()->toDateString(),
        ]);

        // Signal 2: approved game participation (past, to avoid trypass)
        $game = Game::factory()->create([
            'date_time' => now()->subDay(),
            'created_at' => now()->subDay(),
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        // Flush any session cache from previous requests
        session()->flush();

        $response = actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Install Roundup Games', false);
    });

    it('authenticated user without location does not see prompt', function () {
        $user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => null,
            'email_verified_at' => now(),
        ]);

        // Even with engagement signals, no location = baseline fails
        UserAppVisit::factory()->create([
            'user_id' => $user->id,
            'visit_date' => now()->subDay()->toDateString(),
        ]);
        UserAppVisit::factory()->create([
            'user_id' => $user->id,
            'visit_date' => now()->toDateString(),
        ]);

        session()->flush();

        $response = actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee('Install Roundup Games', false);
    });
});

// ── Trypass Events (HTTP-level) ───────────────────────

describe('Trypass events via HTTP', function () {
    beforeEach(function () {
        $this->user = User::factory()->create([
            'profile_complete' => true,
            'location_id' => $this->location->id,
            'email_verified_at' => now(),
        ]);
    });

    it('user with upcoming game within 7 days sees prompt', function () {
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'date_time' => now()->addDays(5),
        ]);

        session()->flush();

        $response = actingAs($this->user)->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('Install Roundup Games', false);
    });

    it('user with game starting in 8 days does not get trypass', function () {
        // Game is 8 days out — beyond 7-day trypass window. No other signals = not eligible.
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'date_time' => now()->addDays(8),
            'created_at' => now()->subDay(),
        ]);

        session()->flush();

        $response = actingAs($this->user)->get(route('dashboard'));
        $response->assertOk();
        $response->assertDontSee('Install Roundup Games', false);
    });

    it('user who just created a game sees prompt via trypass', function () {
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'date_time' => now()->addDays(30),
            'created_at' => now()->subMinutes(2),
        ]);

        session()->flush();

        $response = actingAs($this->user)->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('Install Roundup Games', false);
    });

    it('user who just received a game invitation sees prompt via trypass', function () {
        $host = User::factory()->create(['profile_complete' => true]);
        $game = Game::factory()->create(['owner_id' => $host->id]);
        GameParticipant::create([
            'user_id' => $this->user->id,
            'game_id' => $game->id,
            'status' => ParticipantStatus::Pending->value,
            'role' => ParticipantRole::Player->value,
        ]);

        session()->flush();

        $response = actingAs($this->user)->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('Install Roundup Games', false);
    });
});
