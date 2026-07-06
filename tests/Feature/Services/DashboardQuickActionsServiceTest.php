<?php

namespace Tests\Feature\Services;

use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\DashboardQuickActionsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardQuickActionsServiceTest extends TestCase
{
    use DatabaseTransactions;

    private DashboardQuickActionsService $service;

    private GameSystem $gameSystem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DashboardQuickActionsService;
        Cache::flush();
        Queue::fake();
        URL::defaults(['locale' => 'en']);

        $this->gameSystem = GameSystem::factory()->create();

        // Ensure the Game Master role exists for tests
        Role::firstOrCreate([
            'name' => 'Game Master',
            'guard_name' => 'web',
            'team_id' => null,
        ]);
    }

    // ── Player role ────────────────────────────────────

    public function test_player_no_upcoming_shows_discover_games(): void
    {
        $user = User::factory()->create();

        $actions = $this->service->getQuickActions($user);

        // Plain player with no upcoming: primary=Discover Games, secondary=Find Campaigns
        $this->assertCount(2, $actions);
        $this->assertEquals('profile.dashboard_quick_discover', $actions[0]['label']);
        $this->assertEquals('primary', $actions[0]['style']);
        $this->assertEquals('explore', $actions[0]['icon']);
        $this->assertEquals(route('discover'), $actions[0]['url']);
    }

    public function test_player_with_upcoming_shows_view_my_games(): void
    {
        $user = User::factory()->create();

        Game::factory()->create([
            'owner_id' => $user->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->addHours(3),
            'status' => GameStatus::Scheduled->value,
        ]);

        $actions = $this->service->getQuickActions($user);

        $this->assertCount(3, $actions);
        $this->assertEquals('profile.dashboard_quick_my_games', $actions[0]['label']);
        $this->assertEquals('primary', $actions[0]['style']);
        $this->assertEquals('schedule', $actions[0]['icon']);
        $this->assertEquals(route('games.index'), $actions[0]['url']);
    }

    // ── GM role ────────────────────────────────────────

    public function test_gm_no_upcoming_shows_plan_something(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Game Master');

        $actions = $this->service->getQuickActions($user);

        $this->assertCount(3, $actions);
        $this->assertEquals('plan.action_plan_something', $actions[0]['label']);
        $this->assertEquals('primary', $actions[0]['style']);
        $this->assertEquals('add_circle', $actions[0]['icon']);
        $this->assertEquals(route('plan.create'), $actions[0]['url']);
    }

    public function test_gm_with_upcoming_shows_gm_workspace(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Game Master');

        Game::factory()->create([
            'owner_id' => $user->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->addHours(3),
            'status' => GameStatus::Scheduled->value,
        ]);

        $actions = $this->service->getQuickActions($user);

        $this->assertCount(3, $actions);
        $this->assertEquals('profile.dashboard_quick_gm_workspace', $actions[0]['label']);
        $this->assertEquals('primary', $actions[0]['style']);
        $this->assertEquals('castle', $actions[0]['icon']);
        $this->assertEquals(route('gm.workspace'), $actions[0]['url']);

        // Secondary should include Create Game and Discover
        $secondaryLabels = collect($actions)->skip(1)->pluck('label')->toArray();
        $this->assertContains('profile.dashboard_quick_discover', $secondaryLabels);
        $this->assertContains('plan.action_plan_something', $secondaryLabels);
    }

    // ── Team captain role ──────────────────────────────

    public function test_team_captain_shows_manage_team_primary(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['created_by' => $user->id]);

        TeamMember::factory()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'captain',
            'status' => 'active',
        ]);

        $actions = $this->service->getQuickActions($user);

        $this->assertCount(3, $actions);
        $this->assertEquals('profile.dashboard_quick_manage_team', $actions[0]['label']);
        $this->assertEquals('primary', $actions[0]['style']);
        $this->assertEquals('groups', $actions[0]['icon']);
        $this->assertEquals(route('teams.manage', ['slug' => $team->slug]), $actions[0]['url']);
    }

    public function test_team_captain_also_gm_shows_manage_team(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Game Master');
        $team = Team::factory()->create(['created_by' => $user->id]);

        TeamMember::factory()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'captain',
            'status' => 'active',
        ]);

        $actions = $this->service->getQuickActions($user);

        // Team captain takes priority over GM
        $this->assertEquals('profile.dashboard_quick_manage_team', $actions[0]['label']);
    }

    // ── Campaign member actions ────────────────────────

    public function test_campaign_member_gets_my_campaigns_secondary(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create(['game_system_id' => $this->gameSystem->id]);

        CampaignParticipant::factory()->create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $actions = $this->service->getQuickActions($user);

        $labels = collect($actions)->pluck('label')->toArray();
        $this->assertContains('profile.dashboard_quick_my_campaigns', $labels);
    }

    public function test_non_campaign_member_gets_find_campaigns_secondary(): void
    {
        $user = User::factory()->create();

        $actions = $this->service->getQuickActions($user);

        $labels = collect($actions)->pluck('label')->toArray();
        $this->assertContains('profile.dashboard_quick_find_campaigns', $labels);
        $this->assertNotContains('profile.dashboard_quick_my_campaigns', $labels);
    }

    // ── Action structure ───────────────────────────────

    public function test_each_action_has_required_keys(): void
    {
        $user = User::factory()->create();

        $actions = $this->service->getQuickActions($user);

        foreach ($actions as $action) {
            $this->assertArrayHasKey('label', $action);
            $this->assertArrayHasKey('url', $action);
            $this->assertArrayHasKey('style', $action);
            $this->assertArrayHasKey('icon', $action);
            $this->assertContains($action['style'], ['primary', 'secondary']);
            $this->assertNotEmpty($action['url']);
            $this->assertNotEmpty($action['label']);
            $this->assertNotEmpty($action['icon']);
        }
    }

    public function test_primary_action_always_first_and_only_one(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Game Master');

        $actions = $this->service->getQuickActions($user);

        $this->assertEquals('primary', $actions[0]['style']);
        $secondaryStyles = collect($actions)->skip(1)->pluck('style')->toArray();
        $this->assertNotContains('primary', $secondaryStyles);
    }

    public function test_max_three_actions_returned(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Game Master');

        // Give them team captain + campaign membership too — still max 3
        $team = Team::factory()->create(['created_by' => $user->id]);
        TeamMember::factory()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'captain',
            'status' => 'active',
        ]);

        $campaign = Campaign::factory()->create(['game_system_id' => $this->gameSystem->id]);
        CampaignParticipant::factory()->create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $actions = $this->service->getQuickActions($user);

        $this->assertLessThanOrEqual(3, count($actions));
        $this->assertGreaterThanOrEqual(1, count($actions));
    }

    public function test_min_one_action_returned(): void
    {
        $user = User::factory()->create();

        $actions = $this->service->getQuickActions($user);

        $this->assertGreaterThanOrEqual(1, count($actions));
    }

    // ── GM secondary actions ───────────────────────────

    public function test_gm_no_upcoming_secondary_includes_discover(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Game Master');

        $actions = $this->service->getQuickActions($user);

        $secondaryLabels = collect($actions)->skip(1)->pluck('label')->toArray();
        $this->assertContains('profile.dashboard_quick_discover', $secondaryLabels);
    }

    public function test_gm_with_upcoming_secondary_includes_plan_and_discover(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Game Master');

        Game::factory()->create([
            'owner_id' => $user->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->addHours(5),
            'status' => GameStatus::Scheduled->value,
        ]);

        $actions = $this->service->getQuickActions($user);

        $secondaryLabels = collect($actions)->skip(1)->pluck('label')->toArray();
        $this->assertContains('profile.dashboard_quick_discover', $secondaryLabels);
        $this->assertContains('plan.action_plan_something', $secondaryLabels);
    }

    // ── Player as approved participant ─────────────────

    public function test_player_as_participant_with_upcoming_shows_view_games(): void
    {
        $host = User::factory()->create();
        $user = User::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->addHours(4),
            'status' => GameStatus::Scheduled->value,
        ]);

        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $actions = $this->service->getQuickActions($user);

        $this->assertEquals('profile.dashboard_quick_my_games', $actions[0]['label']);
    }

    // ── Edge cases ─────────────────────────────────────

    public function test_inactive_captain_not_counted_as_team_captain(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['created_by' => $user->id]);

        TeamMember::factory()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'captain',
            'status' => 'inactive',
        ]);

        $actions = $this->service->getQuickActions($user);

        // Should not be team captain — treated as regular player
        $this->assertNotEquals('profile.dashboard_quick_manage_team', $actions[0]['label']);
    }

    public function test_completed_games_do_not_count_as_upcoming(): void
    {
        $user = User::factory()->create();

        Game::factory()->create([
            'owner_id' => $user->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->addHours(3),
            'status' => GameStatus::Completed->value,
        ]);

        $actions = $this->service->getQuickActions($user);

        // Completed games don't count — should show discover
        $this->assertEquals('profile.dashboard_quick_discover', $actions[0]['label']);
    }

    public function test_pending_participant_not_counted_as_upcoming(): void
    {
        $host = User::factory()->create();
        $user = User::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->addHours(4),
            'status' => GameStatus::Scheduled->value,
        ]);

        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Pending->value,
        ]);

        $actions = $this->service->getQuickActions($user);

        // Pending participant — no upcoming games
        $this->assertEquals('profile.dashboard_quick_discover', $actions[0]['label']);
    }
}
