<?php

namespace Tests\Feature\Livewire;

use App\Enums\ActivityType;
use App\Enums\RelationshipType;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\GMProfile;
use App\Models\User;
use App\Models\UserRelationship;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\SetsUpLocale;

class DashboardTest extends TestCase
{
    use DatabaseTransactions;
    use SetsUpLocale {
        SetsUpLocale::setUp as setUpLocale;
    }

    private User $user;

    protected function setUp(): void
    {
        $this->setUpLocale();

        $this->user = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);

        // Ensure a game system exists for factory relations
        GameSystem::factory()->create();
    }

    // ── Stats: Active Games ────────────────────────────

    public function test_active_games_count_includes_owned_scheduled_games(): void
    {
        Game::factory()->count(3)->create([
            'owner_id' => $this->user->id,
            'status' => 'scheduled',
        ]);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\Dashboard::class)
            ->assertSet('activeGamesCount', 3);
    }

    // ── Stats: Active Campaigns ────────────────────────

    public function test_active_campaigns_count_includes_owned_active(): void
    {
        Campaign::factory()->count(2)->create([
            'owner_id' => $this->user->id,
            'status' => 'active',
        ]);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\Dashboard::class)
            ->assertSet('activeCampaignsCount', 2);
    }

    // ── Stats: Upcoming Sessions ────────────────────────

    public function test_upcoming_sessions_count_includes_owned_future_games(): void
    {
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'status' => 'scheduled',
            'date_time' => now()->addDay(),
        ]);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\Dashboard::class)
            ->assertSet('upcomingSessionsCount', 1);
    }

    public function test_upcoming_sessions_count_includes_approved_participations(): void
    {
        $game = Game::factory()->create([
            'status' => 'scheduled',
            'date_time' => now()->addDay(),
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'role' => 'player',
            'status' => 'approved',
        ]);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\Dashboard::class)
            ->assertSet('upcomingSessionsCount', 1);
    }

    // ── Stats: Pending Invitations ─────────────────────

    public function test_pending_invitations_count_includes_game_and_campaign(): void
    {
        $game = Game::factory()->create();
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'role' => 'player',
            'status' => 'pending',
        ]);

        $campaign = Campaign::factory()->create();
        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $this->user->id,
            'role' => 'player',
            'status' => 'pending',
        ]);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\Dashboard::class)
            ->assertSet('pendingInvitationsCount', 2);
    }

    // ── Recent Activity ────────────────────────────────

    public function test_unread_notifications_count(): void
    {
        // Use database notification directly
        \DB::table('notifications')->insert([
            'id' => \Str::uuid()->toString(),
            'type' => 'Illuminate\Auth\Notifications\VerifyEmail',
            'notifiable_type' => User::class,
            'notifiable_id' => $this->user->id,
            'data' => json_encode(['message' => 'test']),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\Dashboard::class)
            ->assertSet('unreadNotificationsCount', 1);
    }

    // ── Stats: GM-specific ─────────────────────────────

    public function test_gm_stats_are_null_for_non_gm(): void
    {
        Livewire::actingAs($this->user)
            ->test(\App\Livewire\Dashboard::class)
            ->assertSet('gmAverageRating', null)
            ->assertSet('gmReviewCount', 0)
            ->assertSet('gmUpcomingSessionsCount', 0);
    }

    public function test_gm_stats_show_for_gm_user(): void
    {
        Role::create(['name' => 'Game Master']);

        $gmUser = User::factory()->create([
            'profile_complete' => true,
            'email_verified_at' => now(),
        ]);
        $gmUser->assignRole('Game Master');

        GMProfile::factory()->create([
            'user_id' => $gmUser->id,
            'average_rating' => 4.75,
            'review_count' => 12,
        ]);

        Game::factory()->count(2)->create([
            'owner_id' => $gmUser->id,
            'status' => 'scheduled',
            'date_time' => now()->addDays(3),
        ]);

        Livewire::actingAs($gmUser)
            ->test(\App\Livewire\Dashboard::class)
            ->assertSet('gmAverageRating', 4.75)
            ->assertSet('gmReviewCount', 12)
            ->assertSet('gmUpcomingSessionsCount', 2);
    }

    // ── Recent Activity ────────────────────────────────

    public function test_recent_activity_returns_user_logs(): void
    {
        ActivityLog::create([
            'user_id' => $this->user->id,
            'event_type' => ActivityType::GameCreated,
            'created_at' => now(),
        ]);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\Dashboard::class)
            ->assertSet('recentActivity', function ($activity) {
                return $activity->count() === 1;
            });
    }

}
