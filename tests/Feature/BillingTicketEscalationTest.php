<?php

use App\Models\User;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\EscalationRule;
use Escalated\Laravel\Models\Ticket;
use Escalated\Laravel\Services\EscalationService;

beforeEach(function () {
    seedRoles();

    $this->billing = Department::firstOrCreate(
        ['name' => 'Billing'],
        ['description' => 'Payment issues, subscription questions']
    );

    // Seed the escalation rule (same as EscalatedSetupSeeder would)
    EscalationRule::firstOrCreate(
        ['name' => 'Billing Ticket 24h Auto-Escalation'],
        [
            'description' => 'Auto-escalate Billing department tickets unresolved after 24 hours to urgent priority',
            'trigger_type' => 'time_based',
            'category' => 'Billing',
            'conditions' => [
                ['field' => 'department_id', 'value' => $this->billing->id],
                ['field' => 'age_hours', 'value' => 24],
            ],
            'actions' => [
                ['type' => 'escalate'],
                ['type' => 'change_priority', 'value' => 'urgent'],
            ],
            'is_active' => true,
            'order' => 20,
        ]
    );

    $this->reporter = User::factory()->create(['profile_complete' => true]);
});

function createBillingTicket($department, $reporter, array $overrides = []): Ticket
{
    return Ticket::create(array_merge([
        'requester_type' => User::class,
        'requester_id' => $reporter->id,
        'subject' => 'Payment failed for subscription',
        'description' => 'My payment was declined and I cannot access premium features.',
        'status' => TicketStatus::Open->value,
        'priority' => TicketPriority::Medium->value,
        'department_id' => $department->id,
        'ticket_type' => 'billing_support',
        'metadata' => [
            'paddle_subscription_id' => 'sub_12345',
        ],
    ], $overrides));
}

// ── Core Escalation Rule Tests ─────────────────────────────────────────

it('escalates billing ticket after 24 hours', function () {
    $ticket = createBillingTicket($this->billing, $this->reporter);

    // Age the ticket past 24 hours
    $ticket->updateQuietly(['created_at' => now()->subHours(25)]);

    $service = app(EscalationService::class);
    $escalated = $service->evaluateRules();

    expect($escalated)->toBeGreaterThanOrEqual(1);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Escalated);
    expect($ticket->priority)->toBe(TicketPriority::Urgent);
});

it('does not escalate billing ticket before 24 hours', function () {
    $ticket = createBillingTicket($this->billing, $this->reporter);

    // Age the ticket only 12 hours (under threshold)
    $ticket->updateQuietly(['created_at' => now()->subHours(12)]);

    $service = app(EscalationService::class);
    $service->evaluateRules();

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Open);
    expect($ticket->priority)->toBe(TicketPriority::Medium);
});

it('does not escalate ticket at exactly 23 hours', function () {
    $ticket = createBillingTicket($this->billing, $this->reporter);

    // Age the ticket to exactly 23 hours — just under the 24h threshold
    $ticket->updateQuietly(['created_at' => now()->subHours(23)]);

    $service = app(EscalationService::class);
    $service->evaluateRules();

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Open);
});

it('does not escalate already closed billing ticket', function () {
    $ticket = createBillingTicket($this->billing, $this->reporter);

    $ticket->updateQuietly([
        'status' => TicketStatus::Closed->value,
        'created_at' => now()->subHours(48),
    ]);

    $service = app(EscalationService::class);
    $service->evaluateRules();

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Closed);
});

it('does not escalate already resolved billing ticket', function () {
    $ticket = createBillingTicket($this->billing, $this->reporter);

    $ticket->updateQuietly([
        'status' => TicketStatus::Resolved->value,
        'created_at' => now()->subHours(48),
    ]);

    $service = app(EscalationService::class);
    $service->evaluateRules();

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Resolved);
});

it('does not escalate ticket in different department after 24 hours', function () {
    $otherDept = Department::firstOrCreate(
        ['name' => 'Contact'],
        ['description' => 'General inquiries']
    );

    $ticket = createBillingTicket($otherDept, $this->reporter);

    // Age past 24 hours but wrong department
    $ticket->updateQuietly(['created_at' => now()->subHours(30)]);

    $service = app(EscalationService::class);
    $service->evaluateRules();

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Open);
});

// ── Artisan Command Tests ──────────────────────────────────────────────

it('evaluates billing escalations via artisan command', function () {
    $ticket = createBillingTicket($this->billing, $this->reporter);
    $ticket->updateQuietly(['created_at' => now()->subHours(25)]);

    $this->artisan('escalated:evaluate-escalations')
        ->expectsOutputToContain('Escalation evaluation complete')
        ->assertSuccessful();

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Escalated);
    expect($ticket->priority)->toBe(TicketPriority::Urgent);
});

// ── Escalation Rule Seed Tests ─────────────────────────────────────────

it('escalation rule is idempotent via firstOrCreate', function () {
    $count = EscalationRule::where('name', 'Billing Ticket 24h Auto-Escalation')->count();

    EscalationRule::firstOrCreate(
        ['name' => 'Billing Ticket 24h Auto-Escalation'],
        [
            'description' => 'Auto-escalate Billing department tickets unresolved after 24 hours to urgent priority',
            'trigger_type' => 'time_based',
            'category' => 'Billing',
            'conditions' => [
                ['field' => 'department_id', 'value' => $this->billing->id],
                ['field' => 'age_hours', 'value' => 24],
            ],
            'actions' => [
                ['type' => 'escalate'],
                ['type' => 'change_priority', 'value' => 'urgent'],
            ],
            'is_active' => true,
            'order' => 20,
        ]
    );

    expect(EscalationRule::where('name', 'Billing Ticket 24h Auto-Escalation')->count())->toBe($count);
});

// ── Multiple Ticket Tests ──────────────────────────────────────────────

it('escalates multiple matching billing tickets at once', function () {
    $ticket1 = createBillingTicket($this->billing, $this->reporter);
    $ticket1->updateQuietly(['created_at' => now()->subHours(25)]);

    $ticket2 = createBillingTicket($this->billing, $this->reporter);
    $ticket2->updateQuietly(['created_at' => now()->subHours(30)]);

    // Fresh ticket — should NOT be escalated
    $ticket3 = createBillingTicket($this->billing, $this->reporter);

    $service = app(EscalationService::class);
    $escalated = $service->evaluateRules();

    expect($escalated)->toBeGreaterThanOrEqual(2);

    $ticket1->refresh();
    $ticket2->refresh();
    $ticket3->refresh();

    expect($ticket1->status)->toBe(TicketStatus::Escalated);
    expect($ticket2->status)->toBe(TicketStatus::Escalated);
    expect($ticket3->status)->toBe(TicketStatus::Open);
});

it('only matches active escalation rules', function () {
    EscalationRule::where('name', 'Billing Ticket 24h Auto-Escalation')
        ->update(['is_active' => false]);

    $ticket = createBillingTicket($this->billing, $this->reporter);
    $ticket->updateQuietly(['created_at' => now()->subHours(25)]);

    $service = app(EscalationService::class);
    $escalated = $service->evaluateRules();

    // Could still be 0 from billing (deactivated), but may escalate from other rules
    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Open);
});
