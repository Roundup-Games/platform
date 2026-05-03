<?php

use App\Models\GameSystem;
use App\Models\GameSystemCategory;
use App\Models\GameSystemDesigner;
use App\Models\GameSystemFamily;
use App\Models\GameSystemMechanic;
use App\Models\GameSystemPublisher;



// ═══════════════════════════════════════════════════════════
// GAME SYSTEM RELATIONSHIPS (BGG TAXONOMY)
// ═══════════════════════════════════════════════════════════

describe('GameSystem BGG Relationships', function () {
    it('attaches and queries families', function () {
        $system = GameSystem::factory()->create();
        $family1 = GameSystemFamily::create(['name' => 'War Games']);
        $family2 = GameSystemFamily::create(['name' => 'World War II']);

        $system->families()->attach([$family1->id, $family2->id]);

        expect($system->families)->toHaveCount(2);
    });

    it('attaches and queries designers', function () {
        $system = GameSystem::factory()->create();
        $designer1 = GameSystemDesigner::create(['name' => 'Reiner Knizia']);
        $designer2 = GameSystemDesigner::create(['name' => 'Klaus Teuber']);

        $system->designers()->attach([$designer1->id, $designer2->id]);

        expect($system->designers)->toHaveCount(2);
    });

    it('attaches and queries publishers', function () {
        $system = GameSystem::factory()->create();
        $publisher1 = GameSystemPublisher::create(['name' => 'Fantasy Flight Games']);
        $publisher2 = GameSystemPublisher::create(['name' => 'Rio Grande Games']);

        $system->publishers()->attach([$publisher1->id, $publisher2->id]);

        expect($system->publishers)->toHaveCount(2);
    });

    it('can attach all taxonomy types to one game system', function () {
        $system = GameSystem::factory()->create();
        $family = GameSystemFamily::create(['name' => 'Abstract Strategy']);
        $designer = GameSystemDesigner::create(['name' => 'Wolfgang Kramer']);
        $publisher = GameSystemPublisher::create(['name' => 'Hans im Glück']);

        $system->families()->attach($family);
        $system->designers()->attach($designer);
        $system->publishers()->attach($publisher);

        $system->load('families', 'designers', 'publishers');

        expect($system->families)->toHaveCount(1)
            ->and($system->designers)->toHaveCount(1)
            ->and($system->publishers)->toHaveCount(1);
    });
});

// ═══════════════════════════════════════════════════════════
// EXPANSION HIERARCHY
// ═══════════════════════════════════════════════════════════

describe('Expansion Hierarchy', function () {
    it('creates base game with expansion and resolves relationships', function () {
        $baseGame = GameSystem::factory()->create([
            'name' => 'Catan',
            'bgg_type' => 'boardgame',
        ]);
        $expansion = GameSystem::factory()->create([
            'name' => 'Catan: Seafarers',
            'bgg_type' => 'boardgameexpansion',
            'base_game_id' => $baseGame->id,
        ]);

        // Expansion sees its base game
        expect($expansion->baseGame)->not->toBeNull()
            ->and($expansion->baseGame->id)->toBe($baseGame->id)
            ->and($expansion->baseGame->name)->toBe('Catan');

        // Base game sees its expansions
        expect($baseGame->expansions)->toHaveCount(1)
            ->and($baseGame->expansions->first()->id)->toBe($expansion->id)
            ->and($baseGame->expansions->first()->name)->toBe('Catan: Seafarers');
    });

    it('supports multiple expansions on a single base game', function () {
        $baseGame = GameSystem::factory()->create(['name' => 'Ticket to Ride']);
        $exp1 = GameSystem::factory()->create(['name' => 'Ticket to Ride: Europe', 'base_game_id' => $baseGame->id]);
        $exp2 = GameSystem::factory()->create(['name' => 'Ticket to Ride: USA 1910', 'base_game_id' => $baseGame->id]);

        expect($baseGame->expansions)->toHaveCount(2);
    });

    it('returns null baseGame when not an expansion', function () {
        $standalone = GameSystem::factory()->create(['name' => 'Pandemic']);

        expect($standalone->baseGame)->toBeNull()
            ->and($standalone->expansions)->toHaveCount(0);
    });
});



