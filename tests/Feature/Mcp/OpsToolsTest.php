<?php

namespace Tests\Feature\Mcp;

use App\Enums\GameStatus;
use App\Enums\McpAbility;
use App\Mcp\Servers\RoundupServer;
use App\Mcp\Tools\OpsMemberLookupTool;
use App\Mcp\Tools\OpsModerationTool;
use App\Mcp\Tools\OpsTicketsTool;
use App\Models\Game;
use App\Models\McpCall;
use App\Models\Review;
use App\Models\User;
use App\Notifications\AccountSuspended;
use App\Notifications\ContentRemoved;
use Escalated\Laravel\Enums\TicketChannel;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Events\TicketAssigned;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Reply;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\Traits\CapturesLogRecords;
use Tests\Traits\InteractsWithMcp;

uses(CapturesLogRecords::class, InteractsWithMcp::class);

beforeEach(function () {
    seedRoles();
    setPermissionsTeamId(null);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
});

/*
|--------------------------------------------------------------------------
| Ops Tools (M079/S04/T01, R090)
|--------------------------------------------------------------------------
|
| The three dual-gated ops tools (mcp:ops ability AND Platform Admin
| role), exercised through the real agent surface (bearer PAT +
| mcp_clients consumer → McpAuthentication → RoundupServer dispatch).
|
| The pins here are the contract the slice verification names: every
| targeted call is audited with consumer + acting admin + target entity
| (mcp_calls.target_type/target_id — the trail that replaces ad-hoc
| SSH/artisan access), the tools call the same shared layer the Filament
| admin actions use, and the dual gate refuses non-admins at 403 for
| every tool.
|
*/

// Helpers carry an opsTool prefix — other MCP suites define same-shaped
// globals and a collision is fatal in one Pest process.

/**
 * @return array<string, mixed>
 */
function opsToolPayload(TestResponse $response): array
{
    return json_decode((string) $response->json('result.content.0.text'), true);
}

/** A dual-gate-satisfying acting admin (Platform Admin). */
function opsToolAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole('Platform Admin');

    return $admin;
}

/** The Safety department the moderation/report tickets live in. */
function opsToolSafetyDepartment(): Department
{
    return Department::firstOrCreate(
        ['name' => 'Safety'],
        ['slug' => 'safety-ops-tool', 'is_active' => true],
    );
}

/**
 * @param  array<string, mixed>  $metadata
 */
function opsToolTicket(User $requester, string $type, array $metadata = [], ?TicketStatus $status = null): Ticket
{
    return Ticket::create([
        'requester_type' => (new User)->getMorphClass(),
        'requester_id' => $requester->id,
        'subject' => 'Ops tool test ticket',
        'description' => 'Filed from the ops tool suite.',
        'status' => ($status ?? TicketStatus::Open)->value,
        'priority' => TicketPriority::Medium->value,
        'department_id' => opsToolSafetyDepartment()->id,
        'ticket_type' => $type,
        'channel' => TicketChannel::Web->value,
        'metadata' => $metadata,
    ]);
}

/**
 * A published game owned by $owner carrying a host-uploaded cover —
 * the entity the clear_cover takedown path operates on.
 *
 * @return array{0: Game, 1: File}
 */
function opsToolGameWithCover(User $owner): array
{
    $game = Game::factory()->create([
        'owner_id' => $owner->id,
        'status' => GameStatus::Scheduled->value,
        'name' => ['en' => 'Cover Test Table'],
    ]);

    $game->addMedia(File::fake()->image('cover.jpg', 800, 600))
        ->toMediaCollection('cover');

    return [$game->fresh(), $game->getFirstMedia('cover')];
}

// ═══════════════════════════════════════════════════════════
// Dual gate (D277): ability AND role, every tool
// ═══════════════════════════════════════════════════════════

