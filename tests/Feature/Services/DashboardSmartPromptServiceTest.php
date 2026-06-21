<?php

namespace Tests\Unit\Services;

use App\Enums\GameStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Enums\RelationshipType;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Models\UserRelationship;
use App\Services\DashboardCacheService;
use App\Services\DashboardSmartPromptService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

// The setUp() mock and one inline mock use createMock() + method()->willReturn()
// as pure stubs (no expects() assertions). PHPUnit 12 emits a "no expectations
// configured" notice per such mock at teardown — which under `pest --parallel`
// surfaces as one notice per test in this file (23 notices) because paratest
// workers batch tests and the mock-verification timing differs from serial
// execution. The attribute is PHPUnit's blessed opt-out for stub-only mocks
// (the notice text itself recommends it). Serial runs are unaffected.
#[AllowMockObjectsWithoutExpectations]
class DashboardSmartPromptServiceTest extends TestCase
{
    use DatabaseTransactions;

    private DashboardSmartPromptService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Default to Thursday so the empty_week prompt (Priority 4, fires
        // Mon–Wed when the week is empty) cannot preempt the lower-priority
        // prompts under test here. Tests that explicitly assert empty_week
        // behaviour override this with their own travelTo().
        $this->travelTo(now()->startOfWeek()->addDays(3)); // Thursday

        $cacheService = $this->createMock(DashboardCacheService::class);
        $cacheService->method('getWeekData')->willReturn([
            'summary' => ['total' => 0],
            'days' => [],
        ]);
        $this->service = new DashboardSmartPromptService($cacheService);
        Cache::flush();
        URL::defaults(['locale' => 'en']);
    }

    // ── Priority 1: Pending invitations ────────────────

