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
    test('fresh user with no games resolves to newcomer mode', function () {
        $user = User::factory()->create(['created_at' => now()->subDays(5)]);
        $this->actingAs($user);

        $component = Livewire::test(Dashboard::class);

        $mode = $component->viewData('dashboardMode');
        expect($mode)->toBe('newcomer');
    });

    test('user older than 30 days with zero attended games resolves to established', function () {
        $user = User::factory()->create(['created_at' => now()->subDays(60)]);
        $this->actingAs($user);

        $component = Livewire::test(Dashboard::class);

        $mode = $component->viewData('dashboardMode');
        expect($mode)->toBe('established');
    });

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

        $mode = $component->viewData('dashboardMode');
        expect($mode)->toBe('established');
    });

    test('old user with five attended games resolves to established', function () {
        $user = User::factory()->create(['created_at' => now()->subDays(90)]);
        $games = Game::factory()->count(5)->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Completed,
        ]);
        foreach ($games as $game) {
            GameParticipant::factory()->create([
                'game_id' => $game->id,
                'user_id' => $user->id,
                'status' => ParticipantStatus::Approved,
                'attendance_status' => AttendanceStatus::Attended,
            ]);
        }

        $this->actingAs($user);
        $component = Livewire::test(Dashboard::class);

        $mode = $component->viewData('dashboardMode');
        expect($mode)->toBe('established');
    });

    test('boundary: user at exactly 30 days is established', function () {
        $user = User::factory()->create(['created_at' => now()->subDays(30)]);
        $this->actingAs($user);

        $component = Livewire::test(Dashboard::class);

        $mode = $component->viewData('dashboardMode');
        expect($mode)->toBe('established');
    });

    test('boundary: user at 29 days with no attendance is newcomer', function () {
        $user = User::factory()->create(['created_at' => now()->subDays(29)]);
        $this->actingAs($user);

        $component = Livewire::test(Dashboard::class);

        $mode = $component->viewData('dashboardMode');
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
        expect($first->viewData('dashboardMode'))->toBe('newcomer');

        // Pre-seed cache with 'established' to verify cache is read
        Cache::put("dashboard:mode:{$user->id}", 'established', 300);

        $second = Livewire::test(Dashboard::class);
        expect($second->viewData('dashboardMode'))->toBe('established');
    });

    test('mode invalidation causes re-resolution on next render', function () {
        $user = User::factory()->create(['created_at' => now()->subDays(5)]);
        $this->actingAs($user);

        // First render — newcomer
        $first = Livewire::test(Dashboard::class);
        expect($first->viewData('dashboardMode'))->toBe('newcomer');

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
        expect($second->viewData('dashboardMode'))->toBe('established');
    });
});

// ── Component wiring: dashboardMode always passed to view ───────

describe('Dashboard component wiring', function () {
    test('dashboard always passes dashboardMode to view', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(Dashboard::class);

        $component->assertViewHas('dashboardMode');
        $mode = $component->viewData('dashboardMode');
        expect($mode)->toBeString();
        expect($mode)->toBeIn(['newcomer', 'established']);
    });

    test('dashboardMode is resolved via DashboardModeService', function () {
        $user = User::factory()->create(['created_at' => now()->subDays(5)]);
        $this->actingAs($user);

        // Verify service resolution matches component output
        $serviceMode = app(DashboardModeService::class)->resolve($user);

        $component = Livewire::test(Dashboard::class);
        $componentMode = $component->viewData('dashboardMode');

        expect($componentMode)->toBe($serviceMode);
    });

    test('dashboard renders without error regardless of mode', function () {
        // Test both modes render without exception
        $newcomer = User::factory()->create(['created_at' => now()]);
        $this->actingAs($newcomer);
        Livewire::test(Dashboard::class)->assertStatus(200);

        $established = User::factory()->create(['created_at' => now()->subDays(60)]);
        $this->actingAs($established);
        Livewire::test(Dashboard::class)->assertStatus(200);
    });
});