test('every ops tool refuses a non-admin token even with the ops scope', function () {
    $regularUser = User::factory()->create();
    [, $bearer] = $this->mcpConsumer($regularUser, [McpAbility::Ops->value]);

    foreach ([OpsTicketsTool::NAME, OpsMemberLookupTool::NAME, OpsModerationTool::NAME] as $tool) {
        $this->postMcp($bearer, 'tools/call', ['name' => $tool, 'arguments' => []], $tool)
            ->assertStatus(403)
            ->assertJsonPath('error.message', 'The ops surface requires an active Platform Admin.');
    }

    // A demoted admin's consumer dies the same way — the gate re-checks the
    // role at dispatch time, not at issuance time.
    $demotedAdmin = opsToolAdmin();
    [, $demotedBearer] = $this->mcpConsumer($demotedAdmin, [McpAbility::Ops->value]);
    $demotedAdmin->removeRole('Platform Admin');

    $this->postMcp($demotedBearer, 'tools/call', ['name' => OpsTicketsTool::NAME, 'arguments' => ['action' => 'list']], OpsTicketsTool::NAME)
        ->assertStatus(403)
        ->assertJsonPath('error.message', 'The ops surface requires an active Platform Admin.');

    // Nothing was dispatched: no audit rows, only gate rejections.
    expect(McpCall::query()->count())->toBe(0);
});

test('every ops tool refuses a regular participant-scope token and an admin token without the ops scope', function () {
    // A regular user with valid participant scopes lacks the ability.
    [, $participantBearer] = $this->mcpConsumer(User::factory()->create(), [McpAbility::Read->value, McpAbility::Write->value]);

    // An admin without the ops scope lacks the ability too — dual gate,
    // both sides, not either.
    [, $readAdminBearer] = $this->mcpConsumer(opsToolAdmin(), [McpAbility::Read->value]);

    foreach ([OpsTicketsTool::NAME, OpsMemberLookupTool::NAME, OpsModerationTool::NAME] as $tool) {
        foreach ([$participantBearer, $readAdminBearer] as $bearer) {
            $this->postMcp($bearer, 'tools/call', ['name' => $tool, 'arguments' => []], $tool)
                ->assertStatus(403)
                ->assertJsonPath('error.message', "This token does not have the mcp:ops ability required by {$tool}.");
        }
    }
});

// ═══════════════════════════════════════════════════════════
// ops.tickets
// ═══════════════════════════════════════════════════════════

test('ops tickets lists the triage queue through the ticket service', function () {
    $admin = opsToolAdmin();
    $requester = User::factory()->create();
    $secondAdmin = opsToolAdmin();

    $open = opsToolTicket($requester, 'question');

    // Distinct created_at: the vendor list orders by created_at DESC with
    // no id tiebreak, so same-second creations order nondeterministically
    // under parallel load (caught by the pre-push gate, 2026-11-11).
    $this->travel(1)->seconds();

    $assigned = opsToolTicket($requester, 'content_report');
    $assigned->update(['assigned_to' => $secondAdmin->id]);
    opsToolTicket($requester, 'question', [], TicketStatus::Resolved);

    [, $bearer] = $this->mcpConsumer($admin, [McpAbility::Ops->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => OpsTicketsTool::NAME,
        'arguments' => ['action' => 'list', 'open' => true],
    ], OpsTicketsTool::NAME);

    $payload = opsToolPayload($response->assertStatus(200)->assertJsonPath('result.isError', false));

    $ids = array_column($payload['tickets'], 'id');

    expect($payload['status'])->toBe('listed')
        ->and($payload['pagination']['total'])->toBe(2)
        ->and($ids)->toBe([$assigned->id, $open->id]) // newest first
        ->and($payload['tickets'][0]['assignee']['id'])->toBe($secondAdmin->id)
        ->and($payload['tickets'][1]['status'])->toBe('open');

    $row = $payload['tickets'][1];
    expect($row['reference'])->toBe($open->reference)
        ->and($row['requester']['id'])->toBe($requester->id)
        ->and($row['department'])->toBe('Safety')
        ->and($row['url'])->toBe('/admin/tickets/'.$open->reference);

    // A status filter narrows the same pipeline.
    $resolved = $this->postMcp($bearer, 'tools/call', [
        'name' => OpsTicketsTool::NAME,
        'arguments' => ['action' => 'list', 'status' => 'resolved'],
    ], OpsTicketsTool::NAME);

    $resolvedPayload = opsToolPayload($resolved->assertStatus(200));

    expect($resolvedPayload['pagination']['total'])->toBe(1)
        ->and($resolvedPayload['tickets'][0]['status'])->toBe('resolved');
});

