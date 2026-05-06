<?php

namespace App\Dto;


/**
 * Encapsulates all shared filter state for discovery pages.
 *
 * Replaces the ad-hoc array of Livewire component properties that the
 * DiscoveryUtilities trait previously accessed via `$this->`.
 */
class DiscoveryFilters
{
    public function __construct(
        public string $search = '',
        public ?string $game_system_id = null,
        public string $experience_level = '',
        public array $vibe_flags = [],
        public array $safety_tools = [],
        public string $language = '',
        public ?string $complexity_min = null,
        public ?string $complexity_max = null,
        public string $price = '',
        public array $category_ids = [],
        public array $mechanic_ids = [],
    ) {}

    /**
     * Build a DiscoveryFilters instance from a Livewire component's public properties.
     *
     * @param  object  $component  A Livewire component instance (or any object with matching public properties)
     */
    public static function fromLivewire(object $component): self
    {
        return new self(
            search: $component->search ?? '',
            game_system_id: $component->game_system_id ?? null,
            experience_level: $component->experience_level ?? '',
            vibe_flags: $component->vibe_flags ?? [],
            safety_tools: $component->safety_tools ?? [],
            language: $component->language ?? '',
            complexity_min: $component->complexity_min ?? null,
            complexity_max: $component->complexity_max ?? null,
            price: $component->price ?? '',
            category_ids: $component->category_ids ?? [],
            mechanic_ids: $component->mechanic_ids ?? [],
        );
    }

    /**
     * Convert to array for logging/debugging.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'search' => $this->search,
            'game_system_id' => $this->game_system_id,
            'experience_level' => $this->experience_level,
            'vibe_flags' => $this->vibe_flags,
            'safety_tools' => $this->safety_tools,
            'language' => $this->language,
            'complexity_min' => $this->complexity_min,
            'complexity_max' => $this->complexity_max,
            'price' => $this->price,
            'category_ids' => $this->category_ids,
            'mechanic_ids' => $this->mechanic_ids,
        ];
    }
}
