<?php

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Filament\Resources\TicketResource\Pages\ViewTicket;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Notifications\AccountSuspended;
use App\Notifications\ContentRemoved;
use App\Notifications\ContentReportWarning;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Events\TicketAssigned;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification as NotificationFacade;

beforeEach(function () {
    seedRoles();

    // Set up the Safety department
    Department::firstOrCreate(['name' => 'Safety'], ['description' => 'Safety and moderation department']);

    // Create a Platform Admin user for escalation tests
    $this->platformAdmin = User::factory()->create(['profile_complete' => true]);
    $this->platformAdmin->assignRole('Platform Admin');

    // Create an agent user who performs the actions
    $this->agent = User::factory()->create(['profile_complete' => true]);
    $this->agent->assignRole('Service Admin');
});

/**
 * Create a content report ticket for a user profile.
 */
function createUserReportTicket(): array
{
    $reportedUser = User::factory()->create(['profile_complete' => true]);
    $reporter = User::factory()->create(['profile_complete' => true]);
    $department = Department::where('name', 'Safety')->first();

    $ticket = Ticket::create([
        'requester_type' => User::class,
        'requester_id' => $reporter->id,
        'subject' => 'User Report: Harassment',
        'description' => 'Reported user content...',
        'status' => TicketStatus::Open->value,
        'priority' => TicketPriority::High->value,
        'department_id' => $department?->id,
        'ticket_type' => 'content_report',
        'metadata' => [
            'entity_type' => 'user',
            'entity_id' => $reportedUser->id,
            'entity_name' => $reportedUser->name,
            'reporter_id' => $reporter->id,
            'report_reason' => 'harassment',
            'description' => 'This user is harassing other players.',
        ],
    ]);

    return compact('ticket', 'reportedUser', 'reporter', 'department');
}

/**
 * Create a content report ticket for a game.
 */
function createGameReportTicket(): array
{
    $gm = User::factory()->create(['profile_complete' => true]);
    $reporter = User::factory()->create(['profile_complete' => true]);
    $department = Department::where('name', 'Safety')->first();

    $game = Game::factory()->create([
        'owner_id' => $gm->id,
        'date_time' => now()->addDay(),
        'status' => GameStatus::Scheduled,
    ]);

    $ticket = Ticket::create([
        'requester_type' => User::class,
        'requester_id' => $reporter->id,
        'subject' => 'Game Report: Inappropriate Content',
        'description' => 'Reported game content...',
        'status' => TicketStatus::Open->value,
        'priority' => TicketPriority::High->value,
        'department_id' => $department?->id,
        'ticket_type' => 'content_report',
        'metadata' => [
            'entity_type' => 'game',
            'entity_id' => $game->id,
            'entity_name' => $game->name,
            'reporter_id' => $reporter->id,
            'report_reason' => 'inappropriate-content',
            'description' => 'This game has inappropriate content.',
        ],
    ]);

    return compact('ticket', 'gm', 'reporter', 'game', 'department');
}

/**
 * Create a content report ticket for a campaign.
 */
function createCampaignReportTicket(): array
{
    $owner = User::factory()->create(['profile_complete' => true]);
    $reporter = User::factory()->create(['profile_complete' => true]);
    $department = Department::where('name', 'Safety')->first();

    $campaign = Campaign::factory()->create([
        'owner_id' => $owner->id,
        'status' => CampaignStatus::Active,
    ]);

    $ticket = Ticket::create([
        'requester_type' => User::class,
        'requester_id' => $reporter->id,
        'subject' => 'Campaign Report: Spam',
        'description' => 'Reported campaign content...',
        'status' => TicketStatus::Open->value,
        'priority' => TicketPriority::High->value,
        'department_id' => $department?->id,
        'ticket_type' => 'content_report',
        'metadata' => [
            'entity_type' => 'campaign',
            'entity_id' => $campaign->id,
            'entity_name' => $campaign->name,
            'reporter_id' => $reporter->id,
            'report_reason' => 'spam',
            'description' => 'This campaign is spam.',
        ],
    ]);

    return compact('ticket', 'owner', 'reporter', 'campaign', 'department');
}

