<?php

namespace Tests\Feature\Mcp;

use App\Dto\CharterContent;
use App\Enums\GameStatus;
use App\Enums\McpAbility;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Mcp\Tools\HostAttendanceReportTool;
use App\Mcp\Tools\HostAttendanceResolveTool;
use App\Mcp\Tools\HostCharterAmendTool;
use App\Mcp\Tools\HostRecapWriteTool;
use App\Models\AttendanceReport;
use App\Models\Campaign;
use App\Models\CampaignJournalEntry;
use App\Models\CampaignParticipant;
use App\Models\Game;
use App\Models\GameParticipant;
use App\Models\McpCall;
use App\Models\SessionZeroSurvey;
use App\Models\User;
use App\Notifications\AttendanceReported;
use App\Notifications\AttendanceResolved;
use App\Notifications\CharterAmended;
use App\Notifications\RecapPosted;
use App\Services\AttendanceService;
use App\Services\CampaignJournalService;
use App\Services\Charter\CharterService;
use App\Services\Charter\CharterSigningService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Traits\CapturesLogRecords;
use Tests\Traits\InteractsWithMcp;

uses(CapturesLogRecords::class, InteractsWithMcp::class);

/*
|--------------------------------------------------------------------------
| GM Write Tools (M079/S03/T02)
|--------------------------------------------------------------------------
|
| The four host-workspace write tools, exercised through the real agent
| surface (bearer PAT + mcp_clients consumer → McpAuthentication →
| RoundupServer dispatch). Each tool delegates to the identical service
| the web action uses, so the pins here are the web pins: service guards
| surface as structured tool errors, notification fan-out matches the
| web path (AttendanceReported / AttendanceResolved / RecapPosted /
| CharterAmended), grief-resistance weights stay server-side, and every
| write is agent-marked in the audit trail (mcp_calls actor column +
| the mcp.agent_write structured event carrying actor=agent and the
| consumer id — the observable difference from a web-path write).
|
*/

// Helpers carry a gmWrite prefix — other MCP suites define same-shaped
// globals and a collision is fatal in one Pest process.

/**
 * @return array<string, mixed>
 */
function gmWritePayload(TestResponse $response): array
{
    return json_decode((string) $response->json('result.content.0.text'), true);
}

/**
 * A host's game with the owner participant row every real game carries.
 *
 * @param  array<string, mixed>  $gameOverrides
 * @return array{0: User, 1: Game}
 */
function gmWriteHostGame(array $gameOverrides = []): array
{
    $host = User::factory()->create();
    $game = Game::factory()->create(array_merge([
        'owner_id' => $host->id,
        'date_time' => now()->subDays(2),
        'max_players' => 4,
        'name' => ['en' => 'Tonight\'s Table'],
    ], $gameOverrides));

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $host->id,
        'role' => ParticipantRole::Owner->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    return [$host, $game];
}

/**
 * A completed game with an open attendance reporting window.
 *
 * @return array{0: User, 1: Game}
 */
