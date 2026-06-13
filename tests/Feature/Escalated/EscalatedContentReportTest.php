<?php

use App\Enums\CampaignStatus;
use App\Enums\GameStatus;
use App\Livewire\Reports\ReportContent;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Notifications\ContentRemoved;
use App\Notifications\ContentReportWarning;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Tag;
use Escalated\Laravel\Models\Ticket;
use Escalated\Laravel\Services\TicketService;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Livewire\Livewire;

beforeEach(function () {
    seedRoles();

    // Set up the Safety department and report tags
    Department::firstOrCreate(
        ['name' => 'Safety'],
        ['description' => 'Review reports, content moderation, user reports', 'is_active' => true],
    );

    $tags = [
        ['name' => 'user-report', 'color' => '#BE185D'],
        ['name' => 'game-report', 'color' => '#DB2777'],
        ['name' => 'campaign-report', 'color' => '#EC4899'],
        ['name' => 'inappropriate-content', 'color' => '#DC2626'],
        ['name' => 'harassment', 'color' => '#B91C1C'],
        ['name' => 'spam', 'color' => '#D97706'],
        ['name' => 'misleading', 'color' => '#EA580C'],
    ];
    foreach ($tags as $tag) {
        Tag::firstOrCreate(['name' => $tag['name']], $tag);
    }

    $this->admin = User::factory()->create(['profile_complete' => true]);
    $this->admin->assignRole('Service Admin');
});

// ── End-to-end: Report user profile → ticket in Safety department ────

it('reports a user profile and creates ticket in Safety department', function () {
    $reportedUser = User::factory()->create(['profile_complete' => true, 'name' => 'Bad Actor']);
    $reporter = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($reporter)
        ->test(ReportContent::class, ['entityType' => 'user', 'entityId' => $reportedUser->id])
        ->call('openModal')
        ->set('reason', 'harassment')
        ->set('description', 'This user is sending threatening messages')
        ->call('submitReport')
        ->assertSet('successMessage', __('reports.flash_report_submitted'));

    $ticket = Ticket::where('ticket_type', 'content_report')->first();
    expect($ticket)->not->toBeNull();
    expect($ticket->subject)->toBe('User Report: Harassment');
    expect($ticket->status)->toBe(TicketStatus::Open);
    expect($ticket->priority)->toBe(TicketPriority::High);
    expect($ticket->ticket_type)->toBe('content_report');

    $department = Department::where('name', 'Safety')->first();
    expect($ticket->department_id)->toBe($department->id);

    expect($ticket->metadata['entity_type'])->toBe('user');
    expect($ticket->metadata['entity_id'])->toBe($reportedUser->id);
    expect($ticket->metadata['entity_name'])->toBe('Bad Actor');
    expect($ticket->metadata['report_reason'])->toBe('harassment');
    expect($ticket->metadata['details'])->toBe('This user is sending threatening messages');

    // Verify entity-type tag
    expect($ticket->tags->pluck('name')->toArray())->toContain('user-report');
    expect($ticket->tags->pluck('name')->toArray())->toContain('harassment');
});

// ── End-to-end: Report a game → ticket with game context ─────────────

it('reports a game and creates ticket with game context', function () {
    $gm = User::factory()->create(['profile_complete' => true, 'name' => 'GameMaster']);
    $game = Game::factory()->create([
        'owner_id' => $gm->id,
        'name' => ['en' => 'Suspicious Game'],
        'status' => GameStatus::Scheduled,
    ]);
    $reporter = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($reporter)
        ->test(ReportContent::class, ['entityType' => 'game', 'entityId' => $game->id])
        ->call('openModal')
        ->set('reason', 'inappropriate-content')
        ->call('submitReport');

    $ticket = Ticket::where('ticket_type', 'content_report')->first();
    expect($ticket)->not->toBeNull();
    expect($ticket->subject)->toBe('Game Report: Inappropriate content');
    expect($ticket->metadata['entity_type'])->toBe('game');
    expect($ticket->metadata['entity_id'])->toBe($game->id);
    expect($ticket->metadata['entity_name'])->toBe('Suspicious Game');

    // Verify game owner appears in ticket description
    expect($ticket->description)->toContain('GameMaster');

    expect($ticket->tags->pluck('name')->toArray())->toContain('game-report');
    expect($ticket->tags->pluck('name')->toArray())->toContain('inappropriate-content');
});