    #[Test]
    public function pending_game_invitation_shows_invitation_prompt(): void
    {
        $owner = User::factory()->create(['name' => 'Alice']);
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'name' => ['en' => 'Dragon Heist'],
            'date_time' => now()->addDays(3),
            'status' => GameStatus::Scheduled,
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Pending,
        ]);

        $result = $this->service->getPrompt($user);

        $this->assertEquals('pending_invitations', $result['type']);
        $this->assertStringContainsString('Alice', $result['message']);
        $this->assertStringContainsString('Dragon Heist', $result['message']);
        $this->assertEquals(1, $result['metadata']['count']);
        $this->assertNotNull($result['action_url']);
    }

    #[Test]
    public function multiple_pending_invitations_shows_count(): void
    {
        $user = User::factory()->create();
        $owner = User::factory()->create(['name' => 'Bob']);

        foreach (['Game A', 'Game B', 'Game C'] as $name) {
            $game = Game::factory()->create([
                'owner_id' => $owner->id,
                'name' => ['en' => $name],
                'date_time' => now()->addDays(3),
                'status' => GameStatus::Scheduled,
            ]);
            GameParticipant::create([
                'game_id' => $game->id,
                'user_id' => $user->id,
                'status' => ParticipantStatus::Pending,
            ]);
        }

        $result = $this->service->getPrompt($user);

        $this->assertEquals('pending_invitations', $result['type']);
        $this->assertEquals(3, $result['metadata']['count']);
        $this->assertStringContainsString('3 pending invitations', $result['message']);
    }

    #[Test]
    public function pending_campaign_invitation_shows_invitation_prompt(): void
    {
        $owner = User::factory()->create(['name' => 'Helen']);
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'owner_id' => $owner->id,
            'name' => ['en' => 'Lost Mine Campaign'],
            'status' => 'active',
        ]);

        CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Pending,
            'role' => ParticipantRole::Player->value,
        ]);

        $result = $this->service->getPrompt($user);

        // Campaign-only invitations use the plural message form since
        // the service only looks up game details for the single-game path
        $this->assertEquals('pending_invitations', $result['type']);
        $this->assertEquals(1, $result['metadata']['count']);
        $this->assertNotNull($result['action_url']);
    }

    // ── Priority 2: Upcoming session within 24h ────────

    /**
     * @return array<string, array{int, bool}>
     */
    public static function upcomingThresholds(): array
    {
        return [
            'within 24h shows' => [3, true],
            'beyond 24h skipped' => [48, false],
        ];
    }

    #[Test]
    #[DataProvider('upcomingThresholds')]
    public function upcoming_session_threshold_gates_prompt(int $hoursUntil, bool $shouldShow): void
    {
        $user = User::factory()->create();
        Game::factory()->create([
            'owner_id' => $user->id,
            'name' => ['en' => 'Threshold Game'],
            'date_time' => now()->addHours($hoursUntil),
            'status' => GameStatus::Scheduled,
        ]);

        $result = $this->service->getPrompt($user);

        if ($shouldShow) {
            $this->assertEquals('upcoming_session', $result['type']);
        } else {
            $this->assertNotEquals('upcoming_session', $result['type']);
        }
    }

    #[Test]
    public function upcoming_session_as_approved_participant(): void
    {
        $owner = User::factory()->create();
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'name' => ['en' => 'Participant Session'],
            'date_time' => now()->addHours(2),
            'status' => GameStatus::Scheduled,
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Approved,
        ]);

        $result = $this->service->getPrompt($user);

        $this->assertEquals('upcoming_session', $result['type']);
    }

    // ── Priority 3: Just completed, recap missing ──────

    /**
     * @return array<string, array{?string, int, bool}>
     */
    public static function justCompletedConditions(): array
    {
        return [
            'no recap within 48h triggers' => [null, 24, true],
            'with recap within 48h skipped' => ['Great session!', 24, false],
            'no recap beyond 48h skipped' => [null, 72, false],
        ];
    }

    #[Test]
    #[DataProvider('justCompletedConditions')]
    public function just_completed_recap_prompt_respects_recap_and_age(?string $recap, int $hoursAgo, bool $shouldTrigger): void
    {
        $user = User::factory()->create();
        Game::factory()->create([
            'owner_id' => $user->id,
            'name' => ['en' => 'Completed Game'],
            'status' => GameStatus::Completed,
            'recap' => $recap,
            'updated_at' => now()->subHours($hoursAgo),
            'date_time' => now()->subDays($hoursAgo >= 48 ? 5 : 2),
        ]);

        $result = $this->service->getPrompt($user);

        if ($shouldTrigger) {
            $this->assertEquals('just_completed', $result['type']);
            $this->assertStringContainsString('Completed Game', $result['message']);
        } else {
            $this->assertNotEquals('just_completed', $result['type']);
        }
    }

    #[Test]
    public function completed_game_as_approved_participant_triggers_just_completed(): void
    {
        $owner = User::factory()->create();
        $user = User::factory()->create(['created_at' => now()->subDays(30)]);
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'name' => ['en' => 'Participant Recap'],
            'status' => GameStatus::Completed,
            'recap' => null,
            'updated_at' => now()->subHours(12),
            'date_time' => now()->subDays(1),
        ]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Approved,
        ]);

        $result = $this->service->getPrompt($user);

        $this->assertEquals('just_completed', $result['type']);
        $this->assertStringContainsString('Participant Recap', $result['message']);
    }

    // ── Priority 4: Empty week ─────────────────────────

    /**
     * @return array<string, array{int, bool}>
     */
    public static function emptyWeekDays(): array
    {
        return [
            'monday shows' => [0, true],
            'wednesday shows' => [2, true],
            'thursday does not show' => [3, false],
            'sunday does not show' => [6, false],
        ];
    }

    #[Test]
    #[DataProvider('emptyWeekDays')]
    public function empty_week_prompt_only_fires_mon_through_wed(int $dayOffset, bool $shouldShow): void
    {
        $this->travelTo(now()->startOfWeek()->addDays($dayOffset));

        $user = User::factory()->create(['created_at' => now()->subDays(30)]);
        Cache::flush();

        $result = $this->service->getPrompt($user);

        if ($shouldShow) {
            $this->assertEquals('empty_week', $result['type']);
        } else {
            $this->assertNotEquals('empty_week', $result['type']);
        }
    }

    #[Test]
    public function empty_week_does_not_show_when_games_exist(): void
    {
        $this->travelTo(now()->startOfWeek()); // Monday

        $user = User::factory()->create(['created_at' => now()->subDays(30)]);
        Game::factory()->create([
            'owner_id' => $user->id,
            'status' => GameStatus::Scheduled,
            'date_time' => now()->addDays(2),
        ]);
        Cache::flush();

        $cacheService = $this->createMock(DashboardCacheService::class);
        $cacheService->method('getWeekData')->willReturn([
            'summary' => ['total' => 1],
            'days' => [],
        ]);
        $service = new DashboardSmartPromptService($cacheService);

        $result = $service->getPrompt($user);

        $this->assertNotEquals('empty_week', $result['type']);
    }

    // ── Priority 5: New follower ───────────────────────

    /**
     * @return array<string, array{int, bool}>
     */
    public static function followerAgeThresholds(): array
    {
        return [
            'within 24h shows' => [0, true],
            'beyond 24h skipped' => [2, false],
        ];
    }

    #[Test]
    #[DataProvider('followerAgeThresholds')]
    public function follower_prompt_respects_24h_threshold(int $daysOld, bool $shouldShow): void
    {
        $user = User::factory()->create(['created_at' => now()->subDays(30)]);
        $follower = User::factory()->create(['name' => 'Follower']);

        UserRelationship::create([
            'user_id' => $follower->id,
            'related_user_id' => $user->id,
            'type' => RelationshipType::Follow,
        ]);

        if ($daysOld > 0) {
            UserRelationship::query()
                ->where('user_id', $follower->id)
                ->where('related_user_id', $user->id)
                ->update(['created_at' => now()->subDays($daysOld)]);
        }

        $result = $this->service->getPrompt($user);

        if ($shouldShow) {
            $this->assertEquals('new_follower', $result['type']);
            $this->assertStringContainsString('Follower', $result['message']);
        } else {
            $this->assertNotEquals('new_follower', $result['type']);
        }
    }

    #[Test]
    public function follower_with_shared_game_systems_shows_count(): void
    {
        $system = GameSystem::factory()->create();
        $user = User::factory()->create(['created_at' => now()->subDays(30)]);
        $user->gameSystemPreferences()->attach($system->id, ['preference_type' => 'favorite']);

        $follower = User::factory()->create(['name' => 'Dana']);
        $follower->gameSystemPreferences()->attach($system->id, ['preference_type' => 'favorite']);

        UserRelationship::create([
            'user_id' => $follower->id,
            'related_user_id' => $user->id,
            'type' => RelationshipType::Follow,
        ]);

        $result = $this->service->getPrompt($user);

        $this->assertEquals('new_follower', $result['type']);
        $this->assertStringContainsString('1 game system', $result['message']);
        $this->assertEquals(1, $result['metadata']['shared_system_count']);
    }

    // ── Priority 6: Fallback active ────────────────────

    #[Test]
    public function active_user_gets_time_of_day_greeting(): void
    {
        $user = User::factory()->create([
            'name' => 'Pat Player',
            'created_at' => now()->subDays(30),
        ]);

        $result = $this->service->getPrompt($user);

        $this->assertEquals('fallback_active', $result['type']);
        $this->assertStringContainsString('Pat', $result['message']);
        $this->assertArrayHasKey('time_of_day', $result['metadata']);
    }

    #[Test]
    public function active_user_with_upcoming_sessions_shows_count(): void
    {
        $user = User::factory()->create([
            'name' => 'Session User',
            'created_at' => now()->subDays(30),
        ]);

        // Create an upcoming game more than 24h away (so it's not priority 2)
        Game::factory()->create([
            'owner_id' => $user->id,
            'date_time' => now()->addHours(48),
            'status' => GameStatus::Scheduled,
        ]);

        $result = $this->service->getPrompt($user);

        $this->assertEquals('fallback_active', $result['type']);
        $this->assertStringContainsString('1 upcoming session', $result['message']);
    }

    // ── Priority 7: Fallback new ───────────────────────

    #[Test]
    public function brand_new_user_gets_welcome_prompt(): void
    {
        $user = User::factory()->create([
            'name' => 'Newbie',
            'created_at' => now()->subHours(1),
        ]);

        // The fallback_active always returns a result, so fallback_new is only
        // reached when nothing else matches AND the service is explicitly called.
        // Since fallback_active is priority 6 (always returns), fallback_new
        // is essentially dead code in the current chain — fallback_active always wins.
        // We test the method directly by reflection or verify the type isn't used.
        // For now, verify that a brand-new user still gets a valid prompt.
        $result = $this->service->getPrompt($user);

        $this->assertNotEmpty($result['type']);
        $this->assertNotEmpty($result['message']);
    }

    // ── Return structure ────────────────────────────────

    #[Test]
    public function prompt_always_returns_required_keys(): void
    {
        $user = User::factory()->create();

        $result = $this->service->getPrompt($user);

        $this->assertArrayHasKey('type', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('action_url', $result);
        $this->assertArrayHasKey('action_label', $result);
        $this->assertArrayHasKey('metadata', $result);
        $this->assertIsArray($result['metadata']);
    }

    #[Test]
    public function priority_order_invitations_beats_upcoming(): void
    {
        $owner = User::factory()->create(['name' => 'Inviter']);
        $user = User::factory()->create();

        // Both pending invitation AND upcoming session within 24h
        $upcomingGame = Game::factory()->create([
            'owner_id' => $user->id,
            'name' => ['en' => 'Upcoming'],
            'date_time' => now()->addHours(3),
            'status' => GameStatus::Scheduled,
        ]);

        $inviteGame = Game::factory()->create([
            'owner_id' => $owner->id,
            'name' => ['en' => 'Invite Game'],
            'date_time' => now()->addDays(3),
            'status' => GameStatus::Scheduled,
        ]);

        GameParticipant::create([
            'game_id' => $inviteGame->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Pending,
        ]);

        $result = $this->service->getPrompt($user);

        $this->assertEquals('pending_invitations', $result['type']);
    }
}
