<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use Escalated\Laravel\Models\Ticket;

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
    /** Resolve an entity type + ID to a URL, or null if unknown. */
    public function resolveEntityUrl(string $type, string $id): ?string
    {
        return match ($type) {
            'user' => route('profile.public', $id, absolute: false),
            'game' => route('games.detail', $id, absolute: false),
            'campaign' => route('campaigns.detail', $id, absolute: false),
            'review' => null, // Reviews don't have standalone public pages
            default => null,
        };
    }

    /** Resolve an entity type to a human-readable label. */
    public function entityTypeLabel(string $type): string
    {
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
    public function reasonLabel(string $reason): string
    {
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
        $payload = [
            'schema' => 'content_report/v1',
            'actor' => ['type' => 'user', 'id' => $reporter->id, 'name' => $reporter->name],
            'action' => 'report',
            'entities' => [
                ['type' => $entityType, 'id' => $entityId, 'name' => $entityName],
            ],
            'reason' => $reason,
            'details' => $details,
            // Flat keys for backward compat with admin ViewTicket + duplicate detection
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_name' => $entityName,
            'report_reason' => $reason,
            'reporter_id' => $reporter->id,
        ];

        if ($entityOwnerName) {
            $payload['context'] = ['entity_owner' => $entityOwnerName];
        }

        return $payload;
    }

    /**
     * Build enriched metadata for a review report ticket.
     */
    public static function reviewReportPayload(
        User $reporter,
        string $reviewId,
        string $reviewAuthorId,
        string $reviewAuthorName,
        string $reason,
        ?string $details = null,
    ): array {
        return [
            'schema' => 'review_report/v1',
            'actor' => ['type' => 'user', 'id' => $reporter->id, 'name' => $reporter->name],
            'action' => 'report',
            'entities' => [
                ['type' => 'review', 'id' => $reviewId, 'name' => __('Review by :name', ['name' => $reviewAuthorName])],
            ],
            'reported_user' => ['type' => 'user', 'id' => $reviewAuthorId, 'name' => $reviewAuthorName],
            'reason' => $reason,
            'details' => $details,
            // Flat keys for backward compat with admin ViewTicket
            'review_id' => $reviewId,
            'review_author_id' => $reviewAuthorId,
            'reporter_id' => $reporter->id,
            'report_reason' => $reason,
        ];
    }

    /**
     * Build enriched metadata for an account support ticket.
     */
    public static function accountSupportPayload(
        User $user,
        string $issueType,
        ?string $details = null,
    ): array {
        return [
            'schema' => 'account_support/v1',
            'actor' => ['type' => 'user', 'id' => $user->id, 'name' => $user->name],
            'action' => 'support',
            'entities' => [],
            'reason' => $issueType,
            'details' => $details,
            // Flat keys for backward compat
            'user_id' => $user->id,
            'issue_type' => $issueType,
        ];
    }

    /**
     * Build enriched metadata for a billing support ticket.
     */
    public static function billingSupportPayload(
        User $user,
        string $issueType,
        ?string $details = null,
        ?array $subscriptionContext = null,
    ): array {
        $payload = [
            'schema' => 'billing_support/v1',
            'actor' => ['type' => 'user', 'id' => $user->id, 'name' => $user->name],
            'action' => 'support',
            'entities' => [],
            'reason' => $issueType,
            'details' => $details,
            // Flat keys for backward compat
            'user_id' => $user->id,
            'issue_type' => $issueType,
        ];

        if ($subscriptionContext) {
            $payload['context'] = $subscriptionContext;
            // Merge subscription flat keys
            $payload['has_subscription'] = $subscriptionContext['has_subscription'] ?? false;
            $payload['subscription_status'] = $subscriptionContext['subscription_status'] ?? null;
            $payload['paddle_subscription_id'] = $subscriptionContext['paddle_subscription_id'] ?? null;
            $payload['paddle_customer_id'] = $user->paddle_id;
        }

        return $payload;
    }

    /**
     * Build enriched metadata for a data export request ticket.
     */
    public static function dataExportPayload(User $user): array
    {
        return [
            'schema' => 'data_export/v1',
            'actor' => ['type' => 'user', 'id' => $user->id, 'name' => $user->name],
            'action' => 'export',
            'entities' => [],
            'reason' => 'data_request',
            'details' => __('User requested a full data export via settings.'),
        ];
    }

    /**
     * Build enriched metadata for a payment failure auto-ticket.
     */
    public static function paymentFailurePayload(
        User $user,
        array $webhookMetadata,
    ): array {
        return [
            'schema' => 'payment_failure/v1',
            'actor' => ['type' => 'user', 'id' => $user->id, 'name' => $user->name],
            'action' => 'payment_failure',
            'entities' => [],
            'reason' => 'payment_issue',
            'details' => 'Auto-created from Paddle webhook payment failure event.',
            'context' => $webhookMetadata,
        ];
    }

    /**
     * Render a ticket's metadata as structured HTML with entity links.
     * Falls back to normalizing legacy flat-key metadata.
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

        $parts = [];

        // Actor line
        if (isset($metadata['actor'])) {
            $parts[] = $this->renderActor($metadata['actor']);
        }

        // Reason line
        if (isset($metadata['reason'])) {
            $parts[] = '<div class="mb-2"><span class="font-medium text-on-surface-variant">' . __('Reason') . ':</span> '
                . '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300">'
                . e($this->reasonLabel($metadata['reason']))
                . '</span></div>';
        }

        // Entities
        if (! empty($metadata['entities'])) {
            $parts[] = $this->renderEntities($metadata['entities']);
        }

        // Reported user (review reports)
        if (isset($metadata['reported_user'])) {
            $parts[] = '<div class="mb-2"><span class="font-medium text-on-surface-variant">' . __('Reported user') . ':</span> '
                . $this->renderEntityLink($metadata['reported_user'])
                . '</div>';
        }

        // Context (subscription details, entity owner, etc.)
        if (isset($metadata['context']) && is_array($metadata['context']) && ! empty($metadata['context'])) {
            $parts[] = $this->renderContext($metadata['context']);
        }

        // Details (user's freeform text)
        if (! empty($metadata['details'])) {
            $parts[] = '<div class="mt-3 pt-3 border-t border-outline-variant">'
                . '<span class="font-medium text-on-surface-variant">' . __('Details') . ':</span>'
                . '<p class="mt-1 text-on-surface">' . e($metadata['details']) . '</p>'
                . '</div>';
        }

        return implode("\n", $parts);
    }

    /** Render the actor line with a profile link. */
    private function renderActor(array $actor): string
    {
        $name = e($actor['name'] ?? __('Unknown'));

        if (isset($actor['id']) && $actor['type'] === 'user') {
            $url = $this->resolveEntityUrl('user', $actor['id']);
            if ($url) {
                $name = '<a href="' . e($url) . '" class="text-primary hover:underline font-medium">' . $name . '</a>';
            }
        }

        return '<div class="mb-2"><span class="font-medium text-on-surface-variant">' . __('Reported by') . ':</span> ' . $name . '</div>';
    }

    /** Render the entities list with links. */
    private function renderEntities(array $entities): string
    {
        if (empty($entities)) {
            return '';
        }

        $lines = ['<div class="mb-2">', '<span class="font-medium text-on-surface-variant">' . __('Affected entities') . ':</span>', '<ul class="mt-1 space-y-1">'];

        foreach ($entities as $entity) {
            $type = $entity['type'] ?? 'unknown';
            $typeLabel = $this->entityTypeLabel($type);
            $link = $this->renderEntityLink($entity);

            $lines[] = '<li class="flex items-center gap-2 text-sm">'
                . '<span class="text-on-surface-variant">' . e($typeLabel) . ':</span> '
                . $link
                . '</li>';
        }

        $lines[] = '</ul></div>';

        return implode("\n", $lines);
    }

    /** Render a single entity as a link or plain text. */
    private function renderEntityLink(array $entity): string
    {
        $name = e($entity['name'] ?? $entity['id'] ?? __('Unknown'));
        $type = $entity['type'] ?? 'unknown';
        $id = $entity['id'] ?? null;

        if ($id && $url = $this->resolveEntityUrl($type, $id)) {
            return '<a href="' . e($url) . '" class="text-primary hover:underline font-medium">' . $name . '</a>'
                . ' <span class="text-on-surface-variant text-xs font-mono">' . e($id) . '</span>';
        }

        return $name;
    }

    /** Render context data as definition list. */
    private function renderContext(array $context): string
    {
        $lines = ['<div class="mb-2 text-sm">', '<span class="font-medium text-on-surface-variant">' . __('Context') . ':</span>', '<dl class="mt-1 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">'];

        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value);
            }

            $label = match ($key) {
                'entity_owner' => __('Entity owner'),
                'has_subscription' => __('Has subscription'),
                'subscription_status' => __('Subscription status'),
                'paddle_subscription_id' => __('Subscription ID'),
                'paddle_customer_id' => __('Customer ID'),
                'plan' => __('Plan'),
                default => ucfirst(str_replace('_', ' ', $key)),
            };

            $displayValue = is_bool($value) ? ($value ? __('Yes') : __('No')) : e((string) $value);

            $lines[] = '<dt class="text-on-surface-variant">' . e($label) . '</dt>';
            $lines[] = '<dd class="text-on-surface">' . $displayValue . '</dd>';
        }

        $lines[] = '</dl></div>';

        return implode("\n", $lines);
    }

    /**
     * Normalize legacy ticket metadata (flat keys) into the structured schema
     * on-the-fly for rendering. Does not persist changes.
     */
    private function normalizeLegacyMetadata(Ticket $ticket): array
    {
        $metadata = $ticket->metadata;
        $ticketType = $ticket->ticket_type;
        $requester = $ticket->requester;

        // Content reports: entity_type, entity_id, entity_name, report_reason
        if ($ticketType === 'content_report' && isset($metadata['entity_type'])) {
            return [
                'schema' => 'content_report/v1',
                'actor' => [
                    'type' => 'user',
                    'id' => $metadata['reporter_id'] ?? $ticket->requester_id,
                    'name' => $requester?->name ?? __('Unknown'),
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
            $reviewAuthor = User::find($metadata['review_author_id']);

            return [
                'schema' => 'review_report/v1',
                'actor' => [
                    'type' => 'user',
                    'id' => $metadata['reporter_id'] ?? $ticket->requester_id,
                    'name' => $requester?->name ?? __('Unknown'),
                ],
                'action' => 'report',
                'entities' => [
                    [
                        'type' => 'review',
                        'id' => (string) $metadata['review_id'],
                        'name' => __('Review by :name', ['name' => $reviewAuthor?->name ?? __('Unknown')]),
                    ],
                ],
                'reported_user' => [
                    'type' => 'user',
                    'id' => $metadata['review_author_id'],
                    'name' => $reviewAuthor?->name ?? __('Unknown'),
                ],
                'reason' => $metadata['report_reason'] ?? 'other',
                'details' => $metadata['description'] ?? null,
            ];
        }

        // Account support: issue_type
        if (in_array($ticketType, ['account_recovery', 'data_export_request']) && isset($metadata['issue_type'])) {
            return [
                'schema' => 'account_support/v1',
                'actor' => [
                    'type' => 'user',
                    'id' => $metadata['user_id'] ?? $ticket->requester_id,
                    'name' => $requester?->name ?? __('Unknown'),
                ],
                'action' => $ticketType === 'data_export_request' ? 'export' : 'support',
                'entities' => [],
                'reason' => $metadata['issue_type'] ?? $metadata['source'] ?? 'other',
                'details' => null,
            ];
        }

        // Billing support: has subscription context
        if ($ticketType === 'billing_support' && isset($metadata['issue_type'])) {
            $context = [];
            if (isset($metadata['subscription_status'])) {
                $context['subscription_status'] = $metadata['subscription_status'];
            }
            if (isset($metadata['paddle_subscription_id'])) {
                $context['paddle_subscription_id'] = $metadata['paddle_subscription_id'];
            }

            return [
                'schema' => 'billing_support/v1',
                'actor' => [
                    'type' => 'user',
                    'id' => $metadata['user_id'] ?? $ticket->requester_id,
                    'name' => $requester?->name ?? __('Unknown'),
                ],
                'action' => 'support',
                'entities' => [],
                'reason' => $metadata['issue_type'],
                'details' => null,
                'context' => ! empty($context) ? $context : null,
            ];
        }

        return $metadata;
    }
}
