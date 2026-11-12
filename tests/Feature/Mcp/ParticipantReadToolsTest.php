<?php

namespace Tests\Feature\Mcp;

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Enums\McpAbility;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Enums\Visibility;
use App\Mcp\Tools\GamesDetailTool;
use App\Mcp\Tools\GamesSearchTool;
use App\Mcp\Tools\MyDashboardTool;
use App\Mcp\Tools\MySessionsTool;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\Location;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Traits\InteractsWithMcp;

use function Pest\Laravel\assertDatabaseCount;

uses(InteractsWithMcp::class);

/*
|--------------------------------------------------------------------------
| Participant Read Tools (M079/S02/T01)
|--------------------------------------------------------------------------
|
| The four read-only participant tools, exercised through the real agent
| surface (bearer PAT + mcp_clients consumer → McpAuthentication →
| RoundupServer dispatch). Visibility pins assert the same scoping the
| web feed uses: private games are invisible everywhere they should be,
| and games.detail answers "not found" identically for invisible and
| unknown ids so hidden games are never confirmed to exist.
|
| All fixtures use the default factory language ('en') because
| games.search defaults its language filter to the acting user's
| preferred language (else the app locale, 'en' in tests) — web parity.
|
*/

// Helpers carry a participantRead prefix — other MCP suites define
// same-shaped globals and a collision is fatal in one Pest process.
function participantReadPayload(TestResponse $response): array
{
    return json_decode((string) $response->json('result.content.0.text'), true);
}

function participantReadUpcomingGame(array $overrides = []): Game
{
    return Game::factory()->create(array_merge([
        'date_time' => now()->addDays(7),
        'language' => 'en',
    ], $overrides));
}

// ═══════════════════════════════════════════════════════════
// games.search
// ═══════════════════════════════════════════════════════════

test('games search returns compact public rows with the web feed scoping', function () {
    $user = User::factory()->create();

    $visible = participantReadUpcomingGame(['name' => ['en' => 'Public Board Night']]);
    participantReadUpcomingGame(['name' => ['en' => 'Second Public Game']]);

    // Hidden shapes: another owner's private game (never in listings, even
    // to its own owner — discovery parity) and a canceled public game.
    participantReadUpcomingGame(['visibility' => Visibility::Private->value, 'name' => ['en' => 'Private Game']]);
    participantReadUpcomingGame(['status' => GameStatus::Canceled->value, 'name' => ['en' => 'Canceled Game']]);
    participantReadUpcomingGame(['date_time' => now()->subDays(2), 'name' => ['en' => 'Past Game']]);

    [, $bearer] = $this->mcpConsumer($user);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesSearchTool::NAME,
        'arguments' => (object) [],
    ], GamesSearchTool::NAME);

    $response->assertStatus(200)->assertJsonPath('result.isError', false);

    $payload = participantReadPayload($response);

    expect($payload['total'])->toBe(2)
        ->and($payload['page'])->toBe(1)
        ->and($payload['limit'])->toBe(10)
        ->and($payload['has_more'])->toBeFalse()
        ->and($payload['proximity'])->toBe(['radius_km' => 0, 'origin' => 'none']);

    $row = collect($payload['games'])->firstWhere('id', $visible->id);

    expect($row)->not->toBeNull()
        ->and($row['name'])->toBe('Public Board Night')
        ->and($row['date_time'])->toBe($visible->date_time->toIso8601String())
        ->and($row['type'])->toBe('board_game')
        ->and($row['systems'])->toBeArray()
        ->and($row['seats'])->toHaveKeys(['taken', 'max', 'available', 'waitlisted', 'benched'])
        ->and($row['url'])->toBe(route('games.detail', $visible));

    // Compact by contract: no descriptions, no full entities in rows.
    expect($row)->not->toHaveKey('description');

    $ids = collect($payload['games'])->pluck('id');
    expect($ids)->not->toContain(Game::query()->where('name->en', 'Private Game')->first()->id);
});

