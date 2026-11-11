<?php

namespace Tests\Feature\Mcp;

use App\Dto\CharterContent;
use App\Enums\AttendanceStatus;
use App\Enums\GameStatus;
use App\Enums\McpAbility;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Mcp\Tools\HostAttendanceSummaryTool;
use App\Mcp\Tools\HostCharterTool;
use App\Mcp\Tools\HostRosterTool;
use App\Models\AttendanceReport;
use App\Models\Campaign;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\SessionZeroSurvey;
use App\Models\User;
use App\Services\Charter\CharterService;
use App\Services\Charter\CharterSigningService;
use Illuminate\Testing\TestResponse;
use Tests\Traits\InteractsWithMcp;

uses(InteractsWithMcp::class);

/*
|--------------------------------------------------------------------------
| GM Read Tools (M079/S03/T01)
|--------------------------------------------------------------------------
|
| The three host-workspace read tools, exercised through the real agent
| surface (bearer PAT + mcp_clients consumer → McpAuthentication →
| RoundupServer dispatch) with the mcp:host ability every host tool
| declares. Authorization pins assert the SAME policies the web
| surfaces use (GamePolicy::update / CampaignPolicy::update /
| CampaignPolicy::manageCharter): non-hosts get the 403-equivalent
| structured denial, never roster data. The charter privacy pin asserts
| that private boundary answers — even when they exist in the database
| on the current version — never reach the agent surface.
|
*/

// Helpers carry a gmRead prefix — other MCP suites define same-shaped
// globals and a collision is fatal in one Pest process.
function gmReadPayload(TestResponse $response): array
{
    return json_decode((string) $response->json('result.content.0.text'), true);
}

/**
 * @return array{0: User, 1: Game}
 */
