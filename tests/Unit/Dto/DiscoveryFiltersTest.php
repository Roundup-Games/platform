<?php

use App\Dto\DiscoveryFilters;

describe('DiscoveryFilters DTO', function () {
    it('creates with default values', function () {
        $dto = new DiscoveryFilters;

        expect($dto->search)->toBe('');
        expect($dto->gameSystemId)->toBeNull();
        expect($dto->experienceLevel)->toBe('');
        expect($dto->vibeFlags)->toBe([]);
        expect($dto->safetyTools)->toBe([]);
        expect($dto->language)->toBe('');
        expect($dto->complexityMin)->toBeNull();
        expect($dto->complexityMax)->toBeNull();
        expect($dto->price)->toBe('');
        expect($dto->categoryIds)->toBe([]);
        expect($dto->mechanicIds)->toBe([]);
    });

    it('creates with custom values', function () {
        $dto = new DiscoveryFilters(
            search: 'D&D',
            gameSystemId: '42',
            experienceLevel: 'beginner',
            vibeFlags: ['creative'],
            safetyTools: ['x-card'],
            language: 'en',
            complexityMin: '1.5',
            complexityMax: '3.0',
            price: 'free',
            categoryIds: ['cat-1', 'cat-2'],
            mechanicIds: ['mec-5'],
        );

        expect($dto->search)->toBe('D&D');
        expect($dto->gameSystemId)->toBe('42');
        expect($dto->experienceLevel)->toBe('beginner');
        expect($dto->vibeFlags)->toBe(['creative']);
        expect($dto->safetyTools)->toBe(['x-card']);
        expect($dto->language)->toBe('en');
        expect($dto->complexityMin)->toBe('1.5');
        expect($dto->complexityMax)->toBe('3.0');
        expect($dto->price)->toBe('free');
        expect($dto->categoryIds)->toBe(['cat-1', 'cat-2']);
        expect($dto->mechanicIds)->toBe(['mec-5']);
    });

    it('converts to array', function () {
        $dto = new DiscoveryFilters(
            search: 'test',
            gameSystemId: '7',
        );

        $array = $dto->toArray();

        expect($array)->toBe([
            'search' => 'test',
            'gameSystemId' => '7',
            'experienceLevel' => '',
            'vibeFlags' => [],
            'safetyTools' => [],
            'language' => '',
            'complexityMin' => null,
            'complexityMax' => null,
            'price' => '',
            'categoryIds' => [],
            'mechanicIds' => [],
        ]);
    });

    it('creates from Livewire component', function () {
        $component = new class
        {
            public string $search = 'test search';

            public ?string $game_system_id = '99';

            public string $experience_level = 'advanced';

            public array $vibe_flags = ['strategic', 'roleplay'];

            public array $safety_tools = ['lines-veils'];

            public string $language = 'de';

            public ?string $complexity_min = '2';

            public ?string $complexity_max = '4';

            public string $price = 'paid';

            public array $category_ids = [10, 20];

            public array $mechanic_ids = [3];
        };

        $dto = DiscoveryFilters::fromLivewire($component);

        expect($dto->search)->toBe('test search');
        expect($dto->gameSystemId)->toBe('99');
        expect($dto->experienceLevel)->toBe('advanced');
        expect($dto->vibeFlags)->toBe(['strategic', 'roleplay']);
        expect($dto->safetyTools)->toBe(['lines-veils']);
        expect($dto->language)->toBe('de');
        expect($dto->complexityMin)->toBe('2');
        expect($dto->complexityMax)->toBe('4');
        expect($dto->price)->toBe('paid');
        expect($dto->categoryIds)->toBe(['10', '20']);
        expect($dto->mechanicIds)->toBe(['3']);
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
