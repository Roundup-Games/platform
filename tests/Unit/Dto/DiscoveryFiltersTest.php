<?php

use App\Dto\DiscoveryFilters;

describe('DiscoveryFilters DTO', function () {
    it('creates with default values', function () {
        $dto = new DiscoveryFilters();

        expect($dto->search)->toBe('');
        expect($dto->game_system_id)->toBeNull();
        expect($dto->experience_level)->toBe('');
        expect($dto->vibe_flags)->toBe([]);
        expect($dto->safety_tools)->toBe([]);
        expect($dto->language)->toBe('');
        expect($dto->complexity_min)->toBeNull();
        expect($dto->complexity_max)->toBeNull();
        expect($dto->price)->toBe('');
        expect($dto->category_ids)->toBe([]);
        expect($dto->mechanic_ids)->toBe([]);
    });

    it('creates with custom values', function () {
        $dto = new DiscoveryFilters(
            search: 'D&D',
            game_system_id: '42',
            experience_level: 'beginner',
            vibe_flags: ['creative'],
            safety_tools: ['x-card'],
            language: 'en',
            complexity_min: '1.5',
            complexity_max: '3.0',
            price: 'free',
            category_ids: [1, 2],
            mechanic_ids: [5],
        );

        expect($dto->search)->toBe('D&D');
        expect($dto->game_system_id)->toBe('42');
        expect($dto->experience_level)->toBe('beginner');
        expect($dto->vibe_flags)->toBe(['creative']);
        expect($dto->safety_tools)->toBe(['x-card']);
        expect($dto->language)->toBe('en');
        expect($dto->complexity_min)->toBe('1.5');
        expect($dto->complexity_max)->toBe('3.0');
        expect($dto->price)->toBe('free');
        expect($dto->category_ids)->toBe([1, 2]);
        expect($dto->mechanic_ids)->toBe([5]);
    });

    it('converts to array', function () {
        $dto = new DiscoveryFilters(
            search: 'test',
            game_system_id: '7',
        );

        $array = $dto->toArray();

        expect($array)->toBe([
            'search' => 'test',
            'game_system_id' => '7',
            'experience_level' => '',
            'vibe_flags' => [],
            'safety_tools' => [],
            'language' => '',
            'complexity_min' => null,
            'complexity_max' => null,
            'price' => '',
            'category_ids' => [],
            'mechanic_ids' => [],
        ]);
    });

    it('creates from Livewire component', function () {
        $component = new class {
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
        expect($dto->game_system_id)->toBe('99');
        expect($dto->experience_level)->toBe('advanced');
        expect($dto->vibe_flags)->toBe(['strategic', 'roleplay']);
        expect($dto->safety_tools)->toBe(['lines-veils']);
        expect($dto->language)->toBe('de');
        expect($dto->complexity_min)->toBe('2');
        expect($dto->complexity_max)->toBe('4');
        expect($dto->price)->toBe('paid');
        expect($dto->category_ids)->toBe([10, 20]);
        expect($dto->mechanic_ids)->toBe([3]);
    });

    it('handles missing component properties gracefully', function () {
        $component = new class {
            // Intentionally no properties
        };

        $dto = DiscoveryFilters::fromLivewire($component);

        expect($dto->search)->toBe('');
        expect($dto->game_system_id)->toBeNull();
        expect($dto->vibe_flags)->toBe([]);
    });
});
