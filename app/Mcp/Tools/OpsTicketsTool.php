<?php

namespace App\Mcp\Tools;

use App\Enums\McpAbility;
use App\Mcp\Concerns\HandlesIdempotency;
use App\Mcp\Concerns\LinksAuditTarget;
use App\Mcp\Concerns\MarksAgentActor;
use App\Mcp\Concerns\ResolvesActingUser;
use App\Models\User;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Ticket;
use Escalated\Laravel\Services\TicketService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\In;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * The ops ticket triage tool (M079/S04/T01, R090): list, assign, and
 * resolve Escalated helpdesk tickets for support agents.
 *
 * Every action goes through the same shared layer the Filament admin
 * actions use — TicketService (list/addNote/changeStatus) and
 * Ticket::assign() — never the Escalated SDK's write paths directly, so
 * notes, status transitions, activity log, events, and SLA bookkeeping
 * behave identically to the admin panel.
 *
 * Dual-gated (D277): the middleware requires BOTH the mcp:ops token
 * ability AND the Platform Admin role; the tool re-checks the role as
 * defense in depth (a fail-closed tool never assumes the gate ran).
 *
 * Audit: every targeted call (assign, resolve) links its ticket id into
 * the mcp_calls row (target_type 'ticket'), so the trail replaces SSH
 * notes with "which consumer, acting as which admin, touched which
 * ticket" — the slice verification contract.
 */
#[Name(self::NAME)]
#[Description('Platform-Admin support triage over the Escalated helpdesk. Actions: list (filter by status, priority, ticket_type, department name, open flag, search; paginated), assign (ticket_id + assignee_id — assignee must be a Platform Admin), resolve (ticket_id + optional note). All writes go through the same ticket service the admin panel uses and leave an agent-attributed audit row linking the ticket.')]
class OpsTicketsTool extends Tool
{
    use HandlesIdempotency;
    use LinksAuditTarget;
    use MarksAgentActor;
    use ResolvesActingUser;

    /** The MCP tool name (single-sourced: registry, tests, kill-switch, audit). */
    public const NAME = 'ops.tickets';

    /** Tool-class scope (D277) — dual-gated with the Platform Admin role. */
    public const ABILITY = McpAbility::Ops;

    public function handle(Request $request): Response
    {
        $user = $this->actingUser($request);

        if ($user === null) {
            return Response::error('No authenticated acting user for this tool.');
        }

        // Defense in depth: the gate already dual-checked (mcp:ops ability +
        // Platform Admin role); a fail-closed tool never assumes the gate ran.
        if (! $user->hasRole('Platform Admin')) {
            return $this->denial('not_authorized', 'The ops surface requires an active Platform Admin.');
        }

        $args = $request->validate([
            'action' => ['required', 'string', 'in:list,assign,resolve'],
            'ticket_id' => ['nullable', 'integer'],
            'assignee_id' => ['nullable', 'uuid'],
            'note' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', 'string', new In(array_column(TicketStatus::cases(), 'value'))],
            'priority' => ['nullable', 'string', new In(array_column(TicketPriority::cases(), 'value'))],
            'ticket_type' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:100'],
            'open' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            self::IDEMPOTENCY_KEY_ARGUMENT => ['nullable', 'string', 'max:255'],
        ]);

        /** @var 'list'|'assign'|'resolve' $action */
        $action = $args['action'] ?? '';

        $response = $this->idempotentResponse(
            $request,
            fn (): Response => $this->execute($user, $action, $args),
        );

        return $response instanceof Response ? $response : $response[0];
    }

    /**
     * Dispatch the validated action.
     *
     * @param  'list'|'assign'|'resolve'  $action
     * @param  array<string, mixed>  $args
     */
    private function execute(User $user, string $action, array $args): Response
    {
        return match ($action) {
            'list' => $this->listTickets($user, $args),
            'assign' => $this->assignTicket($user, $args),
            'resolve' => $this->resolveTicket($user, $args),
        };
    }

