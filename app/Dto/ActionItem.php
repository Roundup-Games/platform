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
    /**
     * @param  array<string, mixed>  $metadata
     */
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
     *
     * @return array<string, mixed>
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
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: is_string($data['type'] ?? null) ? $data['type'] : 'unknown',
            priority: is_string($data['priority'] ?? null) ? $data['priority'] : 'low',
            title: is_string($data['title'] ?? null) ? $data['title'] : '',
            description: is_string($data['description'] ?? null) ? $data['description'] : '',
            actionUrl: is_string($data['action_url'] ?? null) ? $data['action_url'] : '',
            actionLabel: is_string($data['action_label'] ?? null) ? $data['action_label'] : '',
            icon: is_string($data['icon'] ?? null) ? $data['icon'] : 'info',
            createdAt: Carbon::parse(is_string($data['created_at'] ?? null) ? $data['created_at'] : now()->toIso8601String()),
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
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
