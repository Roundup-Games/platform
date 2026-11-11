<?php

namespace Tests\Feature\Mcp;

use App\Enums\GameStatus;
use App\Enums\JoinSource;
use App\Enums\McpAbility;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Mcp\Support\ConfirmationHandles;
use App\Mcp\Tools\GamesApplyTool;
use App\Mcp\Tools\GamesJoinTool;
use App\Mcp\Tools\GamesLeaveTool;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\McpCall;
use App\Models\User;
use App\Models\UserRelationship;
use App\Notifications\NewApplication;
use App\Notifications\WaitlistPromoted;
use App\Services\EntityJoinGate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Traits\InteractsWithMcp;

use function Pest\Laravel\assertDatabaseCount;

uses(InteractsWithMcp::class);

/*
|--------------------------------------------------------------------------
| Participant Write Tools (M079/S02/T02)
|--------------------------------------------------------------------------
|
| games.join / games.apply / games.leave, exercised through the real agent
| surface (bearer PAT + mcp_clients consumer → McpAuthentication →
| RoundupServer dispatch). Every web-pinned denial case of the join/apply/
| leave surfaces has a tool twin here: capacity → waitlist/bench placement,
| signup cutoff, already-participant (incl. host-removed rows), own-game,
| and visibility denials. Confirmation windows return a pending state with
| a single-use handle — the tool never confirms on the user's behalf.
|
*/

// Helpers carry a participantWrite prefix — other MCP suites define
// same-shaped globals and a collision is fatal in one Pest process.

/**
 * @return array<string, mixed>
 */
function participantWritePayload(TestResponse $response): array
{
    return json_decode((string) $response->json('result.content.0.text'), true);
}

function participantWriteGame(array $overrides = []): Game
{
    return Game::factory()->create(array_merge([
        'date_time' => now()->addDays(7),
        'language' => 'en',
        'max_players' => 4,
    ], $overrides));
}

function participantWriteOwnerRow(Game $game, User $owner): GameParticipant
{
    return GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $owner->id,
        'role' => ParticipantRole::Owner->value,
        'status' => ParticipantStatus::Approved->value,
    ]);
}

function participantWriteRow(Game $game, User $user, ParticipantStatus $status, ParticipantRole $role = ParticipantRole::Player, array $extra = []): GameParticipant
{
    return GameParticipant::create(array_merge([
        'game_id' => $game->id,
        'user_id' => $user->id,
        'role' => $role->value,
        'status' => $status->value,
    ], $extra));
}

function participantWriteFriend(User $a, User $b): void
{
    UserRelationship::follow($a, $b, notify: false);
    UserRelationship::follow($b, $a, notify: false);
}

// ═══════════════════════════════════════════════════════════
// games.join — happy paths
// ═══════════════════════════════════════════════════════════

test('games.join joins an open game as an approved participant with mcp_agent attribution', function () {
    $user = User::factory()->create();
    $game = participantWriteGame();
    participantWriteOwnerRow($game, $game->owner);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesJoinTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], GamesJoinTool::NAME);

    $response->assertStatus(200)->assertJsonPath('result.isError', false);

    $participant = GameParticipant::where('game_id', $game->id)->where('user_id', $user->id)->firstOrFail();

    expect($participant->status)->toBe(ParticipantStatus::Approved)
        ->and($participant->role)->toBe(ParticipantRole::Player)
        ->and($participant->join_source)->toBe(JoinSource::McpAgent)
        ->and($participant->approved_at)->not->toBeNull();

    $payload = participantWritePayload($response);

    expect($payload['status'])->toBe('joined')
        ->and($payload['game']['id'])->toBe($game->id)
        ->and($payload['seats']['taken'])->toBe(2)
        ->and($payload['seats']['max'])->toBe(4)
        ->and($payload['seats']['available'])->toBe(2);
});

