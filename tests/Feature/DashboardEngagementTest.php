<?php

use App\Enums\AttendanceStatus;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use function Pest\Laravel\{actingAs, get};

describe('Dashboard Engagement Cards', function () {
    beforeEach(function () {
        $this->user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        // Ensure a game system exists for factory relations
        GameSystem::factory()->create();
    });

    it('shows Games This Week card with encouraging CTA when no games scheduled', function () {
        $response = actingAs($this->user)
            ->get(route('dashboard'));

        $response->assertOk()
            ->assertSee(__('attendance.dashboard_games_this_week'))
            ->assertSee(__('attendance.dashboard_no_games_this_week'))
            ->assertSee(__('attendance.dashboard_find_next_game'));
    });

    it('shows Games This Week count for owned games this week', function () {
        // Create a game owned by user happening this week
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'date_time' => now()->startOfWeek()->addDays(2),
            'status' => 'scheduled',
        ]);

        // Create a game outside this week — should NOT appear
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'date_time' => now()->subWeek(),
            'status' => 'scheduled',
        ]);

        $response = actingAs($this->user)
            ->get(route('dashboard'));

        $response->assertOk()
            ->assertSee(__('attendance.dashboard_games_this_week'));

        $content = $response->getContent();
        // Should show count of 1 for this week's game
        $this->assertStringContainsString(__('attendance.dashboard_hosting'), $content);
    });

    it('shows Games This Week count for participant games', function () {
        $host = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->startOfWeek()->addDays(1),
            'status' => 'scheduled',
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved,
        ]);

        $response = actingAs($this->user)
            ->get(route('dashboard'));

        $response->assertOk();

        $content = $response->getContent();
        // Should show the game name
        $this->assertStringContainsString(e($game->name), $content);
    });

    it('excludes non-approved participant games from Games This Week', function () {
        $host = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->startOfWeek()->addDays(1),
            'status' => 'scheduled',
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Pending,
        ]);

        $response = actingAs($this->user)
            ->get(route('dashboard'));

        $response->assertOk()
            ->assertSee(__('attendance.dashboard_no_games_this_week'));
    });

    it('shows attendance summary with attended and pending counts', function () {
        // Create an owned game (implicitly attended)
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'date_time' => now()->startOfWeek()->addDays(1),
            'status' => 'scheduled',
        ]);

        $response = actingAs($this->user)
            ->get(route('dashboard'));

        $response->assertOk();

        $content = $response->getContent();
        $this->assertStringContainsString(__('attendance.dashboard_attended'), $content);
        $this->assertStringContainsString(__('attendance.dashboard_total'), $content);
    });

    it('shows recap card for games with new recaps', function () {
        $host = User::factory()->create(['name' => 'Test Host']);
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->subDay(),
            'status' => 'completed',
            'recap' => 'This was an amazing session with great roleplay!',
            'updated_at' => now()->subDay(),
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved,
        ]);

        $response = actingAs($this->user)
            ->get(route('dashboard'));

        $response->assertOk()
            ->assertSee(__('attendance.dashboard_new_recaps'))
            ->assertSee(e($game->name));
    });

    it('does not show recaps for games user owns', function () {
        $game = Game::factory()->create([
            'owner_id' => $this->user->id,
            'date_time' => now()->subDay(),
            'status' => 'completed',
            'recap' => 'Great session recap!',
            'updated_at' => now()->subDay(),
        ]);

        $response = actingAs($this->user)
            ->get(route('dashboard'));

        $response->assertOk()
            ->assertDontSee(__('attendance.dashboard_new_recaps'));
    });

    it('does not show recaps older than 7 days', function () {
        $host = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->subDays(10),
            'status' => 'completed',
            'recap' => 'Old recap',
            'updated_at' => now()->subDays(10),
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved,
        ]);

        $response = actingAs($this->user)
            ->get(route('dashboard'));

        $response->assertOk()
            ->assertDontSee(__('attendance.dashboard_new_recaps'));
    });

    it('shows Find Your Next Game link pointing to discover', function () {
        $response = actingAs($this->user)
            ->get(route('dashboard'));

        $response->assertOk()
            ->assertSee(route('discover'));
    });
});

describe('Attendance Translation Keys', function () {
    it('loads EN attendance translations without errors', function () {
        $keys = trans('attendance');
        expect($keys)->toBeArray()
            ->and($keys)->toHaveKey('tier_reliable')
            ->and($keys)->toHaveKey('tier_active')
            ->and($keys)->toHaveKey('tier_newcomer')
            ->and($keys)->toHaveKey('dashboard_games_this_week')
            ->and($keys)->toHaveKey('dashboard_find_next_game')
            ->and($keys)->toHaveKey('status_attended')
            ->and($keys)->toHaveKey('status_no_show')
            ->and($keys)->toHaveKey('debriefing_title')
            ->and($keys)->toHaveKey('recap_title')
            ->and($keys)->toHaveKey('waitlist_position')
            ->and($keys)->toHaveKey('bench_on_the_bench');
    });

    it('loads DE attendance translations without errors', function () {
        app()->setLocale('de');
        $keys = trans('attendance');
        expect($keys)->toBeArray()
            ->and($keys)->toHaveKey('tier_reliable')
            ->and($keys)->toHaveKey('dashboard_games_this_week')
            ->and($keys)->toHaveKey('dashboard_find_next_game')
            ->and($keys['tier_reliable'])->toBe('Zuverlässig')
            ->and($keys['dashboard_games_this_week'])->toBe('Spiele diese Woche')
            ->and($keys['dashboard_find_next_game'])->toBe('Nächstes Spiel finden');
    });

    it('has same keys in EN and DE attendance files', function () {
        app()->setLocale('en');
        $enKeys = array_keys(trans('attendance'));
        app()->setLocale('de');
        $deKeys = array_keys(trans('attendance'));

        sort($enKeys);
        sort($deKeys);

        expect($enKeys)->toBe($deKeys);
    });
});