    /**
     * The triage list, through TicketService::list() — the same driver
     * filter pipeline the admin helpdesk list uses.
     *
     * @param  array<string, mixed>  $args
     */
    private function listTickets(User $user, array $args): Response
    {
        $perPage = is_numeric($args['per_page'] ?? null) ? (int) $args['per_page'] : 15;
        $filters = [
            'per_page' => $perPage,
        ];

        foreach (['status', 'priority', 'ticket_type'] as $key) {
            if (is_string($args[$key] ?? null) && $args[$key] !== '') {
                $filters[$key] = $args[$key];
            }
        }

        if (($args['open'] ?? null) === true) {
            $filters['open'] = true;
        }

        if (is_string($args['search'] ?? null) && $args['search'] !== '') {
            $filters['search'] = $args['search'];
        }

        if (is_string($args['department'] ?? null) && $args['department'] !== '') {
            $department = Department::query()
                ->whereRaw('lower(name) = lower(?)', [$args['department']])
                ->first();

            if ($department === null) {
                return $this->denial('unknown_department', sprintf('No helpdesk department named "%s".', $args['department']));
            }

            $filters['department_id'] = $department->id;
        }

        $paginator = app(TicketService::class)->list($filters);

        $tickets = [];

        foreach ($paginator->items() as $row) {
            if ($row instanceof Ticket) {
                $tickets[] = $this->ticketSummary($row);
            }
        }

        $this->logAgentWrite('ops.tickets.list', $user, [
            'result_count' => count($tickets),
            'filters' => Arr::except($filters, 'per_page') === [] ? null : array_keys(Arr::except($filters, 'per_page')),
        ]);

        return Response::json([
            'status' => 'listed',
            'tickets' => $tickets,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }

    /**
     * Assign an open ticket to a Platform Admin — the same Ticket::assign()
     * call the Filament escalation actions make (activity log, TicketAssigned
     * event, webhook fan-out included), with the acting admin as causer.
     *
     * @param  array<string, mixed>  $args
     */
    private function assignTicket(User $user, array $args): Response
    {
        $ticketId = $this->intArgument($args, 'ticket_id');
        $assigneeId = is_string($args['assignee_id'] ?? null) ? $args['assignee_id'] : '';

        if ($ticketId === null) {
            return $this->denial('invalid_arguments', 'assign requires ticket_id.');
        }

        if ($assigneeId === '') {
            return $this->denial('invalid_arguments', 'assign requires assignee_id.');
        }

        $ticket = Ticket::query()->find($ticketId);

        if ($ticket === null) {
            return $this->denial('not_found', "Ticket {$ticketId} was not found.");
        }

        $this->linkAuditTarget('ticket', $ticket->id);

        if (! $ticket->isOpen()) {
            $status = $ticket->status instanceof TicketStatus ? $ticket->status->value : 'unknown';

            return $this->denial('not_open', "Ticket {$ticket->reference} is {$status}; only open tickets can be reassigned.");
        }

        $assignee = User::query()->find($assigneeId);

        if ($assignee === null) {
            return $this->denial('not_found', 'Assignee user was not found.');
        }

        // The support staff this platform recognizes are Platform Admins —
        // the same population the Filament escalation path assigns to.
        if (! $assignee->hasRole('Platform Admin')) {
            return $this->denial('assignee_not_admin', 'Tickets can only be assigned to Platform Admins through this tool.');
        }

        $ticketService = app(TicketService::class);
        $note = is_string($args['note'] ?? null) && $args['note'] !== '' ? $args['note'] : null;

        // Note inside the transaction, the webhook-fanout write deferred to
        // afterCommit — the app's Filament escalation precedent
        // (ContentReport::escalateReport): Ticket::assign() dispatches
        // TicketAssigned, whose DispatchWebhook listener does a blocking
        // Http::timeout(10) call. Running it inside the transaction would
        // hold the row locks for the whole webhook window and risk
        // send-on-rollback.
        DB::transaction(function () use ($ticket, $user, $assignee, $ticketService, $note): void {
            if ($note !== null) {
                $ticketService->addNote($ticket, $user, 'Assigned via ops agent: '.$note);
            }

            DB::afterCommit(fn () => $ticket->assign($assignee, $user));
        });

        $this->logAgentWrite('ops.tickets.assign', $user, [
            'ticket_id' => $ticket->id,
            'assignee_id' => $assignee->id,
        ]);

        return Response::json([
            'status' => 'assigned',
            'ticket' => $this->ticketSummary($ticket->fresh() ?? $ticket),
        ]);
    }

    /**
     * Resolve a ticket through TicketService::changeStatus() — the same
     * announced, activity-logged transition the helpdesk status actions
     * use, with the acting admin as causer. An optional internal note is
     * attached first (TicketService::addNote, like every Filament action).
     *
     * @param  array<string, mixed>  $args
     */
    private function resolveTicket(User $user, array $args): Response
    {
        $ticketId = $this->intArgument($args, 'ticket_id');

        if ($ticketId === null) {
            return $this->denial('invalid_arguments', 'resolve requires ticket_id.');
        }

        $ticket = Ticket::query()->find($ticketId);

        if ($ticket === null) {
            return $this->denial('not_found', "Ticket {$ticketId} was not found.");
        }

        $this->linkAuditTarget('ticket', $ticket->id);

        if (! $ticket->isOpen()) {
            $status = $ticket->status instanceof TicketStatus ? $ticket->status->value : 'unknown';

            return $this->denial('already_resolved', "Ticket {$ticket->reference} is already {$status}.");
        }

        $ticketService = app(TicketService::class);
        $note = is_string($args['note'] ?? null) && $args['note'] !== '' ? $args['note'] : null;
        $previousStatus = $ticket->status instanceof TicketStatus ? $ticket->status->value : 'unknown';

        // Note inside the transaction, the status transition deferred to
        // afterCommit — same rationale as assignTicket: Ticket::markResolved
        // dispatches TicketStatusChanged + TicketResolved, whose listeners
        // (webhook, requester notification) make blocking calls that must
        // not hold the transaction's row locks (the ContentReport precedent).
        DB::transaction(function () use ($ticket, $user, $ticketService, $note): void {
            if ($note !== null) {
                $ticketService->addNote($ticket, $user, 'Resolved via ops agent: '.$note);
            }

            DB::afterCommit(fn () => $ticketService->changeStatus($ticket, TicketStatus::Resolved, $user));
        });

        $this->logAgentWrite('ops.tickets.resolve', $user, [
            'ticket_id' => $ticket->id,
            'previous_status' => $previousStatus,
        ]);

        return Response::json([
            'status' => 'resolved',
            'ticket' => $this->ticketSummary($ticket->fresh() ?? $ticket),
        ]);
    }

    /**
     * One ticket's triage summary — the columns the admin helpdesk list
     * shows, ids and enums only.
     *
     * @return array<string, mixed>
     */
    private function ticketSummary(Ticket $ticket): array
    {
        $requester = $ticket->requester;
        $assignee = $ticket->assignee;

        return [
            'id' => $ticket->id,
            'reference' => $ticket->reference,
            'subject' => $ticket->subject,
            'status' => $ticket->status?->value,
            'priority' => self::priorityValue($ticket->priority),
            'ticket_type' => $ticket->ticket_type,
            'department' => $ticket->department?->name,
            'requester' => $this->requesterSummary($ticket, $requester),
            'assignee' => $assignee instanceof User
                ? ['id' => $assignee->id, 'name' => $assignee->name]
                : null,
            'created_at' => $ticket->created_at?->toIso8601String(),
            'updated_at' => $ticket->updated_at?->toIso8601String(),
            'url' => '/admin/tickets/'.$ticket->reference,
        ];
    }

    /**
     * Who filed the ticket — the triage signal the admin list carries.
     * Account requesters resolve to id/name/email; guest tickets carry the
     * guest name/email the vendor model exposes (the same fields the help-
     * desk list shows); contact-filed tickets resolve their ticketable name
     * through the vendor accessor. A ticket with no resolvable requester at
     * all answers null rather than a fabricated row.
     *
     * @return array<string, mixed>|null
     */
    private function requesterSummary(Ticket $ticket, ?object $requester): ?array
    {
        if ($requester instanceof User) {
            return ['id' => $requester->id, 'name' => $requester->name, 'email' => $requester->email];
        }

        if ($ticket->isGuest()) {
            return ['name' => $ticket->guest_name ?? 'Guest', 'email' => $ticket->guest_email];
        }

        if ($ticket->requester !== null) {
            return ['name' => $ticket->requester_name];
        }

        return null;
    }

    /**
     * Structured denial: stable snake_case code + message, mirroring the
     * host tools' denial convention so agents can branch without parsing.
     */
    private function denial(string $code, string $message): Response
    {
        return Response::error($code.': '.$message);
    }

    /**
     * Narrow a validated integer argument out of the mixed args bag.
     *
     * @param  array<string, mixed>  $args
     */
    private function intArgument(array $args, string $key): ?int
    {
        $value = $args[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * The ticket priority as its wire string. The vendor model enum-casts
     * priority at runtime, but Larastan's schema-derived type is string —
     * this narrow handles both shapes without lying to either.
     */
    private static function priorityValue(mixed $priority): ?string
    {
        if ($priority instanceof \BackedEnum) {
            return (string) $priority->value;
        }

        return is_string($priority) ? $priority : null;
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['list', 'assign', 'resolve'])->description('The triage action: list (browse/filter the queue), assign (hand a ticket to a Platform Admin), resolve (mark a ticket resolved).')->required(),
            'ticket_id' => $schema->integer()->description('The numeric Escalated ticket id (from a previous list). Required for assign and resolve.'),
            'assignee_id' => $schema->string()->description('UUID of the Platform Admin to assign the ticket to. Required for assign.'),
            'note' => $schema->string()->description('Optional internal note (max 1000 characters) attached to the ticket when assigning or resolving — the same internal notes the admin panel writes.'),
            'status' => $schema->string()->enum(array_column(TicketStatus::cases(), 'value'))->description('List filter: exact ticket status.'),
            'priority' => $schema->string()->enum(array_column(TicketPriority::cases(), 'value'))->description('List filter: exact priority.'),
            'ticket_type' => $schema->string()->description('List filter: exact ticket type (e.g. content_report, review_report, billing_support).'),
            'department' => $schema->string()->description('List filter: helpdesk department name (e.g. Safety, Billing) — case-insensitive.'),
            'open' => $schema->boolean()->description('List filter: only open tickets (not resolved or closed).'),
            'search' => $schema->string()->description('List filter: free-text search over ticket subject/description and requester.'),
            'per_page' => $schema->integer()->min(1)->max(50)->description('List page size (default 15, max 50).'),
            self::IDEMPOTENCY_KEY_ARGUMENT => $schema->string()->description('Optional client-generated key: a retried call with the same key replays the first answer instead of executing again.'),
        ];
    }
}