function gmWriteCompletedGame(): array
{
    return gmWriteHostGame([
        'status' => GameStatus::Completed->value,
        'attendance_window_opens_at' => now()->subDay(),
        'attendance_window_closes_at' => now()->addDays(2),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function gmWriteApprovedPlayer(Game $game, array $overrides = []): User
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
function gmWriteOwnerCampaign(): array
{
    $owner = User::factory()->create();
    $campaign = Campaign::factory()->create([
        'owner_id' => $owner->id,
        'name' => ['en' => 'The Threshold Heist'],
        'max_players' => 5,
        'recurrence' => 'weekly',
        // Mapped duration so quickStart's logistics land on '3h' (factory
        // decimals map to 'other', which the publish validator refuses).
        'session_duration' => 3,
    ]);

    return [$owner, $campaign];
}

/**
 * A completed campaign session owned by $owner, with one approved player.
 *
 * @return array{0: User, 1: Campaign, 2: Game, 3: User}
 */
function gmWriteCompletedSession(): array
{
    [$owner, $campaign] = gmWriteOwnerCampaign();

    $game = Game::factory()->create([
        'owner_id' => $owner->id,
        'campaign_id' => $campaign->id,
        'status' => GameStatus::Completed->value,
        'date_time' => now()->subDays(2),
        'name' => ['en' => 'Session 12 — The Vault'],
    ]);

    GameParticipant::create([
        'game_id' => $game->id,
        'user_id' => $owner->id,
        'role' => ParticipantRole::Owner->value,
        'status' => ParticipantStatus::Approved->value,
    ]);

    $player = gmWriteApprovedPlayer($game);

    return [$owner, $campaign, $game, $player];
}

/**
 * An approved campaign player (the charter roster), created as a
 * CampaignParticipant row.
 */
function gmWriteCampaignPlayer(Campaign $campaign): User
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
 * Start and publish a charter for the campaign through the real service
 * path, returning the fresh charter row.
 */
function gmWritePublishedCharter(User $owner, Campaign $campaign): SessionZeroSurvey
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

// ═══════════════════════════════════════════════════════════
// host.attendance.report
// ═══════════════════════════════════════════════════════════

test('host attendance report submits the host reports and notifies the reported players', function () {
    [$host, $game] = gmWriteCompletedGame();
    $player = gmWriteApprovedPlayer($game);

    Notification::fake();

    [, $bearer] = $this->mcpGmConsumer($host);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => HostAttendanceReportTool::NAME,
        'arguments' => [
            'game_id' => $game->id,
            'reports' => [
                ['user_id' => $player->id, 'status' => 'no_show'],
            ],
        ],
    ], HostAttendanceReportTool::NAME);

    $payload = gmWritePayload($response->assertStatus(200)->assertJsonPath('result.isError', false));

    expect($payload['status'])->toBe('reports_submitted')
        ->and($payload['submitted'])->toBe(1)
        ->and($payload['game']['id'])->toBe($game->id);

    // The report landed through the exact web intake: full weight for a
    // reliable reporter, not quarantined, and resolution still pending.
    $report = AttendanceReport::query()->sole();

    expect($report->reporter_id)->toBe($host->id)
        ->and($report->reported_id)->toBe($player->id)
        ->and($report->status->value)->toBe('no_show')
        ->and($report->weight_applied)->toBe(1.0)
        ->and($report->quarantined)->toBeFalse();

    // Fan-out parity: the reported player gets AttendanceReported through
    // the same NotificationService path the web form uses.
    Notification::assertSentTo($player, AttendanceReported::class);

    // Reporting is not resolving — the participant status stays untouched
    // until host.attendance.resolve (or a consensus trigger) runs.
    expect($game->participants()->where('user_id', $player->id)->value('attendance_status'))->toBeNull();
});

test('host attendance report replays idempotent retries instead of reporting twice', function () {
    [$host, $game] = gmWriteCompletedGame();
    $player = gmWriteApprovedPlayer($game);

    Notification::fake();

    [, $bearer] = $this->mcpGmConsumer($host);

    $arguments = [
        'game_id' => $game->id,
        'reports' => [['user_id' => $player->id, 'status' => 'attended']],
        'idempotency_key' => 'gm-write-report-retry-1',
    ];

    $first = $this->postMcp($bearer, 'tools/call', ['name' => HostAttendanceReportTool::NAME, 'arguments' => $arguments], HostAttendanceReportTool::NAME)
        ->assertStatus(200)->assertJsonPath('result.isError', false);

    $replay = $this->postMcp($bearer, 'tools/call', ['name' => HostAttendanceReportTool::NAME, 'arguments' => $arguments], HostAttendanceReportTool::NAME)
        ->assertStatus(200)->assertJsonPath('result.isError', false);

    expect(gmWritePayload($replay))->toBe(gmWritePayload($first))
        ->and(AttendanceReport::query()->count())->toBe(1);
});

test('host attendance report surfaces the service guards as structured rejections', function () {
    [$host, $game] = gmWriteCompletedGame();
    $player = gmWriteApprovedPlayer($game);

    [, $bearer] = $this->mcpGmConsumer($host);

    $report = fn (array $reports) => $this->postMcp($bearer, 'tools/call', [
        'name' => HostAttendanceReportTool::NAME,
        'arguments' => ['game_id' => $game->id, 'reports' => $reports],
    ], HostAttendanceReportTool::NAME);

    // Unknown (non-approved) participant id — the same refusal the web
    // intake answers, structured for agents.
    $report([['user_id' => '00000000-0000-0000-0000-000000000000', 'status' => 'attended']])
        ->assertStatus(200)->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'report_rejected: Reported user 00000000-0000-0000-0000-000000000000 is not an approved participant');

    // Self-reporting is refused.
    $report([['user_id' => $host->id, 'status' => 'attended']])
        ->assertStatus(200)->assertJsonPath('result.isError', true);

    // Excused requires a reason.
    $report([['user_id' => $player->id, 'status' => 'excused']])
        ->assertStatus(200)->assertJsonPath('result.isError', true);

    // A non-completed game refuses reports outright.
    [$scheduledHost, $scheduledGame] = gmWriteHostGame();
    gmWriteApprovedPlayer($scheduledGame);
    unset($scheduledHost);

    $this->postMcp($bearer, 'tools/call', [
        'name' => HostAttendanceReportTool::NAME,
        'arguments' => [
            'game_id' => $scheduledGame->id,
            'reports' => [['user_id' => $player->id, 'status' => 'attended']],
        ],
    ], HostAttendanceReportTool::NAME)
        ->assertStatus(200)->assertJsonPath('result.isError', true);

    // Double reporting the same participant is refused by the intake.
    Notification::fake();

    $report([['user_id' => $player->id, 'status' => 'attended']])
        ->assertStatus(200)->assertJsonPath('result.isError', false);

    $report([['user_id' => $player->id, 'status' => 'attended']])
        ->assertStatus(200)->assertJsonPath('result.isError', true);

    expect(AttendanceReport::query()->count())->toBe(1);
});