test('ops tickets list rejects an unknown department filter', function () {
    [, $bearer] = $this->mcpConsumer(opsToolAdmin(), [McpAbility::Ops->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => OpsTicketsTool::NAME,
        'arguments' => ['action' => 'list', 'department' => 'No Such Department'],
    ], OpsTicketsTool::NAME);

    $response->assertStatus(200)->assertJsonPath('result.isError', true);

    expect((string) $response->json('result.content.0.text'))->toStartWith('unknown_department:');
});

test('ops tickets assigns a ticket to a platform admin with the same call the filament escalation uses and links the audit row', function () {
    $this->captureLogRecords();

    $admin = opsToolAdmin();
    $target = opsToolAdmin();
    $ticket = opsToolTicket(User::factory()->create(), 'question');

    [$client, $bearer] = $this->mcpConsumer($admin, [McpAbility::Ops->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => OpsTicketsTool::NAME,
        'arguments' => [
            'action' => 'assign',
            'ticket_id' => $ticket->id,
            'assignee_id' => $target->id,
            'note' => 'Taking this one.',
        ],
    ], OpsTicketsTool::NAME);

    $payload = opsToolPayload($response->assertStatus(200)->assertJsonPath('result.isError', false));

    expect($payload['status'])->toBe('assigned')
        ->and($payload['ticket']['assignee']['id'])->toBe($target->id);

    // The assignment went through the shared layer: assigned_to moved and
    // the internal note landed as a reply on the ticket.
    /** @var Ticket $fresh */
    $fresh = $ticket->fresh();
    expect($fresh->assigned_to)->toBe($target->id)
        ->and($fresh->activities()->where('type', 'assigned')->exists())->toBeTrue();

    expect(Reply::query()->where('ticket_id', $ticket->id)->where('is_internal_note', true)->value('body'))->toContain('Taking this one.');

    // The audit row carries consumer + admin + the target ticket — the
    // SSH-replacement trail.
    $call = McpCall::query()->sole();

    expect($call->tool)->toBe(OpsTicketsTool::NAME)
        ->and($call->mcp_client_id)->toBe($client->id)
        ->and($call->user_id)->toBe($admin->id)
        ->and($call->actor)->toBe('agent')
        ->and($call->target_type)->toBe('ticket')
        ->and($call->target_id)->toBe((string) $ticket->id);

    $record = $this->logRecord('mcp.agent_write');

    expect($record)->not->toBeNull()
        ->and($record['context']['action'])->toBe('ops.tickets.assign')
        ->and($record['context']['consumer_id'])->toBe($client->id)
        ->and($record['context']['ticket_id'])->toBe($ticket->id)
        ->and($record['context']['assignee_id'])->toBe($target->id);
});

test('ops tickets resolve marks the ticket resolved with an internal note', function () {
    $admin = opsToolAdmin();
    $ticket = opsToolTicket(User::factory()->create(), 'question');

    [$client, $bearer] = $this->mcpConsumer($admin, [McpAbility::Ops->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => OpsTicketsTool::NAME,
        'arguments' => ['action' => 'resolve', 'ticket_id' => $ticket->id, 'note' => 'Handled over MCP.'],
    ], OpsTicketsTool::NAME);

    $payload = opsToolPayload($response->assertStatus(200)->assertJsonPath('result.isError', false));

    expect($payload['status'])->toBe('resolved')
        ->and($payload['ticket']['status'])->toBe('resolved');

    expect($ticket->fresh()->status)->toBe(TicketStatus::Resolved);
    expect(Reply::query()->where('ticket_id', $ticket->id)->where('is_internal_note', true)->count())->toBe(1);

    $call = McpCall::query()->sole();

    expect($call->target_type)->toBe('ticket')
        ->and($call->target_id)->toBe((string) $ticket->id)
        ->and($call->user_id)->toBe($admin->id)
        ->and($call->mcp_client_id)->toBe($client->id);
});