function gmReadHostGame(array $gameOverrides = [], array $participantOverrides = []): array
{
    $host = User::factory()->create();
    $game = Game::factory()->create(array_merge([
        'owner_id' => $host->id,
        'date_time' => now()->addDays(7),
        'max_players' => 4,
        'name' => ['en' => 'Tonight\'s Table'],
    ], $gameOverrides));

    // The explicit-owner-participant row every real game carries — the
    // roster must exclude it from the player list (web parity) while
    // seat counts keep counting it.
    GameParticipant::create(array_merge([
        'game_id' => $game->id,
        'user_id' => $host->id,
        'role' => ParticipantRole::Owner->value,
        'status' => ParticipantStatus::Approved->value,
    ], $participantOverrides));

    return [$host, $game];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function gmReadApprovedPlayer(Game $game, array $overrides = []): User
{
    $player = User::factory()->create();

    GameParticipant::create(array_merge([
        'game_id' => $game->id,
        'user_id' => $player->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ], $overrides));

    return $player;
}

/**
 * @return array{0: User, 1: Campaign}
 */
function gmReadOwnerCampaign(): array
{
    $owner = User::factory()->create();
    $campaign = Campaign::factory()->create([
        'owner_id' => $owner->id,
        'name' => ['en' => 'The Threshold Heist'],
        'max_players' => 5,
        'recurrence' => 'weekly',
        // A mapped duration so quickStart's logistics.session_length lands
        // on '3h' — factory-random decimals map to 'other', whose companion
        // text field the publish validator requires.
        'session_duration' => 3,
    ]);

    return [$owner, $campaign];
}

/**
 * Start and publish a charter for the campaign (the real service path),
 * returning the fresh charter row with its current version resolvable.
 */
function gmReadPublishedCharter(User $owner, Campaign $campaign): SessionZeroSurvey
{
    $charter = CharterService::start($owner, $campaign);
    CharterService::publish(
        $owner,
        $charter,
        'The Threshold Heist',
        CharterContent::quickStart($campaign)->toArray(),
    );

    return $charter->refresh();
}

/**
 * An approved campaign player, created as a participant row.
 */
function gmReadCampaignPlayer(Campaign $campaign): User
{
    $player = User::factory()->create();

    CampaignParticipant::create([
        'campaign_id' => $campaign->id,
        'user_id' => $player->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    return $player;
}

/**
 * The signing input with private boundary answers carrying unmissable
 * markers — the answers that must never surface through the tool.
 *
 * @return array<string, mixed>
 */
function gmReadSigningInput(): array
{
    return [
        'attestations' => array_fill_keys(CharterContent::AGREEMENT_ITEMS, true),
        'private_answers' => [
            'my_lines_text' => 'SECRET-LINES-MARKER',
            'note_to_gm' => 'SECRET-NOTE-MARKER',
        ],
    ];
}

// ═══════════════════════════════════════════════════════════
// host.roster
// ═══════════════════════════════════════════════════════════

test('host roster returns the game roster with statuses, roles, and reliability summaries', function () {
    [$host, $game] = gmReadHostGame();

    $reliable = User::factory()->create([
        'reliability_score' => ['tier' => 'reliable', 'score' => 92.5, 'game_count' => 10],
    ]);
    GameParticipant::create([
        'game_id' => $game->id, 'user_id' => $reliable->id,
        'role' => ParticipantRole::Player->value, 'status' => ParticipantStatus::Approved->value,
    ]);
    $newcomer = gmReadApprovedPlayer($game);

    $waitlisted = User::factory()->create();
    GameParticipant::create([
        'game_id' => $game->id, 'user_id' => $waitlisted->id,
        'role' => ParticipantRole::Player->value, 'status' => ParticipantStatus::Waitlisted->value,
        'waitlisted_at' => now(),
    ]);
    $benched = User::factory()->create();
    GameParticipant::create([
        'game_id' => $game->id, 'user_id' => $benched->id,
        'role' => ParticipantRole::Player->value, 'status' => ParticipantStatus::Benched->value,
        'benched_at' => now(),
    ]);

    [, $bearer] = $this->mcpGmConsumer($host);

    $payload = gmReadPayload($this->postMcp($bearer, 'tools/call', [
        'name' => HostRosterTool::NAME,
        'arguments' => (object) ['game_id' => $game->id],
    ], HostRosterTool::NAME)->assertStatus(200)->assertJsonPath('result.isError', false));

    expect($payload['entity'])->toBe([
        'type' => 'game',
        'id' => $game->id,
        'name' => 'Tonight\'s Table',
        'date_time' => $game->date_time->toIso8601String(),
        'status' => 'scheduled',
        'campaign_id' => null,
    ])
        ->and($payload['seats'])->toBe(['taken' => 3, 'max' => 4, 'available' => 1])
        ->and($payload['counts'])->toBe(['approved' => 2, 'waitlisted' => 1, 'benched' => 1]);

    $rows = collect($payload['participants']);

    // The owner row is excluded from the player list (web parity).
    expect($rows->pluck('user_id'))->toContain($reliable->id, $newcomer->id, $waitlisted->id, $benched->id)
        ->and($rows->pluck('user_id'))->not->toContain($host->id)
        ->and($rows->count())->toBe(4);

    $reliableRow = $rows->firstWhere('user_id', $reliable->id);
    expect($reliableRow['role'])->toBe('player')
        ->and($reliableRow['status'])->toBe('approved')
        ->and($reliableRow['reliability'])->toBe(['tier' => 'reliable', 'score' => 92.5, 'games' => 10])
        ->and($reliableRow)->toHaveKey('joined_at');

    $newcomerRow = $rows->firstWhere('user_id', $newcomer->id);
    // toEqual, not toBe: JSON serializes 0.0 to a zero integer, and the
    // shape is what matters — a number, not float-ness on the wire.
    expect($newcomerRow['reliability'])->toEqual(['tier' => 'newcomer', 'score' => 0, 'games' => 0]);

    expect($rows->firstWhere('user_id', $waitlisted->id)['status'])->toBe('waitlisted')
        ->and($rows->firstWhere('user_id', $benched->id)['status'])->toBe('benched');
});

test('host roster returns the campaign roster', function () {
    [$owner, $campaign] = gmReadOwnerCampaign();

    $player = gmReadCampaignPlayer($campaign);

    $waitlisted = User::factory()->create();
    CampaignParticipant::create([
        'campaign_id' => $campaign->id, 'user_id' => $waitlisted->id,
        'role' => ParticipantRole::Player->value, 'status' => ParticipantStatus::Waitlisted->value,
        'waitlisted_at' => now(),
    ]);

    [, $bearer] = $this->mcpGmConsumer($owner);

    $payload = gmReadPayload($this->postMcp($bearer, 'tools/call', [
        'name' => HostRosterTool::NAME,
        'arguments' => (object) ['campaign_id' => $campaign->id],
    ], HostRosterTool::NAME)->assertStatus(200)->assertJsonPath('result.isError', false));

    expect($payload['entity']['type'])->toBe('campaign')
        ->and($payload['entity']['id'])->toBe($campaign->id)
        ->and($payload['entity']['name'])->toBe('The Threshold Heist')
        ->and($payload['entity']['recurrence'])->toBe('weekly')
        ->and($payload['seats'])->toBe(['taken' => 1, 'max' => 5, 'available' => 4])
        ->and($payload['counts'])->toBe(['approved' => 1, 'waitlisted' => 1, 'benched' => 0]);

    $rows = collect($payload['participants']);
    expect($rows->pluck('user_id'))->toContain($player->id, $waitlisted->id)
        ->and($rows->firstWhere('user_id', $waitlisted->id)['status'])->toBe('waitlisted');
});

test('host roster reports unlimited capacity as null available, never zero', function () {
    // M079 review: campaigns.max_players is nullable and HasCapacity treats
    // null/0 as UNLIMITED — reporting available: 0 would tell the host their
    // unlimited campaign is full.
    [$owner, $campaign] = gmReadOwnerCampaign();
    $campaign->forceFill(['max_players' => null])->save();
    gmReadCampaignPlayer($campaign);

    [, $bearer] = $this->mcpGmConsumer($owner);

    $payload = gmReadPayload($this->postMcp($bearer, 'tools/call', [
        'name' => HostRosterTool::NAME,
        'arguments' => (object) ['campaign_id' => $campaign->id],
    ], HostRosterTool::NAME)->assertStatus(200)->assertJsonPath('result.isError', false));

    expect($payload['seats'])->toBe(['taken' => 1, 'max' => null, 'available' => null]);
});

test('host roster denies non-hosts with the 403-equivalent structured denial', function () {
    [$host, $game] = gmReadHostGame();
    $approvedPlayer = gmReadApprovedPlayer($game);
    unset($host);

    [$owner, $campaign] = gmReadOwnerCampaign();
    gmReadCampaignPlayer($campaign);
    unset($owner);

    $stranger = User::factory()->create();
    [, $bearer] = $this->mcpGmConsumer($stranger);

    // A stranger probing a real game id: structured denial, never data.
    $this->postMcp($bearer, 'tools/call', [
        'name' => HostRosterTool::NAME,
        'arguments' => (object) ['game_id' => $game->id],
    ], HostRosterTool::NAME)
        ->assertStatus(200)
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'not_authorized: Only the host of this game can view its roster.');

    // Even an approved player is not the host — same denial as the web
    // Manage Participants 403.
    [, $playerBearer] = $this->mcpGmConsumer($approvedPlayer);

    $this->postMcp($playerBearer, 'tools/call', [
        'name' => HostRosterTool::NAME,
        'arguments' => (object) ['game_id' => $game->id],
    ], HostRosterTool::NAME)
        ->assertStatus(200)
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'not_authorized: Only the host of this game can view its roster.');

    // Campaign variant: a non-organizer gets the organizer denial.
    $this->postMcp($bearer, 'tools/call', [
        'name' => HostRosterTool::NAME,
        'arguments' => (object) ['campaign_id' => $campaign->id],
    ], HostRosterTool::NAME)
        ->assertStatus(200)
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'not_authorized: Only the organizer of this campaign can view its roster.');
});

