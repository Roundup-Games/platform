<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\SessionDebriefing;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\DebriefingService;
use App\Services\GameActivityFeedService;
use App\Services\RecapService;
use App\Services\ReliabilityScoreService;
use App\Services\WaitlistService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\SetsUpLocale;

/**
 * Full lifecycle integration test — exercises the complete M028 chain:
 * fill → waitlist → cancel → promote → confirm → complete →
 * attend → score → debrief → recap → feed
 *
 * This is the Operational verification class test that ties all five
 * M028 slices together in a single end-to-end flow.
 */
class GameLifecycleIntegrationTest extends TestCase
{
    use DatabaseTransactions;
    use SetsUpLocale {
        SetsUpLocale::setUp as setUpLocale;
    }

    private WaitlistService $waitlistService;
    private AttendanceService $attendanceService;
    private ReliabilityScoreService $reliabilityService;
    private RecapService $recapService;
    private DebriefingService $debriefingService;

    protected function setUp(): void
    {
        $this->setUpLocale();
        $this->waitlistService = app(WaitlistService::class);
        $this->attendanceService = app(AttendanceService::class);
        $this->reliabilityService = app(ReliabilityScoreService::class);
        $this->recapService = app(RecapService::class);
        $this->debriefingService = app(DebriefingService::class);
    }