test('host attendance report refuses duplicate entries for one participant in a single batch', function () {
    // M079 review: the MCP batch surface is list-shaped, where the web form
    // was structurally immune (keyed by participant id). Without the guard a
    // duplicate in-batch user_id would insert two rows and double-count this
    // reporter's votes at resolution time — a lone host could flip a NoShow.
    [$host, $game] = gmWriteCompletedGame();
    $player = gmWriteApprovedPlayer($game);

    [, $bearer] = $this->mcpGmConsumer($host);

    $this->postMcp($bearer, 'tools/call', [
        'name' => HostAttendanceReportTool::NAME,
        'arguments' => [
            'game_id' => $game->id,
            'reports' => [
                ['user_id' => $player->id, 'status' => 'no_show'],
                ['user_id' => $player->id, 'status' => 'no_show'],
            ],
        ],
    ], HostAttendanceReportTool::NAME)
        ->assertStatus(200)->assertJsonPath('result.isError', true);

    expect(AttendanceReport::query()->count())->toBe(0);
});

test('attendance service refuses an in-batch duplicate even without tool validation', function () {
    // The durable half of the duplicate guard lives in the service so every
    // future batch caller is protected, not just the MCP tool layer.
    [$host, $game] = gmWriteCompletedGame();
    $player = gmWriteApprovedPlayer($game);

    $result = app(AttendanceService::class)->submitReport($game, $host, [
        ['reported_id' => $player->id, 'status' => 'attended', 'reason' => ''],
        ['reported_id' => $player->id, 'status' => 'attended', 'reason' => ''],
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['reason'])->toBe('Duplicate report entries for the same participant in one submission')
        ->and(AttendanceReport::query()->count())->toBe(0);
});

test('host attendance report exposes no weight or threshold controls', function () {
    [$host, $game] = gmWriteCompletedGame();
    $player = gmWriteApprovedPlayer($game);

    [, $bearer] = $this->mcpGmConsumer($host);

    // An agent guessing grief-resistance controls is refused at the
    // dispatch gate: the schema declares no such argument, so weights
    // and thresholds stay server-side by construction.
    $this->postMcp($bearer, 'tools/call', [
        'name' => HostAttendanceReportTool::NAME,
        'arguments' => [
            'game_id' => $game->id,
            'reports' => [['user_id' => $player->id, 'status' => 'no_show']],
            'weight_applied' => 5.0,
            'quarantined' => false,
        ],
    ], HostAttendanceReportTool::NAME)
        ->assertStatus(400)
        ->assertJsonPath('error.unexpected_arguments', ['weight_applied', 'quarantined']);

    expect(AttendanceReport::query()->count())->toBe(0);
});

// ═══════════════════════════════════════════════════════════
// host.attendance.resolve
// ═══════════════════════════════════════════════════════════

test('host attendance resolve resolves consensus and notifies every resolved participant', function () {
    [$host, $game] = gmWriteCompletedGame();
    $attended = gmWriteApprovedPlayer($game);
    $noShow = gmWriteApprovedPlayer($game);

    Notification::fake();

    [, $bearer] = $this->mcpGmConsumer($host);

    // The host files their reports through the sibling write tool first.
    $this->postMcp($bearer, 'tools/call', [
        'name' => HostAttendanceReportTool::NAME,
        'arguments' => [
            'game_id' => $game->id,
            'reports' => [
                ['user_id' => $attended->id, 'status' => 'attended'],
                ['user_id' => $noShow->id, 'status' => 'no_show'],
            ],
        ],
    ], HostAttendanceReportTool::NAME)->assertStatus(200)->assertJsonPath('result.isError', false);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => HostAttendanceResolveTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], HostAttendanceResolveTool::NAME);

    $payload = gmWritePayload($response->assertStatus(200)->assertJsonPath('result.isError', false));

    expect($payload['status'])->toBe('attendance_resolved')
        ->and($payload['resolution']['method'])->toBe('manual')
        ->and($payload['resolution']['resolved_at'])->not->toBeNull()
        ->and($payload['summary'])->toBe(['attended' => 2, 'no_show' => 1, 'excused' => 0, 'late_cancel' => 0, 'unresolved' => 0]);

    // Consensus landed exactly as the resolver computes it: the reported
    // no_show is a no_show, the reported attended is attended, and the
    // unreported host defaults to attended.
    $statusFor = fn (User $user): ?string => $game->participants()->where('user_id', $user->id)->value('attendance_status')?->value;
    expect($statusFor($attended))->toBe('attended')
        ->and($statusFor($noShow))->toBe('no_show')
        ->and($statusFor($host))->toBe('attended');

    // Every freshly-resolved participant gets AttendanceResolved — the
    // same NotificationService fan-out the queued resolver produces.
    Notification::assertSentTo($attended, AttendanceResolved::class);
    Notification::assertSentTo($noShow, AttendanceResolved::class);
    Notification::assertSentTo($host, AttendanceResolved::class);

    expect($game->refresh()->attendance_resolved_at)->not->toBeNull()
        ->and($game->attendance_resolution_method)->toBe('manual');
});

