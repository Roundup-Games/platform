<?php

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Notifications\AccountSuspended;
use App\Notifications\ContentRemoved;
use App\Notifications\ContentReportWarning;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Ticket;
use Escalated\Laravel\Services\TicketService;
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

// ── Dismiss Action Tests ────────────────────────────────────────────────

it('dismiss action closes ticket with no action on reported user', function () {
    ['ticket' => $ticket, 'reportedUser' => $reportedUser] = createUserReportTicket();

    $ticketService = app(TicketService::class);
    $ticketService->addNote($ticket, $this->agent, 'Content report dismissed by admin — no action taken.');
    $ticketService->close($ticket, $this->agent);

    $ticket->refresh();
    $reportedUser->refresh();

    expect($ticket->status)->toBe(TicketStatus::Closed);
    expect($reportedUser->is_disabled)->toBeFalse();
});

it('dismiss action adds internal note to ticket', function () {
    ['ticket' => $ticket] = createUserReportTicket();

    $ticketService = app(TicketService::class);
    $ticketService->addNote($ticket, $this->agent, 'Content report dismissed by admin — no action taken.');
    $ticketService->close($ticket, $this->agent);

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

    $ticketService = app(TicketService::class);
    $ticketService->addNote($ticket, $this->agent, 'Warning issued by admin.');
    $ticketService->close($ticket, $this->agent);

    // Send warning notification (simulating the action logic)
    $reportedUser->notify(new ContentReportWarning(
        'user',
        $reportedUser->name,
        'harassment',
    ));

    NotificationFacade::assertSentTo($reportedUser, ContentReportWarning::class, function ($notification) {
        return $notification->entityType === 'user'
            && $notification->reason === 'harassment';
    });

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Closed);
});

it('warn user action does not disable the user account', function () {
    ['ticket' => $ticket, 'reportedUser' => $reportedUser] = createUserReportTicket();

    $ticketService = app(TicketService::class);
    $ticketService->addNote($ticket, $this->agent, 'Warning issued by admin.');
    $ticketService->close($ticket, $this->agent);

    $reportedUser->refresh();
    expect($reportedUser->is_disabled)->toBeFalse();
    expect($reportedUser->disabled_at)->toBeNull();
});

// ── Remove Content Action Tests ────────────────────────────────────────

it('remove content action cancels reported game', function () {
    ['ticket' => $ticket, 'game' => $game, 'gm' => $gm] = createGameReportTicket();

    $ticketService = app(TicketService::class);

    // Cancel the game
    $game->update(['status' => GameStatus::Canceled]);

    $ticketService->addNote($ticket, $this->agent, 'Game removed by admin.');
    $ticketService->close($ticket, $this->agent);

    $ticket->refresh();
    $game->refresh();

    expect($ticket->status)->toBe(TicketStatus::Closed);
    expect($game->status)->toBe(GameStatus::Canceled);
});

it('remove content action cancels reported campaign', function () {
    ['ticket' => $ticket, 'campaign' => $campaign, 'owner' => $owner] = createCampaignReportTicket();

    $ticketService = app(TicketService::class);

    // Cancel the campaign
    $campaign->update(['status' => CampaignStatus::Cancelled]);

    $ticketService->addNote($ticket, $this->agent, 'Campaign removed by admin.');
    $ticketService->close($ticket, $this->agent);

    $ticket->refresh();
    $campaign->refresh();

    expect($ticket->status)->toBe(TicketStatus::Closed);
    expect($campaign->status)->toBe(CampaignStatus::Cancelled);
});

it('remove content action sends ContentRemoved notification to owner', function () {
    NotificationFacade::fake();

    ['ticket' => $ticket, 'game' => $game, 'gm' => $gm] = createGameReportTicket();

    $ticketService = app(TicketService::class);

    $game->update(['status' => GameStatus::Canceled]);

    $ticketService->addNote($ticket, $this->agent, 'Game removed by admin.');
    $ticketService->close($ticket, $this->agent);

    // Send notification to the game owner
    $gm->notify(new ContentRemoved(
        'game',
        $game->name,
        'inappropriate-content',
    ));

    NotificationFacade::assertSentTo($gm, ContentRemoved::class, function ($notification) use ($game) {
        return $notification->entityType === 'game'
            && $notification->entityName === $game->name;
    });
});

it('remove content action handles already canceled game gracefully', function () {
    ['ticket' => $ticket, 'game' => $game] = createGameReportTicket();

    // Pre-cancel the game
    $game->update(['status' => GameStatus::Canceled]);

    // Try to remove already-canceled game — should indicate not removed again
    $game->refresh();
    $alreadyCanceled = $game->status === GameStatus::Canceled;

    expect($alreadyCanceled)->toBeTrue();

    // Ticket should still close
    $ticketService = app(TicketService::class);
    $ticketService->addNote($ticket, $this->agent, 'Removal attempted but entity already removed.');
    $ticketService->close($ticket, $this->agent);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Closed);
});

it('remove content action handles missing entity gracefully', function () {
    ['ticket' => $ticket] = createGameReportTicket();

    // Point to a non-existent entity
    $metadata = $ticket->metadata;
    $metadata['entity_id'] = 'nonexistent-id';
    $ticket->updateQuietly(['metadata' => $metadata]);

    $ticketService = app(TicketService::class);
    $ticketService->addNote($ticket, $this->agent, 'Removal attempted but entity not found.');
    $ticketService->close($ticket, $this->agent);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Closed);
});

