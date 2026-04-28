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
        GameSystem::factory()->create();
    });

    it('shows games this week count for owned games', function () {
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'date_time' => now()->startOfWeek()->addDays(2),
            'status' => 'scheduled',
        ]);

        // Game outside this week should not appear in count
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'date_time' => now()->subWeeks(2),
            'status' => 'scheduled',
        ]);

        $response = actingAs($this->user)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee(__('attendance.dashboard_games_this_week'))
            ->assertSee(__('attendance.dashboard_hosting'));
    });

    it('shows attendance summary with attended count', function () {
        // Owner's game counts as attended
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'date_time' => now()->startOfWeek()->addDays(1),
            'status' => 'scheduled',
        ]);

        $response = actingAs($this->user)->get(route('dashboard'));

        $response->assertOk();

        $content = $response->getContent();
        $this->assertStringContainsString(__('attendance.dashboard_attended'), $content);
        $this->assertStringContainsString(__('attendance.dashboard_total'), $content);
    });

    it('shows no games CTA when user has no games this week', function () {
        $response = actingAs($this->user)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee(__('attendance.dashboard_no_games_this_week'))
            ->assertSee(__('attendance.dashboard_find_next_game'));
    });

    it('shows recap card for recently completed games with recaps', function () {
        $host = User::factory()->create(['name' => 'Test Host']);
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->subDay(),
            'status' => 'completed',
            'recap' => 'Amazing session recap content!',
            'updated_at' => now()->subDay(),
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved,
        ]);

        $response = actingAs($this->user)->get(route('dashboard'));

        $response->assertOk()
            ->assertSee(__('attendance.dashboard_new_recaps'))
            ->assertSee(e($game->name));
    });
});