test('host attendance resolve guards non-completed and already-resolved games', function () {
    [$host, $game] = gmWriteCompletedGame();
    $scheduledGame = Game::factory()->create([
        'owner_id' => $host->id,
        'date_time' => now()->addDays(3),
    ]);

    [, $bearer] = $this->mcpGmConsumer($host);

    // Not completed (the web action hides behind the same visibility).
    $this->postMcp($bearer, 'tools/call', [
        'name' => HostAttendanceResolveTool::NAME,
        'arguments' => ['game_id' => $scheduledGame->id],
    ], HostAttendanceResolveTool::NAME)
        ->assertStatus(200)
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'not_completed: Only a completed game\'s attendance can be resolved.');

    // Already resolved — surfaced, never a silent no-op.
    $game->forceFill(['attendance_resolved_at' => now()->subHour(), 'attendance_resolution_method' => 'timeout'])->save();

    $this->postMcp($bearer, 'tools/call', [
        'name' => HostAttendanceResolveTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], HostAttendanceResolveTool::NAME)
        ->assertStatus(200)
        ->assertJsonPath('result.isError', true)
        ->assertSee('already_resolved');

    // The refusal never re-stamped the game's resolution method.
    expect($game->refresh()->attendance_resolution_method)->toBe('timeout');
});