// ═══════════════════════════════════════════════════════════
// TTRPG SUPPORT FIELDS & SCOPES
// ═══════════════════════════════════════════════════════════

describe('GameSystem TTRPG Support', function () {
    it('defaults type to boardgame when not set', function () {
        $system = GameSystem::factory()->create(['name' => 'Test Default Type']);

        expect($system->type)->toBe('boardgame');
    });

    it('creates TTRPG system with all new fields', function () {
        $system = GameSystem::factory()->create([
            'name' => 'Dungeons & Dragons 5e',
            'type' => 'ttrpg',
            'source' => 'startplaying',
            'source_slug' => 'dnd-5e',
            'creator' => 'Gary Gygax',
            'player_range' => '3-7 players',
            'sp_rating' => 4.85,
            'sp_review_count' => 1200,
            'faq_content' => [['q' => 'What dice do I need?', 'a' => 'A standard polyhedral set.']],
            'external_links' => [['title' => 'SRD', 'url' => 'https://dnd.wizards.com/srd', 'type' => 'reference']],
            'showcases' => [['title' => 'Critical Role', 'description' => 'Popular actual play', 'image' => 'https://example.com/img.jpg']],
            'instructions' => ['title' => 'Getting Started', 'description' => 'Quickstart guide', 'video_url' => 'https://youtube.com/xyz'],
        ]);

        expect($system->type)->toBe('ttrpg')
            ->and($system->source)->toBe('startplaying')
            ->and($system->source_slug)->toBe('dnd-5e')
            ->and($system->creator)->toBe('Gary Gygax')
            ->and($system->player_range)->toBe('3-7 players')
            ->and((float) $system->sp_rating)->toBe(4.85)
            ->and($system->sp_review_count)->toBe(1200);

        // JSON casts
        expect($system->faq_content)->toBe([['q' => 'What dice do I need?', 'a' => 'A standard polyhedral set.']])
            ->and($system->external_links)->toBe([['title' => 'SRD', 'url' => 'https://dnd.wizards.com/srd', 'type' => 'reference']])
            ->and($system->showcases)->toBe([['title' => 'Critical Role', 'description' => 'Popular actual play', 'image' => 'https://example.com/img.jpg']])
            ->and($system->instructions)->toBe(['title' => 'Getting Started', 'description' => 'Quickstart guide', 'video_url' => 'https://youtube.com/xyz']);
    });

    it('allows nullable TTRPG fields', function () {
        $system = GameSystem::factory()->create(['name' => 'Board Game Only']);

        expect($system->source)->toBeNull()
            ->and($system->source_slug)->toBeNull()
            ->and($system->creator)->toBeNull()
            ->and($system->player_range)->toBeNull()
            ->and($system->sp_rating)->toBeNull()
            ->and($system->sp_review_count)->toBeNull()
            ->and($system->faq_content)->toBeNull()
            ->and($system->external_links)->toBeNull()
            ->and($system->showcases)->toBeNull()
            ->and($system->instructions)->toBeNull();
    });

    it('filters with scopeTtrpg', function () {
        GameSystem::factory()->create(['name' => 'TTRPG 1', 'type' => 'ttrpg']);
        GameSystem::factory()->create(['name' => 'Board Game 1', 'type' => 'boardgame']);
        GameSystem::factory()->create(['name' => 'TTRPG 2', 'type' => 'ttrpg']);

        $ttrpgs = GameSystem::ttrpg()->get();

        expect($ttrpgs)->toHaveCount(2)
            ->and($ttrpgs->every(fn ($s) => $s->type === 'ttrpg'))->toBeTrue();
    });

    it('filters with scopeBoardgame', function () {
        GameSystem::factory()->create(['name' => 'BG Scope A', 'type' => 'boardgame']);
        GameSystem::factory()->create(['name' => 'BG Scope TTRPG', 'type' => 'ttrpg']);
        GameSystem::factory()->create(['name' => 'BG Scope B', 'type' => 'boardgame']);

        $boardgames = GameSystem::boardgame()->get();

        expect($boardgames)->toHaveCount(2)
            ->and($boardgames->every(fn ($s) => $s->type === 'boardgame'))->toBeTrue();
    });

    it('isTtrpg accessor returns correct boolean', function () {
        $ttrpg = GameSystem::factory()->create(['name' => 'TTRPG Check', 'type' => 'ttrpg']);
        $bg = GameSystem::factory()->create(['name' => 'BG Check', 'type' => 'boardgame']);

        expect($ttrpg->isTtrpg())->toBeTrue()
            ->and($bg->isTtrpg())->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════
// CATEGORY CROSS-LINKS
// ═══════════════════════════════════════════════════════════

describe('GameSystemCategory Cross-Links', function () {
    it('accepts description fillable', function () {
        $category = GameSystemCategory::create([
            'name' => 'Strategy',
            'description' => 'Games focused on strategic thinking and planning.',
        ]);

        expect($category->description)->toBe('Games focused on strategic thinking and planning.');
    });

    it('links similar categories via pivot', function () {
        $cat1 = GameSystemCategory::create(['name' => 'Deck Building']);
        $cat2 = GameSystemCategory::create(['name' => 'Card Games']);

        $cat1->similarCategories()->attach($cat2, ['type' => 'similar']);

        expect($cat1->similarCategories)->toHaveCount(1)
            ->and($cat1->similarCategories->first()->id)->toBe($cat2->id)
            ->and($cat1->similarCategories->first()->pivot->type)->toBe('similar');
    });

    it('resolves inverse similar categories', function () {
        $cat1 = GameSystemCategory::create(['name' => 'Worker Placement']);
        $cat2 = GameSystemCategory::create(['name' => 'Resource Management']);

        $cat1->similarCategories()->attach($cat2, ['type' => 'similar']);

        // $cat2 should see $cat1 in its inverse list
        expect($cat2->inverseSimilarCategories)->toHaveCount(1)
            ->and($cat2->inverseSimilarCategories->first()->id)->toBe($cat1->id);
    });
});

// ═══════════════════════════════════════════════════════════
// MECHANIC CROSS-LINKS
// ═══════════════════════════════════════════════════════════

describe('GameSystemMechanic Cross-Links', function () {
    it('accepts description fillable', function () {
        $mechanic = GameSystemMechanic::create([
            'name' => 'Hex-and-Counter',
            'description' => 'Classic wargaming mechanic using hex grids and counters.',
        ]);

        expect($mechanic->description)->toBe('Classic wargaming mechanic using hex grids and counters.');
    });

    it('links similar mechanics via pivot', function () {
        $mech1 = GameSystemMechanic::create(['name' => 'Action Points']);
        $mech2 = GameSystemMechanic::create(['name' => 'Worker Placement']);

        $mech1->similarMechanics()->attach($mech2, ['type' => 'similar']);

        expect($mech1->similarMechanics)->toHaveCount(1)
            ->and($mech1->similarMechanics->first()->id)->toBe($mech2->id)
            ->and($mech1->similarMechanics->first()->pivot->type)->toBe('similar');
    });

    it('resolves inverse similar mechanics', function () {
        $mech1 = GameSystemMechanic::create(['name' => 'Area Control']);
        $mech2 = GameSystemMechanic::create(['name' => 'Territory Building']);

        $mech1->similarMechanics()->attach($mech2, ['type' => 'similar']);

        expect($mech2->inverseSimilarMechanics)->toHaveCount(1)
            ->and($mech2->inverseSimilarMechanics->first()->id)->toBe($mech1->id);
    });
});
