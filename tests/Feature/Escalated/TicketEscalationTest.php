<?php

use App\Models\User;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\EscalationRule;
use Escalated\Laravel\Models\Ticket;
use Escalated\Laravel\Services\EscalationService;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Ticket Escalation Tests (merged)
|--------------------------------------------------------------------------
| Merges the former BillingTicketEscalationTest (24h, Billing department)
| and SafetyTicketEscalationTest (4h, Safety department) into a single
| parametric file. Common scenarios are driven over a dataset of
| [billing, safety] departments. The two scenarios that existed in only
| one file both generalize cleanly and are folded into the same dataset
| rather than left as standalone it() blocks:
|
|   - "does not escalate one hour below threshold" (was Billing-only, 23h)
|       -> generalizes to (thresholdHours - 1); Safety now also covers 3h.
|   - "reports zero escalations when no tickets match" (was Safety-only)
|       -> a fresh, un-aged ticket is under any threshold; Billing now also
|          covers it.
|
| Feature tests run inside DatabaseTransactions, so each iteration sees a
| clean DB containing only the department + rule it seeds.
*/

beforeEach(function () {
    seedRoles();
    $this->reporter = User::factory()->create(['profile_complete' => true]);
});

/**
 * Per-scenario escalation setup: creates the department and the escalation
 * rule (idempotently via firstOrCreate, mirroring EscalatedSetupSeeder)
 * and returns a config bag including a ticket factory bound to that
 * department. Must be called inside a test so it runs against the current
 * transactional test DB.
 */
function escalationScenario(string $name): array
{
    return match ($name) {
        'billing' => escalationScenarioFor(
            departmentName: 'Billing',
            departmentDescription: 'Payment issues, subscription questions',
            ruleName: 'Billing Ticket 24h Auto-Escalation',
            ruleDescription: 'Auto-escalate Billing department tickets unresolved after 24 hours to urgent priority',
            category: 'Billing',
            thresholdHours: 24,
            order: 20,
            ticketDefaults: [
                'subject' => 'Payment failed for subscription',
                'description' => 'My payment was declined and I cannot access premium features.',
                'priority' => TicketPriority::Medium->value,
                'ticket_type' => 'billing_support',
                'metadata' => [
                    'paddle_subscription_id' => 'sub_12345',
                ],
            ],
        ),
        'safety' => escalationScenarioFor(
            departmentName: 'Safety',
            departmentDescription: 'Safety and moderation department',
            ruleName: 'Safety Ticket Auto-Escalation',
            ruleDescription: 'Auto-escalate Safety department tickets unresolved after 4 hours',
            category: 'Safety',
            thresholdHours: 4,
            order: 10,
            ticketDefaults: [
                'subject' => 'Review Report: Harassment',
                'description' => 'Reported review content...',
                'priority' => TicketPriority::High->value,
                'ticket_type' => 'review_report',
                'metadata' => [
                    'review_id' => Str::uuid()->toString(),
                    'report_reason' => 'harassment',
                ],
            ],
        ),
    };
}

function escalationScenarioFor(
    string $departmentName,
    string $departmentDescription,
    string $ruleName,
    string $ruleDescription,
    string $category,
    int $thresholdHours,
    int $order,
    array $ticketDefaults,
): array {
    $department = Department::firstOrCreate(
        ['name' => $departmentName],
        ['description' => $departmentDescription]
    );

    $ruleAttributes = [
        'description' => $ruleDescription,
        'trigger_type' => 'time_based',
        'category' => $category,
        'conditions' => [
            ['field' => 'department_id', 'value' => $department->id],
            ['field' => 'age_hours', 'value' => $thresholdHours],
        ],
        'actions' => [
            ['type' => 'escalate'],
            ['type' => 'change_priority', 'value' => 'urgent'],
        ],
        'is_active' => true,
        'order' => $order,
    ];

    EscalationRule::firstOrCreate(['name' => $ruleName], $ruleAttributes);

    $createTicket = function (User $reporter, array $overrides = []) use ($ticketDefaults, $department): Ticket {
        return Ticket::create(array_merge(
            [
                'requester_type' => User::class,
                'requester_id' => $reporter->id,
                'status' => TicketStatus::Open->value,
                'department_id' => $department->id,
            ],
            $ticketDefaults,
            $overrides
        ));
    };

    return [
        'department' => $department,
        'thresholdHours' => $thresholdHours,
        'ruleName' => $ruleName,
        'ruleAttributes' => $ruleAttributes,
        'createTicket' => $createTicket,
    ];
}