test('host roster answers not found for unknown ids and rejects both or neither argument', function () {
    [$host, $game] = gmReadHostGame();
    $campaign = Campaign::factory()->create(['owner_id' => $host->id]);

    [, $bearer] = $this->mcpGmConsumer($host);

    // Unknown ids answer not-found (the web 404 equivalent).
    $this->postMcp($bearer, 'tools/call', [
        'name' => HostRosterTool::NAME,
        'arguments' => (object) ['game_id' => '00000000-0000-0000-0000-000000000000'],
    ], HostRosterTool::NAME)
        ->assertStatus(200)
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'not_found: Game not found.');

    $this->postMcp($bearer, 'tools/call', [
        'name' => HostRosterTool::NAME,
        'arguments' => (object) ['campaign_id' => '00000000-0000-0000-0000-000000000000'],
    ], HostRosterTool::NAME)
        ->assertStatus(200)
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'not_found: Campaign not found.');

    // Both ids at once is ambiguous, not a guess.
    $this->postMcp($bearer, 'tools/call', [
        'name' => HostRosterTool::NAME,
        'arguments' => (object) ['game_id' => $game->id, 'campaign_id' => $campaign->id],
    ], HostRosterTool::NAME)
        ->assertStatus(200)
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'invalid_arguments: Provide exactly one of game_id or campaign_id, not both.');

    // Neither id fails validation as a structured tool error.
    $this->postMcp($bearer, 'tools/call', [
        'name' => HostRosterTool::NAME,
        'arguments' => (object) [],
    ], HostRosterTool::NAME)
        ->assertStatus(200)
        ->assertJsonPath('result.isError', true);
});