test('games.join waitlists a full standalone game and reports the queue position', function () {
    $user = User::factory()->create();
    $game = participantWriteGame(['max_players' => 1]);
    participantWriteOwnerRow($game, $game->owner);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesJoinTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], GamesJoinTool::NAME);

    $response->assertStatus(200)->assertJsonPath('result.isError', false);

    $participant = GameParticipant::where('game_id', $game->id)->where('user_id', $user->id)->firstOrFail();

    expect($participant->status)->toBe(ParticipantStatus::Waitlisted)
        ->and($participant->waitlisted_at)->not->toBeNull();

    $payload = participantWritePayload($response);

    expect($payload['status'])->toBe('waitlisted')
        ->and($payload['queue_position'])->toBe(1);
});

test('games.join benches a full bench-mode campaign session', function () {
    $user = User::factory()->create();
    $campaign = Campaign::factory()->create(['bench_mode' => true]);
    $game = participantWriteGame(['max_players' => 1, 'campaign_id' => $campaign->id]);
    participantWriteOwnerRow($game, $game->owner);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesJoinTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], GamesJoinTool::NAME);

    $response->assertStatus(200)->assertJsonPath('result.isError', false);

    $participant = GameParticipant::where('game_id', $game->id)->where('user_id', $user->id)->firstOrFail();

    expect($participant->status)->toBe(ParticipantStatus::Benched)
        ->and($participant->benched_at)->not->toBeNull();

    expect(participantWritePayload($response)['status'])->toBe('benched');
});

test('games.join reactivates a departed row on rejoin after a self-leave', function () {
    $user = User::factory()->create();
    $game = participantWriteGame();
    participantWriteOwnerRow($game, $game->owner);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $join = fn (): TestResponse => $this->postMcp($bearer, 'tools/call', [
        'name' => GamesJoinTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], GamesJoinTool::NAME);

    $join();
    $this->postMcp($bearer, 'tools/call', [
        'name' => GamesLeaveTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], GamesLeaveTool::NAME);
    $join();

    expect(GameParticipant::where('game_id', $game->id)->where('user_id', $user->id)->count())->toBe(1)
        ->and(GameParticipant::where('game_id', $game->id)->where('user_id', $user->id)->firstOrFail()->status)
        ->toBe(ParticipantStatus::Approved);
});

// ═══════════════════════════════════════════════════════════
// games.join — denial cases (web feature-test twins)
// ═══════════════════════════════════════════════════════════

test('games.join refuses a past signup cutoff with a structured error and writes nothing', function () {
    $user = User::factory()->create();
    $game = participantWriteGame(['signup_cutoff_at' => now()->subHour()]);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesJoinTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], GamesJoinTool::NAME);

    $response->assertStatus(200)->assertJsonPath('result.isError', true);
    expect($response->json('result.content.0.text'))->toBe('signup_closed: Signups for this game are closed.');

    assertDatabaseCount('game_participants', 0);
});

test('games.join refuses an existing participant without touching their row', function () {
    $user = User::factory()->create();
    $game = participantWriteGame();
    participantWriteRow($game, $user, ParticipantStatus::Approved);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesJoinTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], GamesJoinTool::NAME);

    $response->assertStatus(200)->assertJsonPath('result.isError', true);
    expect($response->json('result.content.0.text'))->toBe('already_participant: You already hold a spot on this game.');

    $row = GameParticipant::where('game_id', $game->id)->where('user_id', $user->id)->firstOrFail();

    expect($row->status)->toBe(ParticipantStatus::Approved);
});

test('games.join refuses a host-removed participant from rejoining', function () {
    $user = User::factory()->create();
    $game = participantWriteGame();
    participantWriteRow($game, $user, ParticipantStatus::Removed);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesJoinTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], GamesJoinTool::NAME);

    $response->assertStatus(200)->assertJsonPath('result.isError', true);
    expect($response->json('result.content.0.text'))->toBe('already_participant: You already hold a spot on this game.');

    expect(GameParticipant::where('game_id', $game->id)->where('user_id', $user->id)->count())->toBe(1);
});

