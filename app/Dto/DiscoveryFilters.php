<?php

namespace App\Dto;

use Illuminate\Support\Str;

/**
 * Encapsulates all shared filter state for discovery pages.
 *
 * Built from a Livewire component's snake_case public properties (URL-bound)
 * via fromLivewire(); consumed by DiscoveryQueryService. All entity IDs are
 * UUID strings — never integers.
 */
class DiscoveryFilters
{
    /**
     * @param  array<int, string>  $vibeFlags
     * @param  array<int, string>  $safetyTools
     * @param  array<int, string>  $categoryIds  UUIDs of GameSystemCategory records
     * @param  array<int, string>  $mechanicIds  UUIDs of GameSystemMechanic records
     */
    public function __construct(
        public readonly string $search = '',
        public readonly ?string $gameSystemId = null,
        public readonly string $experienceLevel = '',
        public readonly array $vibeFlags = [],
        public readonly array $safetyTools = [],
        public readonly string $language = '',
        public readonly ?string $complexityMin = null,
        public readonly ?string $complexityMax = null,
        public readonly string $price = '',
        public readonly array $categoryIds = [],
        public readonly array $mechanicIds = [],
    ) {}

    /**
     * Build a DiscoveryFilters instance from a Livewire component's public properties.
     *
     * Livewire public properties use snake_case (URL/JS convention); this maps
     * them onto the DTO's camelCase properties and coerces untyped values.
     *
     * @param  object  $component  A Livewire component instance (or any object with matching public properties)
     */
    public static function fromLivewire(object $component): self
    {
        return new self(
            search: self::stringOr($component->search ?? ''),
            gameSystemId: self::uuidOrNull($component->game_system_id ?? null),
            experienceLevel: self::stringOr($component->experience_level ?? ''),
            vibeFlags: self::stringArray($component->vibe_flags ?? []),
            safetyTools: self::stringArray($component->safety_tools ?? []),
            language: self::stringOr($component->language ?? ''),
            complexityMin: self::stringOrNull($component->complexity_min ?? null),
            complexityMax: self::stringOrNull($component->complexity_max ?? null),
            price: self::stringOr($component->price ?? ''),
            categoryIds: self::uuidArray($component->category_ids ?? []),
            mechanicIds: self::uuidArray($component->mechanic_ids ?? []),
        );
    }

    /**
     * Coerce a mixed value to a non-empty string, defaulting to '' for
     * non-string scalars. Used for filter inputs that originate from
     * untyped Livewire public properties / URL query strings.
     */
    private static function stringOr(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    /**
     * Coerce a mixed value to a nullable string. Non-string scalars are
     * cast; everything else becomes null. Used for optional string filters
     * (e.g. complexityMin/Max) that must be string|null at the query layer.
     */
    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : null);
    }

    /**
     * Coerce a mixed array to a list of strings. Used for multi-value
     * filters (vibe flags, safety tools) where the source may contain mixed
     * scalar types from deserialised query strings.
     *
     * @return array<int, string>
     */
    private static function stringArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $v) => is_string($v) ? $v : (is_scalar($v) ? (string) $v : null), $value),
            fn (?string $v) => $v !== null && $v !== '',
        ));
    }

    /**
     * Coerce a mixed value to a valid UUID string, or null when it is not one.
     *
     * The id filters bind to uuid columns, so a malformed query-string value
     * (a truncated shared link, or an automated probe) must become "no filter"
     * instead of a query argument. A non-uuid bound to a uuid column raises
     * SQLSTATE 22P02 and returns a 500 rather than an empty result page.
     */
    private static function uuidOrNull(mixed $value): ?string
    {
        $string = self::stringOrNull($value);

        return $string !== null && Str::isUuid($string) ? $string : null;
    }

    /**
     * Coerce a mixed array to a list of valid UUID strings, dropping any value
     * that is not a uuid. Guards the category and mechanic id filters, which
     * bind to uuid columns via whereIn. See {@see uuidOrNull()}.
     *
     * @return array<int, string>
     */
    private static function uuidArray(mixed $value): array
    {
        return array_values(array_filter(
            self::stringArray($value),
            fn (string $v) => Str::isUuid($v),
        ));
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
            'gameSystemId' => $this->gameSystemId,
            'experienceLevel' => $this->experienceLevel,
            'vibeFlags' => $this->vibeFlags,
            'safetyTools' => $this->safetyTools,
            'language' => $this->language,
            'complexityMin' => $this->complexityMin,
            'complexityMax' => $this->complexityMax,
            'price' => $this->price,
            'categoryIds' => $this->categoryIds,
            'mechanicIds' => $this->mechanicIds,
        ];
    }
}
