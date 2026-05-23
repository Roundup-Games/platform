<?php

use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Models\GameSystemCategory;
use App\Models\GameSystemMechanic;
use App\Models\Team;
use App\Models\User;

// ── Spatie HasTranslations ───────────────────────────

describe('Spatie HasTranslations', function () {
    it('can set and get a translation', function () {
        $event = Event::factory()->create(['name' => ['en' => 'English Name']]);
        $event->setTranslation('name', 'de', 'Deutscher Name');

        expect($event->getTranslation('name', 'de'))->toBe('Deutscher Name');
    })->group('smoke');

    it('falls back to app locale when requested locale is missing', function () {
        $event = Event::factory()->create(['name' => ['en' => 'English Name']]);

        // With fallback enabled (default), returns English value
        expect($event->getTranslation('name', 'de'))->toBe('English Name');
    });

    it('returns null for missing translation when fallback is disabled', function () {
        $event = Event::factory()->create(['name' => ['en' => 'English Name']]);

        // de translation was never set
        $result = $event->getTranslationWithoutFallback('name', 'de');
        expect($result === null || $result === '')->toBeTrue();
    });

    it('can set multiple translations at once', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'English Name'],
        ]);

        $event->setTranslation('name', 'de', 'Deutscher Name');
        $event->setTranslation('description', 'de', 'Deutsche Beschreibung');

        expect($event->getTranslation('name', 'de'))->toBe('Deutscher Name');
        expect($event->getTranslation('description', 'de'))->toBe('Deutsche Beschreibung');
    });

    it('can get all translations for a field', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'English Name'],
        ]);

        $event->setTranslation('name', 'de', 'Deutscher Name');

        $translations = $event->getTranslations('name');

        expect($translations)->toHaveKey('en')
            ->and($translations['en'])->toBe('English Name')
            ->and($translations)->toHaveKey('de')
            ->and($translations['de'])->toBe('Deutscher Name');
    });

    it('updates an existing translation', function () {
        $event = Event::factory()->create(['name' => ['en' => 'English Name']]);

        $event->setTranslation('name', 'de', 'Deutscher Name');
        expect($event->getTranslation('name', 'de'))->toBe('Deutscher Name');

        $event->setTranslation('name', 'de', 'Aktualisierter Name');
        expect($event->getTranslation('name', 'de'))->toBe('Aktualisierter Name');
    });

    it('persists translations to the database', function () {
        $event = Event::factory()->create(['name' => ['en' => 'English Name']]);
        $event->setTranslation('name', 'de', 'Deutscher Name');
        $event->save();

        // Reload from DB to ensure persistence
        $freshEvent = Event::find($event->id);
        expect($freshEvent->getTranslation('name', 'de'))->toBe('Deutscher Name');
    });
});

// ── EventAnnouncement Translations ────────────────────

describe('EventAnnouncement Translations', function () {
    it('can set and get translations on an announcement', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create(['organizer_id' => $user->id]);
        $announcement = EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $user->id,
            'title' => ['en' => 'English Title'],
            'content' => ['en' => '<p>English content</p>'],
        ]);

        $announcement->setTranslation('title', 'de', 'Deutscher Titel');
        $announcement->setTranslation('content', 'de', '<p>Deutscher Inhalt</p>');

        expect($announcement->getTranslation('title', 'de'))->toBe('Deutscher Titel');
        expect($announcement->getTranslation('content', 'de'))->toBe('<p>Deutscher Inhalt</p>');
    });

    it('falls back to app locale for announcement', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create(['organizer_id' => $user->id]);
        $announcement = EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $user->id,
            'title' => ['en' => 'English Title'],
            'content' => ['en' => '<p>English content</p>'],
        ]);

        expect($announcement->getTranslation('title', app()->getLocale()))->toBe('English Title');
    });
});

// ── Team Translations (description only) ──────────────

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
