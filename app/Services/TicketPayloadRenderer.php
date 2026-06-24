<?php

namespace App\Services;

use App\Models\Game;
use App\Models\User;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Database\Eloquent\Model;

/**
 * Renders a Ticket's metadata as structured, linked HTML for display.
 *
 * The metadata payload follows a standard schema:
 *
 *   schema    string   Versioned payload type, e.g. "content_report/v1"
 *   actor     array    {type, id, name} — who triggered the action
 *   action    string   What happened: report, request, support, export, payment_failure
 *   entities  array    [{type, id, name}] — affected entities (users, games, campaigns, reviews)
 *   reason    ?string  Machine-readable reason slug (e.g. "misleading")
 *   details   ?string  Freeform user-provided text
 *   context   ?array   Ticket-type-specific extra data (subscription info, etc.)
 *
 * Legacy tickets without a `schema` key fall back to rendering `description` as-is.
 */
class TicketPayloadRenderer
{
    /**
     * Build the shared base payload: structured schema keys + actor.
     *
     * The single source of truth for the schema envelope. Every builder
     * composes on top of this so the actor/schema/action/entities/reason
     * structure is defined once, not 8 times.
     *
     * @param  array<int, array<string, mixed>>  $entities
     * @return array<string, mixed>
     */
    private static function basePayload(string $schema, User $user, string $action, array $entities, string $reason, ?string $details = null): array
    {
        return [
            'schema' => $schema,
            'actor' => ['type' => 'user', 'id' => $user->id, 'name' => $user->name],
            'action' => $action,
            'entities' => $entities,
            'reason' => $reason,
            'details' => $details,
        ];
    }

    /** Resolve an entity type + ID to a URL, or null if unknown. */
    public function resolveEntityUrl(mixed $type, mixed $id): ?string
    {
        $type = is_string($type) ? $type : '';
        $id = to_string_id($id);

        return match ($type) {
            'user' => route('profile.public', $id, absolute: false),
            'game' => route('games.detail', $id, absolute: false),
            'campaign' => route('campaigns.detail', $id, absolute: false),
            'review' => null, // Reviews don't have standalone public pages
            default => null,
        };
    }

    /** Resolve an entity type to a human-readable label. */
    public function entityTypeLabel(mixed $type): string
    {
        $type = is_string($type) ? $type : '';

        return match ($type) {
            'user' => __('User'),
            'game' => __('Game'),
            'campaign' => __('Campaign'),
            'review' => __('Review'),
            'subscription' => __('Subscription'),
            default => ucfirst($type),
        };
    }

    /** Resolve a reason slug to a human-readable label. */
    public function reasonLabel(mixed $reason): string
    {
        $reason = is_string($reason) ? $reason : '';

        return match ($reason) {
            'inappropriate-content' => __('Inappropriate Content'),
            'harassment' => __('Harassment'),
            'spam' => __('Spam'),
            'misleading' => __('Misleading'),
            'other' => __('Other'),
            'account_access' => __('Account Access'),
            'login_issue' => __('Login Issue'),
            'name_change' => __('Name Change'),
            'email_change' => __('Email Change'),
            'suspended_account' => __('Suspended Account'),
            'data_request' => __('Data Request'),
            'payment_issue' => __('Payment Issue'),
            'refund_request' => __('Refund Request'),
            'subscription_change' => __('Subscription Change'),
            'invoice_question' => __('Invoice Question'),
            'cancellation_issue' => __('Cancellation Issue'),
            default => ucfirst(str_replace('_', ' ', $reason)),
        };
    }

    /**
     * Build enriched metadata for a content report ticket.
     *
     * @return array<string, mixed>
     */
    public static function contentReportPayload(
        User $reporter,
        string $entityType,
        string $entityId,
        string $entityName,
        string $reason,
        ?string $details = null,
        ?string $entityOwnerName = null,
    ): array {
        $payload = self::basePayload(
            schema: 'content_report/v1',
            user: $reporter,
            action: 'report',
            entities: [
                ['type' => $entityType, 'id' => $entityId, 'name' => $entityName],
            ],
            reason: $reason,
            details: $details,
        );

        // Flat keys for backward compat with admin ViewTicket + duplicate detection
        $payload['entity_type'] = $entityType;
        $payload['entity_id'] = $entityId;
        $payload['entity_name'] = $entityName;
        $payload['report_reason'] = $reason;
        $payload['reporter_id'] = $reporter->id;

        if ($entityOwnerName) {
            $payload['context'] = ['entity_owner' => $entityOwnerName];
        }

        return $payload;
    }

