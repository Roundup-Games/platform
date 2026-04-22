<?php

use App\Models\BggSyncLog;
use App\Models\GameSystem;
use App\Models\GameSystemCategory;
use App\Models\GameSystemDesigner;
use App\Models\GameSystemFamily;
use App\Models\GameSystemMechanic;
use App\Models\GameSystemPublisher;
use Spatie\MediaLibrary\InteractsWithMedia;

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

// ═══════════════════════════════════════════════════════════
// GAME SYSTEM FAMILY MODEL
// ═══════════════════════════════════════════════════════════

describe('GameSystemFamily Model', function () {
    it('creates with name and auto-generates slug', function () {
        $family = GameSystemFamily::create(['name' => 'War Games']);

        expect($family->slug)->toBe('war-games')
            ->and($family->name)->toBe('War Games');
    });

    it('preserves explicit slug when provided', function () {
        $family = GameSystemFamily::create(['name' => 'Party Games', 'slug' => 'party']);

        expect($family->slug)->toBe('party');
    });

    it('has gameSystems relationship', function () {
        $family = GameSystemFamily::create(['name' => 'Abstract Strategy']);
        $system = GameSystem::factory()->create();

        $family->gameSystems()->attach($system);

        expect($family->gameSystems)->toHaveCount(1)
            ->and($family->gameSystems->first()->id)->toBe($system->id);
    });

    it('generates slug from special characters', function () {
        $family = GameSystemFamily::create(['name' => 'Sci-Fi & Fantasy']);

        expect($family->slug)->toBe('sci-fi-fantasy');
    });
});

// ═══════════════════════════════════════════════════════════
// GAME SYSTEM DESIGNER MODEL
// ═══════════════════════════════════════════════════════════

describe('GameSystemDesigner Model', function () {
    it('creates with name and auto-generates slug', function () {
        $designer = GameSystemDesigner::create(['name' => 'Reiner Knizia']);

        expect($designer->slug)->toBe('reiner-knizia')
            ->and($designer->name)->toBe('Reiner Knizia');
    });

    it('preserves explicit slug when provided', function () {
        $designer = GameSystemDesigner::create(['name' => 'Klaus Teuber', 'slug' => 'klaus']);

        expect($designer->slug)->toBe('klaus');
    });

    it('has gameSystems relationship', function () {
        $designer = GameSystemDesigner::create(['name' => 'Uwe Rosenberg']);
        $system = GameSystem::factory()->create();

        $designer->gameSystems()->attach($system);

        expect($designer->gameSystems)->toHaveCount(1)
            ->and($designer->gameSystems->first()->id)->toBe($system->id);
    });
});

// ═══════════════════════════════════════════════════════════
// GAME SYSTEM PUBLISHER MODEL
// ═══════════════════════════════════════════════════════════

describe('GameSystemPublisher Model', function () {
    it('creates with name and auto-generates slug', function () {
        $publisher = GameSystemPublisher::create(['name' => 'Fantasy Flight Games']);

        expect($publisher->slug)->toBe('fantasy-flight-games')
            ->and($publisher->name)->toBe('Fantasy Flight Games');
    });

    it('preserves explicit slug when provided', function () {
        $publisher = GameSystemPublisher::create(['name' => 'Alderac Entertainment', 'slug' => 'aeg']);

        expect($publisher->slug)->toBe('aeg');
    });

    it('has gameSystems relationship', function () {
        $publisher = GameSystemPublisher::create(['name' => 'Rio Grande Games']);
        $system = GameSystem::factory()->create();

        $publisher->gameSystems()->attach($system);

        expect($publisher->gameSystems)->toHaveCount(1)
            ->and($publisher->gameSystems->first()->id)->toBe($system->id);
    });
});

// ═══════════════════════════════════════════════════════════
// GAME SYSTEM BGG FIELDS
// ═══════════════════════════════════════════════════════════