// ═══════════════════════════════════════════════════════════
// host.charter
// ═══════════════════════════════════════════════════════════

test('host charter reports version, amendments, signing progress, and per-player completion without private answers', function () {
    [$owner, $campaign] = gmReadOwnerCampaign();
    $charter = gmReadPublishedCharter($owner, $campaign);

    $signer = gmReadCampaignPlayer($campaign);
    $unsigned = gmReadCampaignPlayer($campaign);

    // The signer submits private boundary answers carrying markers.
    CharterSigningService::sign($signer, $charter, gmReadSigningInput());

    // A minor amendment (title-only) publishes v2 and carries the
    // signature — byte-for-byte, INCLUDING the private answers on the
    // current version. The tool must still not surface them.
    $amended = CharterService::amend(
        $owner,
        $charter,
        'The Threshold Heist, Revised',
        CharterContent::quickStart($campaign)->toArray(),
        'Clarified the title.',
        1,
    );
    unset($amended);

    [, $bearer] = $this->mcpGmConsumer($owner);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => HostCharterTool::NAME,
        'arguments' => (object) ['campaign_id' => $campaign->id],
    ], HostCharterTool::NAME);

    $response->assertStatus(200)->assertJsonPath('result.isError', false);

    $payload = gmReadPayload($response);

    expect($payload['campaign'])->toBe(['id' => $campaign->id, 'name' => 'The Threshold Heist']);

    $charterPayload = $payload['charter'];
    expect($charterPayload['status'])->toBe('active')
        ->and($charterPayload['title'])->toBe('The Threshold Heist, Revised')
        ->and($charterPayload['current_version'])->toBe(2)
        ->and($charterPayload['amendment_count'])->toBe(1)
        ->and($charterPayload['published_at'])->not->toBeNull()
        ->and($charterPayload['url'])->toBe(route('charter.view', $charter->uuid));

    expect($charterPayload['signing'])->toBe([
        'approved' => 2,
        'signed' => 1,
        'percent' => 50,
        'countersigned' => false,
    ]);

    $players = collect($charterPayload['players']);
    $signerRow = $players->firstWhere('user_id', $signer->id);
    expect($signerRow['signature'])->toBe('signed')
        ->and($signerRow['signed_at'])->not->toBeNull()
        ->and($players->firstWhere('user_id', $unsigned->id)['signature'])->toBe('unsigned');

    // PRIVACY PIN: completion rows carry signature state only, and the
    // raw response never contains the private-answer markers that ARE
    // stored on the current version's confirmation row.
    expect($signerRow)->toHaveKeys(['user_id', 'name', 'signature', 'signed_at'])
        ->and($signerRow)->not->toHaveKeys(['private_answers', 'attestations']);

    $raw = (string) $response->json('result.content.0.text');
    expect($raw)->not->toContain('SECRET-LINES-MARKER')
        ->and($raw)->not->toContain('SECRET-NOTE-MARKER');

    // The marker genuinely exists in the database on the current
    // version — proving the absence is the tool's doing, not the
    // fixture's.
    $carried = $charter->refresh()->confirmations()
        ->where('session_zero_survey_version_id', $charter->currentVersion()->id)
        ->where('user_id', $signer->id)
        ->first();
    expect($carried?->private_answers['my_lines_text'] ?? null)->toBe('SECRET-LINES-MARKER');
});