test('ops tickets refuse invalid targets, closed tickets, and non-admin assignees', function () {
    [, $bearer] = $this->mcpConsumer(opsToolAdmin(), [McpAbility::Ops->value]);

    $call = fn (array $arguments) => $this->postMcp($bearer, 'tools/call', [
        'name' => OpsTicketsTool::NAME,
        'arguments' => $arguments,
    ], OpsTicketsTool::NAME);

    // Unknown ticket.
    $call(['action' => 'resolve', 'ticket_id' => 999999])
        ->assertStatus(200)->assertJsonPath('result.isError', true);
    expect((string) $call(['action' => 'resolve', 'ticket_id' => 999999])->json('result.content.0.text'))->toStartWith('not_found:');

    // Missing assignee_id / ticket_id.
    expect((string) $call(['action' => 'assign', 'ticket_id' => 1])->json('result.content.0.text'))->toStartWith('invalid_arguments:');
    expect((string) $call(['action' => 'resolve'])->json('result.content.0.text'))->toStartWith('invalid_arguments:');

    // Already resolved ticket.
    $resolved = opsToolTicket(User::factory()->create(), 'question', [], TicketStatus::Resolved);
    expect((string) $call(['action' => 'resolve', 'ticket_id' => $resolved->id])->json('result.content.0.text'))->toStartWith('already_resolved:');

    // Closed ticket cannot be reassigned.
    $closed = opsToolTicket(User::factory()->create(), 'question', [], TicketStatus::Closed);
    $adminId = opsToolAdmin()->id;
    expect((string) $call(['action' => 'assign', 'ticket_id' => $closed->id, 'assignee_id' => $adminId])->json('result.content.0.text'))->toStartWith('not_open:');

    // Non-admin assignee refused.
    $open = opsToolTicket(User::factory()->create(), 'question');
    $regularUserId = User::factory()->create()->id;
    expect((string) $call(['action' => 'assign', 'ticket_id' => $open->id, 'assignee_id' => $regularUserId])->json('result.content.0.text'))->toStartWith('assignee_not_admin:');
});

// ═══════════════════════════════════════════════════════════
// ops.member.lookup
// ═══════════════════════════════════════════════════════════

test('ops member lookup by email returns the support view and links the looked-up member in the audit row', function () {
    $member = User::factory()->create([
        'email' => 'Member@Example.test',
        'profile_complete' => true,
        'reliability_score' => ['score' => 96.5, 'game_count' => 12, 'tier' => 'reliable', 'weights_applied' => []],
    ]);
    $member->assignRole('Games Admin');

    [$admin, $client, $bearer] = [opsToolAdmin(), null, null];
    [$client, $bearer] = $this->mcpConsumer($admin, [McpAbility::Ops->value]);

    // Case-insensitive email match, like the admin user table.
    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => OpsMemberLookupTool::NAME,
        'arguments' => ['email' => 'member@example.test'],
    ], OpsMemberLookupTool::NAME);

    $payload = opsToolPayload($response->assertStatus(200)->assertJsonPath('result.isError', false));

    expect($payload['status'])->toBe('found')
        ->and($payload['user']['id'])->toBe($member->id)
        ->and($payload['user']['email'])->toBe('Member@Example.test')
        ->and($payload['user']['account']['is_disabled'])->toBeFalse()
        ->and($payload['user']['account']['email_verified'])->toBeTrue()
        ->and($payload['user']['account']['profile_complete'])->toBeTrue()
        ->and($payload['user']['roles'])->toBe(['Games Admin'])
        ->and($payload['user']['reliability']['tier'])->toBe('reliable')
        ->and($payload['user']['reliability']['games'])->toBe(12)
        ->and($payload['user']['membership']['supporting_member'])->toBeFalse();

    // The payload is the support subset — no raw PII dump.
    expect($payload['user'])->not->toHaveKeys(['phone', 'gender', 'pronouns', 'bio', 'timezone', 'location_id', 'address']);

    // A lookup is itself an audited act: target = the looked-up member.
    $call = McpCall::query()->sole();

    expect($call->tool)->toBe(OpsMemberLookupTool::NAME)
        ->and($call->mcp_client_id)->toBe($client->id)
        ->and($call->user_id)->toBe($admin->id)
        ->and($call->actor)->toBe('agent')
        ->and($call->target_type)->toBe('user')
        ->and($call->target_id)->toBe($member->id);

    // By id answers identically.
    auth()->forgetGuards();

    $byId = $this->postMcp($bearer, 'tools/call', [
        'name' => OpsMemberLookupTool::NAME,
        'arguments' => ['user_id' => $member->id],
    ], OpsMemberLookupTool::NAME);

    expect(opsToolPayload($byId->assertStatus(200))['user']['id'])->toBe($member->id);

    expect(McpCall::query()->count())->toBe(2);
});

