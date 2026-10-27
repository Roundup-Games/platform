<?php

namespace App\Dto;

/**
 * Encapsulates all shared filter state for discovery pages.
 *
 * Built from a Livewire component's snake_case public properties (URL-bound)
 * via fromLivewire(); consumed by DiscoveryQueryService. All entity IDs are
 * UUID strings — never integers.
 *
 * Besides carrying values, the DTO is the single authority for filter-surface
 * semantics: which component properties belong to a page's filter surface
 * (reset list) and which of them are currently active. Pages declare their
 * surface extras once; clearFilters()/hasActiveFilters() derive from the same
 * declaration, so the two can no longer drift apart (M072 shipped-drift fix:
 * DiscoveryPage missed `city` in both). It also carries the mode-scoped
 * filter state (date, recurrence, the Adventures session constraints) the
 * pages used to pass as loose positional builder arguments — components
 * without a given property contribute its neutral default, so one
 * fromLivewire() shape feeds every DiscoveryQueryService::results() mode.
 */
class DiscoveryFilters
{
    /**
     * Filter properties every discovery page shares (the snake_case public
     * properties declared by ManagesDiscoveryFilters). `city` is NOT in the
     * base — pages opt in via their surface extras so a page without the
     * ?city= filter is not forced to reset a property it does not expose.
     */
    public const BASE_FILTER_PROPERTIES = [
        'search', 'game_system_id', 'experience_level', 'vibe_flags',
        'language', 'complexity_min', 'complexity_max', 'price', 'radius',
    ];

    /** DiscoveryPage surface: shared base + all-mode extras. Includes `city` (M072 drift fix). */
    public const SURFACE_DISCOVERY_PAGE = [
        'safety_tools', 'date', 'recurrence', 'category_ids', 'mechanic_ids', 'city',
    ];

    /** AdventuresDiscovery surface: TTRPG-fork extras. */
    public const SURFACE_ADVENTURES = [
        'safety_tools', 'play_styles', 'session_type', 'session_zero', 'city',
    ];

    /** BoardGamesDiscovery surface: board-game-fork extras (no safety_tools — not exposed there). */
    public const SURFACE_BOARD_GAMES = [
        'date', 'category_ids', 'mechanic_ids', 'city',
    ];

    /**
     * @param  array<int, string>  $vibeFlags
     * @param  array<int, string>  $safetyTools
     * @param  array<int, string>  $categoryIds  UUIDs of GameSystemCategory records
     * @param  array<int, string>  $mechanicIds  UUIDs of GameSystemMechanic records
     * @param  string|null  $citySlug  City-hub filter (?city=) — applied only when CityDirectoryService resolves it to a qualifying cluster
     * @param  string|null  $date  Games-tab date window ('upcoming'|'this_week'|'this_month'|null)
     * @param  string|null  $recurrence  Campaigns-tab recurrence filter
     * @param  string  $sessionType  Adventures session-type tab ('' = all, 'campaign', 'oneshot')
     * @param  bool  $sessionZero  Adventures session-zero filter
     * @param  array<int, string>  $playStyles  Adventures play-style filters (PlayStyle values)
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
        public readonly ?string $citySlug = null,
        public readonly ?string $date = null,
        public readonly ?string $recurrence = null,
        public readonly string $sessionType = '',
        public readonly bool $sessionZero = false,
        public readonly array $playStyles = [],
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
            gameSystemId: uuid_or_null($component->game_system_id ?? null),
            experienceLevel: self::stringOr($component->experience_level ?? ''),
            vibeFlags: self::stringArray($component->vibe_flags ?? []),
            safetyTools: self::stringArray($component->safety_tools ?? []),
            language: self::stringOr($component->language ?? ''),
            complexityMin: self::stringOrNull($component->complexity_min ?? null),
            complexityMax: self::stringOrNull($component->complexity_max ?? null),
            price: self::stringOr($component->price ?? ''),
            categoryIds: uuid_array($component->category_ids ?? []),
            mechanicIds: uuid_array($component->mechanic_ids ?? []),
            citySlug: self::stringOrNull($component->city ?? null),
            date: self::stringOrNull($component->date ?? null),
            recurrence: self::stringOrNull($component->recurrence ?? null),
            sessionType: self::stringOr($component->session_type ?? ''),
            sessionZero: (bool) ($component->session_zero ?? false),
            playStyles: self::stringArray($component->play_styles ?? []),
        );
    }

    /**
     * The component properties a page's clearFilters() must reset: the shared
     * base plus the page's declared surface extras.
     *
     * @param  array<int, string>  $surfaceExtras  Page-declared extras (one of the SURFACE_* sets)
     * @return array<int, string>
     */
    public static function resettableProperties(array $surfaceExtras = []): array
    {
        return array_values(array_unique(array_merge(
            self::BASE_FILTER_PROPERTIES,
            $surfaceExtras,
        )));
    }