test('host attendance resolve refuses a requested resolution-method stamp — the stamp is system-derived', function () {
    [$host, $game] = gmWriteCompletedGame();

    [, $bearer] = $this->mcpGmConsumer($host);

    // attendance_resolution_method is a grief-resistance trust input
    // (checkGriefResistance counts uncorroborated reports in
    // early_consensus games only), so it may never be agent-chosen: the
    // schema declares no method argument and the transport's undeclared-
    // argument discipline refuses the call before dispatch.
    $this->postMcp($bearer, 'tools/call', [
        'name' => HostAttendanceResolveTool::NAME,
        'arguments' => ['game_id' => $game->id, 'method' => 'early_consensus'],
    ], HostAttendanceResolveTool::NAME)
        ->assertStatus(400)
        ->assertJsonPath('error.unexpected_arguments.0', 'method')
        ->assertJsonPath('error.accepted_arguments.0', 'game_id');

    // Refused before dispatch: no audit row, no resolution.
    expect($game->refresh()->attendance_resolved_at)->toBeNull()
        ->and($game->attendance_resolution_method)->toBeNull()
        ->and(McpCall::query()->where('tool', HostAttendanceResolveTool::NAME)->count())->toBe(0);
});

// ═══════════════════════════════════════════════════════════
// host.recap.write
// ═══════════════════════════════════════════════════════════

test('host recap write creates the journal recap and fans out RecapPosted like the web path', function () {
    [$owner, $campaign, $game, $player] = gmWriteCompletedSession();
    unset($campaign);

    Notification::fake();

    [, $bearer] = $this->mcpGmConsumer($owner);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => HostRecapWriteTool::NAME,
        'arguments' => ['game_id' => $game->id, 'body' => 'The vault door gave way at the third watch.'],
    ], HostRecapWriteTool::NAME);

    $payload = gmWritePayload($response->assertStatus(200)->assertJsonPath('result.isError', false));

    expect($payload['status'])->toBe('recap_written')
        ->and($payload['entry']['kind'])->toBe('recap')
        ->and($payload['entry']['visibility'])->toBe('players')
        ->and($payload['entry']['length'])->toBe(43)
        ->and($payload['game']['id'])->toBe($game->id);

    // The kind=recap journal entry IS the recap record — one row, campaign-
    // scoped, players-visible, authored by the host.
    $entry = CampaignJournalEntry::query()->sole();

    expect($entry->game_id)->toBe($game->id)
        ->and($entry->campaign_id)->toBe($game->campaign_id)
        ->and($entry->author_user_id)->toBe($owner->id)
        ->and($entry->body)->toBe('The vault door gave way at the third watch.');

    // Fan-out parity: every approved participant but the author.
    Notification::assertSentTo($player, RecapPosted::class);
    Notification::assertNotSentTo($owner, RecapPosted::class);
});

test('host recap write surfaces the exact guard list as structured errors', function () {
    [$owner, , $game, $player] = gmWriteCompletedSession();

    [, $ownerBearer] = $this->mcpGmConsumer($owner);

    $recap = fn (string $bearer, string $gameId, string $body) => $this->postMcp($bearer, 'tools/call', [
        'name' => HostRecapWriteTool::NAME,
        'arguments' => ['game_id' => $gameId, 'body' => $body],
    ], HostRecapWriteTool::NAME);

    // Not completed (the web action hides behind the same visibility).
    [, $scheduledCampaign] = gmWriteOwnerCampaign();
    $scheduledSession = Game::factory()->create([
        'owner_id' => $owner->id,
        'campaign_id' => $scheduledCampaign->id,
        'status' => GameStatus::Scheduled->value,
        'date_time' => now()->addDays(3),
    ]);

    $recap($ownerBearer, $scheduledSession->id, 'Too early.')
        ->assertStatus(200)->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'not_completed: The game is not completed yet; recaps are written after the session ends.');

    // One-shot games have no recap writer at all.
    [$oneShotHost, $oneShot] = gmWriteCompletedGame();
    [, $oneShotBearer] = $this->mcpGmConsumer($oneShotHost);

    // Feature tests resolve the sanctum guard once per test app: reset it
    // so the new bearer resolves its own consumer (fresh guard per request
    // is the production shape).
    auth()->forgetGuards();

    $recap($oneShotBearer, $oneShot->id, 'A one-shot epilogue.')
        ->assertStatus(200)->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'not_campaign_session: Only campaign sessions have recaps; one-shot games get an epilogue instead.');

    // Back on the owner's consumer for the remaining guards.
    auth()->forgetGuards();

    // Over the 10000-character ceiling.
    $recap($ownerBearer, $game->id, str_repeat('x', 10001))
        ->assertStatus(200)->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'body_too_long: The recap body exceeds the 10000-character limit.');

    // Empty (and whitespace-only) bodies.
    $recap($ownerBearer, $game->id, '')
        ->assertStatus(200)->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'body_empty: The recap body is empty.');

    $recap($ownerBearer, $game->id, '   ')
        ->assertStatus(200)->assertJsonPath('result.isError', true);

    // Non-host author.
    auth()->forgetGuards();
    [, $playerBearer] = $this->mcpGmConsumer($player);

    $recap($playerBearer, $game->id, 'I was not the host.')
        ->assertStatus(200)->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'not_authorized: Only the host of this game can write its recap.');

    // Back on the owner for the write-once checks.
    auth()->forgetGuards();

    // Write-once: the second successful-author attempt refuses.
    $recap($ownerBearer, $game->id, 'The one recap this session gets.')
        ->assertStatus(200)->assertJsonPath('result.isError', false);

    $recap($ownerBearer, $game->id, 'This one must not land.')
        ->assertStatus(200)->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'already_written: A recap has already been written for this session.');

    expect(CampaignJournalEntry::query()->count())->toBe(1);
});