test('games.join refuses the owner their own game', function () {
    $user = User::factory()->create();
    $game = participantWriteGame(['owner_id' => $user->id]);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesJoinTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], GamesJoinTool::NAME);

    $response->assertStatus(200)->assertJsonPath('result.isError', true);
    expect($response->json('result.content.0.text'))->toBe('own_game: You host this game and cannot join it.');

    assertDatabaseCount('game_participants', 0);
});

test('games.join hides invisible games with one byte-identical not-found error', function () {
    $user = User::factory()->create();
    $private = participantWriteGame(['visibility' => Visibility::Private->value]);
    $protected = participantWriteGame(['visibility' => Visibility::Protected->value]);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $call = fn (string $id): string => $this->postMcp($bearer, 'tools/call', [
        'name' => GamesJoinTool::NAME,
        'arguments' => ['game_id' => $id],
    ], GamesJoinTool::NAME)->assertStatus(200)->assertJsonPath('result.isError', true)->json('result.content.0.text');

    $unknown = $call('00000000-0000-0000-0000-000000000000');

    expect($call($private->id))->toBe($unknown)
        ->and($call($protected->id))->toBe($unknown)
        ->and($unknown)->toBe('not_found: Game not found.');

    assertDatabaseCount('game_participants', 0);
});

test('games.join refuses canceled games', function () {
    $user = User::factory()->create();
    $game = participantWriteGame(['status' => GameStatus::Canceled->value]);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesJoinTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], GamesJoinTool::NAME);

    $response->assertStatus(200)->assertJsonPath('result.isError', true);
    expect($response->json('result.content.0.text'))->toBe('game_unavailable: This game is completed or canceled and no longer takes signups.');
});

test('games.join redirects protected games visible to the acting user to games.apply', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();
    participantWriteFriend($user, $owner);
    $game = participantWriteGame(['owner_id' => $owner->id, 'visibility' => Visibility::Protected->value]);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesJoinTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], GamesJoinTool::NAME);

    $response->assertStatus(200)->assertJsonPath('result.isError', true);
    expect($response->json('result.content.0.text'))->toBe('application_required: This game screens participants. Use the games.apply tool.');

    assertDatabaseCount('game_participants', 0);
});

// ═══════════════════════════════════════════════════════════
// games.join — confirmation window + idempotency + scope
// ═══════════════════════════════════════════════════════════

test('games.join surfaces an open waitlist promotion as a pending state with a single-use handle and never confirms', function () {
    $user = User::factory()->create();
    $game = participantWriteGame();
    participantWriteRow($game, $user, ParticipantStatus::Pending, extra: [
        'confirmation_expires_at' => now()->addHours(6),
        'confirmation_attempts' => 1,
    ]);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesJoinTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], GamesJoinTool::NAME);

    // A pending state, not an error — and not a confirmation.
    $response->assertStatus(200)->assertJsonPath('result.isError', false);

    $payload = participantWritePayload($response);
    $handles = app(ConfirmationHandles::class);

    expect($payload['status'])->toBe('confirmation_required')
        ->and($payload['confirmation_handle'])->toStartWith(ConfirmationHandles::PREFIX)
        ->and($payload['participant_status'])->toBe('pending');

    // The row was never confirmed.
    expect(GameParticipant::where('game_id', $game->id)->where('user_id', $user->id)->firstOrFail()->status)
        ->toBe(ParticipantStatus::Pending);

    // Regeneration invalidates the predecessor: a retry without an
    // idempotency key mints a fresh handle and the old one dies.
    $second = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesJoinTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], GamesJoinTool::NAME);
    $secondHandle = participantWritePayload($second)['confirmation_handle'];

    expect($secondHandle)->not->toBe($payload['confirmation_handle']);

    // Single-use: each handle resolves exactly once, the superseded one
    // not at all.
    expect($handles->consume($payload['confirmation_handle']))->toBeNull()
        ->and($handles->consume($secondHandle))->toBe([
            'user_id' => $user->id,
            'participant_id' => GameParticipant::where('game_id', $game->id)->where('user_id', $user->id)->firstOrFail()->id,
            'game_id' => $game->id,
        ])
        ->and($handles->consume($secondHandle))->toBeNull();
});

