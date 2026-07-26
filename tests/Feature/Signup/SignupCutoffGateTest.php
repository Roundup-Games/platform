<?php

use App\Enums\GameStatus;
use App\Enums\JoinSource;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Jobs\ProcessDiscordRsvp;
use App\Livewire\Games\ApplyToGame;
use App\Livewire\Games\GameDetail;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\User;
use App\Services\CapacityService;
use App\Services\Discord\DiscordPublisher;
use App\Services\Discord\DiscordWebhookClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * M057/S05/T02 — the signup cutoff (signup_cutoff_at) must gate NEW signups at
 * ALL THREE participant-write entry points: web apply (HandlesApplicationSubmission),
 * share-link join (GameDetail::joinViaShareLink), and the Discord button RSVP
 * (ProcessDiscordRsvp). A null or future cutoff preserves current behavior; a
 * past cutoff blocks. Waitlist auto-promotion (CapacityService::increase) is
 * intentionally NOT gated.
 *
 * These focused tests prove the integration closure (decision D124): a Discord
 * button RSVP is subject to the SAME cutoff as a web RSVP — no bypass.
 */
describe('web apply path (HandlesApplicationSubmission)', function () {
    it('blocks a new application when the signup cutoff has passed', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
            'max_players' => 6,
            'min_players' => 2,
            'campaign_id' => null,
            'signup_cutoff_at' => now()->subHour(),
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
        $applicant = User::factory()->create();

        Livewire::actingAs($applicant)
            ->test(ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'Pick me!')
            ->call('submitApplication');

        // No participant row, no application row — the cutoff is a hard gate.
        expect(GameParticipant::where('game_id', $game->id)->where('user_id', $applicant->id)->exists())->toBeFalse();
        expect($game->applications()->where('user_id', $applicant->id)->exists())->toBeFalse();
    });

    it('allows applications when the cutoff is in the future', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
            'max_players' => 6,
            'min_players' => 2,
            'campaign_id' => null,
            'signup_cutoff_at' => now()->addDays(5),
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
        $applicant = User::factory()->create();

        Livewire::actingAs($applicant)
            ->test(ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'Pick me!')
            ->call('submitApplication');

        // Public game with capacity → auto-approved participant.
        expect(GameParticipant::where('game_id', $game->id)->where('user_id', $applicant->id)->exists())->toBeTrue();
    });

    it('allows applications when no cutoff is set (backward compatible)', function () {
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
            'max_players' => 6,
            'min_players' => 2,
            'campaign_id' => null,
            'signup_cutoff_at' => null,
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
        $applicant = User::factory()->create();

        Livewire::actingAs($applicant)
            ->test(ApplyToGame::class, ['id' => $game->id])
            ->set('message', 'Pick me!')
            ->call('submitApplication');

        expect(GameParticipant::where('game_id', $game->id)->where('user_id', $applicant->id)->exists())->toBeTrue();
    });
});

describe('share-link join path (GameDetail::joinViaShareLink)', function () {
    it('blocks a share-link join when the signup cutoff has passed', function () {
        $owner = User::factory()->create();
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'share_token' => $token,
            'visibility' => 'protected',
            'max_players' => 5,
            'min_players' => 2,
            'campaign_id' => null,
            'date_time' => now()->addDays(10),
            'signup_cutoff_at' => now()->subHour(),
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
        $joiner = User::factory()->create();

        Livewire::actingAs($joiner)
            ->withQueryParams(['share' => $token])
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('joinViaShareLink');

        expect(GameParticipant::where('game_id', $game->id)->where('user_id', $joiner->id)->exists())->toBeFalse();
    });

    it('hides the Join button (canJoinViaShareLink false) once the cutoff has passed', function () {
        $owner = User::factory()->create();
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'share_token' => $token,
            'visibility' => 'protected',
            'max_players' => 5,
            'min_players' => 2,
            'campaign_id' => null,
            'date_time' => now()->addDays(10),
            'signup_cutoff_at' => now()->subHour(),
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
        $joiner = User::factory()->create();

        $component = Livewire::actingAs($joiner)
            ->withQueryParams(['share' => $token])
            ->test(GameDetail::class, ['id' => $game->id]);

        // canJoinViaShareLink is a #[Computed] on the component; instance()
        // returns the component so we can read the resolved computed value.
        expect($component->instance()->canJoinViaShareLink)->toBeFalse();
    });

    it('allows a share-link join when the cutoff is in the future', function () {
        $owner = User::factory()->create();
        $token = (string) Str::uuid();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'share_token' => $token,
            'visibility' => 'protected',
            'max_players' => 5,
            'min_players' => 2,
            'campaign_id' => null,
            'date_time' => now()->addDays(10),
            'signup_cutoff_at' => now()->addDays(5),
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
        $joiner = User::factory()->create();

        Livewire::actingAs($joiner)
            ->withQueryParams(['share' => $token])
            ->test(GameDetail::class, ['id' => $game->id])
            ->call('joinViaShareLink')
            ->assertHasNoErrors();

        $participant = GameParticipant::where('game_id', $game->id)->where('user_id', $joiner->id)->first();
        expect($participant)->not->toBeNull()
            ->and($participant->status)->toBe(ParticipantStatus::Approved);
    });
});