// ── End-to-end: Report a campaign → ticket with campaign context ──────

it('reports a campaign and creates ticket with campaign context', function () {
    $owner = User::factory()->create(['profile_complete' => true, 'name' => 'CampaignOwner']);
    $campaign = Campaign::factory()->create([
        'owner_id' => $owner->id,
        'name' => ['en' => 'Spam Campaign'],
        'status' => CampaignStatus::Active,
    ]);
    $reporter = User::factory()->create(['profile_complete' => true]);

    Livewire::actingAs($reporter)
        ->test(ReportContent::class, ['entityType' => 'campaign', 'entityId' => $campaign->id])
        ->call('openModal')
        ->set('reason', 'spam')
        ->set('description', 'This is a spam campaign')
        ->call('submitReport');

    $ticket = Ticket::where('ticket_type', 'content_report')->first();
    expect($ticket)->not->toBeNull();
    expect($ticket->subject)->toBe('Campaign Report: Spam');
    expect($ticket->metadata['entity_type'])->toBe('campaign');
    expect($ticket->metadata['entity_id'])->toBe($campaign->id);
    expect($ticket->metadata['entity_name'])->toBe('Spam Campaign');

    expect($ticket->description)->toContain('CampaignOwner');
    expect($ticket->description)->toContain('This is a spam campaign');

    expect($ticket->tags->pluck('name')->toArray())->toContain('campaign-report');
    expect($ticket->tags->pluck('name')->toArray())->toContain('spam');
});

// ── Cannot report own content ────────────────────────────────────────

it('prevents reporting own game', function () {
    $owner = User::factory()->create(['profile_complete' => true]);
    $game = Game::factory()->create(['owner_id' => $owner->id]);

    // The component's isSelfReport() check blocks self-reports server-side.
    Livewire::actingAs($owner)
        ->test(ReportContent::class, ['entityType' => 'game', 'entityId' => $game->id])
        ->call('openModal')
        ->set('reason', 'spam')
        ->call('submitReport')
        ->assertHasErrors(['reason' => __('reports.error_self_report')]);

    // No ticket should be created for self-reports
    expect(Ticket::where('ticket_type', 'content_report')->exists())->toBeFalse();
});

it('prevents reporting own user profile', function () {
    $user = User::factory()->create(['profile_complete' => true]);

    // A user cannot report their own profile — blocked by isSelfReport()
    Livewire::actingAs($user)
        ->test(ReportContent::class, ['entityType' => 'user', 'entityId' => $user->id])
        ->call('openModal')
        ->set('reason', 'other')
        ->call('submitReport')
        ->assertHasErrors(['reason' => __('reports.error_self_report')]);

    // No ticket should be created
    expect(Ticket::where('ticket_type', 'content_report')->exists())->toBeFalse();
});

// ── Rate limiting — 6th report in an hour is blocked ──────────────────

it('blocks the 6th report within an hour via rate limiting', function () {
    $reporter = User::factory()->create(['profile_complete' => true]);

    // Submit 5 reports — all succeed
    for ($i = 0; $i < 5; $i++) {
        $game = Game::factory()->create([
            'owner_id' => User::factory()->create(['profile_complete' => true])->id,
        ]);

        Livewire::actingAs($reporter)
            ->test(ReportContent::class, ['entityType' => 'game', 'entityId' => $game->id])
            ->call('openModal')
            ->set('reason', 'spam')
            ->call('submitReport')
            ->assertSet('successMessage', __('reports.flash_report_submitted'));
    }

    expect(Ticket::where('ticket_type', 'content_report')->count())->toBe(5);

    // 6th report should be blocked
    $game = Game::factory()->create([
        'owner_id' => User::factory()->create(['profile_complete' => true])->id,
    ]);

    Livewire::actingAs($reporter)
        ->test(ReportContent::class, ['entityType' => 'game', 'entityId' => $game->id])
        ->call('openModal')
        ->set('reason', 'spam')
        ->call('submitReport')
        ->assertHasErrors(['reason']);

    // Confirm no 6th ticket was created
    expect(Ticket::where('ticket_type', 'content_report')->count())->toBe(5);
});