// ═══════════════════════════════════════════════════════════
// host.charter.amend
// ═══════════════════════════════════════════════════════════

test('host charter amend publishes a minor version, carries signatures, and notifies the roster', function () {
    [$owner, $campaign] = gmWriteOwnerCampaign();
    $charter = gmWritePublishedCharter($owner, $campaign);

    $signer = gmWriteCampaignPlayer($campaign);
    CharterSigningService::sign($signer, $charter, [
        'attestations' => array_fill_keys(CharterContent::AGREEMENT_ITEMS, true),
        'private_answers' => [],
    ]);

    Notification::fake();

    [, $bearer] = $this->mcpGmConsumer($owner);

    $content = CharterContent::quickStart($campaign)->toArray();

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => HostCharterAmendTool::NAME,
        'arguments' => [
            'campaign_id' => $campaign->id,
            'title' => 'The Threshold Heist, Revised',
            'content' => $content,
            'changelog' => 'Clarified the title.',
            'expected_base_version' => 1,
        ],
    ], HostCharterAmendTool::NAME);

    $payload = gmWritePayload($response->assertStatus(200)->assertJsonPath('result.isError', false));

    expect($payload['status'])->toBe('charter_amended')
        ->and($payload['charter']['current_version'])->toBe(2)
        ->and($payload['charter']['title'])->toBe('The Threshold Heist, Revised')
        ->and($payload['amendment']['version_number'])->toBe(2)
        ->and($payload['amendment']['is_material'])->toBeFalse()
        ->and($payload['amendment']['changelog'])->toBe('Clarified the title.');

    // The version snapshot is immutable and append-only; the charter row
    // repoints to it (the identical web commit path).
    $fresh = $charter->refresh();

    expect($fresh->current_version)->toBe(2)
        ->and($fresh->versions()->count())->toBe(2);

    // Minor amendment: the signature carries onto v2.
    expect(CharterSigningService::signingProgress($fresh))->toBe([
        'approved' => 1,
        'signed' => 1,
        'percent' => 100,
    ]);

    // Fan-out parity: approved participants (never the owner) get
    // CharterAmended through the service's notification path.
    Notification::assertSentTo($signer, CharterAmended::class);
    Notification::assertNotSentTo($owner, CharterAmended::class);
});

test('host charter amend publishes a material version that resets signatures', function () {
    [$owner, $campaign] = gmWriteOwnerCampaign();
    $charter = gmWritePublishedCharter($owner, $campaign);

    $signer = gmWriteCampaignPlayer($campaign);
    CharterSigningService::sign($signer, $charter, [
        'attestations' => array_fill_keys(CharterContent::AGREEMENT_ITEMS, true),
        'private_answers' => [],
    ]);

    Notification::fake();

    [, $bearer] = $this->mcpGmConsumer($owner);

    // Material: §4 pvp flips limited → none.
    $content = CharterContent::quickStart($campaign)->toArray();
    $content['policies']['pvp'] = 'none';

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => HostCharterAmendTool::NAME,
        'arguments' => [
            'campaign_id' => $campaign->id,
            'title' => 'The Threshold Heist',
            'content' => $content,
            'changelog' => 'PvP is now off the table.',
            'expected_base_version' => 1,
        ],
    ], HostCharterAmendTool::NAME);

    $payload = gmWritePayload($response->assertStatus(200)->assertJsonPath('result.isError', false));

    expect($payload['amendment']['is_material'])->toBeTrue();

    // Every signature flips to pending re-agreement.
    expect(CharterSigningService::signingProgress($charter->refresh()))->toBe([
        'approved' => 1,
        'signed' => 0,
        'percent' => 0,
    ]);

    Notification::assertSentTo($signer, CharterAmended::class);
});