test('a retried games.join with the same idempotency key replays the first answer without a second write', function () {
    $user = User::factory()->create();
    $game = participantWriteGame();
    participantWriteOwnerRow($game, $game->owner);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $arguments = ['game_id' => $game->id, 'idempotency_key' => 'agent-retry-1'];

    $first = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesJoinTool::NAME,
        'arguments' => $arguments,
    ], GamesJoinTool::NAME)->assertStatus(200)->assertJsonPath('result.isError', false);

    $retry = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesJoinTool::NAME,
        'arguments' => $arguments,
    ], GamesJoinTool::NAME)->assertStatus(200)->assertJsonPath('result.isError', false);

    expect($retry->json('result.content.0.text'))->toBe($first->json('result.content.0.text'))
        ->and(GameParticipant::where('game_id', $game->id)->where('user_id', $user->id)->count())->toBe(1);
});

test('games.join enforces the per-entity MCP join throttle without burning the web surfaces\' buckets', function () {
    $owner = User::factory()->create();
    $game = participantWriteGame(['owner_id' => $owner->id]);
    participantWriteOwnerRow($game, $owner);
    $joiner = User::factory()->create();

    [, $bearer] = $this->mcpConsumer($joiner, [McpAbility::Write->value]);

    // Exhaust the agent bucket for (joiner, game) — the same key the tool
    // consumes (5 attempts per viewer per entity per minute, web parity).
    $gate = app(EntityJoinGate::class);

    for ($i = 0; $i < EntityJoinGate::JOIN_ATTEMPT_LIMIT; $i++) {
        expect($gate->allowsMcpJoinAttempt($game, $joiner))->toBeTrue();
    }

    $this->postMcp($bearer, 'tools/call', [
        'name' => GamesJoinTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], GamesJoinTool::NAME)
        ->assertStatus(200)
        ->assertJsonPath('result.isError', true)
        ->assertSee('rate_limited');

    // The throttled attempt never reached the participant intake.
    expect(GameParticipant::where('game_id', $game->id)->where('user_id', $joiner->id)->exists())->toBeFalse()

        // Bucket isolation: the agent's attempts share the limit shape but
        // not the budget — the web surfaces' buckets are untouched.
        ->and($gate->allowsShareJoinAttempt($game, $joiner))->toBeTrue()
        ->and($gate->allowsDiscordJoinAttempt($game, $joiner))->toBeTrue();
});

test('write tools refuse a read-only token with the write ability named', function () {
    $user = User::factory()->create();
    $game = participantWriteGame();

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Read->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesJoinTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], GamesJoinTool::NAME);

    $response->assertStatus(403);
    expect($response->json('error.message'))->toContain(McpAbility::Write->value);

    assertDatabaseCount('game_participants', 0);
});

// ═══════════════════════════════════════════════════════════
// games.apply
// ═══════════════════════════════════════════════════════════

