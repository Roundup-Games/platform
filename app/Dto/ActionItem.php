<?php

namespace App\Dto;

use Carbon\Carbon;

/**
 * Represents a single action item in the dashboard Action Center.
 *
 * Each item describes something the user should act on (e.g., confirm
 * waitlist, review an application, write a recap). Items are aggregated
 * by ActionCenterService and rendered in the action-center Blade partial.
 */
class ActionItem
{
    public function __construct(
        public readonly string $type,
        public readonly string $priority,
        public readonly string $title,
        public readonly string $description,
        public readonly string $actionUrl,
        public readonly string $actionLabel,
        public readonly string $icon,
        public readonly Carbon $createdAt,
        public readonly array $metadata = [],
    ) {}

    /**
     * Serialize to a cache-safe array (no Eloquent models, no Carbon objects).
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'priority' => $this->priority,
            'title' => $this->title,
            'description' => $this->description,
            'action_url' => $this->actionUrl,
            'action_label' => $this->actionLabel,
            'icon' => $this->icon,
            'created_at' => $this->createdAt->toIso8601String(),
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Reconstruct from a cached array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'] ?? 'unknown',
            priority: $data['priority'] ?? 'low',
            title: $data['title'] ?? '',
            description: $data['description'] ?? '',
            actionUrl: $data['action_url'] ?? '',
            actionLabel: $data['action_label'] ?? '',
            icon: $data['icon'] ?? 'info',
            createdAt: Carbon::parse($data['created_at'] ?? now()->toIso8601String()),
            metadata: $data['metadata'] ?? [],
        );
    }

    /**
     * Priority sort order for ranking items.
     */
    public static function priorityOrder(string $priority): int
    {
        return match ($priority) {
            'critical' => 0,
            'high' => 1,
            'medium' => 2,
            'low' => 3,
            default => 4,
        };
    }
}
