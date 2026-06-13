<?php

use App\Models\GameSystemCategory;
use App\Models\GameSystemMechanic;
use App\Models\Team;
use App\Models\User;

// ── Team Translations (smoke test for JSON column configuration) ──────

describe('Team Translations', function () {
    it('works with description-only translatable field', function () {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'Test Team',
            'description' => ['en' => 'English description'],
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $team->setTranslation('description', 'de', 'Deutsche Beschreibung');
        $team->save();

        // Reload from DB
        $freshTeam = Team::find($team->id);
        expect($freshTeam->getTranslation('description', 'de'))->toBe('Deutsche Beschreibung');
    });
});

// ── Non-translatable translatedName() (lang-file based) ─────

describe('translatedName() via lang keys', function () {
    it('returns DB name when no lang key exists for GameSystemCategory', function () {
        $cat = new GameSystemCategory(['name' => 'Fantasy', 'slug' => 'fantasy']);
        // No lang key for discovery.cat_fantasy → falls back to $this->name
        expect($cat->translatedName())->toBe('Fantasy');
    });

    it('returns DB name when no lang key exists for GameSystemMechanic', function () {
        $mech = new GameSystemMechanic(['name' => 'Dice Rolling', 'slug' => 'dice-rolling']);
        expect($mech->translatedName())->toBe('Dice Rolling');
    });

    it('returns translated value when lang key exists for category', function () {
        $cat = new GameSystemCategory(['name' => 'Fantasy', 'slug' => 'fantasy']);
        // Temporarily add a lang key
        app('translator')->addLines(['discovery.cat_fantasy' => 'Fantasy (Translated)'], 'en');
        app()->setLocale('en');
        expect($cat->translatedName())->toBe('Fantasy (Translated)');
    });

    it('returns translated value when lang key exists for mechanic', function () {
        $mech = new GameSystemMechanic(['name' => 'Dice Rolling', 'slug' => 'dice-rolling']);
        app('translator')->addLines(['discovery.mech_dice-rolling' => 'Würfel werfen'], 'en');
        app()->setLocale('en');
        expect($mech->translatedName())->toBe('Würfel werfen');
    });
});
