<?php

namespace App\Livewire\Reports;

use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Services\TicketPayloadRenderer;
use Escalated\Laravel\Enums\TicketChannel;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Enums\TicketStatus;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Tag;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Reusable Livewire component for reporting user profiles, games, and campaigns.
 *
 * Creates an Escalated ticket in the Safety department with entity context metadata.
 * Rate limited to 5 reports per user per hour.
 */
class ReportContent extends Component
{
    #[Locked]
    public string $entityType;

    #[Locked]
    public string $entityId;

    public bool $showModal = false;

    public ?string $reason = null;

    public ?string $description = null;

    public ?string $successMessage = null;

    /** Supported entity types and their model classes. */
    private const ENTITY_MODELS = [
        'user' => User::class,
        'game' => Game::class,
        'campaign' => Campaign::class,
    ];

    /** Report reason options. */
    private const REASONS = [
        'inappropriate-content',
        'harassment',
        'spam',
        'misleading',
        'other',
    ];

    public function mount(string $entityType, string $entityId): void
    {
        if (! isset(self::ENTITY_MODELS[$entityType])) {
            throw new \InvalidArgumentException("Unsupported entity type: {$entityType}");
        }

        $this->entityType = $entityType;
        $this->entityId = $entityId;
    }

    public function openModal(): void
    {
        $this->reset('reason', 'description', 'successMessage');
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->reset('reason', 'description', 'successMessage');
    }

    public function submitReport(): void
    {
        $this->validate();

        $reporter = authenticatedUser();

        // Rate limit: 5 reports per user per hour
        $rateLimitKey = "content-reports:{$reporter->id}";

        if (! RateLimiter::attempt($rateLimitKey, 5, fn () => true, decaySeconds: 3600)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            $minutes = ceil($seconds / 60);
            $this->addError('reason', __('reports.error_rate_limit', ['minutes' => $minutes]));

            return;
        }

        $modelClass = self::ENTITY_MODELS[$this->entityType];
        $entity = $modelClass::find($this->entityId);

        if (! $entity) {
            $this->addError('reason', __('reports.error_entity_not_found'));

            return;
        }

        // Prevent self-reporting: users should not report their own content
        if ($this->isSelfReport($entity, $reporter)) {
            $this->addError('reason', __('reports.error_self_report'));

            return;
        }

        // Atomically check for existing report and create ticket to prevent race conditions
        $duplicateDetected = false;

        DB::transaction(function () use ($entity, $reporter, &$duplicateDetected) {
            // Lock existing open tickets for this reporter+entity to prevent concurrent inserts
            $existing = Ticket::where('ticket_type', 'content_report')
                ->where('status', '!=', TicketStatus::Closed->value)
                ->where('requester_id', $reporter->id)
                ->whereJsonContains('metadata->entity_type', $this->entityType)
                ->whereJsonContains('metadata->entity_id', $this->entityId)
                ->lockForUpdate()
                ->exists();

            if ($existing) {
                $duplicateDetected = true;

                return;
            }

            $this->createSafetyTicket($entity, $reporter);
        });

        if ($duplicateDetected) {
            $this->addError('reason', __('reports.error_already_reported'));

            return;
        }

        Log::info('content.reported', [
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'reporter_id' => $reporter->id,
            'reason' => $this->reason,
        ]);

        $this->successMessage = __('reports.flash_report_submitted');
        $this->showModal = false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'in:'.implode(',', self::REASONS)],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function messages(): array
    {
        return [
            'reason.required' => __('reports.validation_reason_required'),
            'reason.in' => __('reports.validation_reason_invalid'),
            'description.max' => __('reports.validation_description_max'),
        ];
    }

    public function render(): View
    {
        return view('livewire.reports.report-content');
    }

    /**
     * Get the human-readable label for an entity type.
     */
    public function getEntityTypeLabel(): string
    {
        return __('reports.entity_'.$this->entityType);
    }

    /**
     * Get the reasons list for the view.
     *
     * @return array<string, mixed>
     */
    public function getReasons(): array
    {
        return [
            'inappropriate-content' => __('reports.field_reason_inappropriate_content'),
            'harassment' => __('reports.field_reason_harassment'),
            'spam' => __('reports.field_reason_spam'),
            'misleading' => __('reports.field_reason_misleading'),
            'other' => __('reports.field_reason_other'),
        ];
    }

