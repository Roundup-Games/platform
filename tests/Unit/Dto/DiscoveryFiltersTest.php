<?php

use App\Dto\DiscoveryFilters;

describe('DiscoveryFilters DTO', function () {
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