/**
 * Invoke a protected perform* moderation method on the ViewTicket page via
 * reflection. This tests the REAL moderation logic (the same code the Filament
 * action runs) instead of stubbing it with TicketService calls.
 *
 * The caller is responsible for `$this->actingAs(...)` first — every perform*
 * method reads `auth()->user()` and bails out if there is none.
 *
 * @param  string  $method  The perform* method name on ViewTicket.
 * @param  mixed  ...$args  Positional args forwarded to the method.
 */
function invokeModerationAction(string $method, mixed ...$args): void
{
    $page = new ViewTicket;
    $reflection = new ReflectionMethod($page, $method);
    $reflection->setAccessible(true);
    $reflection->invoke($page, ...$args);
}

// ── Dismiss Action Tests ────────────────────────────────────────────────

it('dismiss action closes ticket with no action on reported user', function () {
    ['ticket' => $ticket, 'reportedUser' => $reportedUser] = createUserReportTicket();

    $this->actingAs($this->agent);
    invokeModerationAction('performDismissContentReport', $ticket);

    $ticket->refresh();
    $reportedUser->refresh();

    expect($ticket->status)->toBe(TicketStatus::Closed);
    // Dismiss must not mutate the reported user — no suspension, no warning.
    expect($reportedUser->is_disabled)->toBeFalse();
    expect($reportedUser->disabled_at)->toBeNull();
});

it('dismiss action adds internal note to ticket', function () {
    ['ticket' => $ticket] = createUserReportTicket();

    $this->actingAs($this->agent);
    invokeModerationAction('performDismissContentReport', $ticket);

    $ticket->refresh();
    $notes = $ticket->internalNotes;

    expect($notes)->toHaveCount(1);
    expect($notes->first()->body)->toContain('dismissed');
    expect($notes->first()->is_internal_note)->toBeTrue();
});

// ── Warn User Action Tests ─────────────────────────────────────────────

it('warn user action sends ContentReportWarning notification to reported user', function () {
    NotificationFacade::fake();

    ['ticket' => $ticket, 'reportedUser' => $reportedUser] = createUserReportTicket();

    $this->actingAs($this->agent);
    invokeModerationAction('performWarnUser', $ticket, 'user', $reportedUser->name, null);

    // Warning must land on the reported user with the entity type + report
    // reason drawn from the moderation flow, not a hand-built notification.
    NotificationFacade::assertSentTo($reportedUser, ContentReportWarning::class, function ($notification) {
        return $notification->entityType === 'user'
            && $notification->entityName !== ''
            && $notification->reason === 'harassment';
    });

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Closed);
});

it('warn user action does not disable the user account', function () {
    ['ticket' => $ticket, 'reportedUser' => $reportedUser] = createUserReportTicket();

    $this->actingAs($this->agent);
    invokeModerationAction('performWarnUser', $ticket, 'user', $reportedUser->name, null);

    // A warning is advisory only — the account stays active.
    $reportedUser->refresh();
    expect($reportedUser->is_disabled)->toBeFalse();
    expect($reportedUser->disabled_at)->toBeNull();
});

// ── Remove Content Action Tests ────────────────────────────────────────

it('remove content action cancels reported game', function () {
    ['ticket' => $ticket, 'game' => $game] = createGameReportTicket();

    $this->actingAs($this->agent);
    // The real removeGame() dispatch flips status to Canceled — no manual
    // $game->update() in the test.
    invokeModerationAction('performRemoveContent', $ticket, 'game', $game->name);

    $ticket->refresh();
    $game->refresh();

    expect($ticket->status)->toBe(TicketStatus::Closed);
    expect($game->status)->toBe(GameStatus::Canceled);
});

it('remove content action cancels reported campaign', function () {
    ['ticket' => $ticket, 'campaign' => $campaign] = createCampaignReportTicket();

    $this->actingAs($this->agent);
    invokeModerationAction('performRemoveContent', $ticket, 'campaign', $campaign->name);

    $ticket->refresh();
    $campaign->refresh();

    expect($ticket->status)->toBe(TicketStatus::Closed);
    expect($campaign->status)->toBe(CampaignStatus::Cancelled);
});