test('host charter amend refuses stale base versions and invalid input', function () {
    [$owner, $campaign] = gmWriteOwnerCampaign();
    $charter = gmWritePublishedCharter($owner, $campaign);

    [, $bearer] = $this->mcpGmConsumer($owner);

    $content = CharterContent::quickStart($campaign)->toArray();

    $amend = fn (array $overrides) => $this->postMcp($bearer, 'tools/call', [
        'name' => HostCharterAmendTool::NAME,
        'arguments' => array_merge([
            'campaign_id' => $campaign->id,
            'title' => 'The Threshold Heist',
            'content' => $content,
            'changelog' => 'First change.',
            'expected_base_version' => 1,
        ], $overrides),
    ], HostCharterAmendTool::NAME);

    // Publish v2 against base 1.
    $amend([])->assertStatus(200)->assertJsonPath('result.isError', false);

    // A second amend still built against base 1 is refused — concurrent
    // edits must not silently revert v2.
    $amend(['changelog' => 'Built on stale content.'])
        ->assertStatus(200)->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'base_version_stale: The charter changed since expected_base_version was read — fetch the charter again and re-apply your amendment.');

    // The version count pins the refusal: still v2, nothing inserted.
    expect($charter->refresh()->versions()->count())->toBe(2);

    // Empty changelog fails the service validator, structured.
    $amend(['expected_base_version' => 2, 'changelog' => ''])
        ->assertStatus(200)->assertJsonPath('result.isError', true)
        ->assertSee('invalid_charter');

    // A campaign with no charter at all.
    $bareCampaign = Campaign::factory()->create(['owner_id' => $owner->id]);

    $this->postMcp($bearer, 'tools/call', [
        'name' => HostCharterAmendTool::NAME,
        'arguments' => [
            'campaign_id' => $bareCampaign->id,
            'title' => 'No charter here',
            'content' => $content,
            'changelog' => 'Nothing to amend.',
            'expected_base_version' => 1,
        ],
    ], HostCharterAmendTool::NAME)
        ->assertStatus(200)->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'no_charter: This campaign has no charter to amend yet.');

    // Unknown campaign id answers not-found.
    $this->postMcp($bearer, 'tools/call', [
        'name' => HostCharterAmendTool::NAME,
        'arguments' => [
            'campaign_id' => '00000000-0000-0000-0000-000000000000',
            'title' => 'Ghost',
            'content' => $content,
            'changelog' => 'Ghost.',
            'expected_base_version' => 1,
        ],
    ], HostCharterAmendTool::NAME)
        ->assertStatus(200)->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'not_found: Campaign not found.');
});

// ═══════════════════════════════════════════════════════════
// Cross-cutting: non-host denials, agent audit, ability gate
// ═══════════════════════════════════════════════════════════