    /**
     * Names of the surface properties currently holding an active value.
     *
     * A property is active when it deviates from its neutral default: filled
     * strings/nullable strings, non-empty arrays, true bools, or — for the two
     * properties with non-trivial neutrality — `language` differing from the
     * app locale and `radius` above zero. The locale is passed in (not read
     * from the container) so the DTO stays usable from pure unit tests.
     *
     * @param  object  $component  Livewire component holding the snake_case filter properties
     * @param  array<int, string>  $surfaceExtras  Page-declared extras (one of the SURFACE_* sets)
     * @return array<int, string> Active property names, surface order
     */
    public static function activeFilters(object $component, array $surfaceExtras = [], ?string $locale = null): array
    {
        $active = [];

        foreach (self::resettableProperties($surfaceExtras) as $property) {
            if (self::propertyIsActive($component, $property, $locale)) {
                $active[] = $property;
            }
        }

        return $active;
    }

    /**
     * Whether any property of the page's filter surface is active.
     *
     * @param  array<int, string>  $surfaceExtras  Page-declared extras (one of the SURFACE_* sets)
     */
    public static function hasActiveFilters(object $component, array $surfaceExtras = [], ?string $locale = null): bool
    {
        return self::activeFilters($component, $surfaceExtras, $locale) !== [];
    }

    /**
     * Toggle membership of a value in an array-backed multi-select filter
     * (safety tools, categories, mechanics, play styles).
     *
     * @template TValue of int|string
     *
     * @param  array<int, TValue>  $values
     * @param  TValue  $value
     * @return array<int, TValue>
     */
    public static function toggleArrayValue(array $values, int|string $value): array
    {
        $index = array_search($value, $values, true);

        if ($index !== false) {
            unset($values[$index]);

            return array_values($values);
        }

        $values[] = $value;

        return $values;
    }

    /**
     * Per-property active check. `language` counts as active only when it
     * differs from the active locale (mount defaults it to the locale, so an
     * untouched component must read as unfiltered); `radius` uses its 0 =
     * no-proximity-filter sentinel. Everything else is neutral-value based.
     */
    private static function propertyIsActive(object $component, string $property, ?string $locale): bool
    {
        $value = $component->{$property} ?? null;

        return match ($property) {
            'language' => is_string($value) && $value !== '' && $value !== $locale,
            'radius' => is_numeric($value) && (float) $value > 0.0,
            default => self::valueIsActive($value),
        };
    }

    /**
     * Neutral-value check for the generic surface properties: arrays are
     * active when non-empty, bools when true, scalars/nullables when filled.
     */
    private static function valueIsActive(mixed $value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }

        if (is_bool($value)) {
            return $value;
        }

        return filled($value);
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
            'citySlug' => $this->citySlug,
            'date' => $this->date,
            'recurrence' => $this->recurrence,
            'sessionType' => $this->sessionType,
            'sessionZero' => $this->sessionZero,
            'playStyles' => $this->playStyles,
        ];
    }
}