it('remove content action sends ContentRemoved notification to owner', function () {
    NotificationFacade::fake();

    ['ticket' => $ticket, 'game' => $game, 'gm' => $gm] = createGameReportTicket();

    $this->actingAs($this->agent);
    invokeModerationAction('performRemoveContent', $ticket, 'game', $game->name);

    // resolveReportedUser('game') unwraps to the game's owner — the GM must
    // be the one notified, proving the owner-resolution path works.
    NotificationFacade::assertSentTo($gm, ContentRemoved::class, function ($notification) use ($game) {
        return $notification->entityType === 'game'
            && $notification->entityName === $game->name
            && $notification->reason === 'inappropriate-content';
    });
});

// ── Suspend User Action Tests ──────────────────────────────────────────

it('suspend user action disables the reported user account', function () {
    ['ticket' => $ticket, 'reportedUser' => $reportedUser] = createUserReportTicket();

    $this->actingAs($this->agent);
    invokeModerationAction('performSuspendUser', $ticket, 'user');

    $ticket->refresh();
    $reportedUser->refresh();

    expect($ticket->status)->toBe(TicketStatus::Closed);
    expect($reportedUser->is_disabled)->toBeTrue();
    expect($reportedUser->disabled_at)->not->toBeNull();
});

it('suspend user action sends AccountSuspended notification', function () {
    NotificationFacade::fake();

    ['ticket' => $ticket, 'reportedUser' => $reportedUser] = createUserReportTicket();

    $this->actingAs($this->agent);
    invokeModerationAction('performSuspendUser', $ticket, 'user');

    NotificationFacade::assertSentTo($reportedUser, AccountSuspended::class, function ($notification) {
        return $notification->reason === 'harassment';
    });
});

it('suspend user action works for game reports — resolves owner via resolveReportedUser', function () {
    ['ticket' => $ticket, 'game' => $game, 'gm' => $gm, 'reporter' => $reporter] = createGameReportTicket();

    $this->actingAs($this->agent);
    // For a game report, the moderation target is the GAME OWNER (GM), not
    // the reporter. This is the resolveReportedUser('game') path the stubbed
    // tests never exercised.
    invokeModerationAction('performSuspendUser', $ticket, 'game');

    $gm->refresh();
    $reporter->refresh();
    $ticket->refresh();

    expect($gm->is_disabled)->toBeTrue();
    expect($gm->disabled_at)->not->toBeNull();
    // The reporter must not be collateral damage.
    expect($reporter->is_disabled)->toBeFalse();
    expect($ticket->status)->toBe(TicketStatus::Closed);
});

// ── Escalate Action Tests ──────────────────────────────────────────────

it('escalate action increases priority to urgent and reassigns to Platform Admin', function () {
    ['ticket' => $ticket] = createUserReportTicket();

    $this->actingAs($this->agent);
    invokeModerationAction('performEscalateContentReport', $ticket);

    $ticket->refresh();

    // Real behavior: priority → Urgent, reassigned to a Platform Admin that
    // isn't the acting agent, internal escalation note added. The ticket is
    // NOT closed by escalation (it stays open for the new assignee).
    expect($ticket->priority)->toBe(TicketPriority::Urgent);
    expect($ticket->assigned_to)->toBe($this->platformAdmin->id);
    expect($ticket->status)->toBe(TicketStatus::Open);

    $notes = $ticket->internalNotes;
    expect($notes)->toHaveCount(1);
    expect($notes->first()->body)->toContain('escalated');
    expect($notes->first()->body)->toContain($this->agent->name);
});

// Regression guard for the MEM517 removal (2026-06-27): the escalation action
// must call Ticket::assign() so the TicketAssigned event fires (audit trail,
// webhook, assignment notification) — not updateQuietly, which suppressed it.
// Note: this asserts the event fires with the correct agent; it does NOT by
// itself distinguish assign()-inside-transaction from the DB::afterCommit()
// deferral. That the deferred callback only fires on commit is guaranteed by
// Laravel's transaction contract, not re-asserted here.
it('escalate action fires the TicketAssigned event after commit', function () {
    ['ticket' => $ticket] = createUserReportTicket();

    $this->actingAs($this->agent);

    Event::fake([TicketAssigned::class]);
    invokeModerationAction('performEscalateContentReport', $ticket);

    // assign() is deferred via DB::afterCommit(); under DatabaseTransactions
    // the nested transaction completing is enough for the callback to run.
    Event::assertDispatched(TicketAssigned::class, function (TicketAssigned $e) use ($ticket) {
        return $e->ticket->is($ticket)
            && $e->agentId === $this->platformAdmin->id;
    });
});