test('host charter reports pending re-agreement after a material amendment', function () {
    [$owner, $campaign] = gmReadOwnerCampaign();
    $charter = gmReadPublishedCharter($owner, $campaign);

    $signer = gmReadCampaignPlayer($campaign);
    CharterSigningService::sign($signer, $charter, gmReadSigningInput());

    // Material amendment: §4 pvp flips limited → none (vocabulary:
    // none/limited/open), resetting every signature to pending
    // re-agreement.
    $content = CharterContent::quickStart($campaign)->toArray();
    $content['policies']['pvp'] = 'none';

    CharterService::amend($owner, $charter, 'The Threshold Heist', $content, 'PvP is now off the table.', 1);

    [, $bearer] = $this->mcpGmConsumer($owner);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => HostCharterTool::NAME,
        'arguments' => (object) ['campaign_id' => $campaign->id],
    ], HostCharterTool::NAME);

    $payload = gmReadPayload($response->assertStatus(200)->assertJsonPath('result.isError', false));

    $charterPayload = $payload['charter'];
    expect($charterPayload['current_version'])->toBe(2)
        ->and($charterPayload['signing']['signed'])->toBe(0)
        ->and($charterPayload['signing']['percent'])->toBe(0);

    $signerRow = collect($charterPayload['players'])->firstWhere('user_id', $signer->id);
    expect($signerRow['signature'])->toBe('pending_reagreement')
        ->and($signerRow['signed_at'])->toBeNull();

    // Privacy pin holds through the reset path too.
    $raw = (string) $response->json('result.content.0.text');
    expect($raw)->not->toContain('SECRET-LINES-MARKER')
        ->and($raw)->not->toContain('SECRET-NOTE-MARKER');
});

test('host charter covers draft and missing charters', function () {
    [$owner, $draftCampaign] = gmReadOwnerCampaign();
    CharterService::start($owner, $draftCampaign);
    gmReadCampaignPlayer($draftCampaign);

    [, $bearer] = $this->mcpGmConsumer($owner);

    $draft = gmReadPayload($this->postMcp($bearer, 'tools/call', [
        'name' => HostCharterTool::NAME,
        'arguments' => (object) ['campaign_id' => $draftCampaign->id],
    ], HostCharterTool::NAME)->assertStatus(200)->assertJsonPath('result.isError', false));

    expect($draft['charter']['status'])->toBe('draft')
        ->and($draft['charter']['current_version'])->toBeNull()
        ->and($draft['charter']['amendment_count'])->toBe(0)
        ->and($draft['charter']['players'])->toBe([])
        ->and($draft['charter']['signing']['approved'])->toBe(1)
        ->and($draft['charter']['signing']['signed'])->toBe(0)
        ->and($draft['charter']['signing']['percent'])->toBe(0);

    // A campaign that never started a charter answers charter: null —
    // a valid state, not an error.
    $bareCampaign = Campaign::factory()->create(['owner_id' => $owner->id]);

    $bare = gmReadPayload($this->postMcp($bearer, 'tools/call', [
        'name' => HostCharterTool::NAME,
        'arguments' => (object) ['campaign_id' => $bareCampaign->id],
    ], HostCharterTool::NAME)->assertStatus(200)->assertJsonPath('result.isError', false));

    expect($bare['charter'])->toBeNull();
});

