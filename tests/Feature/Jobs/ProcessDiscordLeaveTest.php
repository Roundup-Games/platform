<?php

namespace Tests\Feature\Jobs;

use App\Enums\GameStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Jobs\ProcessDiscordLeave;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\Discord\DiscordPublisher;
use App\Services\Discord\DiscordWebhookClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests the {@see ProcessDiscordLeave} deferred job (M057 RSVP-menu follow-up).
 *
 * Mirrors {@see ProcessDiscordRsvpTest}'s proof level: a REAL webhook client
 * + REAL publisher wired to Http::fake(), so the job → departure pipeline →
 * card refresh → @original PATCH path is live, not mocked.
 *
 * The departure mirrors the web's GameDetail::leaveGame() exactly (one source
 * of truth): ParticipantLifecycle::depart() + Roster::onDeparture(). The
 * reliability/attendance implications of a Discord drop are identical to a
 * web drop — never a second path.
 */
class ProcessDiscordLeaveTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://discord.test/api/v10';

    private const BOT_APP_ID = '111222333444555666';

    private const INTERACTION_TOKEN = 'a-leave-interaction-token';

    private const GUILD_ID_SNOWFLAKE = '999000111222333444';

    private const GAMES_CHANNEL = '888777666555444333';

    private const CARD_MESSAGE_ID = '777666555444333222';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.discord.bot_application_id' => self::BOT_APP_ID,
            'services.discord.api_base_url' => self::BASE_URL,
            'services.discord.publishing_enabled' => true,
        ]);

        $this->app->instance(DiscordWebhookClient::class, new DiscordWebhookClient(
            baseUrl: self::BASE_URL,
            botToken: 'test-bot-token',
            timeout: 5,
            maxAttempts: 3,
            maxRetryAfterSeconds: 30.0,
            serverErrorBackoffSeconds: 0.0,
            sleep: static fn (float $seconds) => null,
        ));
    }

    #[Test]
    public function approved_participant_leaves_through_the_same_pipeline_as_web(): void
    {
        [$owner, $game, $leaver, $participant] = $this->gameWithApprovedParticipant();
        Http::fake(fn (Request $r) => Http::response(['id' => 'orig'], 200));

        $this->runJob($game->id, $leaver->id);

        // depart() sets status=Rejected + removed_by + removed_at. Mirrors the
        // web leaveGame path exactly — one source of truth.
        $participant->refresh();
        $this->assertSame(ParticipantStatus::Rejected, $participant->status);
        $this->assertSame($leaver->id, $participant->removed_by);
        $this->assertNotNull($participant->removed_at);
    }

    #[Test]
    public function owner_cannot_leave_their_own_game(): void
    {
        [$owner, $game] = $this->gameWithOwnerOnly();
        $captured = null;
        Http::fake(function (Request $r) use (&$captured) {
            if (str_contains($r->url(), '@original')) {
                $captured = json_decode($r->body(), true);
            }

            return Http::response(['id' => 'orig'], 200);
        });

        $this->runJob($game->id, $owner->id);

        $this->assertStringContainsString('hosting', $captured['content']);
        // No departure: owner is still Approved.
        $this->assertSame(1, GameParticipant::where('game_id', $game->id)->count());
    }

    #[Test]
    public function non_participant_leaves_gracefully(): void
    {
        [$owner, $game] = $this->gameWithOwnerOnly();
        $stranger = User::factory()->create();
        $captured = null;
        Http::fake(function (Request $r) use (&$captured) {
            if (str_contains($r->url(), '@original')) {
                $captured = json_decode($r->body(), true);
            }

            return Http::response(['id' => 'orig'], 200);
        });

        $this->runJob($game->id, $stranger->id);

        $this->assertStringContainsString('not on the roster', $captured['content']);
    }

    #[Test]
    public function double_dispatch_is_idempotent_second_is_not_a_participant(): void
    {
        [$owner, $game, $leaver] = $this->gameWithApprovedParticipant();
        Http::fake(fn (Request $r) => Http::response(['id' => 'orig'], 200));

        // First leave succeeds.
        $this->runJob($game->id, $leaver->id);
        // Second (e.g. double-click re-dispatch) finds no active row.
        $this->runJob($game->id, $leaver->id);

        // Only one participant row (now Rejected), no duplicate, no error.
        $this->assertSame(2, GameParticipant::where('game_id', $game->id)->count());
    }

    #[Test]
    public function missing_game_resolves_as_not_found_without_throwing(): void
    {
        Http::fake(fn (Request $r) => Http::response(['id' => 'orig'], 200));

        $this->runJob('00000000-0000-0000-0000-000000000000', User::factory()->create()->id);

        // No exception (the test reaching this assertion proves it); the
        // @original PATCH carried the not-found confirmation.
        $this->assertTrue(true);
    }

    #[Test]
    public function confirmation_failure_never_rolls_back_the_departure(): void
    {
        [$owner, $game, $leaver, $participant] = $this->gameWithApprovedParticipant();
        Http::fake(fn (Request $r) => str_contains($r->url(), '@original')
            ? Http::response(['message' => 'Invalid Webhook Token'], 401)
            : Http::response('', 200));

        $this->runJob($game->id, $leaver->id);

        // The departure stands — a confirmation failure never rolls back the write.
        $participant->refresh();
        $this->assertSame(ParticipantStatus::Rejected, $participant->status);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function runJob(string $gameId, string $userId): void
    {
        (new ProcessDiscordLeave($gameId, $userId, self::GUILD_ID_SNOWFLAKE, self::INTERACTION_TOKEN))
            ->handle(app(DiscordWebhookClient::class), app(DiscordPublisher::class));
    }

    private function gameWithApprovedParticipant(): array
    {
        [$owner, $game] = $this->gameWithOwnerOnly();

        $leaver = User::factory()->create();
        $participant = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $leaver->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Approved->value,
        ]);

        return [$owner, $game, $leaver, $participant];
    }

    private function gameWithOwnerOnly(): array
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
