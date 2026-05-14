<?php

use App\Models\User;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\EscalationRule;
use Escalated\Laravel\Models\Ticket;
use Escalated\Laravel\Services\EscalationService;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    seedRoles();

    // Set up the Safety department
    $this->safety = Department::firstOrCreate(
        ['name' => 'Safety'],
        ['description' => 'Safety and moderation department']
    );

    // Seed the escalation rule (same as EscalatedSetupSeeder would)
    EscalationRule::firstOrCreate(
        ['name' => 'Safety Ticket Auto-Escalation'],
        [
            'description' => 'Auto-escalate Safety department tickets unresolved after 4 hours',
            'trigger_type' => 'time_based',
            'category' => 'Safety',
            'conditions' => [
                ['field' => 'department_id', 'value' => $this->safety->id],
                ['field' => 'age_hours', 'value' => 4],
            ],
            'actions' => [
                ['type' => 'escalate'],
                ['type' => 'change_priority', 'value' => 'urgent'],
            ],
            'is_active' => true,
            'order' => 10,
        ]
    );

    $this->reporter = User::factory()->create(['profile_complete' => true]);
});

function createSafetyTicket($department, $reporter, array $overrides = []): Ticket
{
    return Ticket::create(array_merge([
        'requester_type' => User::class,
        'requester_id' => $reporter->id,
        'subject' => 'Review Report: Harassment',
        'description' => 'Reported review content...',
        'status' => TicketStatus::Open->value,
        'priority' => TicketPriority::High->value,
        'department_id' => $department->id,
        'ticket_type' => 'review_report',
        'metadata' => [
            'review_id' => \Illuminate\Support\Str::uuid()->toString(),
            'review_content' => 'Inappropriate content',
            'report_reason' => 'harassment',
        ],
    ], $overrides));
}

// ── Core Escalation Rule Tests ─────────────────────────────────────────

it('escalates safety ticket after 4 hours', function () {
    $ticket = createSafetyTicket($this->safety, $this->reporter);

    // Age the ticket past 4 hours
    $ticket->updateQuietly(['created_at' => now()->subHours(5)]);

    $service = app(EscalationService::class);
    $escalated = $service->evaluateRules();

    expect($escalated)->toBe(1);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Escalated);
    expect($ticket->priority)->toBe(TicketPriority::Urgent);
});

it('does not escalate safety ticket before 4 hours', function () {
    $ticket = createSafetyTicket($this->safety, $this->reporter);

    // Age the ticket only 2 hours (under threshold)
    $ticket->updateQuietly(['created_at' => now()->subHours(2)]);

    $service = app(EscalationService::class);
    $escalated = $service->evaluateRules();
    expect($escalated)->toBe(0);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Open);
    expect($ticket->priority)->toBe(TicketPriority::High);
});

it('does not escalate ticket in different department', function () {
    $otherDept = Department::firstOrCreate(
        ['name' => 'Billing'],
        ['description' => 'Billing department']
    );

    $ticket = createSafetyTicket($otherDept, $this->reporter);

    // Age past 4 hours but wrong department
    $ticket->updateQuietly(['created_at' => now()->subHours(6)]);

    $service = app(EscalationService::class);
    $escalated = $service->evaluateRules();
    expect($escalated)->toBe(0);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Open);
    expect($ticket->priority)->toBe(TicketPriority::High);
});

it('does not escalate already closed safety ticket', function () {
    $ticket = createSafetyTicket($this->safety, $this->reporter);

    // Close the ticket first
    $ticket->updateQuietly([
        'status' => TicketStatus::Closed->value,
        'created_at' => now()->subHours(8),
    ]);

    $service = app(EscalationService::class);
    $escalated = $service->evaluateRules();
    expect($escalated)->toBe(0);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Closed);
});

it('does not escalate already resolved safety ticket', function () {
    $ticket = createSafetyTicket($this->safety, $this->reporter);

    $ticket->updateQuietly([
        'status' => TicketStatus::Resolved->value,
        'created_at' => now()->subHours(8),
    ]);

    $service = app(EscalationService::class);
    $escalated = $service->evaluateRules();
    expect($escalated)->toBe(0);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Resolved);
});

// ── Artisan Command Tests ──────────────────────────────────────────────

it('evaluates escalations via artisan command', function () {
    $ticket = createSafetyTicket($this->safety, $this->reporter);
    $ticket->updateQuietly(['created_at' => now()->subHours(5)]);

    $this->artisan('escalated:evaluate-escalations')
        ->expectsOutputToContain('Escalation evaluation complete')
        ->assertSuccessful();

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Escalated);
    expect($ticket->priority)->toBe(TicketPriority::Urgent);
});

it('reports zero escalations when no tickets match', function () {
    // No aged tickets
    createSafetyTicket($this->safety, $this->reporter);

    $this->artisan('escalated:evaluate-escalations')
        ->expectsOutputToContain('Escalation evaluation complete: 0 tickets affected')
        ->assertSuccessful();
});

// ── Escalation Rule Seed Tests ─────────────────────────────────────────

it('escalation rule is idempotent via firstOrCreate', function () {
    // Rule already created in beforeEach — calling again should not duplicate
    $count = EscalationRule::where('name', 'Safety Ticket Auto-Escalation')->count();

    // Seed again
    EscalationRule::firstOrCreate(
        ['name' => 'Safety Ticket Auto-Escalation'],
        [
            'description' => 'Auto-escalate Safety department tickets unresolved after 4 hours',
            'trigger_type' => 'time_based',
            'category' => 'Safety',
            'conditions' => [
                ['field' => 'department_id', 'value' => $this->safety->id],
                ['field' => 'age_hours', 'value' => 4],
            ],
            'actions' => [
                ['type' => 'escalate'],
                ['type' => 'change_priority', 'value' => 'urgent'],
            ],
            'is_active' => true,
            'order' => 10,
        ]
    );

    expect(EscalationRule::where('name', 'Safety Ticket Auto-Escalation')->count())->toBe($count);
});

// ── Multiple Ticket Tests ──────────────────────────────────────────────

it('escalates multiple matching safety tickets at once', function () {
    $ticket1 = createSafetyTicket($this->safety, $this->reporter);
    $ticket1->updateQuietly(['created_at' => now()->subHours(5)]);

    $ticket2 = createSafetyTicket($this->safety, $this->reporter);
    $ticket2->updateQuietly(['created_at' => now()->subHours(6)]);

    // Fresh ticket — should NOT be escalated
    $ticket3 = createSafetyTicket($this->safety, $this->reporter);

    $service = app(EscalationService::class);
    $escalated = $service->evaluateRules();

    expect($escalated)->toBe(2);

    $ticket1->refresh();
    $ticket2->refresh();
    $ticket3->refresh();

    expect($ticket1->status)->toBe(TicketStatus::Escalated);
    expect($ticket2->status)->toBe(TicketStatus::Escalated);
    expect($ticket3->status)->toBe(TicketStatus::Open);
});

it('only matches active escalation rules', function () {
    // Deactivate the rule
    EscalationRule::where('name', 'Safety Ticket Auto-Escalation')
        ->update(['is_active' => false]);

    $ticket = createSafetyTicket($this->safety, $this->reporter);
    $ticket->updateQuietly(['created_at' => now()->subHours(5)]);

    $service = app(EscalationService::class);
    $escalated = $service->evaluateRules();

    expect($escalated)->toBe(0);
    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Open);
});
