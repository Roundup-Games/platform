<?php

use App\Dto\DiscoveryFilters;

/**
 * Fake Livewire-shaped component holding every surface property in neutral
 * state; tests mutate the properties they exercise.
 */
function discoverySurfaceComponent(): object
{
    return new class
    {
        public string $search = '';

        public ?string $game_system_id = null;

        public string $experience_level = '';

        public array $vibe_flags = [];

        public array $safety_tools = [];

        public string $language = '';

        public ?string $complexity_min = null;

        public ?string $complexity_max = null;

        public string $price = '';

        public float $radius = 0;

        public ?string $city = null;

        public string $date = '';

        public string $recurrence = '';

        public array $category_ids = [];

        public array $mechanic_ids = [];

        public array $play_styles = [];

        public string $session_type = '';

        public bool $session_zero = false;
    };
}

describe('DiscoveryFilters DTO', function () {
    it('creates from Livewire component', function () {
        $component = new class
        {
            public string $search = 'test search';

            public ?string $game_system_id = '11111111-1111-4111-8111-111111111111';

            public string $experience_level = 'advanced';

            public array $vibe_flags = ['strategic', 'roleplay'];

            public array $safety_tools = ['lines-veils'];

            public string $language = 'de';

            public ?string $complexity_min = '2';

            public ?string $complexity_max = '4';

            public string $price = 'paid';

            public array $category_ids = [
                '22222222-2222-4222-8222-222222222222',
                '33333333-3333-4333-8333-333333333333',
            ];

            public array $mechanic_ids = ['44444444-4444-4444-8444-444444444444'];

            public ?string $city = 'koeln';
        };

        $dto = DiscoveryFilters::fromLivewire($component);

        expect($dto->search)->toBe('test search');
        expect($dto->gameSystemId)->toBe('11111111-1111-4111-8111-111111111111');
        expect($dto->experienceLevel)->toBe('advanced');
        expect($dto->vibeFlags)->toBe(['strategic', 'roleplay']);
        expect($dto->safetyTools)->toBe(['lines-veils']);
        expect($dto->language)->toBe('de');
        expect($dto->complexityMin)->toBe('2');
        expect($dto->complexityMax)->toBe('4');
        expect($dto->price)->toBe('paid');
        expect($dto->categoryIds)->toBe([
            '22222222-2222-4222-8222-222222222222',
            '33333333-3333-4333-8333-333333333333',
        ]);
        expect($dto->mechanicIds)->toBe(['44444444-4444-4444-8444-444444444444']);
        expect($dto->citySlug)->toBe('koeln');
    });

    it('maps the component city to citySlug across present, absent, and null cases', function () {
        $present = new class
        {
            public ?string $city = 'koeln';
        };

        $null = new class
        {
            public ?string $city = null;
        };

        $absent = new class
        {
            // No city property at all (e.g. a component outside the trait)
        };

        expect(DiscoveryFilters::fromLivewire($present)->citySlug)->toBe('koeln')
            ->and(DiscoveryFilters::fromLivewire($null)->citySlug)->toBeNull()
            ->and(DiscoveryFilters::fromLivewire($absent)->citySlug)->toBeNull();
    });

    it('drops malformed uuid id filters', function () {
        $component = new class
        {
            public ?string $game_system_id = "1' OR '1'='1";

            public array $category_ids = ['also-bad', '55555555-5555-4555-8555-555555555555'];

            public array $mechanic_ids = ['not-a-uuid', '99'];
        };

        $dto = DiscoveryFilters::fromLivewire($component);

        expect($dto->gameSystemId)->toBeNull();
        expect($dto->categoryIds)->toBe(['55555555-5555-4555-8555-555555555555']);
        expect($dto->mechanicIds)->toBe([]);
    });

    it('handles missing component properties gracefully', function () {
        $component = new class
        {
            // Intentionally no properties
        };

        $dto = DiscoveryFilters::fromLivewire($component);

        expect($dto->search)->toBe('');
        expect($dto->gameSystemId)->toBeNull();
        expect($dto->vibeFlags)->toBe([]);
    });
});