test('host charter denies non-owners and answers not found for unknown ids', function () {
    [$owner, $campaign] = gmReadOwnerCampaign();
    gmReadPublishedCharter($owner, $campaign);
    $player = gmReadCampaignPlayer($campaign);

    // An approved player is not the owner — the same manageCharter
    // refusal every web GM charter surface resolves.
    [, $bearer] = $this->mcpGmConsumer($player);

    $this->postMcp($bearer, 'tools/call', [
        'name' => HostCharterTool::NAME,
        'arguments' => (object) ['campaign_id' => $campaign->id],
    ], HostCharterTool::NAME)
        ->assertStatus(200)
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'not_authorized: Only the owner of this campaign can view its charter status.');

    [, $ownerBearer] = $this->mcpGmConsumer($owner);

    $this->postMcp($ownerBearer, 'tools/call', [
        'name' => HostCharterTool::NAME,
        'arguments' => (object) ['campaign_id' => '00000000-0000-0000-0000-000000000000'],
    ], HostCharterTool::NAME)
        ->assertStatus(200)
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'not_found: Campaign not found.');
});

// ═══════════════════════════════════════════════════════════
// host.attendance-summary
// ═══════════════════════════════════════════════════════════

test('host attendance summary returns resolved statuses, totals, and reliability', function () {
    [$host, $game] = gmReadHostGame([
        'status' => GameStatus::Completed->value,
        'date_time' => now()->subDays(2),
        'attendance_resolved_at' => now()->subDay(),
        'attendance_resolution_method' => 'timeout',
    ]);

    $attended = gmReadApprovedPlayer($game, ['attendance_status' => AttendanceStatus::Attended->value]);
    $noShow = gmReadApprovedPlayer($game, ['attendance_status' => AttendanceStatus::NoShow->value]);
    $excused = gmReadApprovedPlayer($game, ['attendance_status' => AttendanceStatus::Excused->value]);
    gmReadApprovedPlayer($game, ['attendance_status' => null]);

    AttendanceReport::factory()->count(2)->create(['game_id' => $game->id]);

    [, $bearer] = $this->mcpGmConsumer($host);

    $payload = gmReadPayload($this->postMcp($bearer, 'tools/call', [
        'name' => HostAttendanceSummaryTool::NAME,
        'arguments' => (object) ['game_id' => $game->id],
    ], HostAttendanceSummaryTool::NAME)->assertStatus(200)->assertJsonPath('result.isError', false));

    expect($payload['game'])->toBe([
        'id' => $game->id,
        'name' => 'Tonight\'s Table',
        'date_time' => $game->date_time->toIso8601String(),
        'status' => 'completed',
    ]);

    expect($payload['resolution']['resolved'])->toBeTrue()
        ->and($payload['resolution']['resolved_at'])->toBe($game->attendance_resolved_at->toIso8601String())
        ->and($payload['resolution']['method'])->toBe('timeout')
        ->and($payload['resolution']['reports_filed'])->toBe(2);

    // Owner row (null status) + the explicit null player both count as
    // unresolved — resolution covers every approved participant, the
    // host's own row included.
    expect($payload['summary'])->toBe([
        'attended' => 1,
        'no_show' => 1,
        'excused' => 1,
        'late_cancel' => 0,
        'unresolved' => 2,
    ]);

    $rows = collect($payload['participants']);
    expect($rows->count())->toBe(5)
        ->and($rows->firstWhere('user_id', $attended->id)['attendance'])->toBe('attended')
        ->and($rows->firstWhere('user_id', $noShow->id)['attendance'])->toBe('no_show')
        ->and($rows->firstWhere('user_id', $excused->id)['attendance'])->toBe('excused')
        ->and($rows->firstWhere('user_id', $excused->id))->toHaveKey('reliability');
});

test('host attendance summary reports the open window for unresolved games', function () {
    [$host, $game] = gmReadHostGame([
        'status' => GameStatus::Completed->value,
        'date_time' => now()->subHours(3),
        'attendance_window_opens_at' => now()->subHour(),
        'attendance_window_closes_at' => now()->addHours(23),
    ]);

    gmReadApprovedPlayer($game, ['attendance_status' => null]);

    AttendanceReport::factory()->create(['game_id' => $game->id]);

    [, $bearer] = $this->mcpGmConsumer($host);

    $payload = gmReadPayload($this->postMcp($bearer, 'tools/call', [
        'name' => HostAttendanceSummaryTool::NAME,
        'arguments' => (object) ['game_id' => $game->id],
    ], HostAttendanceSummaryTool::NAME)->assertStatus(200)->assertJsonPath('result.isError', false));

    expect($payload['resolution']['resolved'])->toBeFalse()
        ->and($payload['resolution']['resolved_at'])->toBeNull()
        ->and($payload['resolution']['method'])->toBeNull()
        ->and($payload['resolution']['window_opens_at'])->toBe($game->attendance_window_opens_at->toIso8601String())
        ->and($payload['resolution']['window_closes_at'])->toBe($game->attendance_window_closes_at->toIso8601String())
        ->and($payload['resolution']['reports_filed'])->toBe(1)
        ->and($payload['summary']['unresolved'])->toBe(2)
        ->and(collect($payload['participants'])->pluck('attendance')->all())->toBe([null, null]);
});