// Dataset: department label => scenario key resolved by escalationScenario().
$ticketEscalationScenarios = [
    'Billing (24h)' => 'billing',
    'Safety (4h)' => 'safety',
];

// ── Core Escalation Rule Tests ─────────────────────────────────────────

it('escalates ticket after threshold hours', function (string $scenarioName) {
    $scenario = escalationScenario($scenarioName);
    $createTicket = $scenario['createTicket'];
    $threshold = $scenario['thresholdHours'];

    // Age the ticket one hour past the threshold
    $ticket = $createTicket($this->reporter);
    $ticket->updateQuietly(['created_at' => now()->subHours($threshold + 1)]);

    $service = app(EscalationService::class);
    $escalated = $service->evaluateRules();

    expect($escalated)->toBe(1);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Escalated);
    expect($ticket->priority)->toBe(TicketPriority::Urgent);
})->with($ticketEscalationScenarios);

it('does not escalate ticket before threshold hours', function (string $scenarioName) {
    $scenario = escalationScenario($scenarioName);
    $createTicket = $scenario['createTicket'];
    $threshold = $scenario['thresholdHours'];
    $underThreshold = max(1, intdiv($threshold, 2));

    $ticket = $createTicket($this->reporter);
    $initialPriority = $ticket->priority;
    // Age the ticket to roughly half the threshold (well under it)
    $ticket->updateQuietly(['created_at' => now()->subHours($underThreshold)]);

    $service = app(EscalationService::class);
    $escalated = $service->evaluateRules();

    expect($escalated)->toBe(0);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Open);
    expect($ticket->priority)->toBe($initialPriority);
})->with($ticketEscalationScenarios);

// Folded from Billing-only ("does not escalate ticket at exactly 23 hour"):
// thresholdHours - 1 is exactly one hour under the threshold for any scenario.
it('does not escalate ticket one hour below threshold', function (string $scenarioName) {
    $scenario = escalationScenario($scenarioName);
    $createTicket = $scenario['createTicket'];
    $threshold = $scenario['thresholdHours'];

    $ticket = $createTicket($this->reporter);
    // Exactly one hour under the threshold
    $ticket->updateQuietly(['created_at' => now()->subHours($threshold - 1)]);

    $service = app(EscalationService::class);
    $service->evaluateRules();

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Open);
})->with($ticketEscalationScenarios);

it('does not escalate ticket in a different department', function (string $scenarioName) {
    $scenario = escalationScenario($scenarioName);
    $createTicket = $scenario['createTicket'];
    $threshold = $scenario['thresholdHours'];

    $otherDept = Department::firstOrCreate(
        ['name' => 'Unrelated'],
        ['description' => 'A department with no escalation rule in this scenario']
    );

    $ticket = $createTicket($this->reporter, ['department_id' => $otherDept->id]);
    $initialPriority = $ticket->priority;
    // Aged well past threshold but in the wrong department
    $ticket->updateQuietly(['created_at' => now()->subHours($threshold * 2)]);

    $service = app(EscalationService::class);
    $escalated = $service->evaluateRules();

    expect($escalated)->toBe(0);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Open);
    expect($ticket->priority)->toBe($initialPriority);
})->with($ticketEscalationScenarios);

it('does not escalate already closed ticket', function (string $scenarioName) {
    $scenario = escalationScenario($scenarioName);
    $createTicket = $scenario['createTicket'];
    $threshold = $scenario['thresholdHours'];

    $ticket = $createTicket($this->reporter);
    $ticket->updateQuietly([
        'status' => TicketStatus::Closed->value,
        'created_at' => now()->subHours($threshold * 2),
    ]);

    $service = app(EscalationService::class);
    $escalated = $service->evaluateRules();

    expect($escalated)->toBe(0);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Closed);
})->with($ticketEscalationScenarios);