test('games.apply submits a protected-game application, lands pending, and notifies the host', function () {
    Notification::fake();

    $user = User::factory()->create();
    $owner = User::factory()->create();
    participantWriteFriend($user, $owner);
    $game = participantWriteGame(['owner_id' => $owner->id, 'visibility' => Visibility::Protected->value]);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesApplyTool::NAME,
        'arguments' => ['game_id' => $game->id, 'message' => 'I would love to join this table.'],
    ], GamesApplyTool::NAME);

    $response->assertStatus(200)->assertJsonPath('result.isError', false);

    $participant = GameParticipant::where('game_id', $game->id)->where('user_id', $user->id)->firstOrFail();
    $application = $game->applications()->where('user_id', $user->id)->firstOrFail();

    expect($participant->status)->toBe(ParticipantStatus::Pending)
        ->and($participant->role)->toBe(ParticipantRole::Applicant)
        ->and($participant->join_source)->toBe(JoinSource::Application)
        ->and($application->status)->toBe('pending')
        ->and($application->message)->toBe('I would love to join this table.');

    $payload = participantWritePayload($response);

    expect($payload['status'])->toBe('application_submitted')
        ->and($payload['participant_status'])->toBe('pending');

    // Same NotificationService path as the web apply surface.
    Notification::assertSentTo($owner, NewApplication::class);
});

test('games.apply refuses the owner their own game', function () {
    $user = User::factory()->create();
    $game = participantWriteGame(['owner_id' => $user->id, 'visibility' => Visibility::Protected->value]);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesApplyTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], GamesApplyTool::NAME);

    $response->assertStatus(200)->assertJsonPath('result.isError', true);
    expect($response->json('result.content.0.text'))->toBe('own_game: You host this game and cannot apply to it.');
});

test('games.apply refuses a duplicate application', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();
    participantWriteFriend($user, $owner);
    $game = participantWriteGame(['owner_id' => $owner->id, 'visibility' => Visibility::Protected->value]);

    $game->applications()->create([
        'user_id' => $user->id,
        'status' => 'pending',
        'message' => null,
    ]);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesApplyTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], GamesApplyTool::NAME);

    $response->assertStatus(200)->assertJsonPath('result.isError', true);
    expect($response->json('result.content.0.text'))->toBe('already_applied: You have already applied to this game.');

    expect($game->applications()->where('user_id', $user->id)->count())->toBe(1);
});

test('games.apply refuses an existing participant', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();
    participantWriteFriend($user, $owner);
    $game = participantWriteGame(['owner_id' => $owner->id, 'visibility' => Visibility::Protected->value]);
    participantWriteRow($game, $user, ParticipantStatus::Approved);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesApplyTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], GamesApplyTool::NAME);

    $response->assertStatus(200)->assertJsonPath('result.isError', true);
    expect($response->json('result.content.0.text'))->toBe('already_participant: You already hold a spot on this game.');
});

test('games.apply directs open games to games.join', function () {
    $user = User::factory()->create();
    $game = participantWriteGame();

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesApplyTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], GamesApplyTool::NAME);

    $response->assertStatus(200)->assertJsonPath('result.isError', true);
    expect($response->json('result.content.0.text'))->toBe('game_open: This game is open to join directly. Use the games.join tool.');

    assertDatabaseCount('game_participants', 0);
});

test('games.apply hides private and unknown games with the identical not-found error', function () {
    $user = User::factory()->create();
    $private = participantWriteGame(['visibility' => Visibility::Private->value]);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $call = fn (string $id): string => $this->postMcp($bearer, 'tools/call', [
        'name' => GamesApplyTool::NAME,
        'arguments' => ['game_id' => $id],
    ], GamesApplyTool::NAME)->assertStatus(200)->assertJsonPath('result.isError', true)->json('result.content.0.text');

    $unknown = $call('00000000-0000-0000-0000-000000000000');

    expect($call($private->id))->toBe($unknown)
        ->and($unknown)->toBe('not_found: Game not found.');
});

test('games.apply refuses a past signup cutoff', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();
    participantWriteFriend($user, $owner);
    $game = participantWriteGame([
        'owner_id' => $owner->id,
        'visibility' => Visibility::Protected->value,
        'signup_cutoff_at' => now()->subHour(),
    ]);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesApplyTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], GamesApplyTool::NAME);

    $response->assertStatus(200)->assertJsonPath('result.isError', true);
    expect($response->json('result.content.0.text'))->toBe('signup_closed: Signups for this game are closed.');

    assertDatabaseCount('game_participants', 0);
});