test('ops member lookup surfaces the ban state and refuses ambiguous or unknown lookups', function () {
    [, $bearer] = $this->mcpConsumer(opsToolAdmin(), [McpAbility::Ops->value]);

    $suspended = User::factory()->create([
        'is_disabled' => true,
        'disabled_at' => now()->subDay(),
    ]);

    $call = fn (array $arguments) => $this->postMcp($bearer, 'tools/call', [
        'name' => OpsMemberLookupTool::NAME,
        'arguments' => $arguments,
    ], OpsMemberLookupTool::NAME);

    // Ban state is the headline flag.
    $payload = opsToolPayload($call(['user_id' => $suspended->id])->assertStatus(200));

    expect($payload['user']['account']['is_disabled'])->toBeTrue()
        ->and($payload['user']['account']['disabled_at'])->not->toBeNull();

    // Unknown member.
    expect((string) $call(['email' => 'ghost@example.test'])->json('result.content.0.text'))->toStartWith('not_found:');

    // Neither argument / both arguments.
    expect((string) $call([])->json('result.content.0.text'))->toStartWith('invalid_arguments:');
    expect((string) $call(['user_id' => $suspended->id, 'email' => 'ghost@example.test'])->json('result.content.0.text'))->toStartWith('invalid_arguments:');

    // A refused lookup must not be audited with a target it never resolved.
    expect(McpCall::query()->whereNotNull('target_id')->count())->toBe(1);
});

// ═══════════════════════════════════════════════════════════
// ops.moderation
// ═══════════════════════════════════════════════════════════

test('ops moderation clear_cover wraps the filament action and audits the takedown', function () {
    Notification::fake();
    $this->captureLogRecords();

    $admin = opsToolAdmin();
    $owner = User::factory()->create();
    [$game] = opsToolGameWithCover($owner);

    $ticket = opsToolTicket(User::factory()->create(), 'content_report', [
        'entity_type' => 'game',
        'entity_id' => $game->id,
        'entity_name' => 'Cover Test Table',
        'report_reason' => 'inappropriate image',
    ]);

    [$client, $bearer] = $this->mcpConsumer($admin, [McpAbility::Ops->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => OpsModerationTool::NAME,
        'arguments' => ['action' => 'clear_cover', 'ticket_id' => $ticket->id],
    ], OpsModerationTool::NAME);

    $payload = opsToolPayload($response->assertStatus(200)->assertJsonPath('result.isError', false));

    expect($payload['status'])->toBe('completed')
        ->and($payload['ticket']['status'])->toBe('closed')
        ->and($payload['ticket']['entity']['has_cover'])->toBeFalse();

    // The wrapped action did the work: cover gone, ticket closed, owner
    // notified through the exact ContentReport path.
    expect($game->fresh()->hasCover())->toBeFalse()
        ->and($ticket->fresh()->status)->toBe(TicketStatus::Closed);

    Notification::assertSentTo($owner, ContentRemoved::class);

    $call = McpCall::query()->sole();

    expect($call->tool)->toBe(OpsModerationTool::NAME)
        ->and($call->target_type)->toBe('ticket')
        ->and($call->target_id)->toBe((string) $ticket->id)
        ->and($call->user_id)->toBe($admin->id)
        ->and($call->mcp_client_id)->toBe($client->id);

    expect($this->logRecord('mcp.agent_write')['context']['action'])->toBe('ops.moderation.clear_cover');
});

