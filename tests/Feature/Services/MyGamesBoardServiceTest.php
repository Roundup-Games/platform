<?php

namespace Tests\Feature\Services;

use App\Enums\GameStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\GameSystem;
use App\Models\User;
use App\Services\MyGamesBoardService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class MyGamesBoardServiceTest extends TestCase
{
    use DatabaseTransactions;

    private MyGamesBoardService $service;

    private User $user;

    private GameSystem $gameSystem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MyGamesBoardService;
        Cache::flush();
        Queue::fake();
        URL::defaults(['locale' => 'en']);

        $this->user = User::factory()->create();
        $this->gameSystem = GameSystem::factory()->create();
    }

    public function test_empty_user_has_no_games_and_reports_has_any_games_false(): void
    {
        $board = $this->service->build($this->user);

        $this->assertFalse($board['has_any_games']);
        $this->assertCount(0, $board['upcoming_hosting']);
        $this->assertCount(0, $board['upcoming_playing']);
        $this->assertCount(0, $board['recent_completed']);
        $this->assertCount(0, $board['archive']);
    }

    public function test_upcoming_hosting_buckets_future_scheduled_owned_games_soonest_first(): void
    {
        $sooner = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->addDays(2),
            'status' => GameStatus::Scheduled->value,
        ]);

        $later = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->addDays(9),
            'status' => GameStatus::Scheduled->value,
        ]);

        $board = $this->service->build($this->user);

        $this->assertTrue($board['has_any_games']);
        $ids = $board['upcoming_hosting']->pluck('id')->all();
        $this->assertEquals([$sooner->id, $later->id], $ids);
    }

    public function test_completed_within_30_days_is_recent_not_archive(): void
    {
        $recent = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->subDays(5),
            'status' => GameStatus::Completed->value,
        ]);

        $old = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->subDays(60),
            'status' => GameStatus::Completed->value,
        ]);

        $board = $this->service->build($this->user);

        $this->assertTrue($board['recent_completed']->contains('id', $recent->id));
        $this->assertFalse($board['recent_completed']->contains('id', $old->id));
        $this->assertTrue($board['archive']->contains('id', $old->id));
        $this->assertFalse($board['archive']->contains('id', $recent->id));
    }

    public function test_canceled_games_go_to_archive(): void
    {
        $canceled = Game::factory()->create([
            'owner_id' => $this->user->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->subDays(3),
            'status' => GameStatus::Canceled->value,
        ]);

        $board = $this->service->build($this->user);

        $this->assertTrue($board['archive']->contains('id', $canceled->id));
        $this->assertFalse($board['upcoming_hosting']->contains('id', $canceled->id));
        $this->assertFalse($board['recent_completed']->contains('id', $canceled->id));
    }

    public function test_upcoming_playing_buckets_games_where_user_is_approved_player(): void
    {
        $host = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->addDays(3),
            'status' => GameStatus::Scheduled->value,
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        $board = $this->service->build($this->user);

        $this->assertTrue($board['upcoming_playing']->contains('id', $game->id));
        $this->assertFalse($board['upcoming_hosting']->contains('id', $game->id));
    }

    public function test_pending_invitations_collected_separately(): void
    {
        $host = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $host->id,
            'game_system_id' => $this->gameSystem->id,
            'date_time' => now()->addDays(3),
            'status' => GameStatus::Scheduled->value,
        ]);
        $invitation = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $this->user->id,
            'role' => ParticipantRole::Invited->value,
            'status' => ParticipantStatus::Pending->value,
        ]);

        $board = $this->service->build($this->user);

        $this->assertTrue($board['pending_invitations']->contains('id', $invitation->id));
    }
}