test('games.apply reactivates a departed participant row and clears stale applications', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();
    participantWriteFriend($user, $owner);
    $game = participantWriteGame(['owner_id' => $owner->id, 'visibility' => Visibility::Protected->value]);
    participantWriteRow($game, $user, ParticipantStatus::Rejected);

    $game->applications()->create([
        'user_id' => $user->id,
        'status' => 'approved',
        'message' => null,
    ]);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesApplyTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], GamesApplyTool::NAME);

    $response->assertStatus(200)->assertJsonPath('result.isError', false);

    expect(GameParticipant::where('game_id', $game->id)->where('user_id', $user->id)->count())->toBe(1)
        ->and(GameParticipant::where('game_id', $game->id)->where('user_id', $user->id)->firstOrFail()->status)
        ->toBe(ParticipantStatus::Pending);

    $application = $game->applications()->where('user_id', $user->id)->firstOrFail();

    expect($application->status)->toBe('pending');
});

test('application free text never reaches audit rows or responses', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();
    participantWriteFriend($user, $owner);
    $game = participantWriteGame(['owner_id' => $owner->id, 'visibility' => Visibility::Protected->value]);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesApplyTool::NAME,
        'arguments' => ['game_id' => $game->id, 'message' => 'confidential-note-9f2c1a'],
    ], GamesApplyTool::NAME);

    $response->assertStatus(200)->assertJsonPath('result.isError', false);
    expect($response->getContent())->not->toContain('confidential-note-9f2c1a');

    $auditRows = McpCall::query()->where('tool', GamesApplyTool::NAME)->get();

    expect($auditRows)->not->toBeEmpty();

    foreach ($auditRows as $row) {
        expect(json_encode($row->getAttributes()))->not->toContain('confidential-note-9f2c1a');
    }
});

// ═══════════════════════════════════════════════════════════
// games.leave
// ═══════════════════════════════════════════════════════════

test('games.leave departs an approved participant with audit stamps', function () {
    $user = User::factory()->create();
    $game = participantWriteGame();
    participantWriteOwnerRow($game, $game->owner);
    participantWriteRow($game, $user, ParticipantStatus::Approved);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesLeaveTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], GamesLeaveTool::NAME);

    $response->assertStatus(200)->assertJsonPath('result.isError', false);

    $participant = GameParticipant::where('game_id', $game->id)->where('user_id', $user->id)->firstOrFail();

    expect($participant->status)->toBe(ParticipantStatus::Rejected)
        ->and($participant->removed_by)->toBe($user->id)
        ->and($participant->removed_at)->not->toBeNull();

    $payload = participantWritePayload($response);

    expect($payload['status'])->toBe('left')
        ->and($payload['previous_status'])->toBe('approved')
        ->and($payload['seats']['taken'])->toBe(1);
});

test('games.leave refuses the owner their own game', function () {
    $user = User::factory()->create();
    $game = participantWriteGame(['owner_id' => $user->id]);
    participantWriteOwnerRow($game, $user);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesLeaveTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], GamesLeaveTool::NAME);

    $response->assertStatus(200)->assertJsonPath('result.isError', true);
    expect($response->json('result.content.0.text'))->toBe('own_game: You host this game and cannot leave it. Cancel the game instead.');
});

test('games.leave refuses a non-participant', function () {
    $user = User::factory()->create();
    $game = participantWriteGame();
    participantWriteOwnerRow($game, $game->owner);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $call = fn (): TestResponse => $this->postMcp($bearer, 'tools/call', [
        'name' => GamesLeaveTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], GamesLeaveTool::NAME);

    $call()->assertStatus(200)->assertJsonPath('result.isError', true);
    expect($call()->json('result.content.0.text'))->toBe('not_a_participant: You are not a participant of this game.');
});