test('games search includes protected games the user participates in and hides the rest', function () {
    $user = User::factory()->create();

    // Protected + approved participant of the acting user → visible (web parity).
    $joined = participantReadUpcomingGame(['visibility' => Visibility::Protected->value, 'name' => ['en' => 'Friends Table I Joined']]);
    GameParticipant::create([
        'game_id' => $joined->id,
        'user_id' => $user->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    // Protected + stranger-owned (no friendship, no participation) → hidden.
    participantReadUpcomingGame(['visibility' => Visibility::Protected->value, 'name' => ['en' => 'Strangers Friends Table']]);

    // Private + own game → still never in discovery listings.
    participantReadUpcomingGame(['owner_id' => $user->id, 'visibility' => Visibility::Private->value, 'name' => ['en' => 'My Private Table']]);

    [, $bearer] = $this->mcpConsumer($user);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesSearchTool::NAME,
        'arguments' => (object) [],
    ], GamesSearchTool::NAME);

    $payload = participantReadPayload($response->assertStatus(200));

    expect($payload['total'])->toBe(1)
        ->and($payload['games'][0]['id'])->toBe($joined->id);
});

test('games search radius filters around the user saved profile location', function () {
    $berlin = Location::factory()->create([
        'city' => 'Berlin',
        'country' => 'DEU',
        'latitude' => 52.5200,
        'longitude' => 13.4050,
    ]);
    $koeln = Location::factory()->create([
        'city' => 'Koeln',
        'country' => 'DEU',
        'latitude' => 50.9375,
        'longitude' => 6.9603,
    ]);

    $user = User::factory()->create(['location_id' => $berlin->id]);

    participantReadUpcomingGame(['location_id' => $berlin->id, 'name' => ['en' => 'Berlin Game']]);
    participantReadUpcomingGame(['location_id' => $koeln->id, 'name' => ['en' => 'Koeln Game']]);

    [, $bearer] = $this->mcpConsumer($user);

    $nearby = participantReadPayload($this->postMcp($bearer, 'tools/call', [
        'name' => GamesSearchTool::NAME,
        'arguments' => (object) ['radius' => 25],
    ], GamesSearchTool::NAME)->assertStatus(200));

    expect($nearby['proximity'])->toBe(['radius_km' => 25, 'origin' => 'profile-location'])
        ->and($nearby['total'])->toBe(1)
        ->and($nearby['games'][0]['name'])->toBe('Berlin Game');

    // radius 0 disables the proximity constraint — the far game returns.
    $unfiltered = participantReadPayload($this->postMcp($bearer, 'tools/call', [
        'name' => GamesSearchTool::NAME,
        'arguments' => (object) ['radius' => 0],
    ], GamesSearchTool::NAME)->assertStatus(200));

    expect($unfiltered['total'])->toBe(2);
});

test('games search defaults the language filter to the user preferred language', function () {
    $user = User::factory()->create(['preferred_language' => 'de']);

    participantReadUpcomingGame(['language' => 'de', 'name' => ['en' => 'German Table', 'de' => 'German Table']]);
    participantReadUpcomingGame(['language' => 'en', 'name' => ['en' => 'English Table', 'de' => 'English Table']]);

    [, $bearer] = $this->mcpConsumer($user);

    $default = participantReadPayload($this->postMcp($bearer, 'tools/call', [
        'name' => GamesSearchTool::NAME,
        'arguments' => (object) [],
    ], GamesSearchTool::NAME)->assertStatus(200));

    expect($default['total'])->toBe(1)
        ->and($default['games'][0]['name'])->toBe('German Table');

    // An explicit language overrides the preference, like the website filter.
    $explicit = participantReadPayload($this->postMcp($bearer, 'tools/call', [
        'name' => GamesSearchTool::NAME,
        'arguments' => (object) ['language' => 'en'],
    ], GamesSearchTool::NAME)->assertStatus(200));

    expect($explicit['total'])->toBe(1)
        ->and($explicit['games'][0]['name'])->toBe('English Table');
});

test('games search rejects invalid filter values with a structured tool error', function () {
    [, $bearer] = $this->mcpConsumer(User::factory()->create());

    foreach ([
        ['radius' => 33],
        ['experience' => 'l33t'],
        ['q' => str_repeat('x', 201)],
        ['game_system' => 'not-a-uuid'],
    ] as $arguments) {
        $response = $this->postMcp($bearer, 'tools/call', [
            'name' => GamesSearchTool::NAME,
            'arguments' => (object) $arguments,
        ], GamesSearchTool::NAME);

        $response->assertStatus(200)->assertJsonPath('result.isError', true);
    }
});

