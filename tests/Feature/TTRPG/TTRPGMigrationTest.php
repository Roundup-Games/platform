<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ═══════════════════════════════════════════════════════════
// TTRPG MIGRATION: game_systems TTRPG SUPPORT COLUMNS
// ═══════════════════════════════════════════════════════════

describe('TTRPG Migration — game_systems columns', function () {
    it('has the type column with default boardgame', function () {
        expect(Schema::hasColumn('game_systems', 'type'))->toBeTrue();

        $default = DB::selectOne("
            SELECT column_default
            FROM information_schema.columns
            WHERE table_name = 'game_systems'
              AND column_name = 'type'
        ");

        expect(str_contains($default->column_default, 'boardgame'))->toBeTrue();
    });

    it('has a type index on game_systems', function () {
        $indexes = DB::selectOne("
            SELECT indexname
            FROM pg_indexes
            WHERE tablename = 'game_systems'
              AND indexname = 'game_systems_type_index'
        ");

        expect($indexes)->not->toBeNull();
    });

    it('has all new nullable TTRPG columns on game_systems', function () {
        $columns = ['source', 'source_slug', 'creator', 'player_range',
            'sp_rating', 'sp_review_count', 'faq_content', 'external_links',
            'showcases', 'instructions'];

        foreach ($columns as $col) {
            expect(Schema::hasColumn('game_systems', $col))->toBeTrue("Column {$col} should exist");
        }
    });

    it('has nullable new columns', function () {
        $nullableColumns = DB::select("
            SELECT column_name, is_nullable
            FROM information_schema.columns
            WHERE table_name = 'game_systems'
              AND column_name IN (
                'source', 'source_slug', 'creator', 'player_range',
                'sp_rating', 'sp_review_count', 'faq_content', 'external_links',
                'showcases', 'instructions'
              )
        ");

        foreach ($nullableColumns as $row) {
            expect($row->is_nullable)->toBe('YES', "Column {$row->column_name} should be nullable");
        }
    });
});

// ═══════════════════════════════════════════════════════════
// TTRPG MIGRATION: TAXONOMY DESCRIPTION COLUMNS
// ═══════════════════════════════════════════════════════════

describe('TTRPG Migration — taxonomy descriptions', function () {
    it('has description column on game_system_categories', function () {
        expect(Schema::hasColumn('game_system_categories', 'description'))->toBeTrue();
    });

    it('category description is nullable', function () {
        $col = DB::selectOne("
            SELECT is_nullable
            FROM information_schema.columns
            WHERE table_name = 'game_system_categories'
              AND column_name = 'description'
        ");

        expect($col->is_nullable)->toBe('YES');
    });

    it('has description column on game_system_mechanics', function () {
        expect(Schema::hasColumn('game_system_mechanics', 'description'))->toBeTrue();
    });

    it('mechanic description is nullable', function () {
        $col = DB::selectOne("
            SELECT is_nullable
            FROM information_schema.columns
            WHERE table_name = 'game_system_mechanics'
              AND column_name = 'description'
        ");

        expect($col->is_nullable)->toBe('YES');
    });
});

// ═══════════════════════════════════════════════════════════
// TTRPG MIGRATION: CROSS-LINK PIVOT TABLES
// ═══════════════════════════════════════════════════════════

describe('TTRPG Migration — category_relations pivot', function () {
    it('has game_system_category_relations table', function () {
        expect(Schema::hasTable('game_system_category_relations'))->toBeTrue();
    });

    it('has correct columns', function () {
        expect(Schema::hasColumn('game_system_category_relations', 'category_id'))->toBeTrue()
            ->and(Schema::hasColumn('game_system_category_relations', 'related_category_id'))->toBeTrue()
            ->and(Schema::hasColumn('game_system_category_relations', 'type'))->toBeTrue();
    });

    it('has composite primary key', function () {
        $pk = DB::selectOne("
            SELECT constraint_name
            FROM information_schema.table_constraints
            WHERE table_name = 'game_system_category_relations'
              AND constraint_type = 'PRIMARY KEY'
        ");

        expect($pk)->not->toBeNull();
    });

    it('has foreign keys with cascade delete', function () {
        $fks = DB::select("
            SELECT kcu.column_name, rc.delete_rule
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
                ON tc.constraint_name = kcu.constraint_name
                AND tc.table_schema = kcu.table_schema
            JOIN information_schema.referential_constraints rc
                ON tc.constraint_name = rc.constraint_name
                AND tc.constraint_schema = rc.constraint_schema
            WHERE tc.table_name = 'game_system_category_relations'
              AND tc.constraint_type = 'FOREIGN KEY'
        ");

        expect($fks)->toHaveCount(2);

        foreach ($fks as $fk) {
            expect($fk->delete_rule)->toBe('CASCADE', "FK on {$fk->column_name} should cascade on delete");
        }
    });

    it('type column defaults to similar', function () {
        $default = DB::selectOne("
            SELECT column_default
            FROM information_schema.columns
            WHERE table_name = 'game_system_category_relations'
              AND column_name = 'type'
        ");

        expect(str_contains($default->column_default, 'similar'))->toBeTrue();
    });
});

describe('TTRPG Migration — mechanic_relations pivot', function () {
    it('has game_system_mechanic_relations table', function () {
        expect(Schema::hasTable('game_system_mechanic_relations'))->toBeTrue();
    });

    it('has correct columns', function () {
        expect(Schema::hasColumn('game_system_mechanic_relations', 'mechanic_id'))->toBeTrue()
            ->and(Schema::hasColumn('game_system_mechanic_relations', 'related_mechanic_id'))->toBeTrue()
            ->and(Schema::hasColumn('game_system_mechanic_relations', 'type'))->toBeTrue();
    });

    it('has composite primary key', function () {
        $pk = DB::selectOne("
            SELECT constraint_name
            FROM information_schema.table_constraints
            WHERE table_name = 'game_system_mechanic_relations'
              AND constraint_type = 'PRIMARY KEY'
        ");

        expect($pk)->not->toBeNull();
    });

    it('type column defaults to similar', function () {
        $default = DB::selectOne("
            SELECT column_default
            FROM information_schema.columns
            WHERE table_name = 'game_system_mechanic_relations'
              AND column_name = 'type'
        ");

        expect(str_contains($default->column_default, 'similar'))->toBeTrue();
    });
});

// ═══════════════════════════════════════════════════════════
// TTRPG MODEL: FILLABLE & CASTS VERIFICATION
// ═══════════════════════════════════════════════════════════

describe('GameSystem model — TTRPG fillable & casts', function () {
    it('has all new TTRPG fields in fillable', function () {
        $system = new \App\Models\GameSystem();

        $expectedFillable = [
            'type', 'source', 'source_slug', 'creator', 'player_range',
            'sp_rating', 'sp_review_count', 'faq_content', 'external_links',
            'showcases', 'instructions',
        ];

        foreach ($expectedFillable as $field) {
            expect(in_array($field, $system->getFillable()))->toBeTrue("Field {$field} should be fillable");
        }
    });

    it('casts TTRPG fields correctly', function () {
        $system = new \App\Models\GameSystem();
        $casts = $system->getCasts();

        expect($casts)->toHaveKey('type')
            ->and($casts['type'])->toBe('string')
            ->and($casts)->toHaveKey('source')
            ->and($casts['source'])->toBe('string')
            ->and($casts)->toHaveKey('sp_rating')
            ->and($casts['sp_rating'])->toBe('decimal:2')
            ->and($casts)->toHaveKey('sp_review_count')
            ->and($casts['sp_review_count'])->toBe('integer')
            ->and($casts)->toHaveKey('faq_content')
            ->and($casts['faq_content'])->toBe('array')
            ->and($casts)->toHaveKey('external_links')
            ->and($casts['external_links'])->toBe('array')
            ->and($casts)->toHaveKey('showcases')
            ->and($casts['showcases'])->toBe('array')
            ->and($casts)->toHaveKey('instructions')
            ->and($casts['instructions'])->toBe('array');
    });
});

// ═══════════════════════════════════════════════════════════
// TAXONOMY MODEL: DESCRIPTION FILLABLE
// ═══════════════════════════════════════════════════════════

describe('Taxonomy model — description fillable', function () {
    it('GameSystemCategory has description in fillable', function () {
        $category = new \App\Models\GameSystemCategory();

        expect(in_array('description', $category->getFillable()))->toBeTrue();
    });

    it('GameSystemMechanic has description in fillable', function () {
        $mechanic = new \App\Models\GameSystemMechanic();

        expect(in_array('description', $mechanic->getFillable()))->toBeTrue();
    });
});

// ═══════════════════════════════════════════════════════════
// MODEL SCOPES & ACCESSOR
// ═══════════════════════════════════════════════════════════

describe('GameSystem scopes & accessor', function () {
    it('scopeTtrpg returns only ttrpg type', function () {
        \App\Models\GameSystem::factory()->create(['name' => 'Scope TTRPG A', 'type' => 'ttrpg']);
        \App\Models\GameSystem::factory()->create(['name' => 'Scope BG A', 'type' => 'boardgame']);
        \App\Models\GameSystem::factory()->create(['name' => 'Scope TTRPG B', 'type' => 'ttrpg']);

        $results = \App\Models\GameSystem::ttrpg()->get();

        expect($results)->toHaveCount(2)
            ->and($results->every(fn ($s) => $s->type === 'ttrpg'))->toBeTrue();
    });

    it('scopeBoardgame returns only boardgame type', function () {
        \App\Models\GameSystem::factory()->create(['name' => 'Scope BG B', 'type' => 'boardgame']);
        \App\Models\GameSystem::factory()->create(['name' => 'Scope TTRPG C', 'type' => 'ttrpg']);

        $results = \App\Models\GameSystem::boardgame()->get();

        expect($results->every(fn ($s) => $s->type === 'boardgame'))->toBeTrue();
    });

    it('isTtrpg returns true for ttrpg and false for boardgame', function () {
        $ttrpg = \App\Models\GameSystem::factory()->create(['name' => 'TTRPG Accessor', 'type' => 'ttrpg']);
        $bg = \App\Models\GameSystem::factory()->create(['name' => 'BG Accessor', 'type' => 'boardgame']);

        expect($ttrpg->isTtrpg())->toBeTrue()
            ->and($bg->isTtrpg())->toBeFalse();
    });
});

// ═══════════════════════════════════════════════════════════
// CROSS-LINK RELATIONSHIPS
// ═══════════════════════════════════════════════════════════

describe('Category cross-link relationships', function () {
    it('similarCategories uses correct pivot table and columns', function () {
        $cat1 = \App\Models\GameSystemCategory::create(['name' => 'Pivot Cat A']);
        $cat2 = \App\Models\GameSystemCategory::create(['name' => 'Pivot Cat B']);

        $cat1->similarCategories()->attach($cat2, ['type' => 'similar']);

        $related = $cat1->fresh()->similarCategories()->first();
        expect($related->id)->toBe($cat2->id)
            ->and($related->pivot->type)->toBe('similar');
    });

    it('inverseSimilarCategories resolves inverse direction', function () {
        $cat1 = \App\Models\GameSystemCategory::create(['name' => 'Inverse Cat A']);
        $cat2 = \App\Models\GameSystemCategory::create(['name' => 'Inverse Cat B']);

        $cat1->similarCategories()->attach($cat2, ['type' => 'similar']);

        $inverse = $cat2->fresh()->inverseSimilarCategories()->first();
        expect($inverse->id)->toBe($cat1->id)
            ->and($inverse->pivot->type)->toBe('similar');
    });
});

describe('Mechanic cross-link relationships', function () {
    it('similarMechanics uses correct pivot table and columns', function () {
        $mech1 = \App\Models\GameSystemMechanic::create(['name' => 'Pivot Mech A']);
        $mech2 = \App\Models\GameSystemMechanic::create(['name' => 'Pivot Mech B']);

        $mech1->similarMechanics()->attach($mech2, ['type' => 'similar']);

        $related = $mech1->fresh()->similarMechanics()->first();
        expect($related->id)->toBe($mech2->id)
            ->and($related->pivot->type)->toBe('similar');
    });

    it('inverseSimilarMechanics resolves inverse direction', function () {
        $mech1 = \App\Models\GameSystemMechanic::create(['name' => 'Inverse Mech A']);
        $mech2 = \App\Models\GameSystemMechanic::create(['name' => 'Inverse Mech B']);

        $mech1->similarMechanics()->attach($mech2, ['type' => 'similar']);

        $inverse = $mech2->fresh()->inverseSimilarMechanics()->first();
        expect($inverse->id)->toBe($mech1->id)
            ->and($inverse->pivot->type)->toBe('similar');
    });
});