    /**
     * Build enriched metadata for a review report ticket.
     *
     * @return array<string, mixed>
     */
    public static function reviewReportPayload(
        User $reporter,
        string $reviewId,
        string $reviewAuthorId,
        string $reviewAuthorName,
        string $reason,
        ?string $details = null,
    ): array {
        $payload = self::basePayload(
            schema: 'review_report/v1',
            user: $reporter,
            action: 'report',
            entities: [
                ['type' => 'review', 'id' => $reviewId, 'name' => __('Review by :name', ['name' => $reviewAuthorName])],
            ],
            reason: $reason,
            details: $details,
        );
        $payload['reported_user'] = ['type' => 'user', 'id' => $reviewAuthorId, 'name' => $reviewAuthorName];

        // Flat keys for backward compat with admin ViewTicket
        $payload['review_id'] = $reviewId;
        $payload['review_author_id'] = $reviewAuthorId;
        $payload['reporter_id'] = $reporter->id;
        $payload['report_reason'] = $reason;

        return $payload;
    }

    /**
     * Build enriched metadata for an account support ticket.
     *
     * @return array<string, mixed>
     */
    public static function accountSupportPayload(
        User $user,
        string $issueType,
        ?string $details = null,
    ): array {
        $payload = self::basePayload(
            schema: 'account_support/v1',
            user: $user,
            action: 'support',
            entities: [],
            reason: $issueType,
            details: $details,
        );

        // Flat keys for backward compat
        $payload['user_id'] = $user->id;
        $payload['issue_type'] = $issueType;

        return $payload;
    }

    /**
     * Build enriched metadata for a billing support ticket.
     *
     * @param  array<string, mixed>  $subscriptionContext
     * @return array<string, mixed>
     */
    public static function billingSupportPayload(
        User $user,
        string $issueType,
        ?string $details = null,
        ?array $subscriptionContext = null,
    ): array {
        $payload = self::basePayload(
            schema: 'billing_support/v1',
            user: $user,
            action: 'support',
            entities: [],
            reason: $issueType,
            details: $details,
        );

        // Flat keys for backward compat
        $payload['user_id'] = $user->id;
        $payload['issue_type'] = $issueType;

        if ($subscriptionContext) {
            $payload['context'] = $subscriptionContext;
            // Merge subscription flat keys
            $payload['has_subscription'] = $subscriptionContext['has_subscription'] ?? false;
            $payload['subscription_status'] = $subscriptionContext['subscription_status'] ?? null;
            $payload['paddle_subscription_id'] = $subscriptionContext['paddle_subscription_id'] ?? null;
        }

        if ($user->paddle_id) {
            $payload['paddle_customer_id'] = $user->paddle_id;
        }

        return $payload;
    }

    /**
     * Build enriched metadata for a data export request ticket.
     *
     * @return array<string, mixed>
     */
    public static function dataExportPayload(User $user): array
    {
        return self::basePayload(
            schema: 'data_export/v1',
            user: $user,
            action: 'export',
            entities: [],
            reason: 'data_request',
            details: __('User requested a full data export via settings.'),
        );
    }

    /**
     * Build enriched metadata for a payment failure auto-ticket.
     *
     * @param  array<string, mixed>  $webhookMetadata
     * @return array<string, mixed>
     */
    public static function paymentFailurePayload(
        User $user,
        array $webhookMetadata,
    ): array {
        $payload = self::basePayload(
            schema: 'payment_failure/v1',
            user: $user,
            action: 'payment_failure',
            entities: [],
            reason: 'payment_issue',
            details: 'Auto-created from Paddle webhook payment failure event.',
        );
        $payload['context'] = $webhookMetadata;

        return $payload;
    }

