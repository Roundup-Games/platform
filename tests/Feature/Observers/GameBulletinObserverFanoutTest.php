<?php

namespace Tests\Feature\Observers;

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Jobs\PublishGameBulletinToDiscord;
use App\Models\Game;
use App\Models\GameBulletin;
use App\Models\GameParticipant;
use App\Models\User;
use App\Notifications\BulletinPosted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests the GameBulletinObserver created() fan-out hook (M062/S01).
 *
 * The observer is now the single path-independent hook for bulletin
 * side effects: action-center invalidation (covered by
 * ActionCenterServiceTest), the BulletinPosted participant cascade
 * (moved out of the GameBulletinBoard Livewire component so every
 * creation path notifies — host, platform admin, or a future
 * Filament/console path), and dispatch of the Discord session-thread
 * teaser job.
 */
class GameBulletinObserverFanoutTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function creating_a_bulletin_dispatches_the_discord_thread_job(): void
    {
        Queue::fake();

        [$host, $game] = $this->gameWithHost();

        $bulletin = GameBulletin::postBy((string) $game->id, (string) $host->id, 'Doors open at 18:00.');

        Queue::assertPushed(PublishGameBulletinToDiscord::class, fn (PublishGameBulletinToDiscord $job) => $job->bulletinId === $bulletin->id);
    }

    #[Test]
    public function creating_a_bulletin_notifies_approved_participants_except_the_author(): void
    {
        Queue::fake();
        Notification::fake();

        [$host, $game] = $this->gameWithHost();
        $player = $this->attachParticipant($game, ParticipantStatus::Approved);
        $waitlisted = $this->attachParticipant($game, ParticipantStatus::Waitlisted);

        GameBulletin::postBy((string) $game->id, (string) $host->id, 'Bring a level 3 character.');

        Notification::assertSentTo($player, BulletinPosted::class);
        Notification::assertNotSentTo($host, BulletinPosted::class);
        Notification::assertNotSentTo($waitlisted, BulletinPosted::class);
    }

    #[Test]
    public function deleting_a_bulletin_does_not_dispatch_the_thread_job(): void
    {
        Queue::fake();
        Notification::fake();

        [$host, $game] = $this->gameWithHost();

        $bulletin = GameBulletin::postBy((string) $game->id, (string) $host->id, 'Scratch that.');
        Queue::assertPushed(PublishGameBulletinToDiscord::class, 1);

        $bulletin->delete();

        Queue::assertPushed(PublishGameBulletinToDiscord::class, 1);
        Notification::assertNothingSent();
    }

    #[Test]
    public function fanout_waits_for_the_enclosing_transaction_to_commit(): void
    {
        Queue::fake();
        Notification::fake();

        [$host, $game] = $this->gameWithHost();
        $player = $this->attachParticipant($game, ParticipantStatus::Approved);

        // $afterCommit = true on the observer: while the bulletin row is
        // still uncommitted, NO fan-out may exist — a rollback (the
        // PreviouslyOnService publish transaction can fail on the draft
        // stamp after the insert) must never leave participants notified
        // or Discord queued for a bulletin that rolled back out of
        // existence.
        DB::beginTransaction();
        try {
            $bulletin = GameBulletin::postBy((string) $game->id, (string) $host->id, 'Uncommitted.');

            Notification::assertNothingSent();
            Queue::assertNotPushed(PublishGameBulletinToDiscord::class);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        Notification::assertSentTo($player, BulletinPosted::class);
        Queue::assertPushed(PublishGameBulletinToDiscord::class, fn (PublishGameBulletinToDiscord $job) => $job->bulletinId === $bulletin->id);
    }

    #[Test]
    public function each_recipient_gets_its_own_notification_instance(): void
    {
        Queue::fake();
        Notification::fake();

        [$host, $game] = $this->gameWithHost();
        $playerA = $this->attachParticipant($game, ParticipantStatus::Approved);
        $playerB = $this->attachParticipant($game, ParticipantStatus::Approved);

        GameBulletin::postBy((string) $game->id, (string) $host->id, 'Fresh per recipient.');

        $toA = Notification::sent($playerA, BulletinPosted::class);
        $toB = Notification::sent($playerB, BulletinPosted::class);

        $this->assertCount(1, $toA);
        $this->assertCount(1, $toB);

        // A FRESH instance per recipient: send() memoizes the per-user
        // resolved channels onto the instance, so a shared one would
        // deliver recipient N the channels of recipient N-1. Distinct
        // instances (distinct object identity) pin the fix.
        $this->assertNotSame(
            spl_object_id($toA[0]),
            spl_object_id($toB[0]),
        );
    }

    /**
     * @return array{0: User, 1: Game}
     */
    private function gameWithHost(): array
    {
        $host = User::factory()->create();
        $game = Game::factory()->create(['owner_id' => $host->id]);

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $host->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        return [$host, $game];
    }

    private function attachParticipant(Game $game, ParticipantStatus $status): User
    {
        $user = User::factory()->create();

        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'role' => ParticipantRole::Player->value,
            'status' => $status->value,
        ]);

        return $user;
    }
}