// ── Admin warn action → notification sent to reported user ────────────

it('admin warn action sends notification to reported user', function () {
    NotificationFacade::fake();

    $reportedUser = User::factory()->create(['profile_complete' => true, 'name' => 'WarnTarget']);
    $reporter = User::factory()->create(['profile_complete' => true]);

    // Step 1: Report the user
    Livewire::actingAs($reporter)
        ->test(ReportContent::class, ['entityType' => 'user', 'entityId' => $reportedUser->id])
        ->call('openModal')
        ->set('reason', 'harassment')
        ->set('description', 'Harassing messages')
        ->call('submitReport');

    $ticket = Ticket::where('ticket_type', 'content_report')->first();
    expect($ticket)->not->toBeNull();

    // Step 2: Admin warns the user
    $ticketService = app(TicketService::class);
    $ticketService->addNote($ticket, $this->admin, 'Warning issued by admin.');
    $ticketService->close($ticket, $this->admin);
    $reportedUser->notify(new ContentReportWarning('user', $reportedUser->name, 'harassment'));

    // Verify ticket is closed
    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Closed);

    // Verify warning notification sent
    NotificationFacade::assertSentTo($reportedUser, ContentReportWarning::class, function ($notification) {
        return $notification->entityType === 'user'
            && $notification->reason === 'harassment';
    });

    // User should NOT be disabled
    $reportedUser->refresh();
    expect($reportedUser->is_disabled)->toBeFalse();
});

// ── Admin suspend action → user.is_disabled = true ──────────────────

it('admin suspend action disables reported user account', function () {
    $reportedUser = User::factory()->create(['profile_complete' => true, 'name' => 'SuspendTarget']);
    $reporter = User::factory()->create(['profile_complete' => true]);

    // Step 1: Report the user
    Livewire::actingAs($reporter)
        ->test(ReportContent::class, ['entityType' => 'user', 'entityId' => $reportedUser->id])
        ->call('openModal')
        ->set('reason', 'spam')
        ->call('submitReport');

    $ticket = Ticket::where('ticket_type', 'content_report')->first();

    // Step 2: Admin suspends the user
    $reportedUser->update([
        'is_disabled' => true,
        'disabled_at' => now(),
    ]);

    $ticketService = app(TicketService::class);
    $ticketService->addNote($ticket, $this->admin, "User account suspended ({$reportedUser->name}, ID: {$reportedUser->id}).");
    $ticketService->close($ticket, $this->admin);

    $reportedUser->refresh();
    $ticket->refresh();

    expect($reportedUser->is_disabled)->toBeTrue();
    expect($reportedUser->disabled_at)->not->toBeNull();
    expect($ticket->status)->toBe(TicketStatus::Closed);
});

// ── Admin remove action → content hidden/cancelled ──────────────────

it('admin remove action cancels reported game', function () {
    NotificationFacade::fake();

    $gm = User::factory()->create(['profile_complete' => true]);
    $game = Game::factory()->create([
        'owner_id' => $gm->id,
        'status' => GameStatus::Scheduled,
    ]);
    $reporter = User::factory()->create(['profile_complete' => true]);

    // Step 1: Report the game
    Livewire::actingAs($reporter)
        ->test(ReportContent::class, ['entityType' => 'game', 'entityId' => $game->id])
        ->call('openModal')
        ->set('reason', 'inappropriate-content')
        ->call('submitReport');

    $ticket = Ticket::where('ticket_type', 'content_report')->first();

    // Step 2: Admin removes the game
    $game->update(['status' => GameStatus::Canceled]);

    $ticketService = app(TicketService::class);
    $ticketService->addNote($ticket, $this->admin, 'Game removed by admin.');
    $ticketService->close($ticket, $this->admin);

    // Notify the game owner
    $gm->notify(new ContentRemoved('game', $game->name, 'inappropriate-content'));

    $ticket->refresh();
    $game->refresh();

    expect($ticket->status)->toBe(TicketStatus::Closed);
    expect($game->status)->toBe(GameStatus::Canceled);

    NotificationFacade::assertSentTo($gm, ContentRemoved::class, function ($notification) use ($game) {
        return $notification->entityType === 'game'
            && $notification->entityName === $game->name
            && $notification->reason === 'inappropriate-content';
    });
});