test('ops moderation suspend_user disables the reported member through the shared action', function () {
    Notification::fake();

    $admin = opsToolAdmin();
    $reported = User::factory()->create();

    $ticket = opsToolTicket(User::factory()->create(), 'content_report', [
        'entity_type' => 'user',
        'entity_id' => $reported->id,
        'entity_name' => $reported->name,
        'report_reason' => 'harassment',
    ]);

    [, $bearer] = $this->mcpConsumer($admin, [McpAbility::Ops->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => OpsModerationTool::NAME,
        'arguments' => ['action' => 'suspend_user', 'ticket_id' => $ticket->id],
    ], OpsModerationTool::NAME);

    $payload = opsToolPayload($response->assertStatus(200)->assertJsonPath('result.isError', false));

    expect($payload['ticket']['reported_user']['is_disabled'])->toBeTrue()
        ->and($payload['ticket']['status'])->toBe('closed');

    expect($reported->fresh()->is_disabled)->toBeTrue()
        ->and($reported->fresh()->disabled_at)->not->toBeNull()
        ->and($ticket->fresh()->status)->toBe(TicketStatus::Closed);

    Notification::assertSentTo($reported, AccountSuspended::class);

    expect(McpCall::query()->sole()->target_id)->toBe((string) $ticket->id);
});

test('ops moderation remove_review hides the review and dismiss keeps content intact', function () {
    Notification::fake();

    $admin = opsToolAdmin();
    $review = Review::factory()->create(['status' => 'reported']);

    $removeTicket = opsToolTicket(User::factory()->create(), 'review_report', [
        'review_id' => $review->id,
        'report_reason' => 'unfair',
    ]);

    $dismissTarget = User::factory()->create();
    $dismissGame = Game::factory()->create(['owner_id' => $dismissTarget->id, 'status' => GameStatus::Scheduled->value]);
    $dismissTicket = opsToolTicket(User::factory()->create(), 'content_report', [
        'entity_type' => 'game',
        'entity_id' => $dismissGame->id,
        'entity_name' => 'Some game',
        'report_reason' => 'spam',
    ]);

    [, $bearer] = $this->mcpConsumer($admin, [McpAbility::Ops->value]);

    $remove = $this->postMcp($bearer, 'tools/call', [
        'name' => OpsModerationTool::NAME,
        'arguments' => ['action' => 'remove_review', 'ticket_id' => $removeTicket->id],
    ], OpsModerationTool::NAME);

    $removePayload = opsToolPayload($remove->assertStatus(200)->assertJsonPath('result.isError', false));

    expect($removePayload['ticket']['review_status'])->toBe('hidden')
        ->and($removePayload['ticket']['status'])->toBe('closed');

    expect($review->fresh()->status)->toBe('hidden')
        ->and($removeTicket->fresh()->status)->toBe(TicketStatus::Closed);

    auth()->forgetGuards();

    $dismiss = $this->postMcp($bearer, 'tools/call', [
        'name' => OpsModerationTool::NAME,
        'arguments' => ['action' => 'dismiss', 'ticket_id' => $dismissTicket->id],
    ], OpsModerationTool::NAME);

    $dismissPayload = opsToolPayload($dismiss->assertStatus(200)->assertJsonPath('result.isError', false));

    expect($dismissPayload['ticket']['status'])->toBe('closed')
        ->and($dismissGame->fresh()->status)->toBe(GameStatus::Scheduled) // untouched
        ->and($dismissTicket->fresh()->status)->toBe(TicketStatus::Closed);
});

