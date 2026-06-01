<?php

namespace Tests\Unit\Services;

use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\DashboardScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class DashboardScheduleServiceTest extends TestCase
{
    use DatabaseTransactions;

    private DashboardScheduleService $service;
    private User $user;
    private GameSystem $gameSystem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DashboardScheduleService;
        Cache::flush();
        Queue::fake();
        URL::defaults(['locale' => 'en']);

        $this->user = User::factory()->create();
        $this->gameSystem = GameSystem::factory()->create();
    }

    // ── getUpcomingGames ────────────────────────────────

    public function test_get_upcoming_games_groups_into_today_this_week_and_coming_up(): void
    {
        // Game today — 30 min from now, guaranteed within today and after now()
        $today = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->addMinutes(30),
            'status' => GameStatus::Scheduled->value,
        ]);

        // Game this week (but not today) — placed safely within the current week.
        // On Sunday, the week has already ended, so there's no "this week but not today" slot.
        // In that case, we place it tomorrow (it becomes "coming_up") and adjust expectations.
        $endOfWeek = now()->copy()->endOfWeek();
        $isSundayWithNoThisWeek = now()->isSameDay($endOfWeek) && now()->addMinutes(30)->isAfter(now()->copy()->startOfDay()->addHours(22));

        if ($isSundayWithNoThisWeek || now()->diffInDays($endOfWeek, false) < 1) {
            // Sunday evening or Monday (effectively no room this week): place mid-next-week
            $thisWeekDate = now()->addDays(3)->startOfDay()->addHours(12);
            $expectThisWeekCount = 0;
            $expectComingUpCount = 2;
        } else {
            // There's room this week — place the game 2 days out (safe even on Saturday)
            $thisWeekDate = now()->addDays(2)->startOfDay()->addHours(12);
            // Verify it's actually within this week
            if (Carbon::parse($thisWeekDate)->gt($endOfWeek)) {
                $expectThisWeekCount = 0;
                $expectComingUpCount = 2;
            } else {
                $expectThisWeekCount = 1;
                $expectComingUpCount = 1;
            }
        }

        $thisWeek = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => $thisWeekDate,
            'status' => GameStatus::Scheduled->value,
        ]);

        // Game coming up (after this week, within 14 days)
        $comingUpDate = $endOfWeek->copy()->addDays(5)->addHours(12);
        $comingUp = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => $comingUpDate,
            'status' => GameStatus::Scheduled->value,
        ]);

        $result = $this->service->getUpcomingGames($this->user);

        $this->assertCount(1, $result['today']);
        $this->assertCount($expectThisWeekCount, $result['this_week']);
        $this->assertCount($expectComingUpCount, $result['coming_up']);

        $this->assertEquals($today->id, $result['today'][0]['id']);
    }

    public function test_get_upcoming_games_includes_games_where_user_is_approved_participant(): void
    {
        $host = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->addMinutes(45),
            'status' => GameStatus::Scheduled->value,
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $result = $this->service->getUpcomingGames($this->user);

        $this->assertCount(1, $result['today']);
        $this->assertEquals($game->id, $result['today'][0]['id']);
        $this->assertFalse($result['today'][0]['is_hosting']);
    }

    public function test_get_upcoming_games_excludes_games_beyond_14_days(): void
    {
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->addDays(20),
            'status' => GameStatus::Scheduled->value,
        ]);

        $result = $this->service->getUpcomingGames($this->user);

        $this->assertEmpty($result['today']);
        $this->assertEmpty($result['this_week']);
        $this->assertEmpty($result['coming_up']);
    }

    public function test_get_upcoming_games_excludes_canceled_and_completed_games(): void
    {
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->addMinutes(30),
            'status' => GameStatus::Canceled->value,
        ]);

        Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->addMinutes(45),
            'status' => GameStatus::Completed->value,
        ]);

        $result = $this->service->getUpcomingGames($this->user);

        $this->assertEmpty($result['today']);
        $this->assertEmpty($result['this_week']);
        $this->assertEmpty($result['coming_up']);
    }

    public function test_get_upcoming_games_excludes_past_games(): void
    {
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->subHours(2),
            'status' => GameStatus::Scheduled->value,
        ]);

        $result = $this->service->getUpcomingGames($this->user);

        $this->assertEmpty($result['today']);
    }

    public function test_get_upcoming_games_returns_correct_game_entry_shape(): void
    {
        $campaign = Campaign::factory()->create(['game_system_id' => $this->gameSystem->id]);
        $game = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'campaign_id' => $campaign->id,
            'date_time' => now()->addMinutes(30),
            'status' => GameStatus::Scheduled->value,
            'max_players' => 6,
        ]);

        $result = $this->service->getUpcomingGames($this->user);
        $entry = $result['today'][0];

        $this->assertEquals($game->id, $entry['id']);
        $this->assertArrayHasKey('name', $entry);
        $this->assertArrayHasKey('system_badge', $entry);
        $this->assertArrayHasKey('name', $entry['system_badge']);
        $this->assertArrayHasKey('icon', $entry['system_badge']);
        $this->assertArrayHasKey('date_time', $entry);
        $this->assertArrayHasKey('relative_time', $entry);
        $this->assertArrayHasKey('player_count', $entry);
        $this->assertEquals(6, $entry['max_players']);
        $this->assertTrue($entry['is_hosting']);
        $this->assertEquals($campaign->name, $entry['campaign_name']);
    }

    public function test_get_upcoming_games_sorts_by_date_time_within_each_group(): void
    {
        $early = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->addMinutes(30),
            'status' => GameStatus::Scheduled->value,
        ]);

        $late = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->addMinutes(45),
            'status' => GameStatus::Scheduled->value,
        ]);

        $result = $this->service->getUpcomingGames($this->user);

        $this->assertCount(2, $result['today']);
        $this->assertEquals($early->id, $result['today'][0]['id']);
        $this->assertEquals($late->id, $result['today'][1]['id']);
    }

    public function test_get_upcoming_games_returns_empty_groups_when_no_games(): void
    {
        $result = $this->service->getUpcomingGames($this->user);

        $this->assertEquals(['today' => [], 'this_week' => [], 'coming_up' => []], $result);
    }

    // ── getHostAgainBridge ──────────────────────────────

    public function test_host_again_bridge_returns_null_when_user_has_upcoming_games(): void
    {
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->addDays(2),
            'status' => GameStatus::Scheduled->value,
        ]);

        $result = $this->service->getHostAgainBridge($this->user);

        $this->assertNull($result);
    }

    public function test_host_again_bridge_returns_null_when_no_completed_games(): void
    {
        $result = $this->service->getHostAgainBridge($this->user);

        $this->assertNull($result);
    }

    public function test_host_again_bridge_returns_last_completed_game_with_clone_url(): void
    {
        // Older completed game
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->subDays(10),
            'status' => GameStatus::Completed->value,
        ]);

        // More recent completed game
        $lastGame = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->subDays(2),
            'status' => GameStatus::Completed->value,
            'expected_duration' => 3.5,
        ]);

        $result = $this->service->getHostAgainBridge($this->user);

        $this->assertNotNull($result);
        $this->assertEquals($lastGame->id, $result['game']['id']);
        $this->assertEquals($lastGame->name, $result['game']['name']);
        $this->assertEquals($this->gameSystem->name, $result['game']['system']);
        $this->assertEquals(3.5, $result['game']['expected_duration']);
        $this->assertStringContainsString('clone=' . $lastGame->id, $result['clone_url']);
    }

    public function test_host_again_bridge_uses_completed_games_as_host_only(): void
    {
        // User participated (not hosted) in a completed game
        $host = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->subDays(2),
            'status' => GameStatus::Completed->value,
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        // No hosted completed games — bridge should be null
        $result = $this->service->getHostAgainBridge($this->user);

        $this->assertNull($result);
    }

    // ── getNextUpcomingGame ─────────────────────────────

    public function test_next_upcoming_game_returns_null_when_no_games(): void
    {
        $result = $this->service->getNextUpcomingGame($this->user);

        $this->assertNull($result);
    }

    public function test_next_upcoming_game_returns_soonest_game(): void
    {
        $later = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->addDays(5),
            'status' => GameStatus::Scheduled->value,
        ]);

        $sooner = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->addDays(1),
            'status' => GameStatus::Scheduled->value,
        ]);

        $result = $this->service->getNextUpcomingGame($this->user);

        $this->assertNotNull($result);
        $this->assertEquals($sooner->id, $result['id']);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('date_time', $result);
        $this->assertArrayHasKey('relative_time', $result);
    }

    public function test_next_upcoming_game_includes_participated_games(): void
    {
        $host = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->addDays(1),
            'status' => GameStatus::Scheduled->value,
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $result = $this->service->getNextUpcomingGame($this->user);

        $this->assertNotNull($result);
        $this->assertEquals($game->id, $result['id']);
    }

    public function test_relative_time_includes_at_keyword(): void
    {
        Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->addDays(1)->setTime(19, 0),
            'status' => GameStatus::Scheduled->value,
        ]);

        $next = $this->service->getNextUpcomingGame($this->user);

        $this->assertNotNull($next);
        $this->assertStringContainsStringIgnoringCase('at', $next['relative_time']);
    }
}