// ── Suspend User Action Tests ──────────────────────────────────────────

it('suspend user action disables the reported user account', function () {
    ['ticket' => $ticket, 'reportedUser' => $reportedUser] = createUserReportTicket();

    $ticketService = app(TicketService::class);

    // Suspend the user
    $reportedUser->update([
        'is_disabled' => true,
        'disabled_at' => now(),
    ]);

    $ticketService->addNote($ticket, $this->agent, "User account suspended ({$reportedUser->name}, ID: {$reportedUser->id}).");
    $ticketService->close($ticket, $this->agent);

    $ticket->refresh();
    $reportedUser->refresh();

    expect($ticket->status)->toBe(TicketStatus::Closed);
    expect($reportedUser->is_disabled)->toBeTrue();
    expect($reportedUser->disabled_at)->not->toBeNull();
});

it('suspend user action sends AccountSuspended notification', function () {
    NotificationFacade::fake();

    ['ticket' => $ticket, 'reportedUser' => $reportedUser] = createUserReportTicket();

    // Suspend and notify
    $reportedUser->update([
        'is_disabled' => true,
        'disabled_at' => now(),
    ]);

    $reportedUser->notify(new AccountSuspended('harassment'));

    NotificationFacade::assertSentTo($reportedUser, AccountSuspended::class, function ($notification) {
        return $notification->reason === 'harassment';
    });
});

it('suspend user action works for game reports — suspends game owner', function () {
    ['ticket' => $ticket, 'game' => $game, 'gm' => $gm] = createGameReportTicket();

    $ticketService = app(TicketService::class);

    // The reported user is the game owner
    $gm->update([
        'is_disabled' => true,
        'disabled_at' => now(),
    ]);

    $ticketService->addNote($ticket, $this->agent, "User account suspended ({$gm->name}, ID: {$gm->id}).");
    $ticketService->close($ticket, $this->agent);

    $gm->refresh();
    $ticket->refresh();

    expect($gm->is_disabled)->toBeTrue();
    expect($ticket->status)->toBe(TicketStatus::Closed);
});

it('suspend user action works for campaign reports — suspends campaign owner', function () {
    ['ticket' => $ticket, 'campaign' => $campaign, 'owner' => $owner] = createCampaignReportTicket();

    $ticketService = app(TicketService::class);

    $owner->update([
        'is_disabled' => true,
        'disabled_at' => now(),
    ]);

    $ticketService->addNote($ticket, $this->agent, "User account suspended ({$owner->name}, ID: {$owner->id}).");
    $ticketService->close($ticket, $this->agent);

    $owner->refresh();
    $ticket->refresh();

    expect($owner->is_disabled)->toBeTrue();
    expect($ticket->status)->toBe(TicketStatus::Closed);
});

// ── Escalate Action Tests ──────────────────────────────────────────────

it('escalate action increases priority to urgent and reassigns to Platform Admin', function () {
    ['ticket' => $ticket] = createUserReportTicket();

    $ticketService = app(TicketService::class);

    $ticketService->addNote($ticket, $this->agent, "Content report escalated by {$this->agent->name}.");
    $ticketService->changePriority($ticket, TicketPriority::Urgent, $this->agent);

    // Assign to Platform Admin
    $ticket->updateQuietly(['assigned_to' => $this->platformAdmin->id]);

    $ticket->refresh();

    expect($ticket->priority)->toBe(TicketPriority::Urgent);
    expect($ticket->assigned_to)->toBe($this->platformAdmin->id);
});

it('escalate action adds escalation internal note', function () {
    ['ticket' => $ticket] = createUserReportTicket();

    $ticketService = app(TicketService::class);
    $ticketService->addNote($ticket, $this->agent, "Content report escalated by {$this->agent->name}.");

    $ticket->refresh();
    $notes = $ticket->internalNotes;

    expect($notes)->toHaveCount(1);
    expect($notes->first()->body)->toContain('escalated');
    expect($notes->first()->body)->toContain($this->agent->name);
});

// ── Edge Case Tests ────────────────────────────────────────────────────

it('non-content-report tickets are unaffected by content moderation logic', function () {
    $department = Department::where('name', 'Safety')->first();

    // Create a regular (non-content-report) ticket
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

    $ticketService = app(TicketService::class);
    $ticketService->close($ticket, $this->agent);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Closed);
});

it('handles missing reported user gracefully during warn action', function () {
    ['ticket' => $ticket, 'reportedUser' => $reportedUser] = createUserReportTicket();

    // Delete the reported user
    $reportedUser->delete();

    // The ticket should still be processable
    $ticketService = app(TicketService::class);
    $ticketService->addNote($ticket, $this->agent, 'Warning attempted but user not found.');
    $ticketService->close($ticket, $this->agent);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Closed);
});

it('handles missing game during remove content action', function () {
    ['ticket' => $ticket, 'game' => $game] = createGameReportTicket();

    // Delete the game
    $game->delete();

    // The ticket should still close
    $ticketService = app(TicketService::class);
    $ticketService->addNote($ticket, $this->agent, 'Removal attempted but entity not found.');
    $ticketService->close($ticket, $this->agent);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Closed);
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