test('ops moderation escalate mirrors the filament escalation', function () {
    $this->captureLogRecords();

    $admin = opsToolAdmin();
    opsToolAdmin(); // a second admin exists for the reassignment

    $ticket = opsToolTicket(User::factory()->create(), 'content_report', [
        'entity_type' => 'game',
        'entity_id' => '00000000-0000-0000-0000-000000000001',
        'entity_name' => 'Some game',
        'report_reason' => 'spam',
    ]);

    [, $bearer] = $this->mcpConsumer($admin, [McpAbility::Ops->value]);

    $response = $this->postMcp($bearer, 'tools/call', [
        'name' => OpsModerationTool::NAME,
        'arguments' => ['action' => 'escalate', 'ticket_id' => $ticket->id],
    ], OpsModerationTool::NAME);

    $payload = opsToolPayload($response->assertStatus(200)->assertJsonPath('result.isError', false));

    expect($payload['status'])->toBe('completed')
        ->and($payload['ticket']['priority'])->toBe('urgent');

    expect($ticket->fresh()->priority)->toBe(TicketPriority::Urgent)
        ->and($ticket->fresh()->status)->toBe(TicketStatus::Open); // escalated stays open
});

test('ops tickets assign fires its webhook-carrying event outside the transaction', function () {
    // M079 review: TicketAssigned's DispatchWebhook listener makes a
    // blocking Http::timeout(10) call. Firing it inside the ops tool's DB
    // transaction would hold the row locks for the whole webhook window —
    // the ContentReport afterCommit precedent this tool now follows.
    $admin = opsToolAdmin();
    $target = opsToolAdmin();
    $ticket = opsToolTicket(User::factory()->create(), 'question');

    $levelAtDispatch = null;
    Event::listen(TicketAssigned::class, function () use (&$levelAtDispatch): void {
        $levelAtDispatch = DB::transactionLevel();
    });

    // Under DatabaseTransactions the suite itself wraps the test at level 1;
    // in production the ambient level is 0. The invariant under test is that
    // the event fires only after the tool's transaction fully unwound —
    // i.e. at the ambient level, not one deeper.
    $ambientLevel = DB::transactionLevel();

    [, $bearer] = $this->mcpConsumer($admin, [McpAbility::Ops->value]);

    $this->postMcp($bearer, 'tools/call', [
        'name' => OpsTicketsTool::NAME,
        'arguments' => ['action' => 'assign', 'ticket_id' => $ticket->id, 'assignee_id' => $target->id],
    ], OpsTicketsTool::NAME)
        ->assertStatus(200)->assertJsonPath('result.isError', false);

    expect($levelAtDispatch)->toBe($ambientLevel)
        ->and($ticket->fresh()->assigned_to)->toBe($target->id);
});

test('ops moderation reports a completed escalate on an already-urgent ticket', function () {
    // M079 review: urgency alone cannot prove completion when the ticket was
    // already urgent — the old updated_at comparison false-failed when the
    // action landed within the same DB second. Completion proof is the new
    // escalation note (reply row) the committed transaction always writes.
    $admin = opsToolAdmin();
    opsToolAdmin(); // a second admin exists for the reassignment

    $ticket = opsToolTicket(User::factory()->create(), 'content_report', [
        'entity_type' => 'game',
        'entity_id' => '00000000-0000-0000-0000-000000000001',
        'entity_name' => 'Some game',
        'report_reason' => 'spam',
    ]);
    $ticket->update(['priority' => TicketPriority::Urgent->value]);

    [, $bearer] = $this->mcpConsumer($admin, [McpAbility::Ops->value]);

    $payload = opsToolPayload($this->postMcp($bearer, 'tools/call', [
        'name' => OpsModerationTool::NAME,
        'arguments' => ['action' => 'escalate', 'ticket_id' => $ticket->id],
    ], OpsModerationTool::NAME)->assertStatus(200)->assertJsonPath('result.isError', false));

    // No time travel needed: even at second granularity the new note row
    // proves the transaction committed.
    expect($payload['status'])->toBe('completed')
        ->and($payload['ticket']['priority'])->toBe('urgent');
});