it('does not escalate already resolved ticket', function (string $scenarioName) {
    $scenario = escalationScenario($scenarioName);
    $createTicket = $scenario['createTicket'];
    $threshold = $scenario['thresholdHours'];

    $ticket = $createTicket($this->reporter);
    $ticket->updateQuietly([
        'status' => TicketStatus::Resolved->value,
        'created_at' => now()->subHours($threshold * 2),
    ]);

    $service = app(EscalationService::class);
    $escalated = $service->evaluateRules();

    expect($escalated)->toBe(0);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Resolved);
})->with($ticketEscalationScenarios);

// ── Artisan Command Tests ──────────────────────────────────────────────

it('evaluates escalations via artisan command', function (string $scenarioName) {
    $scenario = escalationScenario($scenarioName);
    $createTicket = $scenario['createTicket'];
    $threshold = $scenario['thresholdHours'];

    $ticket = $createTicket($this->reporter);
    $ticket->updateQuietly(['created_at' => now()->subHours($threshold + 1)]);

    $this->artisan('escalated:evaluate-escalations')
        ->expectsOutputToContain('Escalation evaluation complete')
        ->assertSuccessful();

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Escalated);
    expect($ticket->priority)->toBe(TicketPriority::Urgent);
})->with($ticketEscalationScenarios);

// Folded from Safety-only ("reports zero escalations when no tickets match"):
// a fresh, un-aged ticket is under every threshold, so nothing escalates.
it('reports zero escalations when no tickets match', function (string $scenarioName) {
    $scenario = escalationScenario($scenarioName);
    $createTicket = $scenario['createTicket'];

    // No aged tickets — only a fresh one that should not match
    $createTicket($this->reporter);

    $this->artisan('escalated:evaluate-escalations')
        ->expectsOutputToContain('Escalation evaluation complete: 0 tickets affected')
        ->assertSuccessful();
})->with($ticketEscalationScenarios);

// ── Escalation Rule Seed Tests ─────────────────────────────────────────

it('escalation rule is idempotent via firstOrCreate', function (string $scenarioName) {
    $scenario = escalationScenario($scenarioName);

    // Rule already created in escalationScenario() — seeding again must not duplicate
    $count = EscalationRule::where('name', $scenario['ruleName'])->count();

    EscalationRule::firstOrCreate(['name' => $scenario['ruleName']], $scenario['ruleAttributes']);

    expect(EscalationRule::where('name', $scenario['ruleName'])->count())->toBe($count);
})->with($ticketEscalationScenarios);

// ── Multiple Ticket Tests ──────────────────────────────────────────────

it('escalates multiple matching tickets at once', function (string $scenarioName) {
    $scenario = escalationScenario($scenarioName);
    $createTicket = $scenario['createTicket'];
    $threshold = $scenario['thresholdHours'];

    $ticket1 = $createTicket($this->reporter);
    $ticket1->updateQuietly(['created_at' => now()->subHours($threshold + 1)]);

    $ticket2 = $createTicket($this->reporter);
    $ticket2->updateQuietly(['created_at' => now()->subHours($threshold + 2)]);

    // Fresh ticket — should NOT be escalated
    $ticket3 = $createTicket($this->reporter);

    $service = app(EscalationService::class);
    $escalated = $service->evaluateRules();

    expect($escalated)->toBe(2);

    $ticket1->refresh();
    $ticket2->refresh();
    $ticket3->refresh();

    expect($ticket1->status)->toBe(TicketStatus::Escalated);
    expect($ticket2->status)->toBe(TicketStatus::Escalated);
    expect($ticket3->status)->toBe(TicketStatus::Open);
})->with($ticketEscalationScenarios);

it('only matches active escalation rules', function (string $scenarioName) {
    $scenario = escalationScenario($scenarioName);
    $createTicket = $scenario['createTicket'];
    $threshold = $scenario['thresholdHours'];

    // Deactivate the scenario's rule
    EscalationRule::where('name', $scenario['ruleName'])->update(['is_active' => false]);

    $ticket = $createTicket($this->reporter);
    $ticket->updateQuietly(['created_at' => now()->subHours($threshold + 1)]);

    $service = app(EscalationService::class);
    $escalated = $service->evaluateRules();

    expect($escalated)->toBe(0);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Open);
})->with($ticketEscalationScenarios);