it('admin remove action cancels reported campaign', function () {
    NotificationFacade::fake();

    $owner = User::factory()->create(['profile_complete' => true]);
    $campaign = Campaign::factory()->create([
        'owner_id' => $owner->id,
        'status' => CampaignStatus::Active,
    ]);
    $reporter = User::factory()->create(['profile_complete' => true]);

    // Step 1: Report the campaign
    Livewire::actingAs($reporter)
        ->test(ReportContent::class, ['entityType' => 'campaign', 'entityId' => $campaign->id])
        ->call('openModal')
        ->set('reason', 'misleading')
        ->call('submitReport');

    $ticket = Ticket::where('ticket_type', 'content_report')->first();

    // Step 2: Admin removes the campaign
    $campaign->update(['status' => CampaignStatus::Cancelled]);

    $ticketService = app(TicketService::class);
    $ticketService->addNote($ticket, $this->admin, 'Campaign removed by admin.');
    $ticketService->close($ticket, $this->admin);

    $owner->notify(new ContentRemoved('campaign', $campaign->name, 'misleading'));

    $ticket->refresh();
    $campaign->refresh();

    expect($ticket->status)->toBe(TicketStatus::Closed);
    expect($campaign->status)->toBe(CampaignStatus::Cancelled);

    NotificationFacade::assertSentTo($owner, ContentRemoved::class);
});

// ── Full lifecycle: report → dismiss ──────────────────────────────────

it('full lifecycle: report user → admin dismisses → no action taken', function () {
    $reportedUser = User::factory()->create(['profile_complete' => true]);
    $reporter = User::factory()->create(['profile_complete' => true]);

    // Step 1: Report
    Livewire::actingAs($reporter)
        ->test(ReportContent::class, ['entityType' => 'user', 'entityId' => $reportedUser->id])
        ->call('openModal')
        ->set('reason', 'other')
        ->set('description', 'Just testing the report flow')
        ->call('submitReport');

    $ticket = Ticket::where('ticket_type', 'content_report')->first();
    expect($ticket->status)->toBe(TicketStatus::Open);

    // Step 2: Admin dismisses
    $ticketService = app(TicketService::class);
    $ticketService->addNote($ticket, $this->admin, 'Content report dismissed by admin — no action taken.');
    $ticketService->close($ticket, $this->admin);

    $ticket->refresh();
    $reportedUser->refresh();

    expect($ticket->status)->toBe(TicketStatus::Closed);
    expect($reportedUser->is_disabled)->toBeFalse();
    expect($reportedUser->disabled_at)->toBeNull();
});

// ── Full lifecycle: report → escalate ────────────────────────────────

it('full lifecycle: report game → admin escalates to Platform Admin', function () {
    $platformAdmin = User::factory()->create(['profile_complete' => true]);
    $platformAdmin->assignRole('Platform Admin');

    $gm = User::factory()->create(['profile_complete' => true]);
    $game = Game::factory()->create(['owner_id' => $gm->id]);
    $reporter = User::factory()->create(['profile_complete' => true]);

    // Step 1: Report the game
    Livewire::actingAs($reporter)
        ->test(ReportContent::class, ['entityType' => 'game', 'entityId' => $game->id])
        ->call('openModal')
        ->set('reason', 'harassment')
        ->call('submitReport');

    $ticket = Ticket::where('ticket_type', 'content_report')->first();

    // Step 2: Admin escalates
    $ticketService = app(TicketService::class);
    $ticketService->addNote($ticket, $this->admin, "Content report escalated by {$this->admin->name}.");
    $ticketService->changePriority($ticket, TicketPriority::Urgent, $this->admin);
    $ticket->updateQuietly(['assigned_to' => $platformAdmin->id]);

    $ticket->refresh();
    expect($ticket->priority)->toBe(TicketPriority::Urgent);
    expect($ticket->assigned_to)->toBe($platformAdmin->id);
    expect($ticket->status)->toBe(TicketStatus::Open); // Escalation doesn't close
});
