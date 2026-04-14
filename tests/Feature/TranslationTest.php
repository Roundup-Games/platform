<?php

use App\Enums\ContentLanguage;
use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Models\Team;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;

// ── HasTranslations Trait ─────────────────────────────

describe('HasTranslations Trait', function () {
    it('can set and get a translation', function () {
        $event = Event::factory()->create(['name' => 'English Name']);
        $event->setTranslation('de', 'name', 'Deutscher Name');

        expect($event->getTranslation('de', 'name'))->toBe('Deutscher Name');
    });

    it('falls back to entity attribute for app locale', function () {
        $event = Event::factory()->create(['name' => 'English Name']);

        // getTranslation for the current app locale falls back to the entity attribute
        expect($event->getTranslation(app()->getLocale(), 'name'))->toBe('English Name');
    });

    it('returns null for missing translation in non-app locale', function () {
        $event = Event::factory()->create(['name' => 'English Name']);

        expect($event->getTranslation('de', 'name'))->toBeNull();
    });

    it('can set multiple translations at once', function () {
        $event = Event::factory()->create([
            'name' => 'English Name',
            'description' => 'English Description',
        ]);

        $event->setTranslation('de', 'name', 'Deutscher Name');
        $event->setTranslation('de', 'description', 'Deutsche Beschreibung');

        expect($event->getTranslation('de', 'name'))->toBe('Deutscher Name');
        expect($event->getTranslation('de', 'description'))->toBe('Deutsche Beschreibung');
    });

    it('handles JSON (array-cast) fields correctly', function () {
        $event = Event::factory()->create(['rules' => ['Rule 1', 'Rule 2']]);

        $deRules = ['Regel 1', 'Regel 2'];
        $event->setTranslation('de', 'rules', $deRules);

        $retrieved = $event->getTranslation('de', 'rules');
        expect($retrieved)->toBe(['Regel 1', 'Regel 2']);
    });

    it('can get all translations for a locale', function () {
        $event = Event::factory()->create([
            'name' => 'English Name',
            'description' => 'English Description',
            'short_description' => 'Short EN',
        ]);

        $event->setTranslation('de', 'name', 'Deutscher Name');
        $event->setTranslation('de', 'description', 'Deutsche Beschreibung');

        $translations = $event->getTranslationsForLocale('de');

        expect($translations)->toHaveKey('name')
            ->and($translations['name'])->toBe('Deutscher Name')
            ->and($translations)->toHaveKey('description')
            ->and($translations['description'])->toBe('Deutsche Beschreibung')
            // Missing DE translation falls back to entity attribute
            ->and($translations)->toHaveKey('short_description')
            ->and($translations['short_description'])->toBe('Short EN');
    });

    it('updates an existing translation', function () {
        $event = Event::factory()->create(['name' => 'English Name']);

        $event->setTranslation('de', 'name', 'Deutscher Name');
        expect($event->getTranslation('de', 'name'))->toBe('Deutscher Name');

        $event->setTranslation('de', 'name', 'Aktualisierter Name');
        expect($event->getTranslation('de', 'name'))->toBe('Aktualisierter Name');

        // Should only be one translation row, not two
        expect(
            Translation::where('translatable_type', 'event')
                ->where('translatable_id', $event->id)
                ->where('locale', 'de')
                ->where('field', 'name')
                ->count()
        )->toBe(1);
    });

    it('persists translations to the database', function () {
        $event = Event::factory()->create(['name' => 'English Name']);
        $event->setTranslation('de', 'name', 'Deutscher Name');

        // Reload from DB to ensure persistence
        $freshEvent = Event::find($event->id);
        expect($freshEvent->getTranslation('de', 'name'))->toBe('Deutscher Name');
    });
});

// ── EventAnnouncement Translations ────────────────────

describe('EventAnnouncement Translations', function () {
    it('can set and get translations on an announcement', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $announcement = EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $user->id,
            'title' => 'English Title',
            'content' => '<p>English content</p>',
        ]);

        $announcement->setTranslation('de', 'title', 'Deutscher Titel');
        $announcement->setTranslation('de', 'content', '<p>Deutscher Inhalt</p>');

        expect($announcement->getTranslation('de', 'title'))->toBe('Deutscher Titel');
        expect($announcement->getTranslation('de', 'content'))->toBe('<p>Deutscher Inhalt</p>');
    });

    it('falls back to entity attribute for app locale on announcement', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $announcement = EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $user->id,
            'title' => 'English Title',
            'content' => '<p>English content</p>',
        ]);

        expect($announcement->getTranslation(app()->getLocale(), 'title'))->toBe('English Title');
    });
});

// ── Int PK Model (Team) Translations ──────────────────

describe('Mixed PK Types', function () {
    it('works with int PK model (Team)', function () {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'Test Team',
            'description' => 'English description',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $team->setTranslation('de', 'description', 'Deutsche Beschreibung');

        // Reload from DB
        $freshTeam = Team::find($team->id);
        expect($freshTeam->getTranslation('de', 'description'))->toBe('Deutsche Beschreibung');

        // Verify the morph map uses 'team' alias, not full class name
        $translation = Translation::where('translatable_type', 'team')
            ->where('translatable_id', (string) $team->id)
            ->where('locale', 'de')
            ->where('field', 'description')
            ->first();

        expect($translation)->not->toBeNull()
            ->and($translation->value)->toBe('Deutsche Beschreibung');
    });
});

// ── Morph Map Aliases ─────────────────────────────────

describe('Morph Map Aliases', function () {
    it('uses short morph map aliases for translatable models', function () {
        expect(Relation::getMorphedModel('event'))->toBe(Event::class)
            ->and(Relation::getMorphedModel('event_announcement'))->toBe(EventAnnouncement::class)
            ->and(Relation::getMorphedModel('team'))->toBe(Team::class);
    });

    it('stores translations using the morph map alias', function () {
        $event = Event::factory()->create(['name' => 'Test Event']);
        $event->setTranslation('de', 'name', 'Testveranstaltung');

        $translation = Translation::firstWhere([
            'translatable_type' => 'event',
            'translatable_id' => $event->id,
            'locale' => 'de',
            'field' => 'name',
        ]);

        expect($translation)->not->toBeNull()
            ->and($translation->translatable_type)->toBe('event')
            ->and($translation->value)->toBe('Testveranstaltung');
    });
});

// ── ContentLanguage Enum ──────────────────────────────

describe('ContentLanguage Enum', function () {
    it('has expected cases and values', function () {
        expect(ContentLanguage::En->value)->toBe('en')
            ->and(ContentLanguage::De->value)->toBe('de')
            ->and(ContentLanguage::DeEn->value)->toBe('de+en');
    });

    it('provides human-readable labels', function () {
        expect(ContentLanguage::En->label())->toBe('English')
            ->and(ContentLanguage::De->label())->toBe('German')
            ->and(ContentLanguage::DeEn->label())->toBe('German + English');
    });

    it('returns all values', function () {
        $values = ContentLanguage::values();
        expect($values)->toBe(['en', 'de', 'de+en']);
    });
});
