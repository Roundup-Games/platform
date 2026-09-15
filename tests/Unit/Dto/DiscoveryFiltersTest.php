<?php

use App\Dto\DiscoveryFilters;

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