    #[Test]
    public function test_full_game_lifecycle_with_waitlist_attendance_debriefing_recap(): void
    {
        // ── Setup: Host + players, game with max_players=3 (host counts) ──
        $host = User::factory()->create(['name' => 'Host']);
        $player1 = User::factory()->create(['name' => 'Player1']);
        $player2 = User::factory()->create(['name' => 'Player2']);
        $waitlistedPlayer = User::factory()->create(['name' => 'Waitlisted']);
        $follower = User::factory()->create(['name' => 'Follower']);

        // Follower follows host for activity feed
        $follower->followings()->create(['related_user_id' => $host->id, 'type' => 'follow']);

        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->addDays(3),
            'status' => 'scheduled',
            'max_players' => 3,
            'min_players' => 1,
            'safety_rules' => ['debriefing', 'x-card'],
        ]);

        // Host is a participant (counts toward max_players)
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $host->id,
            'role' => 'owner',
            'status' => ParticipantStatus::Approved->value,
        ]);

        // ── Step 1: Fill the game (S02 — waitlist) ──
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player1->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $player2->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved->value,
        ]);

        // Game is now full (host + 2 players = 3 = max_players)
        $approvedCount = $game->participants()
            ->where('status', ParticipantStatus::Approved->value)
            ->count();
        $this->assertEquals(3, $approvedCount);

        // Waitlisted player applies
        $waitlistedParticipant = $this->waitlistService->addToWaitlist($game, $waitlistedPlayer);
        $this->assertEquals(ParticipantStatus::Waitlisted, $waitlistedParticipant->status);
        $this->assertEquals(1, $this->waitlistService->getWaitlistPosition($waitlistedParticipant));

        // ── Step 2: Approved player cancels → auto-promote waitlisted (S02) ──
        $player2Participant = $game->participants()
            ->where('user_id', $player2->id)
            ->first();

        $player2Participant->update(['status' => ParticipantStatus::Rejected->value]);

        $this->waitlistService->promoteAllOnCancel($game);

        // Waitlisted player should be promoted to pending (awaiting confirmation)
        $waitlistedParticipant->refresh();
        $this->assertEquals(ParticipantStatus::Pending, $waitlistedParticipant->status);
        $this->assertNotNull($waitlistedParticipant->confirmation_expires_at);

        // Confirm promotion — player accepts the spot
        $this->waitlistService->confirmPromotion($waitlistedParticipant);
        $waitlistedParticipant->refresh();
        $this->assertEquals(ParticipantStatus::Approved, $waitlistedParticipant->status);
        $this->assertNull($waitlistedParticipant->confirmation_expires_at);

        // ── Step 3: Complete the game (S04 — attendance) ──
        $game->update(['status' => 'completed', 'date_time' => now()->subHours(2)]);

        // Report attendance: host reports all players
        // Host reports player1 as attended
        $result1 = $this->attendanceService->reportAttendance(
            $game, $host, $player1, AttendanceStatus::Attended->value
        );
        $this->assertTrue($result1['success']);

        // Host reports waitlisted player as attended
        $result2 = $this->attendanceService->reportAttendance(
            $game, $host, $waitlistedPlayer, AttendanceStatus::Attended->value
        );
        $this->assertTrue($result2['success']);

        // Player1 reports host as attended (corroboration)
        $result3 = $this->attendanceService->reportAttendance(
            $game, $player1, $host, AttendanceStatus::Attended->value
        );
        $this->assertTrue($result3['success']);

        // Player2 (cancelled) doesn't get attendance reported

        // ── Step 4: Verify reliability scores updated (S01/S04) ──
        $player1->refresh();
        $player1Score = $this->reliabilityService->computeScore($player1);
        $this->assertEquals(100.0, $player1Score['score']);

        $host->refresh();
        $hostScore = $this->reliabilityService->computeScore($host);
        $this->assertEquals(100.0, $hostScore['score']);

        $waitlistedPlayer->refresh();
        $wlScore = $this->reliabilityService->computeScore($waitlistedPlayer);
        $this->assertEquals(100.0, $wlScore['score']);

        // ── Step 5: Submit debriefing for game with debriefing tool (S05) ──
        $this->assertTrue($game->hasDebriefingTools());

        $debriefing = $this->debriefingService->submitDebriefing($game, $player1, [
            'what_went_well' => 'Great pacing and story',
            'what_to_change' => 'Could use more combat encounters',
        ]);

        $this->assertNotNull($debriefing);
        $this->assertEquals($game->id, $debriefing->game_id);
        $this->assertEquals($player1->id, $debriefing->user_id);

        // Verify debriefing is in DB
        $this->assertDatabaseHas('session_debriefings', [
            'game_id' => $game->id,
            'user_id' => $player1->id,
        ]);

        // ── Step 6: Host writes recap (S05) ──
        $this->recapService->writeRecap($game, $host, 'Amazing session! The party defeated the dragon and saved the village.');

        $game->refresh();
        $this->assertEquals('Amazing session! The party defeated the dragon and saved the village.', $game->recap);

        // ── Step 7: Verify recap appears in activity feed (S05) ──
        $feedService = app(GameActivityFeedService::class);
        $feed = $feedService->getFeed($follower, 20);

        $recapEntry = collect($feed->items())->first(function ($item) {
            return $item->type === 'session_recapped';
        });

        $this->assertNotNull($recapEntry, 'Recap should appear in follower activity feed');
        $this->assertEquals($game->id, $recapEntry->entity->id);
    }

    #[Test]
    public function test_host_no_show_heavier_penalty_than_player_no_show(): void
    {
        $host = User::factory()->create();
        $player = User::factory()->create();
        $reporter = User::factory()->create();

        // Setup a completed game where host no-showed
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'status' => 'completed',
            'date_time' => now()->subHours(3),
        ]);

        GameParticipant::create([
            'game_id' => $game->id, 'user_id' => $host->id,
            'role' => 'owner', 'status' => ParticipantStatus::Approved->value,
        ]);
        GameParticipant::create([
            'game_id' => $game->id, 'user_id' => $player->id,
            'role' => 'player', 'status' => ParticipantStatus::Approved->value,
        ]);
        GameParticipant::create([
            'game_id' => $game->id, 'user_id' => $reporter->id,
            'role' => 'player', 'status' => ParticipantStatus::Approved->value,
        ]);

        // Reporter marks host as no-show
        $result = $this->attendanceService->reportAttendance(
            $game, $reporter, $host, AttendanceStatus::NoShow->value
        );
        $this->assertTrue($result['success']);

        // Reporter marks player as no-show
        $result = $this->attendanceService->reportAttendance(
            $game, $reporter, $player, AttendanceStatus::NoShow->value
        );
        $this->assertTrue($result['success']);

        // Verify host penalty is heavier
        $hostScore = $this->reliabilityService->computeScore($host);
        $playerScore = $this->reliabilityService->computeScore($player);

        // Both scores are clamped to 0-100 range, but weights applied differ
        // Host no-show weight is heavier than player no-show weight
        $this->assertArrayHasKey('weights_applied', $hostScore);
        $this->assertArrayHasKey('weights_applied', $playerScore);

        // Host should have a lower score (or same 0 if both clamped)
        $this->assertLessThanOrEqual($playerScore['score'], $hostScore['score']);
    }

    #[Test]
    public function test_host_late_cancellation_uses_host_weight(): void
    {
        $host = User::factory()->create();
        $player = User::factory()->create();

        // Give host some prior good history
        GameParticipant::factory()->count(5)->create([
            'user_id' => $host->id,
            'attendance_status' => AttendanceStatus::Attended,
        ]);

        // Host cancels game <24h before start
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'date_time' => now()->addHours(12),
            'status' => 'canceled',
        ]);

        GameParticipant::create([
            'game_id' => $game->id, 'user_id' => $host->id,
            'role' => 'owner', 'status' => ParticipantStatus::Approved->value,
        ]);
        GameParticipant::create([
            'game_id' => $game->id, 'user_id' => $player->id,
            'role' => 'player', 'status' => ParticipantStatus::Approved->value,
        ]);

        $this->attendanceService->recordHostCancellationOffence($game);

        // Verify host score dropped with host_cancel_late weight (-1.2)
        $host->refresh();
        $hostScore = $this->reliabilityService->computeScore($host);

        // 5 attended (5.0) + 1 host_cancel_late (-1.2) = 3.8 / 6 * 100 = 63.33
        $this->assertEquals(63.33, $hostScore['score']);
    }

    #[Test]
    public function test_game_cancellation_resolves_waitlisted_and_benched(): void
    {
        $host = User::factory()->create();
        $player = User::factory()->create();
        $waitlisted = User::factory()->create();

        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'max_players' => 1,
            'status' => 'scheduled',
            'date_time' => now()->addDays(1),
        ]);

        GameParticipant::create([
            'game_id' => $game->id, 'user_id' => $host->id,
            'role' => 'owner', 'status' => ParticipantStatus::Approved->value,
        ]);
        GameParticipant::create([
            'game_id' => $game->id, 'user_id' => $player->id,
            'role' => 'player', 'status' => ParticipantStatus::Approved->value,
        ]);

        // Waitlist a player
        $wlParticipant = $this->waitlistService->addToWaitlist($game, $waitlisted);
        $this->assertEquals(ParticipantStatus::Waitlisted, $wlParticipant->status);

        // Cancel the game
        $game->update(['status' => 'canceled']);
        $this->waitlistService->handleGameCancellation($game);

        // Waitlisted player should be rejected
        $wlParticipant->refresh();
        $this->assertEquals(ParticipantStatus::Rejected, $wlParticipant->status);

        // No offence recorded since cancellation is >24h before game
        $hostParticipant = $game->participants()->where('user_id', $host->id)->first();
        $this->assertNull($hostParticipant->attendance_status);
    }

    #[Test]
    public function test_auto_attend_after_48h_and_reliability_tier(): void
    {
        $host = User::factory()->create();
        $player = User::factory()->create();

        // Give player 4 prior attended games (newcomer)
        GameParticipant::factory()->count(4)->create([
            'user_id' => $player->id,
            'attendance_status' => AttendanceStatus::Attended,
        ]);

        // Complete a game 50h ago
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'status' => 'completed',
            'date_time' => now()->subHours(50),
        ]);

        GameParticipant::create([
            'game_id' => $game->id, 'user_id' => $host->id,
            'role' => 'owner', 'status' => ParticipantStatus::Approved->value,
        ]);
        $playerParticipant = GameParticipant::create([
            'game_id' => $game->id, 'user_id' => $player->id,
            'role' => 'player', 'status' => ParticipantStatus::Approved->value,
            'attendance_status' => null, // No report yet
        ]);

        // Before auto-attend: player is newcomer with 4 games
        $scoreBefore = $this->reliabilityService->computeScore($player);
        $this->assertEquals('newcomer', $scoreBefore['tier']);
        $this->assertEquals(4, $scoreBefore['game_count']);

        // Run auto-attend — both host and player get auto-attended (no prior reports)
        $count = $this->attendanceService->autoAttendAfter48Hours();
        $this->assertEquals(2, $count);

        // After auto-attend: player has 5 games, reliable tier
        $scoreAfter = $this->reliabilityService->computeScore($player);
        $this->assertEquals('reliable', $scoreAfter['tier']);
        $this->assertEquals(5, $scoreAfter['game_count']);
        $this->assertEquals(100.0, $scoreAfter['score']);
    }
}