// ═══════════════════════════════════════════════════════════
// games.detail
// ═══════════════════════════════════════════════════════════

test('games detail returns date venue seats safety tools and systems', function () {
    $owner = User::factory()->create();
    $venue = Location::factory()->create([
        'city' => 'Berlin',
        'country' => 'DEU',
        'latitude' => 52.5200,
        'longitude' => 13.4050,
        'venue_type' => null,
        'is_verified' => false,
    ]);

    $game = Game::factory()->create([
        'owner_id' => $owner->id,
        'location_id' => $venue->id,
        'max_players' => 4,
        'safety_rules' => [
            'tools' => ['x-card', 'lines-and-veils', 'made-up-tool'],
            'lines_and_veils_text' => 'SECRET-LINES-TEXT',
            'comfort_notes' => 'SECRET-COMFORT-NOTES',
        ],
        'signup_cutoff_at' => now()->addDays(2),
    ]);

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => User::factory()->create()->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    $stranger = User::factory()->create();
    [, $bearer] = $this->mcpConsumer($stranger);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesDetailTool::NAME,
        'arguments' => (object) ['game_id' => $game->id],
    ], GamesDetailTool::NAME);

    $response->assertStatus(200)->assertJsonPath('result.isError', false);

    $payload = participantReadPayload($response);

    expect($payload['id'])->toBe($game->id)
        ->and($payload['date_time'])->toBe($game->date_time->toIso8601String())
        ->and($payload['status'])->toBe('scheduled')
        ->and($payload['visibility'])->toBe('public')
        ->and($payload['systems'])->toBeArray()->not->toBeEmpty()
        ->and($payload['safety_tools'])->toBe(['x-card', 'lines-and-veils'])
        ->and($payload['seats'])->toBe([
            'taken' => 1,
            'max' => 4,
            'available' => 3,
            'waitlisted' => 0,
            'benched' => 0,
        ])
        ->and($payload['signup_cutoff_at'])->toBe($game->signup_cutoff_at->toIso8601String())
        ->and($payload['is_participating'])->toBeFalse()
        ->and($payload['online'])->toBeFalse();

    // Graduated disclosure: an unrelated viewer of a private venue gets the
    // area rung — no city, no address, never raw location attributes.
    expect($payload['venue'])->toBe(['disclosure_level' => 'area']);

    // Free-text safety notes never reach the agent surface.
    $raw = (string) $response->json('result.content.0.text');
    expect($raw)->not->toContain('SECRET');
});

test('games detail exposes the exact venue to the game owner and for verified public venues', function () {
    // Owner of a game at a private venue → exact rung.
    $privateVenue = Location::factory()->create([
        'city' => 'Berlin',
        'country' => 'DEU',
        'latitude' => 52.5200,
        'longitude' => 13.4050,
        'venue_type' => null,
        'is_verified' => false,
    ]);
    $owner = User::factory()->create();
    $ownGame = participantReadUpcomingGame([
        'owner_id' => $owner->id,
        'location_id' => $privateVenue->id,
    ]);

    [, $ownerBearer] = $this->mcpConsumer($owner);

    $ownerView = participantReadPayload($this->postMcp($ownerBearer, 'tools/call', [
        'name' => GamesDetailTool::NAME,
        'arguments' => (object) ['game_id' => $ownGame->id],
    ], GamesDetailTool::NAME)->assertStatus(200));

    expect($ownerView['venue']['disclosure_level'])->toBe('exact')
        ->and($ownerView['venue']['city'])->toBe('Berlin')
        ->and($ownerView['venue']['address'])->toBe($privateVenue->fullAddress());

    // A verified commercial venue is exact for everyone, with its public name.
    $cafe = Location::factory()->create([
        'city' => 'Hamburg',
        'country' => 'DEU',
        'latitude' => 53.5511,
        'longitude' => 9.9937,
        'venue_type' => 'cafe',
        'is_verified' => true,
    ]);
    $cafeGame = participantReadUpcomingGame(['location_id' => $cafe->id]);

    $strangerView = participantReadPayload($this->postMcp($ownerBearer, 'tools/call', [
        'name' => GamesDetailTool::NAME,
        'arguments' => (object) ['game_id' => $cafeGame->id],
    ], GamesDetailTool::NAME)->assertStatus(200));

    expect($strangerView['venue']['disclosure_level'])->toBe('exact')
        ->and($strangerView['venue']['address'])->toBe($cafe->fullAddress())
        ->and($strangerView['venue']['name'])->toBe($cafe->name);
});