    /**
     * Check if the reporter is the owner of the entity being reported.
     */
    private function isSelfReport(Model $entity, User $reporter): bool
    {
        // User profiles: check if reporter is the profile owner
        if ($entity instanceof User) {
            return $entity->id === $reporter->id;
        }

        // Games and campaigns: check ownership via owner_id
        if (isset($entity->owner_id) && (string) $entity->owner_id === (string) $reporter->id) {
            return true;
        }

        // Defensive: check gm_id / user_id if present (for future entity types)
        if (isset($entity->gm_id) && (string) $entity->gm_id === (string) $reporter->id) {
            return true;
        }

        if (isset($entity->user_id) && (string) $entity->user_id === (string) $reporter->id) {
            return true;
        }

        return false;
    }

    private function createSafetyTicket(Model $entity, User $reporter): void
    {
        $department = Department::where('name', 'Safety')->first();
        $entityName = $this->resolveEntityName($entity);
        $entityOwner = $this->resolveEntityOwner($entity);

        $metadata = TicketPayloadRenderer::contentReportPayload(
            reporter: $reporter,
            entityType: $this->entityType,
            entityId: $this->entityId,
            entityName: $entityName,
            reason: $this->reason ?? '',
            details: $this->description,
            entityOwnerName: $entityOwner,
        );

        $ticket = Ticket::create([
            'requester_type' => User::class,
            'requester_id' => $reporter->id,
            'subject' => ucfirst($this->entityType).' Report: '.$this->formatReason($this->reason ?? ''),
            'description' => $this->buildTicketDescription($entity, $reporter, $entityName),
            'status' => TicketStatus::Open->value,
            'priority' => TicketPriority::High->value,
            'department_id' => $department?->id,
            'ticket_type' => 'content_report',
            'channel' => TicketChannel::Web->value,
            'metadata' => $metadata,
        ]);

        // Apply entity-type tag
        $tagName = $this->entityType.'-report';
        $tag = Tag::where('name', $tagName)->first();
        if ($tag) {
            $ticket->tags()->syncWithoutDetaching([$tag->id]);
        }

        // Also apply reason tag if it exists
        $reasonTag = Tag::where('name', $this->reason)->first();
        if ($reasonTag) {
            $ticket->tags()->syncWithoutDetaching([$reasonTag->id]);
        }

        Log::info('content.report.ticket_created', [
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'ticket_id' => $ticket->id,
            'ticket_reference' => $ticket->reference,
            'department' => $department?->name,
        ]);
    }

    /**
     * Resolve the display name for the reported entity.
     */
    private function resolveEntityName(Model $entity): string
    {
        return $entity->name ?? ($entity->title ?? ($entity->username ?? 'Unknown'));
    }

    /**
     * Format a reason slug into a human-readable label.
     */
    private function formatReason(string $reason): string
    {
        return ucfirst(str_replace('-', ' ', $reason));
    }

    /**
     * Build a human-readable description for the safety ticket.
     */
    private function buildTicketDescription(Model $entity, User $reporter, string $entityName): string
    {
        $entityOwner = $this->resolveEntityOwner($entity);

        $lines = [
            "**Reported by:** {$reporter->name}",
            '**Reason:** '.$this->formatReason($this->reason ?? ''),
            '',
            '**Reported entity:**',
            '- Type: '.ucfirst($this->entityType),
            "- Name: {$entityName}",
            "- ID: {$this->entityId}",
        ];

        if ($entityOwner) {
            $lines[] = "- Owner: {$entityOwner}";
        }

        if ($this->description) {
            $lines[] = '';
            $lines[] = '**Additional details:**';
            $lines[] = $this->description;
        }

        return implode("\n", $lines);
    }

    /**
     * Resolve the owner/creator name for the reported entity.
     */
    private function resolveEntityOwner(Model $entity): ?string
    {
        if ($this->entityType === 'user') {
            return null; // Users don't have an "owner"
        }

        // Games and campaigns have an owner relationship. Use the loaded relation
        // directly — name is an Eloquent DB column (in $attributes), NOT a real
        // property, so the prior property_exists($owner, 'name') guard was always
        // false and fell through to the owner_id fallback, costing a wasted query
        // (and a second User::find) per content report.
        if (method_exists($entity, 'owner')) {
            $owner = $entity->getAttribute('owner');
            if ($owner instanceof User) {
                return $owner->name;
            }
        }

        // Fallback: check for user_id / gm_id relationships
        if (isset($entity->gm_id)) {
            $gm = User::find($entity->gm_id);

            return $gm instanceof User ? $gm->name : null;
        }

        if (isset($entity->owner_id)) {
            $owner = User::find($entity->owner_id);

            return $owner instanceof User ? $owner->name : null;
        }

        return null;
    }
}