describe('Discord button-RSVP path (ProcessDiscordRsvp)', function () {
    beforeEach(function () {
        // Bind a REAL webhook client wired to Http::fake (no real network), so
        // the @original confirmation PATCH resolves without error. Mirrors the
        // ProcessDiscordRsvpTest harness.
        config([
            'services.discord.bot_application_id' => '111222333444555666', // gitleaks:allow — synthetic test-only Discord app id
            'services.discord.api_base_url' => 'https://discord.test/api/v10',
        ]);
        $this->app->instance(DiscordWebhookClient::class, new DiscordWebhookClient(
            baseUrl: 'https://discord.test/api/v10',
            botToken: 'test-bot-token',
            timeout: 5,
            maxAttempts: 1,
            maxRetryAfterSeconds: 30.0,
            serverErrorBackoffSeconds: 0.0,
            sleep: static fn (float $seconds) => null,
        ));
    });

    it('refuses a new Discord RSVP and logs signup_closed when the cutoff has passed', function () {
        Http::fake(fn (Request $request) => Http::response(['id' => 'orig-1'], 200));

        [$owner, $game, $clicker] = discordJoinableGame();
        $game->update(['signup_cutoff_at' => now()->subHour()]);

        // Record every Log::info call so we can assert the specific refused
        // event fired without fragility from mixed Mockery expectations.
        $refusedLogged = false;
        Log::shouldReceive('info')->zeroOrMoreTimes()->andReturnUsing(function (string $message, array $context = []) use (&$refusedLogged): void {
            if ($message === 'discord_rsvp.refused' && ($context['reason'] ?? null) === 'signup_closed') {
                $refusedLogged = true;
            }
        });
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        (new ProcessDiscordRsvp($game->id, $clicker->id, '999000111222333444', 'a-test-token'))
            ->handle(app(DiscordWebhookClient::class), app(DiscordPublisher::class));

        // No participant row — the cutoff is a hard gate that mirrors the web paths.
        expect(GameParticipant::where('game_id', $game->id)
            ->where('user_id', $clicker->id)
            ->where('join_source', JoinSource::Discord->value)
            ->exists())->toBeFalse();
        // The structured-log refused event must carry reason=signup_closed
        // (slice verification contract — mirrors owner/game_status reason keys).
        expect($refusedLogged)->toBeTrue('Expected discord_rsvp.refused {reason: signup_closed} to be logged');
    });

    it('approves a Discord RSVP when the cutoff is in the future', function () {
        Http::fake(fn (Request $request) => Http::response(['id' => 'orig-1'], 200));

        [$owner, $game, $clicker] = discordJoinableGame();
        $game->update(['signup_cutoff_at' => now()->addDays(5)]);

        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        (new ProcessDiscordRsvp($game->id, $clicker->id, '999000111222333444', 'a-test-token'))
            ->handle(app(DiscordWebhookClient::class), app(DiscordPublisher::class));

        $participant = GameParticipant::where('game_id', $game->id)
            ->where('user_id', $clicker->id)
            ->where('join_source', JoinSource::Discord->value)
            ->first();
        expect($participant)->not->toBeNull()
            ->and($participant->status)->toBe(ParticipantStatus::Approved);
    });
});

describe('waitlist auto-promotion (NOT gated by the cutoff)', function () {
    it('still promotes a waitlisted player when the cutoff has passed', function () {
        // D124: the cutoff gates NEW signups only. Auto-promotion from the
        // waitlist on a capacity increase flows through CapacityService::increase
        // → WaitlistService::promoteNext, which must NOT call signupHasClosed().
        $owner = User::factory()->create();
        $game = Game::factory()->create([
            'owner_id' => $owner->id,
            'visibility' => 'public',
            'max_players' => 1,
            'min_players' => 1,
            'campaign_id' => null,
            'signup_cutoff_at' => now()->subHour(),
        ]);
        GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'role' => ParticipantRole::Owner->value,
            'status' => ParticipantStatus::Approved->value,
        ]);
        // Waitlisted player (created BEFORE the cutoff, so legitimately on the roster).
        $waitlisted = GameParticipant::create([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'role' => ParticipantRole::Player->value,
            'status' => ParticipantStatus::Waitlisted->value,
            'waitlisted_at' => now()->subDay(),
        ]);

        // Grow the table — auto-promotion should fire despite the closed cutoff.
        app(CapacityService::class)->increase($game, 2);

        // promoteNext() moves a waitlisted player to Pending (pending the
        // spot confirmation), NOT Approved. The status changing away from
        // Waitlisted proves the promotion fired through the ungated path —
        // the cutoff never reached CapacityService::increase / WaitlistService.
        $waitlisted->refresh();
        expect($waitlisted->status)->toBe(ParticipantStatus::Pending);
    });
});

// ── Helpers ──────────────────────────────────────────────────────────────

/**
 * A joinable Discord-RSVP game: owner is the only approved participant, leaving
 * an open seat for the clicker. Mirrors ProcessDiscordRsvpTest::joinableGame().
 *
 * @return array{User, Game, User} [owner, game, clicker]
 */
function discordJoinableGame(): array
{
    $owner = User::factory()->create();
    $game = Game::factory()->create([
        'owner_id' => $owner->id,
        'campaign_id' => null,
        'max_players' => 4,
        'min_players' => 2,
        'status' => GameStatus::Scheduled->value,
        'visibility' => 'public',
        'signup_cutoff_at' => null,
    ]);
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $owner->id,
        'role' => ParticipantRole::Owner->value,
        'status' => ParticipantStatus::Approved->value,
    ]);
    $clicker = User::factory()->create();

    return [$owner, $game, $clicker];
}