describe('GameSystem BGG Fields', function () {
    it('creates with BGG fields and verifies casts', function () {
        $system = GameSystem::factory()->create([
            'bgg_id' => 174430,
            'bgg_type' => 'boardgame',
            'thumbnail_url' => 'https://cf.geekdo-images.com/thumb/img.png',
            'bgg_average_rating' => 7.85,
            'bgg_bayes_average' => 7.1234,
            'bgg_rank' => 42,
            'bgg_users_rated' => 25000,
            'bgg_average_weight' => 3.67,
            'bgg_last_synced_at' => '2026-04-15 10:00:00',
        ]);

        expect($system->bgg_id)->toBe(174430)
            ->and($system->bgg_type)->toBe('boardgame')
            ->and($system->thumbnail_url)->toBe('https://cf.geekdo-images.com/thumb/img.png')
            ->and($system->bgg_rank)->toBe(42)
            ->and($system->bgg_users_rated)->toBe(25000);

        // Decimal casts
        expect((float) $system->bgg_average_rating)->toBe(7.85)
            ->and((float) $system->bgg_bayes_average)->toBe(7.12)
            ->and((float) $system->bgg_average_weight)->toBe(3.67);

        // Datetime cast
        expect($system->bgg_last_synced_at)->toBeInstanceOf(\Carbon\Carbon::class);
    });

    it('creates with nullable BGG fields by default', function () {
        $system = GameSystem::factory()->create();

        expect($system->bgg_type)->toBeNull()
            ->and($system->thumbnail_url)->toBeNull()
            ->and($system->base_game_id)->toBeNull()
            ->and($system->bgg_last_synced_at)->toBeNull();
    });

    it('auto-generates slug from name', function () {
        $system = GameSystem::factory()->create(['name' => 'Gloomhaven']);

        expect($system->slug)->toBe('gloomhaven');
    });
});

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
// BGG SYNC LOG
// ═══════════════════════════════════════════════════════════

describe('BggSyncLog', function () {
    it('creates with running status and updates to success', function () {
        $system = GameSystem::factory()->create();

        $log = BggSyncLog::create([
            'game_system_id' => $system->id,
            'status' => 'running',
            'bgg_ids' => [174430, 174431],
            'started_at' => now(),
        ]);

        expect($log->status)->toBe('running')
            ->and($log->bgg_ids)->toBe([174430, 174431])
            ->and($log->started_at)->toBeInstanceOf(\Carbon\Carbon::class);

        // Update to success
        $log->update([
            'status' => 'success',
            'items_synced' => 2,
            'items_failed' => 0,
            'completed_at' => now(),
        ]);

        expect($log->fresh()->status)->toBe('success')
            ->and($log->fresh()->items_synced)->toBe(2)
            ->and($log->fresh()->completed_at)->toBeInstanceOf(\Carbon\Carbon::class);
    });

    it('resolves gameSystem relationship', function () {
        $system = GameSystem::factory()->create();
        $log = BggSyncLog::create([
            'game_system_id' => $system->id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        expect($log->gameSystem)->not->toBeNull()
            ->and($log->gameSystem->id)->toBe($system->id);
    });

    it('gameSystem has bggSyncLogs relationship', function () {
        $system = GameSystem::factory()->create();
        BggSyncLog::create([
            'game_system_id' => $system->id,
            'status' => 'success',
            'items_synced' => 1,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        BggSyncLog::create([
            'game_system_id' => $system->id,
            'status' => 'failed',
            'items_synced' => 0,
            'items_failed' => 1,
            'error_message' => 'Connection timeout',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        expect($system->bggSyncLogs)->toHaveCount(2);
    });

    it('stores error message on failure', function () {
        $system = GameSystem::factory()->create();
        $log = BggSyncLog::create([
            'game_system_id' => $system->id,
            'status' => 'failed',
            'error_message' => 'HTTP 429: Rate limited',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        expect($log->fresh()->error_message)->toBe('HTTP 429: Rate limited');
    });
});

// ═══════════════════════════════════════════════════════════
// INTERACTS WITH MEDIA
// ═══════════════════════════════════════════════════════════

describe('GameSystem Media', function () {
    it('uses the InteractsWithMedia trait', function () {
        expect(in_array(InteractsWithMedia::class, class_uses(GameSystem::class)))->toBeTrue();
    });

    it('implements HasMedia interface', function () {
        expect(GameSystem::class)->toImplement(\Spatie\MediaLibrary\HasMedia::class);
    });

    it('has registerMediaConversions method', function () {
        expect(method_exists(GameSystem::class, 'registerMediaConversions'))->toBeTrue();
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