    /**
     * Build enriched metadata for a game system request ticket.
     *
     * @return array<string, mixed>
     */
    public static function gameSystemRequestPayload(
        User $user,
        string $name,
        ?string $bggUrl = null,
        ?string $publisher = null,
        ?string $designer = null,
        ?string $type = null,
        ?string $notes = null,
    ): array {
        $payload = self::basePayload(
            schema: 'game_system_request/v1',
            user: $user,
            action: 'request',
            entities: [],
            reason: 'game_system_request',
            details: $notes,
        );

        // Flat keys for backward compat
        $payload['game_system_request'] = true;
        $payload['game_system_name'] = $name;
        $payload['bgg_url'] = $bggUrl;
        $payload['publisher'] = $publisher;
        $payload['designer'] = $designer;
        $payload['game_system_type'] = $type;
        $payload['game_system_id'] = null;

        return $payload;
    }

    /**
     * Render a ticket's metadata as structured HTML with entity links.
     * Falls back to normalizing legacy flat-key metadata.
     *
     * Dispatches to ticket-type-specific renderers for high-quality output.
     */
    public function renderStructured(Ticket $ticket): ?string
    {
        $metadata = $ticket->metadata;

        if (! is_array($metadata) || empty($metadata)) {
            return null;
        }

        // Normalize legacy tickets that have flat keys but no schema
        if (! isset($metadata['schema'])) {
            $metadata = $this->normalizeLegacyMetadata($ticket);
        }

        if (! isset($metadata['schema'])) {
            return null;
        }

        // Delegate to the Blade component — HTML rendering belongs in templates,
        // not PHP string concatenation. Designers edit Blade, not PHP.
        return view('components.escalated.ticket-payload', [
            'ticket' => $ticket,
            'metadata' => $metadata,
        ])->render();
    }