describe('DiscoveryFilters surface semantics', function () {
    it('derives resettable properties from base + surface extras, deduplicated', function () {
        expect(DiscoveryFilters::resettableProperties())
            ->toBe(['search', 'game_system_id', 'experience_level', 'vibe_flags', 'language', 'complexity_min', 'complexity_max', 'price', 'radius'])
            ->and(DiscoveryFilters::resettableProperties(DiscoveryFilters::SURFACE_DISCOVERY_PAGE))
            ->toContain('city', 'date', 'recurrence', 'category_ids', 'mechanic_ids', 'safety_tools')
            ->and(DiscoveryFilters::resettableProperties(['radius', 'city']))
            ->toHaveLength(10); // duplicate radius collapsed
    });

    it('reports no active filters on a neutral component for every page surface', function () {
        foreach ([
            DiscoveryFilters::SURFACE_DISCOVERY_PAGE,
            DiscoveryFilters::SURFACE_ADVENTURES,
            DiscoveryFilters::SURFACE_BOARD_GAMES,
        ] as $surface) {
            expect(DiscoveryFilters::activeFilters(discoverySurfaceComponent(), $surface, 'de'))
                ->toBe([])
                ->and(DiscoveryFilters::hasActiveFilters(discoverySurfaceComponent(), $surface, 'de'))
                ->toBeFalse();
        }
    });

    it('marks shared and page-specific extras active, and lists them by name', function () {
        $component = discoverySurfaceComponent();
        $component->search = 'dungeon';
        $component->vibe_flags = ['strategic'];
        $component->city = 'koeln';

        expect(DiscoveryFilters::activeFilters($component, DiscoveryFilters::SURFACE_DISCOVERY_PAGE, 'de'))
            ->toBe(['search', 'vibe_flags', 'city'])
            ->and(DiscoveryFilters::hasActiveFilters($component, DiscoveryFilters::SURFACE_DISCOVERY_PAGE, 'de'))
            ->toBeTrue();
    });

    it('treats language as active only when it differs from the app locale', function () {
        $component = discoverySurfaceComponent();
        $component->language = 'de';

        expect(DiscoveryFilters::hasActiveFilters($component, DiscoveryFilters::SURFACE_DISCOVERY_PAGE, 'de'))
            ->toBeFalse()
            ->and(DiscoveryFilters::hasActiveFilters($component, DiscoveryFilters::SURFACE_DISCOVERY_PAGE, 'en'))
            ->toBeTrue();
    });

    it('treats radius 0 as neutral and any positive radius as active', function () {
        $component = discoverySurfaceComponent();
        expect(DiscoveryFilters::hasActiveFilters($component, DiscoveryFilters::SURFACE_DISCOVERY_PAGE, 'de'))
            ->toBeFalse();

        $component->radius = 25.0;
        expect(DiscoveryFilters::activeFilters($component, DiscoveryFilters::SURFACE_DISCOVERY_PAGE, 'de'))
            ->toBe(['radius']);
    });

    it('recognizes adventures-only extras (bool session_zero, arrays, strings)', function () {
        $component = discoverySurfaceComponent();
        expect(DiscoveryFilters::hasActiveFilters($component, DiscoveryFilters::SURFACE_ADVENTURES, 'de'))
            ->toBeFalse();

        $component->session_zero = true;
        expect(DiscoveryFilters::activeFilters($component, DiscoveryFilters::SURFACE_ADVENTURES, 'de'))
            ->toBe(['session_zero']);

        $component->play_styles = ['narrative'];
        $component->session_type = 'oneshot';
        expect(DiscoveryFilters::activeFilters($component, DiscoveryFilters::SURFACE_ADVENTURES, 'de'))
            ->toBe(['play_styles', 'session_type', 'session_zero']);
    });

    it('never counts properties outside the declared surface', function () {
        $component = discoverySurfaceComponent();
        $component->city = 'koeln';

        // city is a real property on the component but only the declared
        // surface decides whether it is a filter — a bare base surface ignores it.
        expect(DiscoveryFilters::hasActiveFilters($component, [], 'de'))->toBeFalse()
            ->and(DiscoveryFilters::hasActiveFilters($component, DiscoveryFilters::SURFACE_DISCOVERY_PAGE, 'de'))->toBeTrue();
    });
});

describe('DiscoveryFilters toggleArrayValue', function () {
    it('adds a missing value and re-indexes', function () {
        expect(DiscoveryFilters::toggleArrayValue(['a'], 'b'))->toBe(['a', 'b']);
    });

    it('removes an existing value and re-indexes', function () {
        expect(DiscoveryFilters::toggleArrayValue(['a', 'b', 'c'], 'b'))->toBe(['a', 'c']);
    });

    it('toggles on strict comparison so numeric strings stay distinct', function () {
        expect(DiscoveryFilters::toggleArrayValue(['1'], 1))->toBe(['1', 1]);
    });
});
