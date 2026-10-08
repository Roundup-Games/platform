<?php

use App\Filament\Resources\EventResource\Pages\EditEvent;
use App\Filament\Resources\EventResource\RelationManagers\AnnouncementsRelationManager;
use App\Models\Event;
use App\Models\User;
use Filament\Facades\Filament;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

// ── Translatable form editing (M063 post-UAT feedback) ──
//
// Regressions covered here: the AnnouncementsRelationManager previously used
// plain fields, so the EditAction hydrated translatable state from spatie's
// toArray() (full translation arrays) and the modal rendered "[object Object]"
// for title/content; and EditEvent always opened on the resource-default
// locale, showing blank fields for records whose content lives only in
// another locale.

describe('Filament announcements relation manager translations', function () {
    beforeEach(function () {
        seedRoles();

        setPermissionsTeamId(null);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->platformAdmin = User::factory()->create();
        $this->platformAdmin->assignRole('Platform Admin');
        $this->platformAdmin->unsetRelations();

        Filament::setCurrentPanel('admin');

        $this->organizer = User::factory()->create();
        $this->event = Event::factory()->create(['organizer_id' => $this->organizer->id]);
    });

    it('fills the edit action with the active locale text instead of the translation array', function () {
        $announcement = $this->event->announcements()->create([
            'author_id' => $this->organizer->id,
            'title' => ['en' => 'English title', 'de' => 'Deutscher Titel'],
            'content' => ['en' => 'English body.', 'de' => 'Deutscher Text.'],
            'is_published' => false,
        ]);

        actingAs($this->platformAdmin);

        Livewire\Livewire::test(AnnouncementsRelationManager::class, [
            'ownerRecord' => $this->event,
            'pageClass' => EditEvent::class,
        ])
            ->mountTableAction('edit', $announcement)
            ->assertTableActionDataSet([
                'title' => 'English title',
                'content' => 'English body.',
            ]);
    });

    it('saves edits to the default locale without clobbering secondary translations', function () {
        $announcement = $this->event->announcements()->create([
            'author_id' => $this->organizer->id,
            'title' => ['en' => 'English title', 'de' => 'Deutscher Titel'],
            'content' => ['en' => 'English body.', 'de' => 'Deutscher Text.'],
            'is_published' => false,
        ]);

        actingAs($this->platformAdmin);

        Livewire\Livewire::test(AnnouncementsRelationManager::class, [
            'ownerRecord' => $this->event,
            'pageClass' => EditEvent::class,
        ])
            ->callTableAction('edit', $announcement, [
                'title' => 'Updated English title',
                'content' => 'Updated English body.',
            ])
            ->assertSuccessful();

        $announcement->refresh();

        expect($announcement->getTranslation('title', 'en'))->toBe('Updated English title');
        expect($announcement->getTranslation('content', 'en'))->toBe('Updated English body.');
        expect($announcement->getTranslation('title', 'de', useFallbackLocale: false))->toBe('Deutscher Titel');
        expect($announcement->getTranslation('content', 'de', useFallbackLocale: false))->toBe('Deutscher Text.');
    });

    it('edits German content after switching the relation manager locale', function () {
        $announcement = $this->event->announcements()->create([
            'author_id' => $this->organizer->id,
            'title' => ['en' => 'English title', 'de' => 'Deutscher Titel'],
            'content' => ['en' => 'English body.', 'de' => 'Deutscher Text.'],
            'is_published' => false,
        ]);

        actingAs($this->platformAdmin);

        Livewire\Livewire::test(AnnouncementsRelationManager::class, [
            'ownerRecord' => $this->event,
            'pageClass' => EditEvent::class,
        ])
            ->set('activeLocale', 'de')
            ->mountTableAction('edit', $announcement)
            ->assertTableActionDataSet([
                'title' => 'Deutscher Titel',
                'content' => 'Deutscher Text.',
            ])
            ->setTableActionData([
                'title' => 'Aktualisierter Titel',
            ])
            ->callMountedTableAction()
            ->assertSuccessful();

        $announcement->refresh();

        expect($announcement->getTranslation('title', 'de', useFallbackLocale: false))->toBe('Aktualisierter Titel');
        expect($announcement->getTranslation('title', 'en', useFallbackLocale: false))->toBe('English title');
    });
});

describe('EditEvent translatable locale', function () {
    beforeEach(function () {
        seedRoles();

        setPermissionsTeamId(null);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->platformAdmin = User::factory()->create();
        $this->platformAdmin->assignRole('Platform Admin');
        $this->platformAdmin->unsetRelations();

        Filament::setCurrentPanel('admin');

        actingAs($this->platformAdmin);
    });

    it('opens on the default locale when that locale has content', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'English event name'],
            'short_description' => ['en' => 'English short description'],
        ]);

        $component = Livewire\Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->assertSchemaStateSet(['name' => 'English event name']);

        expect($component->instance()->activeLocale)->toBe('en');
    });

    it('opens on a locale with content when the default locale is empty', function () {
        $event = Event::factory()->create([
            'language' => 'de',
            'name' => ['de' => 'Deutscher Veranstaltungstitel'],
            'short_description' => ['de' => 'Deutsche Kurzbeschreibung'],
            'description' => ['de' => 'Deutsche Beschreibung.'],
        ]);

        $component = Livewire\Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->assertSchemaStateSet(['name' => 'Deutscher Veranstaltungstitel']);

        expect($component->instance()->activeLocale)->toBe('de');
    });

    it('keeps the record editable when no locale has content', function () {
        $event = Event::factory()->create([
            'name' => ['en' => 'Will be cleared below'],
        ]);

        $event->setTranslation('name', 'en', '');
        $event->save();

        $component = Livewire\Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()]);

        expect($component->instance()->activeLocale)->toBe('en');
    });
});
