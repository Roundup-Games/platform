<?php

namespace Tests\Feature\Services;

use App\Enums\AttendanceStatus;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\ReliabilityScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReliabilityScoreServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReliabilityScoreService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ReliabilityScoreService::class);
    }

    // ── computeScore ──────────────────────────────────

    public function test_compute_score_returns_zero_for_user_with_no_games(): void
    {
        $user = User::factory()->create();

        $result = $this->service->computeScore($user);

        $this->assertEquals(0.0, $result['score']);
        $this->assertEquals(0, $result['game_count']);
        $this->assertEquals('newcomer', $result['tier']);
        $this->assertEmpty($result['weights_applied']);
    }

    public function test_compute_score_for_all_attended(): void
    {
        $user = User::factory()->create();
        $this->createParticipationRecords($user, AttendanceStatus::Attended, 6);

        $result = $this->service->computeScore($user);

        $this->assertEquals(100.0, $result['score']);
        $this->assertEquals(6, $result['game_count']);
        $this->assertEquals('reliable', $result['tier']);
    }

    public function test_compute_score_for_mixed_attendance(): void
    {
        $user = User::factory()->create();
        // 4 attended (4.0) + 1 late_cancel (-0.3) + 1 no_show (-1.0) = 2.7 / 6.0 * 100 = 45.0
        $this->createParticipationRecords($user, AttendanceStatus::Attended, 4);
        $this->createParticipationRecords($user, AttendanceStatus::LateCancel, 1);
        $this->createParticipationRecords($user, AttendanceStatus::NoShow, 1);

        $result = $this->service->computeScore($user);

        $this->assertEquals(45.0, $result['score']);
        $this->assertEquals(6, $result['game_count']);
        $this->assertEquals('active', $result['tier']);
    }

    public function test_compute_score_ignores_records_without_attendance_status(): void
    {
        $user = User::factory()->create();
        // 2 attended + 1 record with no attendance_status
        $this->createParticipationRecords($user, AttendanceStatus::Attended, 2);
        $this->createParticipationRecordWithoutAttendance($user);

        $result = $this->service->computeScore($user);

        $this->assertEquals(100.0, $result['score']);
        $this->assertEquals(2, $result['game_count']);
        $this->assertEquals('newcomer', $result['tier']);
    }

    public function test_compute_score_with_excused_is_neutral(): void
    {
        $user = User::factory()->create();
        // 5 attended (5.0) + 1 excused (0.0) = 5.0 / 6.0 * 100 ≈ 83.33
        $this->createParticipationRecords($user, AttendanceStatus::Attended, 5);
        $this->createParticipationRecords($user, AttendanceStatus::Excused, 1);

        $result = $this->service->computeScore($user);

        $this->assertEquals(83.33, $result['score']);
        $this->assertEquals(6, $result['game_count']);
        $this->assertEquals('active', $result['tier']);
    }

    // ── getTier ────────────────────────────────────────

    public function test_tier_newcomer_with_few_games(): void
    {
        $this->assertEquals('newcomer', $this->service->getTier(100.0, 4));
        $this->assertEquals('newcomer', $this->service->getTier(0.0, 0));
    }

    public function test_tier_reliable_with_high_score_and_enough_games(): void
    {
        $this->assertEquals('reliable', $this->service->getTier(95.0, 5));
        $this->assertEquals('reliable', $this->service->getTier(100.0, 10));
    }

    public function test_tier_active_with_enough_games_but_below_threshold(): void
    {
        $this->assertEquals('active', $this->service->getTier(94.99, 5));
        $this->assertEquals('active', $this->service->getTier(50.0, 10));
    }

    // ── recomputeAfterAttendance ───────────────────────

    public function test_recompute_persists_score_to_user(): void
    {
        $user = User::factory()->create();
        $participant = $this->createParticipationRecords($user, AttendanceStatus::Attended, 5)->first();

        $this->service->recomputeAfterAttendance($participant);

        $user->refresh();
        $this->assertNotNull($user->reliability_score);
        $this->assertEquals(100.0, $user->reliability_score['score']);
        $this->assertEquals(5, $user->reliability_score['game_count']);
        $this->assertEquals('reliable', $user->reliability_score['tier']);
        $this->assertNotNull($user->reliability_computed_at);
    }

    public function test_recompute_updates_existing_score(): void
    {
        $user = User::factory()->create();

        // First: 5 attended → 100% reliable
        $participant = $this->createParticipationRecords($user, AttendanceStatus::Attended, 5)->first();
        $this->service->recomputeAfterAttendance($participant);

        // Now add a no-show
        $noShow = $this->createParticipationRecords($user, AttendanceStatus::NoShow, 1)->first();
        $this->service->recomputeAfterAttendance($noShow);

        $user->refresh();
        // 5.0 - 1.0 = 4.0 / 6.0 * 100 ≈ 66.67
        $this->assertEquals(66.67, $user->reliability_score['score']);
        $this->assertEquals(6, $user->reliability_score['game_count']);
        $this->assertEquals('active', $user->reliability_score['tier']);
    }

    public function test_recompute_handles_user_with_no_participant_relationship(): void
    {
        $user = User::factory()->create();
        // Create a participant that references the user but user model is fresh
        $game = Game::factory()->create(['owner_id' => $user->id]);
        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => ParticipantStatus::Approved,
            'attendance_status' => AttendanceStatus::Attended,
        ]);

        $this->service->recomputeAfterAttendance($participant);

        $user->refresh();
        $this->assertEquals(100.0, $user->reliability_score['score']);
    }

    // ── Weight constants ───────────────────────────────

    public function test_weight_constants_cover_all_attendance_statuses(): void
    {
        foreach (AttendanceStatus::cases() as $status) {
            $this->assertArrayHasKey(
                $status->value,
                ReliabilityScoreService::WEIGHTS,
                "Missing weight for AttendanceStatus::{$status->name}"
            );
        }
    }

    public function test_min_games_constant(): void
    {
        $this->assertEquals(5, ReliabilityScoreService::MIN_GAMES);
    }

    public function test_reliable_threshold_constant(): void
    {
        $this->assertEquals(95.0, ReliabilityScoreService::RELIABLE_THRESHOLD);
    }

    // ── Helpers ────────────────────────────────────────

    private function createParticipationRecords(User $user, AttendanceStatus $status, int $count)
    {
        return GameParticipant::factory()->count($count)->create([
            'user_id' => $user->id,
            'attendance_status' => $status,
        ]);
    }

    private function createParticipationRecordWithoutAttendance(User $user): GameParticipant
    {
        return GameParticipant::factory()->create([
            'user_id' => $user->id,
            'attendance_status' => null,
        ]);
    }
}
