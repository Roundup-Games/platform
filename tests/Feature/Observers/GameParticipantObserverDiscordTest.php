<?php

namespace Tests\Feature\Observers;

use App\Enums\AttendanceStatus;
use App\Enums\ParticipantStatus;
use App\Jobs\RefreshDiscordCard;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Observers\GameObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests the {@see GameParticipantObserver} roster-churn → debounced card
 * refresh dispatch hook (M057/S04/T02).
 *
 * Roster churn (a GameParticipant created / status-changed / deleted — a join,
 * a drop, a waitlist promotion, a bench demote) never re-saves the Game, so
 * {@see GameObserver::saved()} (which dispatches
 * PublishGameToDiscord on material change) never fires for churn. This hook
 * closes that gap by dispatching the ShouldBeUnique RefreshDiscordCard job.
 *
 * Contract proven here:
 *   - join (created) dispatches a refresh keyed on the game.
 *   - drop (deleted) dispatches a refresh.
 *   - a status change (waitlisted→approved, approved→benched) dispatches.
 *   - an attendance_status change (no-show reporting) does NOT dispatch.
 *   - a non-status / non-attendance update does NOT dispatch.
 *   - the publishing_enabled master switch (MEM918) gates the whole path.
 *   - rapid churn for one game coalesces to a single queued refresh.
 *   - a dispatch infrastructure failure is swallowed + logged and never
 *     blocks the participant write.
 *   - the card_refresh_dispatched structured log is emitted for tracing.
 *
 * All dispatch assertions use Queue::fake(), which both prevents inline
 * execution (the suite runs QUEUE_CONNECTION=sync) and keeps the
 * ShouldBeUnique lock held so coalescing is observable (the same trick T01's
 * RefreshDiscordCardTest uses).
 */
class GameParticipantObserverDiscordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // publishing_enabled defaults OFF (MEM918). Individual tests flip it
        // on for the action under test; the default keeps the isolated-creation
        // steps below from dispatching anything.
        config(['services.discord.publishing_enabled' => false]);
    }

    // ── Roster churn that SHOULD dispatch a refresh ───────────────────────

    #[Test]
    public function a_join_dispatches_a_debounced_card_refresh_keyed_on_the_game(): void
    {
        $game = $this->makeGame();

        $this->enablePublishingAndFakeQueue();

        $participant = GameParticipant::factory()->create([
            'game_id' => $game->id,
            'status' => ParticipantStatus::Approved,
        ]);

        Queue::assertPushed(RefreshDiscordCard::class, 1);
        Queue::assertPushed(RefreshDiscordCard::class, function (RefreshDiscordCard $job) use ($participant) {
            return $job->gameId === (string) $participant->game_id;
        });
    }

    #[Test]
    public function a_drop_dispatches_a_debounced_card_refresh(): void
    {
        $game = $this->makeGame();
        $participant = GameParticipant::factory()->create([
            'game_id' => $game->id,
            'status' => ParticipantStatus::Approved,
        ]);

        $this->enablePublishingAndFakeQueue();

        $participant->delete();

        Queue::assertPushed(RefreshDiscordCard::class, 1);
        Queue::assertPushed(RefreshDiscordCard::class, function (RefreshDiscordCard $job) use ($game) {
            return $job->gameId === (string) $game->id;
        });
    }

    #[Test]
    public function a_status_change_to_approved_dispatches_a_refresh(): void
    {
        $game = $this->makeGame();
        $participant = GameParticipant::factory()->create([
            'game_id' => $game->id,
            'status' => ParticipantStatus::Waitlisted,
        ]);

        $this->enablePublishingAndFakeQueue();

        $participant->update(['status' => ParticipantStatus::Approved]);

        Queue::assertPushed(RefreshDiscordCard::class, 1);
        Queue::assertPushed(RefreshDiscordCard::class, function (RefreshDiscordCard $job) use ($game) {
            return $job->gameId === (string) $game->id;
        });
    }

    #[Test]
    public function a_status_change_to_benched_dispatches_a_refresh(): void
    {
        $game = $this->makeGame();
        $participant = GameParticipant::factory()->create([
            'game_id' => $game->id,
            'status' => ParticipantStatus::Approved,
        ]);

        $this->enablePublishingAndFakeQueue();

        $participant->update(['status' => ParticipantStatus::Benched]);

        Queue::assertPushed(RefreshDiscordCard::class, 1);
        Queue::assertPushed(RefreshDiscordCard::class, function (RefreshDiscordCard $job) use ($game) {
            return $job->gameId === (string) $game->id;
        });
    }

    // ── Updates that must NOT dispatch a refresh ──────────────────────────

    #[Test]
    public function an_attendance_status_update_does_not_dispatch_a_refresh(): void
    {
        $game = $this->makeGame();
        $participant = GameParticipant::factory()->create([
            'game_id' => $game->id,
            'status' => ParticipantStatus::Approved,
            'attendance_status' => AttendanceStatus::Attended,
        ]);

        $this->enablePublishingAndFakeQueue();

        // A no-show report changes attendance_status, NOT the roster — must
        // not trigger a card refresh.
        $participant->update(['attendance_status' => AttendanceStatus::NoShow]);

        Queue::assertNotPushed(RefreshDiscordCard::class);
    }

    #[Test]
    public function a_non_status_non_attendance_update_does_not_dispatch_a_refresh(): void
    {
        $game = $this->makeGame();
        $participant = GameParticipant::factory()->create([
            'game_id' => $game->id,
            'status' => ParticipantStatus::Approved,
        ]);

        $this->enablePublishingAndFakeQueue();

        // promoted_manually is bookkeeping, not roster state.
        $participant->update(['promoted_manually' => true]);

        Queue::assertNotPushed(RefreshDiscordCard::class);
    }

    // ── The publishing_enabled master switch (MEM918) ─────────────────────

    #[Test]
    public function roster_churn_is_inert_when_publishing_is_disabled(): void
    {
        $this->enablePublishingAndFakeQueue(false);
        $game = $this->makeGame();

        $participant = GameParticipant::factory()->create([
            'game_id' => $game->id,
            'status' => ParticipantStatus::Approved,
        ]);
        $participant->update(['status' => ParticipantStatus::Benched]);
        $participant->delete();

        Queue::assertNotPushed(RefreshDiscordCard::class);
    }

    // ── Coalescing: rapid churn for one game → one refresh ───────────────

    #[Test]
    public function rapid_roster_churn_for_one_game_coalesces_to_a_single_refresh(): void
    {
        $game = $this->makeGame();

        $this->enablePublishingAndFakeQueue();

        // A burst of churn for the SAME game: two joins, a status change, and
        // a drop. ShouldBeUnique keyed on gameId coalesces these to a single
        // queued refresh (the UniqueLock is held while the job is delayed).
        $p1 = GameParticipant::factory()->create(['game_id' => $game->id, 'status' => ParticipantStatus::Approved]);
        $p2 = GameParticipant::factory()->create(['game_id' => $game->id, 'status' => ParticipantStatus::Waitlisted]);
        $p2->update(['status' => ParticipantStatus::Approved]);
        $p1->delete();

        Queue::assertPushed(RefreshDiscordCard::class, 1);
        Queue::assertPushed(RefreshDiscordCard::class, function (RefreshDiscordCard $job) use ($game) {
            return $job->gameId === (string) $game->id;
        });
    }

    #[Test]
    public function roster_churn_for_different_games_does_not_coalesce(): void
    {
        $gameA = $this->makeGame();
        $gameB = $this->makeGame();

        $this->enablePublishingAndFakeQueue();

        GameParticipant::factory()->create(['game_id' => $gameA->id, 'status' => ParticipantStatus::Approved]);
        GameParticipant::factory()->create(['game_id' => $gameB->id, 'status' => ParticipantStatus::Approved]);

        Queue::assertPushed(RefreshDiscordCard::class, 2);
    }

    // ── Resilience: dispatch failure never blocks the write ───────────────

    #[Test]
    public function a_dispatch_infrastructure_failure_is_swallowed_and_logged_and_never_blocks_the_write(): void
    {
        // Point the queue at a connection that does not exist so the dispatch
        // throws at push time inside the observer's try/catch — proving the
        // failure is swallowed, logged, and the participant write completes.
        config([
            'services.discord.publishing_enabled' => true,
            'queue.default' => 'definitely-not-a-real-connection',
        ]);
        $log = Log::spy();

        // This must NOT throw — the observer swallows the dispatch failure and
        // the participant row is written regardless.
        $participant = GameParticipant::factory()->create([
            'game_id' => $this->makeGame()->id,
            'status' => ParticipantStatus::Approved,
        ]);

        $this->assertDatabaseHas('game_participants', ['id' => $participant->id]);

        $log->shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($participant) {
                return $message === 'discord_publisher.card_refresh_dispatch_failed'
                    && ($context['game_id'] ?? null) === (string) $participant->game_id;
            })
            ->atLeast()
            ->once();
    }

    // ── Observability: the dispatched structured log ──────────────────────

    #[Test]
    public function a_successful_dispatch_emits_the_card_refresh_dispatched_structured_log(): void
    {
        $game = $this->makeGame();
        $this->enablePublishingAndFakeQueue();
        $log = Log::spy();

        GameParticipant::factory()->create([
            'game_id' => $game->id,
            'status' => ParticipantStatus::Approved,
        ]);

        $log->shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) use ($game) {
                return $message === 'discord_publisher.card_refresh_dispatched'
                    && ($context['game_id'] ?? null) === (string) $game->id;
            })
            ->atLeast()
            ->once();
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Create a Game with publishing disabled so neither the Game's own
     * GameObserver::saved dispatch (PublishGameToDiscord) nor any participant
     * churn fires during setup. Each test flips publishing on + fakes the
     * queue only for the action under test, isolating the dispatch it asserts.
     */
    private function makeGame(): Game
    {
        return Game::factory()->create([
            'owner_id' => User::factory()->create()->id,
            'status' => 'scheduled',
            'visibility' => 'public',
        ]);
    }

    private function enablePublishingAndFakeQueue(bool $enabled = true): void
    {
        config(['services.discord.publishing_enabled' => $enabled]);
        Queue::fake();
    }
}
