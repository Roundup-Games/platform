<?php

use App\Models\GameSystem;
use App\Models\GameSystemCategory;
use App\Models\GameSystemMechanic;

// ═══════════════════════════════════════════════════════════
// GAME SYSTEM CATEGORY MODEL
// ═══════════════════════════════════════════════════════════

describe('GameSystemCategory Model', function () {
    it('creates with name and auto-generates slug', function () {
        $category = GameSystemCategory::create(['name' => 'Role-Playing Games']);

        expect($category->slug)->toBe('role-playing-games')
            ->and($category->name)->toBe('Role-Playing Games');
    });

    it('preserves explicit slug when provided', function () {
        $category = GameSystemCategory::create(['name' => 'Board Games', 'slug' => 'custom-slug']);

        expect($category->slug)->toBe('custom-slug');
    });

    it('does not overwrite existing slug on create', function () {
        $category = GameSystemCategory::create(['name' => 'Card Games', 'slug' => 'cards']);

        expect($category->slug)->toBe('cards');
    });

    it('has gameSystems relationship', function () {
        $category = GameSystemCategory::create(['name' => 'RPG']);
        $system = GameSystem::factory()->create();

        $category->gameSystems()->attach($system);

        expect($category->gameSystems)->toHaveCount(1)
            ->and($category->gameSystems->first()->id)->toBe($system->id);
    });

    it('generates slug from special characters', function () {
        $category = GameSystemCategory::create(['name' => 'Sci-Fi & Fantasy']);

        expect($category->slug)->toBe('sci-fi-fantasy');
    });
});

// ═══════════════════════════════════════════════════════════
// GAME SYSTEM MECHANIC MODEL
// ═══════════════════════════════════════════════════════════

describe('GameSystemMechanic Model', function () {
    it('creates with name and auto-generates slug', function () {
        $mechanic = GameSystemMechanic::create(['name' => 'Dice Pool']);

        expect($mechanic->slug)->toBe('dice-pool')
            ->and($mechanic->name)->toBe('Dice Pool');
    });

    it('preserves explicit slug when provided', function () {
        $mechanic = GameSystemMechanic::create(['name' => 'Card Drafting', 'slug' => 'draft']);

        expect($mechanic->slug)->toBe('draft');
    });

    it('has gameSystems relationship', function () {
        $mechanic = GameSystemMechanic::create(['name' => 'Tile Placement']);
        $system = GameSystem::factory()->create();

        $mechanic->gameSystems()->attach($system);

        expect($mechanic->gameSystems)->toHaveCount(1)
            ->and($mechanic->gameSystems->first()->id)->toBe($system->id);
    });
});