// ── Edge Case Tests ────────────────────────────────────────────────────

it('dismiss action is ticket-type-agnostic — closes a non-content-report ticket too', function () {
    // FINDING: the perform* methods do NOT check ticket_type. They rely on the
    // Filament UI only surfacing the actions for content_report tickets. Direct
    // invocation on a question ticket still closes it. This test documents that
    // contract so a future ticket_type guard would be a deliberate change.
    $department = Department::where('name', 'Safety')->first();

    $ticket = Ticket::create([
        'requester_type' => User::class,
        'requester_id' => $this->agent->id,
        'subject' => 'General safety inquiry',
        'description' => 'A question about safety policies',
        'status' => TicketStatus::Open->value,
        'priority' => TicketPriority::Medium->value,
        'department_id' => $department?->id,
        'ticket_type' => 'question',
        'metadata' => [],
    ]);

    $this->actingAs($this->agent);
    invokeModerationAction('performDismissContentReport', $ticket);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Closed);
});

it('handles missing entity gracefully during remove content', function () {
    ['ticket' => $ticket, 'game' => $game] = createGameReportTicket();

    // Delete the reported game; the stale entity_id stays in metadata.
    $game->delete();

    $this->actingAs($this->agent);
    // removeGame() returns false for a missing entity, the ticket still closes
    // with a "not found" note, and no exception escapes the transaction.
    invokeModerationAction('performRemoveContent', $ticket, 'game', null);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Closed);
});

it('handles missing reported user gracefully during warn', function () {
    NotificationFacade::fake();

    ['ticket' => $ticket, 'reportedUser' => $reportedUser] = createUserReportTicket();

    // Delete the reported user so resolveReportedUser('user') returns null.
    $reportedUser->delete();

    $this->actingAs($this->agent);
    invokeModerationAction('performWarnUser', $ticket, 'user', null, null);

    $ticket->refresh();

    // FINDING: the stubbed suite assumed the ticket still closes on a missing
    // user. The real performWarnUser() hits the resolveReportedUser null-check
    // and returns EARLY — the ticket stays Open and no warning is sent.
    expect($ticket->status)->toBe(TicketStatus::Open);
    NotificationFacade::assertNotSentTo($reportedUser, ContentReportWarning::class);
});

// ── Notification Content Tests ─────────────────────────────────────────

it('ContentReportWarning notification has correct database payload', function () {
    $user = User::factory()->create(['profile_complete' => true]);
    $notification = new ContentReportWarning('game', 'Test Game', 'harassment');
    $payload = $notification->toDatabase($user);

    expect($payload['type'])->toBe('content_report_warning');
    expect($payload['entity_type'])->toBe('game');
    expect($payload['entity_name'])->toBe('Test Game');
    expect($payload['reason'])->toBe('harassment');
});

it('ContentRemoved notification has correct database payload', function () {
    $user = User::factory()->create(['profile_complete' => true]);
    $notification = new ContentRemoved('campaign', 'Test Campaign', 'spam');
    $payload = $notification->toDatabase($user);

    expect($payload['type'])->toBe('content_removed');
    expect($payload['entity_type'])->toBe('campaign');
    expect($payload['entity_name'])->toBe('Test Campaign');
    expect($payload['reason'])->toBe('spam');
});

it('AccountSuspended notification has correct database payload', function () {
    $user = User::factory()->create(['profile_complete' => true]);
    $notification = new AccountSuspended('inappropriate-content');
    $payload = $notification->toDatabase($user);

    expect($payload['type'])->toBe('account_suspended');
    expect($payload['reason'])->toBe('inappropriate-content');
});