test('games.leave refuses a user who already departed', function () {
    $user = User::factory()->create();
    $game = participantWriteGame();
    participantWriteRow($game, $user, ParticipantStatus::Rejected);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesLeaveTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], GamesLeaveTool::NAME);

    $response->assertStatus(200)->assertJsonPath('result.isError', true);
    expect($response->json('result.content.0.text'))->toBe('not_a_participant: You are not a participant of this game.');
});

test('games.leave frees the seat and promotes the next waitlisted player into a confirmation window', function () {
    Notification::fake();

    $leaver = User::factory()->create();
    $waitlisted = User::factory()->create();
    $game = participantWriteGame(['max_players' => 2]);
    participantWriteOwnerRow($game, $game->owner);
    participantWriteRow($game, $leaver, ParticipantStatus::Approved);
    participantWriteRow($game, $waitlisted, ParticipantStatus::Waitlisted, extra: ['waitlisted_at' => now()->subMinutes(5)]);

    [, $bearer] = $this->mcpConsumer($leaver, [McpAbility::Write->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesLeaveTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], GamesLeaveTool::NAME);

    $response->assertStatus(200)->assertJsonPath('result.isError', false);

    $promoted = GameParticipant::where('game_id', $game->id)->where('user_id', $waitlisted->id)->firstOrFail();
    $departed = GameParticipant::where('game_id', $game->id)->where('user_id', $leaver->id)->firstOrFail();

    // The freed seat cascaded exactly like the web leave: promotion into
    // a Pending confirmation window plus the promotion notification.
    expect($departed->status)->toBe(ParticipantStatus::Rejected)
        ->and($promoted->status)->toBe(ParticipantStatus::Pending)
        ->and($promoted->confirmation_expires_at)->not->toBeNull()
        ->and($promoted->confirmation_expires_at->isFuture())->toBeTrue();

    Notification::assertSentTo($waitlisted, WaitlistPromoted::class);
});

test('games.leave records a late cancel inside the late-cancel window', function () {
    $user = User::factory()->create();
    $game = participantWriteGame(['date_time' => now()->addHours(2)]);
    participantWriteOwnerRow($game, $game->owner);
    participantWriteRow($game, $user, ParticipantStatus::Approved);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $this->postMcp($bearer, 'tools/call', [
        'name' => GamesLeaveTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], GamesLeaveTool::NAME)->assertStatus(200);

    $participant = GameParticipant::where('game_id', $game->id)->where('user_id', $user->id)->firstOrFail();

    expect($participant->attendance_status?->value)->toBe('late_cancel');
});

test('games.leave lets an applicant withdraw their pending application', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();
    $game = participantWriteGame(['owner_id' => $owner->id, 'visibility' => Visibility::Protected->value]);
    participantWriteRow($game, $user, ParticipantStatus::Pending, ParticipantRole::Applicant);

    $game->applications()->create([
        'user_id' => $user->id,
        'status' => 'pending',
        'message' => 'please',
    ]);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $this->postMcp($bearer, 'tools/call', [
        'name' => GamesLeaveTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], GamesLeaveTool::NAME)->assertStatus(200)->assertJsonPath('result.isError', false);

    $participant = GameParticipant::where('game_id', $game->id)->where('user_id', $user->id)->firstOrFail();

    expect($participant->status)->toBe(ParticipantStatus::Rejected)
        ->and($game->applications()->where('user_id', $user->id)->count())->toBe(0);
});

test('games.leave hides invisible games with the identical not-found error', function () {
    $user = User::factory()->create();
    $private = participantWriteGame(['visibility' => Visibility::Private->value]);

    [, $bearer] = $this->mcpConsumer($user, [McpAbility::Write->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesLeaveTool::NAME,
        'arguments' => ['game_id' => $private->id],
    ], GamesLeaveTool::NAME);

    $response->assertStatus(200)->assertJsonPath('result.isError', true);
    expect($response->json('result.content.0.text'))->toBe('not_found: Game not found.');
});