test('a non-host attempting each write gets the structured denial', function () {
    [$host, $game] = gmWriteCompletedGame();
    $player = gmWriteApprovedPlayer($game);
    unset($host);

    [$owner, $campaign] = gmWriteOwnerCampaign();
    gmWritePublishedCharter($owner, $campaign);
    $campaignPlayer = gmWriteCampaignPlayer($campaign);
    unset($owner);

    [, $bearer] = $this->mcpGmConsumer($player);
    auth()->forgetGuards();
    [, $campaignBearer] = $this->mcpGmConsumer($campaignPlayer);

    // Even an approved participant is not the host — the same refusal
    // the web host workspace resolves for every write here.
    $this->postMcp($bearer, 'tools/call', [
        'name' => HostAttendanceReportTool::NAME,
        'arguments' => ['game_id' => $game->id, 'reports' => [['user_id' => $player->id, 'status' => 'no_show']]],
    ], HostAttendanceReportTool::NAME)
        ->assertStatus(200)->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'not_authorized: Only the host of this game can report attendance.');

    $this->postMcp($bearer, 'tools/call', [
        'name' => HostAttendanceResolveTool::NAME,
        'arguments' => ['game_id' => $game->id],
    ], HostAttendanceResolveTool::NAME)
        ->assertStatus(200)->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'not_authorized: Only the host of this game can resolve its attendance.');

    $this->postMcp($bearer, 'tools/call', [
        'name' => HostRecapWriteTool::NAME,
        'arguments' => ['game_id' => $game->id, 'body' => 'Not my session to narrate.'],
    ], HostRecapWriteTool::NAME)
        ->assertStatus(200)->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'not_authorized: Only the host of this game can write its recap.');

    auth()->forgetGuards();
    $content = CharterContent::quickStart($campaign)->toArray();

    $this->postMcp($campaignBearer, 'tools/call', [
        'name' => HostCharterAmendTool::NAME,
        'arguments' => [
            'campaign_id' => $campaign->id,
            'title' => 'Player coup',
            'content' => $content,
            'changelog' => 'Players rewrite the charter.',
            'expected_base_version' => 1,
        ],
    ], HostCharterAmendTool::NAME)
        ->assertStatus(200)->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'not_authorized: Only the owner of this campaign can amend its charter.');

    // Not one write landed.
    expect(AttendanceReport::query()->count())->toBe(0)
        ->and(CampaignJournalEntry::query()->count())->toBe(0)
        ->and(CharterService::charterFor($campaign)->versions()->count())->toBe(1);
});

test('agent writes are marked actor=agent in the audit trail and structured log', function () {
    [$owner, , $game, $player] = gmWriteCompletedSession();
    unset($player);

    [$client, $bearer] = $this->mcpGmConsumer($owner);

    Notification::fake();
    $this->captureLogRecords();

    $this->postMcp($bearer, 'tools/call', [
        'name' => HostRecapWriteTool::NAME,
        'arguments' => ['game_id' => $game->id, 'body' => 'An agent-written recap.'],
    ], HostRecapWriteTool::NAME)->assertStatus(200)->assertJsonPath('result.isError', false);

    // The mcp_calls audit row carries the agent marker and the consumer
    // identity — observably distinct from any web-path write.
    $call = McpCall::query()->sole();

    expect($call->tool)->toBe(HostRecapWriteTool::NAME)
        ->and($call->mcp_client_id)->toBe($client->id)
        ->and($call->user_id)->toBe($owner->id)
        ->and($call->actor)->toBe('agent');

    // The structured log carries actor=agent + the consumer id alongside
    // the service's own (unmarked) recap line.
    $record = $this->logRecord('mcp.agent_write');

    expect($record)->not->toBeNull()
        ->and($record['context']['actor'])->toBe('agent')
        ->and($record['context']['action'])->toBe('recap.write')
        ->and($record['context']['consumer_id'])->toBe($client->id)
        ->and($record['context']['user_id'])->toBe($owner->id)
        ->and($record['context']['game_id'])->toBe($game->id)
        ->and($record['context'])->not->toHaveKey('body');

    // The web path (the exact call HandlesSessionEnd makes) writes the
    // recap through the identical service and fan-out — but leaves no
    // mcp_calls row and no agent marker. One audit row, one agent write.
    [$webOwner, , $webGame] = gmWriteCompletedSession();
    app(CampaignJournalService::class)->writeRecap($webGame, $webOwner, 'A web-written recap.');

    expect(CampaignJournalEntry::query()->count())->toBe(2)
        ->and(McpCall::query()->count())->toBe(1);
});

test('the host write tools require the host ability', function () {
    [, $bearer] = $this->mcpConsumer(User::factory()->create(), [McpAbility::Write->value]);

    $this->postMcp($bearer, 'tools/call', [
        'name' => HostRecapWriteTool::NAME,
        'arguments' => ['game_id' => '00000000-0000-0000-0000-000000000000', 'body' => 'x'],
    ], HostRecapWriteTool::NAME)
        ->assertStatus(403)
        ->assertJsonPath('error.message', 'This token does not have the mcp:host ability required by host.recap.write.');
});
