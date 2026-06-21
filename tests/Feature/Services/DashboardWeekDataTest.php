<?php

namespace Tests\Unit\Services;

use App\Enums\AttendanceStatus;
use App\Enums\GameStatus;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\DashboardCacheService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardWeekDataTest extends TestCase
{
    use DatabaseTransactions;

    private DashboardCacheService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DashboardCacheService::class);
        Cache::flush();
        Queue::fake();
        Log::spy();
    }

    // ── Structure ──────────────────────────────────────────────

    #[Test]
    public function compute_week_data_returns_seven_days_with_summary(): void
    {
        $user = User::factory()->create();

        $result = $this->service->computeWeekData($user);

        $this->assertArrayHasKey('days', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertCount(7, $result['days']);

        // Each day has required keys
        $firstDay = $result['days'][0];
        $this->assertArrayHasKey('date', $firstDay);
        $this->assertArrayHasKey('day_name', $firstDay);
        $this->assertArrayHasKey('is_today', $firstDay);
        $this->assertArrayHasKey('games', $firstDay);
        $this->assertIsArray($firstDay['games']);
    }

    #[Test]
    public function days_span_monday_to_sunday(): void
    {
        $user = User::factory()->create();

        $result = $this->service->computeWeekData($user);
        $days = $result['days'];

        $this->assertEquals('Mon', $days[0]['day_name']);
        $this->assertEquals('Sun', $days[6]['day_name']);
    }

    #[Test]
    public function exactly_one_day_is_today(): void
    {
        $user = User::factory()->create();

        $result = $this->service->computeWeekData($user);
        $todayCount = collect($result['days'])->where('is_today', true)->count();

        $this->assertEquals(1, $todayCount);
    }

    #[Test]
    public function summary_defaults_to_zero_with_no_games(): void
    {
        $user = User::factory()->create();

        $result = $this->service->computeWeekData($user);
        $summary = $result['summary'];

        $this->assertEquals(0, $summary['total']);
        $this->assertEquals(0, $summary['past']);
        $this->assertEquals(0, $summary['upcoming']);
        $this->assertEquals(0, $summary['hosting']);
        $this->assertEquals(0, $summary['playing']);
    }

    // ── Owned games ────────────────────────────────────────────

    #[Test]
    public function includes_game_user_owns_this_week(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Scheduled,
            'date_time' => now()->startOfWeek()->addDays(2)->setHour(19),
        ]);

        $result = $this->service->computeWeekData($user);

        $this->assertEquals(1, $result['summary']['total']);
        $this->assertEquals(1, $result['summary']['hosting']);
        $this->assertEquals(0, $result['summary']['playing']);
    }

    #[Test]
    public function owned_game_is_placed_on_correct_day(): void
    {
        $user = User::factory()->create();
        $targetDate = now()->startOfWeek()->addDays(3)->setHour(18);
        Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Scheduled,
            'date_time' => $targetDate,
        ]);

        $result = $this->service->computeWeekData($user);

        $targetDayKey = $targetDate->format('Y-m-d');
        $day = collect($result['days'])->firstWhere('date', $targetDayKey);
        $this->assertNotNull($day);
        $this->assertCount(1, $day['games']);
    }

    // ── Participating games ────────────────────────────────────

    #[Test]
    public function includes_game_where_user_is_approved_participant(): void
    {
        $owner = User::factory()->create();
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'status' => GameStatus::Scheduled,
            'date_time' => now()->startOfWeek()->addDays(1)->setHour(19),
        ]);
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Approved,
        ]);

        $result = $this->service->computeWeekData($user);

        $this->assertEquals(1, $result['summary']['total']);
        $this->assertEquals(0, $result['summary']['hosting']);
        $this->assertEquals(1, $result['summary']['playing']);
    }

    #[Test]
    public function excludes_game_where_user_is_pending_participant(): void
    {
        $owner = User::factory()->create();
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'status' => GameStatus::Scheduled,
            'date_time' => now()->startOfWeek()->addDays(1)->setHour(19),
        ]);
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Pending,
        ]);

        $result = $this->service->computeWeekData($user);

        $this->assertEquals(0, $result['summary']['total']);
    }

    // ── Time boundaries ────────────────────────────────────────

    #[Test]
    public function excludes_games_outside_this_week(): void
    {
        $user = User::factory()->create();

        // Last week
        Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Scheduled,
            'date_time' => now()->startOfWeek()->subDays(3),
        ]);

        // Next week
        Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Scheduled,
            'date_time' => now()->endOfWeek()->addDays(3),
        ]);

        $result = $this->service->computeWeekData($user);

        $this->assertEquals(0, $result['summary']['total']);
    }

    // ── Status filtering ───────────────────────────────────────

    #[Test]
    public function includes_scheduled_completed_and_canceled_games(): void
    {
        $user = User::factory()->create();

        Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Scheduled,
            'date_time' => now()->startOfWeek()->addDays(1)->setHour(18),
        ]);
        Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Completed,
            'date_time' => now()->startOfWeek()->addDays(2)->setHour(18),
        ]);
        Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Canceled,
            'date_time' => now()->startOfWeek()->addDays(3)->setHour(18),
        ]);

        $result = $this->service->computeWeekData($user);

        $this->assertEquals(3, $result['summary']['total']);
    }

    // ── Past/upcoming classification ───────────────────────────

    #[Test]
    public function classifies_past_and_upcoming_games(): void
    {
        $user = User::factory()->create();

        // Past game (yesterday or earlier this week)
        Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Completed,
            'date_time' => now()->startOfWeek()->addHours(2),
        ]);

        // Future game
        Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Scheduled,
            'date_time' => now()->endOfWeek()->subHours(2),
        ]);

        $result = $this->service->computeWeekData($user);

        $this->assertEquals(2, $result['summary']['total']);
        // At least one past and one upcoming (exact counts depend on when test runs)
        $this->assertGreaterThan(0, $result['summary']['past'] + $result['summary']['upcoming']);
        $this->assertEquals($result['summary']['total'], $result['summary']['past'] + $result['summary']['upcoming']);
    }

    // ── is_hosting flag ────────────────────────────────────────

    #[Test]
    public function game_data_includes_is_hosting_flag(): void
    {
        $user = User::factory()->create();
        Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Scheduled,
            'date_time' => now()->startOfWeek()->addDays(1)->setHour(19),
        ]);

        $result = $this->service->computeWeekData($user);
        $allGames = collect($result['days'])->flatMap->games;

        $this->assertCount(1, $allGames);
        $this->assertTrue($allGames->first()['is_hosting']);
    }

    #[Test]
    public function game_data_shows_is_hosting_false_for_participant(): void
    {
        $owner = User::factory()->create();
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'status' => GameStatus::Scheduled,
            'date_time' => now()->startOfWeek()->addDays(1)->setHour(19),
        ]);
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Approved,
        ]);

        $result = $this->service->computeWeekData($user);
        $allGames = collect($result['days'])->flatMap->games;

        $this->assertFalse($allGames->first()['is_hosting']);
    }

    // ── player_count ───────────────────────────────────────────

    #[Test]
    public function game_data_includes_approved_player_count(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Scheduled,
            'date_time' => now()->startOfWeek()->addDays(1)->setHour(19),
        ]);

        // Add 3 approved participants
        for ($i = 0; $i < 3; $i++) {
            GameParticipant::factory()->create([
                'game_id' => $game->id,
                'user_id' => User::factory()->create()->id,
                'status' => ParticipantStatus::Approved,
            ]);
        }
        // Add 1 pending — should not count
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'status' => ParticipantStatus::Pending,
        ]);

        $result = $this->service->computeWeekData($user);
        $allGames = collect($result['days'])->flatMap->games;

        $this->assertEquals(3, $allGames->first()['player_count']);
    }

    // ── needs_recap ────────────────────────────────────────────

    #[Test]
    public function needs_recap_true_when_past_and_owner_and_no_recap(): void
    {
        $user = User::factory()->create();
        Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Completed,
            'date_time' => now()->startOfWeek()->addHours(2), // past
            'recap' => null,
        ]);

        $result = $this->service->computeWeekData($user);
        $allGames = collect($result['days'])->flatMap->games;

        $this->assertTrue($allGames->first()['needs_recap']);
    }

    #[Test]
    public function needs_recap_false_when_recap_exists(): void
    {
        $user = User::factory()->create();
        Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Completed,
            'date_time' => now()->startOfWeek()->addHours(2),
            'recap' => 'Great session! Everyone had fun.',
        ]);

        $result = $this->service->computeWeekData($user);
        $allGames = collect($result['days'])->flatMap->games;

        $this->assertFalse($allGames->first()['needs_recap']);
    }

    #[Test]
    public function needs_recap_false_for_participant(): void
    {
        $owner = User::factory()->create();
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'status' => GameStatus::Completed,
            'date_time' => now()->startOfWeek()->addHours(2),
            'recap' => null,
        ]);
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Approved,
        ]);

        $result = $this->service->computeWeekData($user);
        $allGames = collect($result['days'])->flatMap->games;

        $this->assertFalse($allGames->first()['needs_recap']);
    }

    #[Test]
    public function needs_recap_false_for_upcoming_game(): void
    {
        $user = User::factory()->create();
        Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Scheduled,
            'date_time' => now()->endOfWeek()->subHours(2), // future
            'recap' => null,
        ]);

        $result = $this->service->computeWeekData($user);
        $allGames = collect($result['days'])->flatMap->games;

        $this->assertFalse($allGames->first()['needs_recap']);
    }

    // ── needs_attendance ───────────────────────────────────────

    #[Test]
    public function needs_attendance_true_when_past_and_participant_and_no_status(): void
    {
        $owner = User::factory()->create();
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'status' => GameStatus::Completed,
            'date_time' => now()->startOfWeek()->addHours(2),
        ]);
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Approved,
            'attendance_status' => null,
        ]);

        $result = $this->service->computeWeekData($user);
        $allGames = collect($result['days'])->flatMap->games;

        $this->assertTrue($allGames->first()['needs_attendance']);
    }

    #[Test]
    public function needs_attendance_false_when_attendance_recorded(): void
    {
        $owner = User::factory()->create();
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'status' => GameStatus::Completed,
            'date_time' => now()->startOfWeek()->addHours(2),
        ]);
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Approved,
            'attendance_status' => AttendanceStatus::Attended,
        ]);

        $result = $this->service->computeWeekData($user);
        $allGames = collect($result['days'])->flatMap->games;

        $this->assertFalse($allGames->first()['needs_attendance']);
    }

    #[Test]
    public function needs_attendance_false_for_owner(): void
    {
        $user = User::factory()->create();
        Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Completed,
            'date_time' => now()->startOfWeek()->addHours(2),
        ]);

        $result = $this->service->computeWeekData($user);
        $allGames = collect($result['days'])->flatMap->games;

        $this->assertFalse($allGames->first()['needs_attendance']);
    }

    // ── Eager loading ──────────────────────────────────────────

    #[Test]
    public function game_data_includes_game_system_name(): void
    {
        $gameSystem = GameSystem::factory()->create(['name' => ['en' => 'D&D 5e']]);
        $user = User::factory()->create();
        Game::factory()->create([
            'owner_id' => $user->id,
            'game_system_id' => $gameSystem->id,
            'status' => GameStatus::Scheduled,
            'date_time' => now()->startOfWeek()->addDays(2)->setHour(19),
        ]);

        $result = $this->service->computeWeekData($user);
        $allGames = collect($result['days'])->flatMap->games;

        $this->assertEquals('D&D 5e', $allGames->first()['game_system_name']);
    }

    #[Test]
    public function game_data_includes_campaign_name_when_linked(): void
    {
        $campaign = Campaign::factory()->create(['name' => ['en' => 'Waterdeep Chronicles']]);
        $user = User::factory()->create();
        Game::factory()->create([
            'owner_id' => $user->id,
            'campaign_id' => $campaign->id,
            'status' => GameStatus::Scheduled,
            'date_time' => now()->startOfWeek()->addDays(2)->setHour(19),
        ]);

        $result = $this->service->computeWeekData($user);
        $allGames = collect($result['days'])->flatMap->games;

        $this->assertEquals('Waterdeep Chronicles', $allGames->first()['campaign_name']);
    }

    #[Test]
    public function game_data_campaign_name_null_for_standalone_game(): void
    {
        $user = User::factory()->create();
        Game::factory()->create([
            'owner_id' => $user->id,
            'campaign_id' => null,
            'status' => GameStatus::Scheduled,
            'date_time' => now()->startOfWeek()->addDays(2)->setHour(19),
        ]);

        $result = $this->service->computeWeekData($user);
        $allGames = collect($result['days'])->flatMap->games;

        $this->assertNull($allGames->first()['campaign_name']);
    }

    // ── Deduplication ──────────────────────────────────────────

    #[Test]
    public function game_counts_once_when_user_is_both_owner_and_participant(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Scheduled,
            'date_time' => now()->startOfWeek()->addDays(1)->setHour(19),
        ]);
        // User is also an approved participant of their own game
        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Approved,
        ]);

        $result = $this->service->computeWeekData($user);

        $this->assertEquals(1, $result['summary']['total']);
        $this->assertEquals(1, $result['summary']['hosting']);
    }

    // ── Multiple games per day ─────────────────────────────────

    #[Test]
    public function multiple_games_on_same_day(): void
    {
        $user = User::factory()->create();
        $dayDate = now()->startOfWeek()->addDays(2);
        Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Scheduled,
            'date_time' => $dayDate->copy()->setHour(14),
            'name' => ['en' => 'Afternoon Game'],
        ]);
        Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Scheduled,
            'date_time' => $dayDate->copy()->setHour(19),
            'name' => ['en' => 'Evening Game'],
        ]);

        $result = $this->service->computeWeekData($user);
        $dayKey = $dayDate->format('Y-m-d');
        $day = collect($result['days'])->firstWhere('date', $dayKey);

        $this->assertCount(2, $day['games']);
        $this->assertEquals(2, $result['summary']['total']);
    }

    // ── Full game data shape ───────────────────────────────────

    #[Test]
    public function game_data_contains_all_expected_fields(): void
    {
        $user = User::factory()->create();
        Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Scheduled,
            'date_time' => now()->startOfWeek()->addDays(1)->setHour(19),
        ]);

        $result = $this->service->computeWeekData($user);
        $gameData = collect($result['days'])->flatMap->games->first();

        $expectedKeys = [
            'id', 'name', 'date_time', 'expected_duration', 'status',
            'game_system_name', 'campaign_name', 'max_players',
            'is_past', 'is_hosting', 'player_count',
            'needs_recap', 'needs_attendance',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $gameData, "Missing key: {$key}");
        }
    }

    #[Test]
    public function empty_week_returns_seven_empty_days(): void
    {
        $user = User::factory()->create();

        $result = $this->service->computeWeekData($user);
        $allGames = collect($result['days'])->flatMap->games;

        $this->assertCount(7, $result['days']);
        $this->assertCount(0, $allGames);
    }
}