test('host attendance summary denies non-hosts and answers not found', function () {
    [$host, $game] = gmReadHostGame(['status' => GameStatus::Completed->value]);
    $player = gmReadApprovedPlayer($game);
    unset($host);

    [, $bearer] = $this->mcpGmConsumer($player);

    $this->postMcp($bearer, 'tools/call', [
        'name' => HostAttendanceSummaryTool::NAME,
        'arguments' => (object) ['game_id' => $game->id],
    ], HostAttendanceSummaryTool::NAME)
        ->assertStatus(200)
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'not_authorized: Only the host of this game can view its attendance summary.');

    $this->postMcp($bearer, 'tools/call', [
        'name' => HostAttendanceSummaryTool::NAME,
        'arguments' => (object) ['game_id' => '00000000-0000-0000-0000-000000000000'],
    ], HostAttendanceSummaryTool::NAME)
        ->assertStatus(200)
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'not_found: Game not found.');
});

// ═══════════════════════════════════════════════════════════
// Cross-cutting
// ═══════════════════════════════════════════════════════════

test('the host read surface performs zero domain writes', function () {
    [$host, $game] = gmReadHostGame();
    gmReadApprovedPlayer($game);
    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => User::factory()->create()->id,
        'role' => ParticipantRole::Player->value,
        'status' => ParticipantStatus::Waitlisted->value,
        'waitlisted_at' => now(),
    ]);

    [$charterOwner, $campaign] = gmReadOwnerCampaign();
    gmReadPublishedCharter($charterOwner, $campaign);
    gmReadCampaignPlayer($campaign);
    unset($charterOwner);

    [, $bearer] = $this->mcpGmConsumer($host);

    $this->postMcp($bearer, 'tools/call', ['name' => HostRosterTool::NAME, 'arguments' => (object) ['game_id' => $game->id]], HostRosterTool::NAME)->assertStatus(200);
    $this->postMcp($bearer, 'tools/call', ['name' => HostRosterTool::NAME, 'arguments' => (object) ['campaign_id' => $campaign->id]], HostRosterTool::NAME)->assertStatus(200);
    $this->postMcp($bearer, 'tools/call', ['name' => HostAttendanceSummaryTool::NAME, 'arguments' => (object) ['game_id' => $game->id]], HostAttendanceSummaryTool::NAME)->assertStatus(200);

    $charterOwner = $campaign->owner;
    [, $charterBearer] = $this->mcpGmConsumer($charterOwner);
    $this->postMcp($charterBearer, 'tools/call', ['name' => HostCharterTool::NAME, 'arguments' => (object) ['campaign_id' => $campaign->id]], HostCharterTool::NAME)->assertStatus(200);

    // The roster the fixtures wrote is exactly the roster that exists
    // after the reads — no participant-status writes, no attendance
    // reports, no confirmations.
    expect(GameParticipant::query()->count())->toBe(3)
        ->and(CampaignParticipant::query()->count())->toBe(1)
        ->and(AttendanceReport::query()->count())->toBe(0)
        ->and(Game::query()->count())->toBe(1)
        ->and(Campaign::query()->count())->toBe(1);
});

test('the host read tools require the host ability', function () {
    [$client, $bearer] = $this->mcpConsumer(User::factory()->create(), [McpAbility::Write->value]);
    unset($client);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => HostRosterTool::NAME,
        'arguments' => (object) [],
    ], HostRosterTool::NAME);

    $response->assertStatus(403)
        ->assertJsonPath('error.message', 'This token does not have the mcp:host ability required by host.roster.');
});
