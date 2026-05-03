<?php

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
