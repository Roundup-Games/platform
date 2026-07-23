<?php

namespace Tests\Feature\Services;

use App\Enums\GameStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\ParticipantLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests the unified {@see ParticipantLifecycle::createOrReactivate()} — the
 * single source of truth for participant creation across web (joinViaShareLink)
 * and Discord (ProcessDiscordRsvp).
 *
 * The core invariant: a departed participant (status=Rejected from depart())
 * keeps its row for audit, so a rejoin must UPDATE the row rather than INSERT a
 * duplicate (which would violate the unique (game_id, user_id) constraint).
 */
class ParticipantLifecycleCreateOrReactivateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function creates_a_new_participant_when_none_exists(): void
    {
        [$owner, $game] = $this->gameWithOwner();
        $joiner = User::factory()->create();

        $participant = app(ParticipantLifecycle::class)->createOrReactivate([
            'game_id' => $game->id,
            'user_id' => $joiner->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
            'approved_at' => now(),
        ]);

        $this->assertSame(ParticipantStatus::Approved, $participant->status);
        $this->assertDatabaseCount('game_participants', 2); // owner + joiner
    }

    #[Test]
    public function reactivates_a_departed_participant_instead_of_inserting_a_duplicate(): void
    {
        [$owner, $game] = $this->gameWithOwner();
        $joiner = User::factory()->create();

        // Join → leave → rejoin cycle.
        $participant = app(ParticipantLifecycle::class)->createOrReactivate([
            'game_id' => $game->id,
            'user_id' => $joiner->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
            'approved_at' => now(),
        ]);

        app(ParticipantLifecycle::class)->depart($participant, $joiner);
        $this->assertSame(ParticipantStatus::Rejected, $participant->fresh()->status);
        $this->assertDatabaseCount('game_participants', 2);

        // Rejoin — must reactivate the departed row, not throw a unique violation.
        $reactivated = app(ParticipantLifecycle::class)->createOrReactivate([
            'game_id' => $game->id,
            'user_id' => $joiner->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
            'approved_at' => now(),
        ]);

        $this->assertSame(ParticipantStatus::Approved, $reactivated->status);
        $this->assertSame($participant->id, $reactivated->id, 'must be the same row, not a new one');
        $this->assertDatabaseCount('game_participants', 2);
    }

    #[Test]
    public function preserves_row_count_across_multiple_join_leave_cycles(): void
    {
        [$owner, $game] = $this->gameWithOwner();
        $joiner = User::factory()->create();

        foreach (range(1, 3) as $i) {
            $p = app(ParticipantLifecycle::class)->createOrReactivate([
                'game_id' => $game->id,
                'user_id' => $joiner->id,
                'role' => ParticipantRole::Player->value,
                'status' => ParticipantStatus::Approved->value,
                'approved_at' => now(),
            ]);
            app(ParticipantLifecycle::class)->depart($p, $joiner);
        }

        // Three join/leave cycles → still just 2 rows (owner + the one reactive row).
        $this->assertDatabaseCount('game_participants', 2);
    }

    #[Test]
    public function reactivate_overwrites_all_supplied_attributes(): void
    {
        [$owner, $game] = $this->gameWithOwner();
        $joiner = User::factory()->create();

        $participant = app(ParticipantLifecycle::class)->createOrReactivate([
            'game_id' => $game->id,
            'user_id' => $joiner->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
            'approved_at' => now(),
        ]);
        app(ParticipantLifecycle::class)->depart($participant, $joiner);

        // Rejoin as waitlisted — the reactivated row must reflect the new status.
        $reactivated = app(ParticipantLifecycle::class)->createOrReactivate([
            'game_id' => $game->id,
            'user_id' => $joiner->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Waitlisted->value,
            'waitlisted_at' => now(),
        ]);

        $this->assertSame(ParticipantStatus::Waitlisted, $reactivated->fresh()->status);
        $this->assertNotNull($reactivated->fresh()->waitlisted_at);
    }

    private function gameWithOwner(): array
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'campaign_id' => null,
            'max_players' => 4,
            'min_players' => 2,
            'status' => GameStatus::Scheduled->value,
            'visibility' => 'public',
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        return [$owner, $game];
    }
}
