<?php

use App\Enums\AttendanceStatus;
use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Livewire\Dashboard;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\DashboardModeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
    Log::spy();
    URL::defaults(['locale' => 'en']);
});

// ── Mode resolution via Livewire component ──────────────────────

describe('Dashboard mode resolution', function () {
    // Validation note (M077/S01): the fresh-user, 60d-user and old+5-games cases
    // were cut — same branches as the boundary pair below and the attended-flip.
    test('new user with one attended game resolves to established', function () {
        $user = User::factory()->create(['created_at' => now()->subDays(5)]);
        $game = Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Completed,
        ]);
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Approved,
            'attendance_status' => AttendanceStatus::Attended,
        ]);

        $this->actingAs($user);
        $component = Livewire::test(Dashboard::class);

        $mode = $component->viewData('dashboard')->mode;
        expect($mode)->toBe('established');
    });

    test('boundary: user at exactly 30 days is established', function () {
        $user = User::factory()->create(['created_at' => now()->subDays(30)]);
        $this->actingAs($user);

        $component = Livewire::test(Dashboard::class);

        $mode = $component->viewData('dashboard')->mode;
        expect($mode)->toBe('established');
    });

    test('boundary: user at 29 days with no attendance is newcomer', function () {
        $user = User::factory()->create(['created_at' => now()->subDays(29)]);
        $this->actingAs($user);

        $component = Livewire::test(Dashboard::class);

        $mode = $component->viewData('dashboard')->mode;
        expect($mode)->toBe('newcomer');
    });
});

// ── DashboardModeService caching via component ──────────────────
describe('Dashboard mode caching', function () {
    test('mode is cached after first render', function () {
        $user = User::factory()->create(['created_at' => now()->subDays(5)]);
        $this->actingAs($user);

        // First render populates cache
        Livewire::test(Dashboard::class);

        $cacheKey = "dashboard:mode:{$user->id}";
        expect(Cache::get($cacheKey))->toBe('newcomer');
    });

    test('cached mode is reused on subsequent renders', function () {
        $user = User::factory()->create(['created_at' => now()->subDays(5)]);
        $this->actingAs($user);

        // First render
        $first = Livewire::test(Dashboard::class);
        expect($first->viewData('dashboard')->mode)->toBe('newcomer');

        // Pre-seed cache with 'established' to verify cache is read
        Cache::put("dashboard:mode:{$user->id}", 'established', 300);

        $second = Livewire::test(Dashboard::class);
        expect($second->viewData('dashboard')->mode)->toBe('established');
    });

    test('mode invalidation causes re-resolution on next render', function () {
        $user = User::factory()->create(['created_at' => now()->subDays(5)]);
        $this->actingAs($user);

        // First render — newcomer
        $first = Livewire::test(Dashboard::class);
        expect($first->viewData('dashboard')->mode)->toBe('newcomer');

        // Invalidate the mode cache
        app(DashboardModeService::class)->invalidateForUser($user);

        // Now create an attended game
        $game = Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Completed,
        ]);
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Approved,
            'attendance_status' => AttendanceStatus::Attended,
        ]);

        // Re-render should resolve as established
        $second = Livewire::test(Dashboard::class);
        expect($second->viewData('dashboard')->mode)->toBe('established');
    });
});

// Validation note (M077/S01): the 'component wiring' describe (3 mount-smoke
// tests — always-passes-mode, matches-service-output, renders-regardless) was
// cut as duplicates of the resolution and caching tests above.
