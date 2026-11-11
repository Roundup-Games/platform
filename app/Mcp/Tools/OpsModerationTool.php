<?php

namespace App\Mcp\Tools;

use App\Enums\McpAbility;
use App\Filament\Resources\TicketResource\Actions\ContentReport;
use App\Filament\Resources\TicketResource\Actions\ReviewModeration;
use App\Mcp\Concerns\HandlesIdempotency;
use App\Mcp\Concerns\LinksAuditTarget;
use App\Mcp\Concerns\MarksAgentActor;
use App\Mcp\Concerns\ResolvesActingUser;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\Review;
use App\Models\User;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * The ops moderation tool (M079/S04/T01, R090): wrap — never reimplement —
 * the admin moderation actions that are already backed by services.
 *
 * Every action delegates to the exact static the Filament ViewTicket page
 * calls (ContentReport::clearCover() and siblings, ReviewModeration::*):
 * the transactions, internal notes, ticket closes, notification fan-out,
 * and the actions' own logging all live there. The tool adds only the
 * agent-surface contract: domain gating (which action fits which ticket
 * type, mirroring the actions' Filament visibility gates), the acting
 * admin bound to the guard the actions read, and post-state reporting.
 *
 * Dual-gated (D277): the middleware requires BOTH the mcp:ops token
 * ability AND the Platform Admin role; the tool re-checks the role as
 * defense in depth.
 *
 * Audit: every call links its ticket id into the mcp_calls row
 * (target_type 'ticket') and emits an mcp.agent_write structured event.
 */
#[Name(self::NAME)]
#[Description('Wraps the platform\'s existing admin moderation actions for Safety-department tickets. content_report tickets: dismiss, warn_user (optional note), clear_cover (removes only the reported game/campaign cover), remove_content, suspend_user, escalate (priority to Urgent + reassign to a Platform Admin). review_report tickets: dismiss, remove_review, escalate. Actions close the ticket exactly like the admin panel; the ticket id is audit-linked.')]
class OpsModerationTool extends Tool
{
    use HandlesIdempotency;
    use LinksAuditTarget;
    use MarksAgentActor;
    use ResolvesActingUser;

    /** The MCP tool name (single-sourced: registry, tests, kill-switch, audit). */
    public const NAME = 'ops.moderation';

    /** Tool-class scope (D277) — dual-gated with the Platform Admin role. */
    public const ABILITY = McpAbility::Ops;

    /**
     * Which actions fit which ticket type — the same pairing the Filament
     * header-action visibility gates enforce.
     *
     * @var array<string, list<string>>
     */
    private const ACTIONS_BY_TYPE = [
        'content_report' => ['dismiss', 'warn_user', 'clear_cover', 'remove_content', 'suspend_user', 'escalate'],
        'review_report' => ['dismiss', 'remove_review', 'escalate'],
    ];

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
            'action' => ['required', 'string', 'in:dismiss,warn_user,clear_cover,remove_content,suspend_user,escalate,remove_review'],
            'ticket_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:1000'],
            self::IDEMPOTENCY_KEY_ARGUMENT => ['nullable', 'string', 'max:255'],
        ]);

        /** @var 'dismiss'|'warn_user'|'clear_cover'|'remove_content'|'suspend_user'|'escalate'|'remove_review' $action */
        $action = $args['action'] ?? '';

        $response = $this->idempotentResponse(
            $request,
            fn (): Response => $this->execute(
                $user,
                $action,
                $this->intArgument($args, 'ticket_id') ?? 0,
                $args,
            ),
        );

        return $response instanceof Response ? $response : $response[0];
    }

    /**
     * Gate the domain, bind the acting admin onto the guard the wrapped
     * actions read, call the action static, and report the observed
     * post-state (the actions own their transactions and outcomes).
     *
     * @param  'dismiss'|'warn_user'|'clear_cover'|'remove_content'|'suspend_user'|'escalate'|'remove_review'  $action
     * @param  array<string, mixed>  $args
     */
    private function execute(User $user, string $action, int $ticketId, array $args): Response
    {
        $ticket = Ticket::query()->find($ticketId);

        if ($ticket === null) {
            return $this->denial('not_found', "Ticket {$ticketId} was not found.");
        }

        $this->linkAuditTarget('ticket', $ticket->id);

        $ticketType = is_string($ticket->ticket_type) ? $ticket->ticket_type : '';
        $allowed = self::ACTIONS_BY_TYPE[$ticketType] ?? null;

        // The wrapped actions' visibility gates require BOTH the ticket
        // type and the Safety department (ContentReport::isContentReport /
        // ReviewModeration::isReviewReport) — a report ticket that was
        // routed into another department carries no moderation actions in
        // the admin panel, so it carries none here either.
        $departmentName = $ticket->department->name ?? null;
        $inSafetyDepartment = $departmentName === 'Safety';

        if ($allowed !== null && ! $inSafetyDepartment) {
            return $this->denial('wrong_department', sprintf(
                'Moderation actions apply to Safety-department %s tickets only (ticket %s is %s).',
                $ticketType !== '' ? $ticketType : 'this',
                $ticket->reference,
                $departmentName !== null ? "in {$departmentName}" : 'undepartmented',
            ));
        }

        if ($allowed === null || ! in_array($action, $allowed, true)) {
            $fits = $ticketType !== '' && isset(self::ACTIONS_BY_TYPE[$ticketType])
                ? implode(', ', self::ACTIONS_BY_TYPE[$ticketType])
                : 'no moderation actions';

            return $this->denial('wrong_ticket_type', sprintf(
                '%s does not apply to %s tickets (ticket %s). This ticket accepts: %s.',
                $action,
                $ticketType !== '' ? $ticketType : 'this',
                $ticket->reference,
                $fits,
            ));
        }

        if (! $ticket->isOpen()) {
            $status = $ticket->status instanceof TicketStatus ? $ticket->status->value : 'unknown';

            return $this->denial('not_open', "Ticket {$ticket->reference} is {$status}; moderation actions need an open ticket.");
        }

        $metadata = is_array($ticket->metadata) ? $ticket->metadata : [];
        $entityType = self::asString($metadata['entity_type'] ?? null);
        $entityName = self::asString($metadata['entity_name'] ?? null);
        $entityName = $entityName === '' ? 'this content' : $entityName;
        $note = is_string($args['note'] ?? null) && $args['note'] !== '' ? $args['note'] : null;

        // clear_cover mirrors the Filament visibility gate: the action only
        // exists for game/campaign entities that actually carry a host cover.
        if ($action === 'clear_cover') {
            $entity = $this->reportedEntity($ticket);

            if ($entity === null || ! method_exists($entity, 'hasCover') || ! $entity->hasCover()) {
                return $this->denial('no_cover', "Ticket {$ticket->reference} reports a {$entityType} with no host-uploaded cover — nothing proportionate to clear.");
            }
        }

        // The wrapped actions resolve their acting admin through the default
        // guard (TicketAction::currentUser()). Bind the verified PAT admin
        // there — same identity the middleware dual-gated, now in the exact
        // shape the shared actions expect. Request-scoped: no session write,
        // and the finally below unbinds so a long-lived worker never carries
        // the admin into a later request (the McpAuditTarget posture).
        auth()->setUser($user);

        // Snapshot the pre-action state BEFORE the call — the wrapped
        // actions mutate the same model instance (changePriority syncs
        // priority + updated_at onto $ticket), so reading it afterwards
        // would compare post-state with itself. For escalate, completion
        // proof is a NEW escalation note (reply row) — every successful
        // escalate run writes one inside its committed transaction, so a
        // before/after count comparison is immune to the same-second
        // timestamp granularity that made the old updated_at comparison
        // report false failures on already-urgent tickets.
        $wasUrgent = self::priorityValue($ticket->priority) === 'urgent';
        $replyCountBefore = $ticket->replies()->count();

        try {
            $this->runAction($action, $ticketType, $ticket, $entityType, $entityName, $note);
        } finally {
            auth()->forgetUser();
        }

        /** @var Ticket $after */
        $after = $ticket->fresh() ?? $ticket;

        // The actions surface their internal failures as Filament toasts
        // (logged via their shared fail() path) and return void — an open
        // ticket after the call means the action did not complete. For
        // escalate (the only action that leaves the ticket open) urgency
        // alone is not proof when the ticket was ALREADY urgent — a failed
        // run would leave it urgent too — so an already-urgent ticket must
        // also carry a new escalation note beyond the pre-action snapshot.
        $completed = $action === 'escalate'
            ? self::priorityValue($after->priority) === 'urgent'
                && (! $wasUrgent || $after->replies()->count() > $replyCountBefore)
            : ! $after->isOpen();

        $this->logAgentWrite('ops.moderation.'.$action, $user, [
            'ticket_id' => $ticket->id,
            'completed' => $completed,
        ]);

        if (! $completed) {
            return $this->denial('action_failed', sprintf(
                'The %s action did not complete on ticket %s (it may have failed to resolve the reported entity or user; its error path logged the details).',
                $action,
                $ticket->reference,
            ));
        }

        return Response::json([
            'status' => 'completed',
            'action' => $action,
            'ticket' => $this->ticketOutcome($after),
        ]);
    }

    /**
     * Dispatch to the wrapped action static — extracted so the guard
     * binding above can guarantee the unbind in a finally.
     *
     * @param  'dismiss'|'warn_user'|'clear_cover'|'remove_content'|'suspend_user'|'escalate'|'remove_review'  $action
     */
    private function runAction(string $action, string $ticketType, Ticket $ticket, string $entityType, string $entityName, ?string $note): void
    {
        match ($action) {
            'dismiss' => $ticketType === 'review_report'
                ? ReviewModeration::dismissReport($ticket)
                : ContentReport::dismissReport($ticket),
            'warn_user' => ContentReport::warnUser($ticket, $entityType, $entityName, $note),
            'clear_cover' => ContentReport::clearCover($ticket, $entityType, $entityName),
            'remove_content' => ContentReport::removeContent($ticket, $entityType, $entityName),
            'suspend_user' => ContentReport::suspendUser($ticket, $entityType),
            'escalate' => $ticketType === 'review_report'
                ? ReviewModeration::escalateReport($ticket)
                : ContentReport::escalateReport($ticket),
            'remove_review' => ReviewModeration::removeReview($ticket),
        };
    }

    /**
     * The observed post-state after the wrapped action ran — facts read
     * back from the domain, never assumptions about what ran.
     *
     * @return array<string, mixed>
     */
    private function ticketOutcome(Ticket $ticket): array
    {
        $outcome = [
            'id' => $ticket->id,
            'reference' => $ticket->reference,
            'status' => $ticket->status?->value,
            'priority' => self::priorityValue($ticket->priority),
        ];

        $metadata = is_array($ticket->metadata) ? $ticket->metadata : [];
        $entityType = self::asString($metadata['entity_type'] ?? null);
        $entityId = self::asString($metadata['entity_id'] ?? null);

        $entity = $this->reportedEntity($ticket);

        if ($entity instanceof Game || $entity instanceof Campaign) {
            $outcome['entity'] = [
                'type' => $entityType,
                'id' => $entity->id,
                'status' => $entity->status?->value,
                'has_cover' => $entity->hasCover(),
            ];
        }

        $reportedUser = match ($entityType) {
            'user' => $entityId !== '' ? User::query()->find($entityId) : null,
            'game', 'campaign' => $entity instanceof Game || $entity instanceof Campaign ? $entity->owner : null,
            default => null,
        };

        if ($reportedUser instanceof User) {
            $outcome['reported_user'] = [
                'id' => $reportedUser->id,
                'is_disabled' => $reportedUser->is_disabled,
            ];
        }

        $reviewId = self::asString($metadata['review_id'] ?? null);

        if ($reviewId !== '') {
            $review = Review::query()->find($reviewId);
            $outcome['review_status'] = $review?->status;
        }

        return $outcome;
    }

    /**
     * Resolve the reported entity model (Game/Campaign/User) from ticket
     * metadata — the same read the Filament cover gate performs to decide
     * whether Clear Cover applies. Read-only: control flow stays in the
     * wrapped actions.
     */
    private function reportedEntity(Ticket $ticket): Game|Campaign|User|null
    {
        $metadata = is_array($ticket->metadata) ? $ticket->metadata : [];
        $entityType = self::asString($metadata['entity_type'] ?? null);
        $entityId = self::asString($metadata['entity_id'] ?? null);

        if ($entityId === '') {
            return null;
        }

        return match ($entityType) {
            'user' => User::query()->find($entityId),
            'game' => Game::query()->find($entityId),
            'campaign' => Campaign::query()->find($entityId),
            default => null,
        };
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
     * Safely convert a mixed metadata value to a string (the shared
     * TicketAction coercion — level-9-safe on mixed metadata).
     */
    private static function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
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
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['dismiss', 'warn_user', 'clear_cover', 'remove_content', 'suspend_user', 'escalate', 'remove_review'])->description('The moderation action. content_report tickets: dismiss | warn_user | clear_cover | remove_content | suspend_user | escalate. review_report tickets: dismiss | remove_review | escalate. Each maps 1:1 to the admin panel action of the same name.'),
            'ticket_id' => $schema->integer()->description('The numeric Escalated ticket id of the Safety-department report being actioned.')->required(),
            'note' => $schema->string()->description('Optional internal note (max 1000 characters) — warn_user\'s admin note, recorded on the ticket.'),
            self::IDEMPOTENCY_KEY_ARGUMENT => $schema->string()->description('Optional client-generated key: a retried call with the same key replays the first answer instead of executing again.'),
        ];
    }

    /**
     * Structured denial: stable snake_case code + message, mirroring the
     * host tools' denial convention so agents can branch without parsing.
     */
    private function denial(string $code, string $message): Response
    {
        return Response::error($code.': '.$message);
    }
}
