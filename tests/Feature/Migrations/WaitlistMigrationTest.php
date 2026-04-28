<?php

namespace Tests\Feature\Migrations;

use App\Enums\AttendanceStatus;
use App\Enums\ParticipantStatus;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WaitlistMigrationTest extends TestCase
{
    use RefreshDatabase;

    // ── ParticipantStatus enum values in CHECK constraints ──

    #[Test]
    public function game_participants_accepts_waitlisted_status(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $user->id]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => ParticipantStatus::Waitlisted,
        ]);

        $this->assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'status' => 'waitlisted',
        ]);
    }

    #[Test]
    public function game_participants_accepts_benched_status(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $user->id]);

        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => ParticipantStatus::Benched,
        ]);

        $this->assertDatabaseHas('game_participants', [
            'id' => $participant->id,
            'status' => 'benched',
        ]);
    }

    #[Test]
    public function campaign_participants_accepts_waitlisted_status(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create(['owner_id' => $user->id]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => ParticipantStatus::Waitlisted,
        ]);

        $this->assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'status' => 'waitlisted',
        ]);
    }

    #[Test]
    public function campaign_participants_accepts_benched_status(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create(['owner_id' => $user->id]);

        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => ParticipantStatus::Benched,
        ]);

        $this->assertDatabaseHas('campaign_participants', [
            'id' => $participant->id,
            'status' => 'benched',
        ]);
    }

    // ── max_players / min_players NOT NULL defaults ──

    #[Test]
    public function games_table_max_players_is_not_null_with_default(): void
    {
        $game = Game::factory()->create([
            'max_players' => 6,
            'min_players' => 2,
        ]);

        $this->assertEquals(6, $game->max_players);
        $this->assertEquals(2, $game->min_players);

        // Verify database-level NOT NULL constraint and default
        $column = DB::selectOne("
            SELECT is_nullable, column_default
            FROM information_schema.columns
            WHERE table_name = 'games'
              AND column_name = 'max_players'
        ");
        $this->assertEquals('NO', $column->is_nullable);
    }

    #[Test]
    public function games_max_players_column_is_not_null(): void
    {
        $column = DB::selectOne("
            SELECT is_nullable, column_default
            FROM information_schema.columns
            WHERE table_name = 'games'
              AND column_name = 'max_players'
        ");

        $this->assertEquals('NO', $column->is_nullable);
        $this->assertStringContainsString('6', $column->column_default);
    }

    #[Test]
    public function games_min_players_column_is_not_null(): void
    {
        $column = DB::selectOne("
            SELECT is_nullable, column_default
            FROM information_schema.columns
            WHERE table_name = 'games'
              AND column_name = 'min_players'
        ");

        $this->assertEquals('NO', $column->is_nullable);
        $this->assertStringContainsString('2', $column->column_default);
    }

    // ── New columns exist ──

    #[Test]
    public function games_table_has_recap_column(): void
    {
        $this->assertTrue(Schema::hasColumn('games', 'recap'));

        $game = Game::factory()->create(['recap' => 'Great session!']);
        $this->assertEquals('Great session!', $game->refresh()->recap);
    }

    #[Test]
    public function games_table_has_min_reliability_preference_column(): void
    {
        $this->assertTrue(Schema::hasColumn('games', 'min_reliability_preference'));

        $game = Game::factory()->create(['min_reliability_preference' => 80.50]);
        $this->assertEquals('80.50', $game->refresh()->min_reliability_preference);
    }

    #[Test]
    public function game_participants_has_confirmation_expires_at(): void
    {
        $this->assertTrue(Schema::hasColumn('game_participants', 'confirmation_expires_at'));

        $participant = GameParticipant::factory()->create([
            'confirmation_expires_at' => now()->addHours(24),
        ]);
        $this->assertNotNull($participant->refresh()->confirmation_expires_at);
    }

    #[Test]
    public function game_participants_has_waitlisted_at(): void
    {
        $this->assertTrue(Schema::hasColumn('game_participants', 'waitlisted_at'));

        $participant = GameParticipant::factory()->create([
            'waitlisted_at' => now(),
        ]);
        $this->assertNotNull($participant->refresh()->waitlisted_at);
    }

    #[Test]
    public function campaign_participants_has_benched_at(): void
    {
        $this->assertTrue(Schema::hasColumn('campaign_participants', 'benched_at'));

        $user = User::factory()->create();
        $campaign = Campaign::factory()->create(['owner_id' => $user->id]);
        $participant = CampaignParticipant::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'role' => 'player',
            'status' => ParticipantStatus::Benched,
            'benched_at' => now(),
        ]);
        $this->assertNotNull($participant->refresh()->benched_at);
    }

    // ── attendance_status column ──

    #[Test]
    public function game_participants_has_attendance_status_column(): void
    {
        $this->assertTrue(Schema::hasColumn('game_participants', 'attendance_status'));
    }

    #[Test]
    public function game_participants_attendance_status_accepts_all_enum_values(): void
    {
        $user = User::factory()->create();

        foreach (AttendanceStatus::cases() as $status) {
            $participant = GameParticipant::factory()->create([
                'user_id' => $user->id,
                'attendance_status' => $status,
            ]);
            $this->assertEquals($status, $participant->refresh()->attendance_status);
        }
    }

    // ── All 5 ParticipantStatus values work on both tables ──

    #[Test]
    public function all_participant_status_values_accepted_on_game_participants(): void
    {
        $user = User::factory()->create();

        foreach (ParticipantStatus::cases() as $status) {
            $participant = GameParticipant::factory()->create([
                'user_id' => $user->id,
                'status' => $status,
            ]);
            $this->assertEquals($status, $participant->refresh()->status);
        }

        $this->assertEquals(5, GameParticipant::count());
    }
}