test('games detail answers not found for hidden games identically to unknown ids', function () {
    $user = User::factory()->create();

    // Private game the user is not in — invisible on this surface.
    $hidden = participantReadUpcomingGame(['visibility' => Visibility::Private->value]);

    // Protected game of a stranger — socially reachable on the web, but the
    // agent surface is deliberately narrower (public + own participation).
    $protected = participantReadUpcomingGame(['visibility' => Visibility::Protected->value]);

    [, $bearer] = $this->mcpConsumer($user);

    foreach ([$hidden->id, $protected->id, '00000000-0000-0000-0000-000000000000'] as $gameId) {
        $response = $this->postMcp($bearer, 'tools/call', [
            'name' => GamesDetailTool::NAME,
            'arguments' => (object) ['game_id' => $gameId],
        ], GamesDetailTool::NAME);

        $response->assertStatus(200)
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', 'not_found: Game not found.');
    }
});

test('games detail shows a private game to its participant', function () {
    $user = User::factory()->create();

    $private = participantReadUpcomingGame(['visibility' => Visibility::Private->value]);
    GameParticipant::create([
        'game_id' => $private->id,
        'user_id' => $user->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    [, $bearer] = $this->mcpConsumer($user);

    $payload = participantReadPayload($this->postMcp($bearer, 'tools/call', [
        'name' => GamesDetailTool::NAME,
        'arguments' => (object) ['game_id' => $private->id],
    ], GamesDetailTool::NAME)->assertStatus(200)->assertJsonPath('result.isError', false));

    expect($payload['id'])->toBe($private->id)
        ->and($payload['is_participating'])->toBeTrue();
});

// ═══════════════════════════════════════════════════════════
// my.sessions
// ═══════════════════════════════════════════════════════════

test('my sessions lists upcoming hosted and joined games and active campaigns', function () {
    $user = User::factory()->create();

    $hosted = participantReadUpcomingGame(['owner_id' => $user->id, 'name' => ['en' => 'My Hosted Table']]);

    $joined = participantReadUpcomingGame(['name' => ['en' => 'Joined Table']]);
    GameParticipant::create([
        'game_id' => $joined->id,
        'user_id' => $user->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    // Excluded shapes: past, canceled, and a pending (not approved) application.
    participantReadUpcomingGame(['owner_id' => $user->id, 'date_time' => now()->subDays(1), 'name' => ['en' => 'Past Own Game']]);
    $pending = participantReadUpcomingGame(['name' => ['en' => 'Pending Table']]);
    GameParticipant::create([
        'game_id' => $pending->id,
        'user_id' => $user->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Pending->value,
    ]);

    // Campaigns: one organized with a next session, one played approved without.
    $organized = Campaign::factory()->create(['owner_id' => $user->id, 'name' => ['en' => 'My Campaign']]);
    $nextSession = participantReadUpcomingGame([
        'campaign_id' => $organized->id,
        'name' => ['en' => 'Campaign Session One'],
    ]);

    $played = Campaign::factory()->create(['name' => ['en' => 'Joined Campaign']]);
    CampaignParticipant::create([
        'campaign_id' => $played->id,
        'user_id' => $user->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    // An ended campaign never lists as a session.
    Campaign::factory()->create(['owner_id' => $user->id, 'status' => CampaignStatus::Completed->value]);

    [, $bearer] = $this->mcpConsumer($user);

    $payload = participantReadPayload($this->postMcp($bearer, 'tools/call', [
        'name' => MySessionsTool::NAME,
        'arguments' => (object) [],
    ], MySessionsTool::NAME)->assertStatus(200)->assertJsonPath('result.isError', false));

    $gameIds = collect($payload['upcoming_games'])->pluck('id');

    // Game rows use dashboard-schedule semantics (owner or approved
    // participant of the game itself); the campaign's session surfaces
    // under the campaign's next_session instead.
    expect($gameIds)->toContain($hosted->id, $joined->id)
        ->and($gameIds)->not->toContain($pending->id)
        ->and($gameIds->count())->toBe(2); // hosted + joined
    expect(collect($payload['upcoming_games'])->firstWhere('id', $hosted->id)['is_hosting'])->toBeTrue()
        ->and(collect($payload['upcoming_games'])->firstWhere('id', $joined->id)['is_hosting'])->toBeFalse();

    $campaignRows = collect($payload['campaigns']);

    expect($campaignRows->pluck('id'))->toContain($organized->id, $played->id)
        ->and($campaignRows->count())->toBe(2)
        ->and($campaignRows->firstWhere('id', $organized->id)['role'])->toBe('organizing')
        ->and($campaignRows->firstWhere('id', $organized->id)['next_session']['id'])->toBe($nextSession->id)
        ->and($campaignRows->firstWhere('id', $played->id)['role'])->toBe('playing');
});

// ═══════════════════════════════════════════════════════════
// my.dashboard
// ═══════════════════════════════════════════════════════════

test('my dashboard summarizes the week and the next session', function () {
    $user = User::factory()->create();

    // A future instant safely inside the current community week.
    $thisWeek = now()->addMinutes(45);
    $endOfWeek = community_now()->copy()->endOfWeek()->utc();
    if ($thisWeek->greaterThan($endOfWeek)) {
        $thisWeek = $endOfWeek->subMinutes(5);
    }

    $hosted = Game::factory()->create([
        'owner_id' => $user->id,
        'date_time' => $thisWeek,
        'name' => ['en' => 'This Week Table'],
    ]);

    [, $bearer] = $this->mcpConsumer($user);

    $payload = participantReadPayload($this->postMcp($bearer, 'tools/call', [
        'name' => MyDashboardTool::NAME,
        'arguments' => (object) [],
    ], MyDashboardTool::NAME)->assertStatus(200)->assertJsonPath('result.isError', false));

    expect($payload['week']['summary']['total'])->toBeGreaterThanOrEqual(1)
        ->and($payload['week']['summary']['upcoming'])->toBeGreaterThanOrEqual(1)
        ->and($payload['week']['summary']['hosting'])->toBeGreaterThanOrEqual(1)
        ->and(count($payload['week']['days']))->toBe(7)
        ->and($payload['next_session']['id'])->toBe($hosted->id)
        ->and($payload['next_session']['is_hosting'])->toBeTrue()
        ->and($payload['next_session']['url'])->toBe(route('games.detail', $hosted));

    $weekGameIds = collect($payload['week']['days'])->flatMap(fn (array $day): array => $day['games'] ?? [])->pluck('id');

    expect($weekGameIds)->toContain($hosted->id);
});

// ═══════════════════════════════════════════════════════════
// Cross-cutting
// ═══════════════════════════════════════════════════════════

test('the read tool surface performs zero domain writes', function () {
    $user = User::factory()->create();
    $game = participantReadUpcomingGame();

    [, $bearer] = $this->mcpConsumer($user);

    $this->postMcp($bearer, 'tools/call', ['name' => GamesSearchTool::NAME, 'arguments' => (object) []], GamesSearchTool::NAME)->assertStatus(200);
    $this->postMcp($bearer, 'tools/call', ['name' => GamesDetailTool::NAME, 'arguments' => (object) ['game_id' => $game->id]], GamesDetailTool::NAME)->assertStatus(200);
    $this->postMcp($bearer, 'tools/call', ['name' => MySessionsTool::NAME, 'arguments' => (object) []], MySessionsTool::NAME)->assertStatus(200);
    $this->postMcp($bearer, 'tools/call', ['name' => MyDashboardTool::NAME, 'arguments' => (object) []], MyDashboardTool::NAME)->assertStatus(200);

    assertDatabaseCount('game_participants', 0);
    assertDatabaseCount('campaign_participants', 0);
    expect(Game::query()->count())->toBe(1);
});

test('the participant read tools require the read ability', function () {
    [$client, $bearer] = $this->mcpConsumer(User::factory()->create(), [McpAbility::Write->value]);
    unset($client);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => GamesSearchTool::NAME,
        'arguments' => (object) [],
    ], GamesSearchTool::NAME);

    $response->assertStatus(403)
        ->assertJsonPath('error.message', 'This token does not have the mcp:read ability required by games.search.');
});