test('ops moderation refuses wrong-ticket-type actions, closed tickets, and coverless entities', function () {
    [, $bearer] = $this->mcpConsumer(opsToolAdmin(), [McpAbility::Ops->value]);

    $call = fn (array $arguments) => $this->postMcp($bearer, 'tools/call', [
        'name' => OpsModerationTool::NAME,
        'arguments' => $arguments,
    ], OpsModerationTool::NAME);

    // remove_review does not exist for content_report tickets.
    $contentTicket = opsToolTicket(User::factory()->create(), 'content_report', [
        'entity_type' => 'game',
        'entity_id' => '00000000-0000-0000-0000-000000000002',
        'entity_name' => 'Some game',
    ]);
    expect((string) $call(['action' => 'remove_review', 'ticket_id' => $contentTicket->id])->json('result.content.0.text'))->toStartWith('wrong_ticket_type:');

    // warn_user does not exist for review_report tickets.
    $reviewTicket = opsToolTicket(User::factory()->create(), 'review_report', ['review_id' => 1]);
    expect((string) $call(['action' => 'warn_user', 'ticket_id' => $reviewTicket->id])->json('result.content.0.text'))->toStartWith('wrong_ticket_type:');

    // A moderation action on a non-report ticket type has no actions at all.
    $questionTicket = opsToolTicket(User::factory()->create(), 'question');
    expect((string) $call(['action' => 'dismiss', 'ticket_id' => $questionTicket->id])->json('result.content.0.text'))->toStartWith('wrong_ticket_type:');

    // A report ticket routed into a NON-Safety department carries no
    // moderation actions in the admin panel (the wrapped actions' gates
    // require the Safety department) — the tool must mirror that, not
    // only the ticket-type half of the gate.
    $billing = Department::firstOrCreate(
        ['name' => 'Billing'],
        ['slug' => 'billing-ops-tool', 'is_active' => true],
    );
    $misrouted = Ticket::create([
        'requester_type' => (new User)->getMorphClass(),
        'requester_id' => User::factory()->create()->id,
        'subject' => 'Mis-routed content report',
        'description' => 'Routed outside Safety.',
        'status' => TicketStatus::Open->value,
        'priority' => TicketPriority::Medium->value,
        'department_id' => $billing->id,
        'ticket_type' => 'content_report',
        'channel' => TicketChannel::Web->value,
        'metadata' => ['entity_type' => 'game', 'entity_id' => '00000000-0000-0000-0000-000000000003', 'entity_name' => 'Some game'],
    ]);
    expect((string) $call(['action' => 'dismiss', 'ticket_id' => $misrouted->id])->json('result.content.0.text'))->toStartWith('wrong_department:')
        ->and($misrouted->fresh()->status)->toBe(TicketStatus::Open); // nothing ran

    // Unknown ticket.
    expect((string) $call(['action' => 'dismiss', 'ticket_id' => 999999])->json('result.content.0.text'))->toStartWith('not_found:');

    // Closed tickets cannot be actioned.
    $closed = opsToolTicket(User::factory()->create(), 'content_report', ['entity_type' => 'game', 'entity_id' => '00000000-0000-0000-0000-000000000003'], TicketStatus::Closed);
    expect((string) $call(['action' => 'dismiss', 'ticket_id' => $closed->id])->json('result.content.0.text'))->toStartWith('not_open:');

    // clear_cover mirrors the visibility gate: no host cover, no action.
    $owner = User::factory()->create();
    $game = Game::factory()->create(['owner_id' => $owner->id, 'status' => GameStatus::Scheduled->value]);
    $coverless = opsToolTicket(User::factory()->create(), 'content_report', [
        'entity_type' => 'game',
        'entity_id' => $game->id,
        'entity_name' => 'No cover game',
    ]);
    expect((string) $call(['action' => 'clear_cover', 'ticket_id' => $coverless->id])->json('result.content.0.text'))->toStartWith('no_cover:')
        ->and($game->fresh()->status)->toBe(GameStatus::Scheduled)
        ->and($coverless->fresh()->status)->toBe(TicketStatus::Open);
});

test('the ops tools are registered on the server with the ops ability', function () {
    expect(RoundupServer::requiredAbilityFor(OpsTicketsTool::NAME))->toBe(McpAbility::Ops)
        ->and(RoundupServer::requiredAbilityFor(OpsMemberLookupTool::NAME))->toBe(McpAbility::Ops)
        ->and(RoundupServer::requiredAbilityFor(OpsModerationTool::NAME))->toBe(McpAbility::Ops)
        ->and(RoundupServer::declaredArgumentsFor(OpsTicketsTool::NAME))->toContain('action', 'ticket_id', 'assignee_id', 'note');
});