    /**
     * Normalize legacy ticket metadata (flat keys) into the structured schema
     * on-the-fly for rendering. Does not persist changes.
     *
     * @return array<string, mixed>
     */
    private function normalizeLegacyMetadata(Ticket $ticket): array
    {
        /**
         * Narrow the metadata from array<string,mixed> to a shape with known optional keys
         * so PHPStan can properly reason about isset() and ?? on offsets.
         *
         * @var array{
         *   entity_type?: string,
         *   entity_id?: string|int,
         *   entity_name?: string,
         *   report_reason?: string,
         *   reporter_id?: int|string,
         *   description?: string,
         *   review_id?: int|string,
         *   review_author_id?: int|string,
         *   issue_type?: string,
         *   source?: string,
         *   user_id?: int|string,
         *   subscription_status?: string,
         *   paddle_subscription_id?: string,
         *   game_system_name?: string,
         *   bgg_url?: string,
         *   publisher?: string,
         *   designer?: string,
         *   game_system_type?: string,
         *   type?: string,
         *   game_system_id?: int|string|null,
         * } $metadata
         */
        $metadata = $ticket->metadata ?? [];
        $ticketType = $ticket->ticket_type;
        /** @var (User&Model)|null $requester */
        $requester = $ticket->requester;

        // Content reports: entity_type, entity_id, entity_name, report_reason
        if ($ticketType === 'content_report' && isset($metadata['entity_type'], $metadata['entity_id'])) {
            return [
                'schema' => 'content_report/v1',
                'actor' => [
                    'type' => 'user',
                    'id' => $metadata['reporter_id'] ?? $ticket->requester_id,
                    'name' => $requester->name ?? __('Unknown'),
                ],
                'action' => 'report',
                'entities' => [
                    [
                        'type' => $metadata['entity_type'],
                        'id' => $metadata['entity_id'],
                        'name' => $metadata['entity_name'] ?? $metadata['entity_id'],
                    ],
                ],
                'reason' => $metadata['report_reason'] ?? 'other',
                'details' => $metadata['description'] ?? null,
            ];
        }

        // Review reports: review_id, review_author_id
        if ($ticketType === 'review_report' && isset($metadata['review_id'])) {
            $reviewAuthor = User::find($metadata['review_author_id'] ?? null);

            if (! $reviewAuthor) {
                return [];
            }

            return [
                'schema' => 'review_report/v1',
                'actor' => [
                    'type' => 'user',
                    'id' => $metadata['reporter_id'] ?? $ticket->requester_id,
                    'name' => $requester->name ?? __('Unknown'),
                ],
                'action' => 'report',
                'entities' => [
                    [
                        'type' => 'review',
                        'id' => (string) $metadata['review_id'],
                        'name' => __('Review by :name', ['name' => $reviewAuthor->name ?? __('Unknown')]),
                    ],
                ],
                'reported_user' => [
                    'type' => 'user',
                    'id' => (string) ($metadata['review_author_id'] ?? ''),
                    'name' => $reviewAuthor->name ?? __('Unknown'),
                ],
                'reason' => $metadata['report_reason'] ?? 'other',
                'details' => $metadata['description'] ?? null,
            ];
        }

        // Account support: issue_type
        if (in_array($ticketType, ['account_recovery', 'data_export_request'])) {
            /** @var array{user_id?: int|string, issue_type?: string, source?: string} $accountMeta */
            $accountMeta = $ticket->metadata ?? [];
            if (isset($accountMeta['issue_type'])) {
                return [
                    'schema' => 'account_support/v1',
                    'actor' => [
                        'type' => 'user',
                        'id' => $accountMeta['user_id'] ?? $ticket->requester_id,
                        'name' => $requester->name ?? __('Unknown'),
                    ],
                    'action' => $ticketType === 'data_export_request' ? 'export' : 'support',
                    'entities' => [],
                    'reason' => $accountMeta['issue_type'],
                    'details' => null,
                ];
            }
        }

        // Billing support: has subscription context
        if ($ticketType === 'billing_support') {
            /** @var array{user_id?: int|string, issue_type?: string, subscription_status?: string, paddle_subscription_id?: string} $billingMeta */
            $billingMeta = $ticket->metadata ?? [];
            if (isset($billingMeta['issue_type'])) {
                $context = [];
                if (isset($billingMeta['subscription_status'])) {
                    $context['subscription_status'] = $billingMeta['subscription_status'];
                }
                if (isset($billingMeta['paddle_subscription_id'])) {
                    $context['paddle_subscription_id'] = $billingMeta['paddle_subscription_id'];
                }

                return [
                    'schema' => 'billing_support/v1',
                    'actor' => [
                        'type' => 'user',
                        'id' => $billingMeta['user_id'] ?? $ticket->requester_id,
                        'name' => $requester->name ?? __('Unknown'),
                    ],
                    'action' => 'support',
                    'entities' => [],
                    'reason' => $billingMeta['issue_type'],
                    'details' => null,
                    'context' => $context !== [] ? $context : null,
                ];
            }
        }

        // Game system request: bgg_url, publisher, designer, type
        if ($ticketType === 'game_system_request') {
            /** @var array{user_id?: int|string, game_system_name?: string, bgg_url?: string, publisher?: string, designer?: string, game_system_type?: string, type?: string, game_system_id?: int|string|null} $gsMeta */
            $gsMeta = $ticket->metadata ?? [];
            // Extract name from subject: "Game System Request: Name"
            $name = $gsMeta['game_system_name']
                ?? preg_replace('/^Game System Request:\s*/i', '', $ticket->subject ?? '')
                ?? $ticket->subject ?? '';

            return [
                'schema' => 'game_system_request/v1',
                'actor' => [
                    'type' => 'user',
                    'id' => $gsMeta['user_id'] ?? $ticket->requester_id,
                    'name' => $requester->name ?? __('Unknown'),
                ],
                'action' => 'request',
                'entities' => [],
                'reason' => 'game_system_request',
                'details' => $ticket->description ?: null,
                'game_system_name' => trim($name),
                'bgg_url' => $gsMeta['bgg_url'] ?? null,
                'publisher' => $gsMeta['publisher'] ?? null,
                'designer' => $gsMeta['designer'] ?? null,
                'game_system_type' => (static function () use ($gsMeta) {
                    if (isset($gsMeta['game_system_type'])) {
                        return $gsMeta['game_system_type'];
                    }

                    return $gsMeta['type'] ?? null;
                })(),
                'game_system_id' => $gsMeta['game_system_id'] ?? null,
            ];
        }

        return $metadata;
    }
}
