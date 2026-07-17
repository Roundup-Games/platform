<?php

use App\Livewire\Events\EventDetail;
use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Models\EventRegistration;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    URL::defaults(['locale' => 'en']);
});

/**
 * Regression coverage for the EventAnnouncement visibility leak.
 *
 * Organizers can mark an announcement all / registered / private via the
 * Filament AnnouncementsRelationManager. Before the fix, EventDetail filtered
 * only by is_published and ignored the visibility column entirely — so a
 * "Private (Admins)" announcement rendered to anonymous visitors. The
 * EventAnnouncement::visibleTo() scope now enforces the three levels.
 */
describe('EventAnnouncement visibility on the public event page', function () {
    test('anonymous visitors see only "all" announcements', function () {
        [$event] = seedAnnouncementsForVisibilityTest();

        Livewire::test(EventDetail::class, ['slug' => $event->slug])
            ->assertSee('All Visitors Announcement')
            ->assertDontSee('Registered Only Announcement')
            ->assertDontSee('Admins Only Announcement');
    });

    test('authenticated but non-registered users see only "all" announcements', function () {
        [$event] = seedAnnouncementsForVisibilityTest();
        $stranger = User::factory()->create();

        Livewire::actingAs($stranger)
            ->test(EventDetail::class, ['slug' => $event->slug])
            ->assertSee('All Visitors Announcement')
            ->assertDontSee('Registered Only Announcement')
            ->assertDontSee('Admins Only Announcement');
    });

    test('registered users additionally see "registered" announcements', function () {
        [$event] = seedAnnouncementsForVisibilityTest();
        $registered = User::factory()->create();

        EventRegistration::factory()->confirmed()->create([
            'event_id' => $event->id,
            'user_id' => $registered->id,
        ]);

        Livewire::actingAs($registered)
            ->test(EventDetail::class, ['slug' => $event->slug])
            ->assertSee('All Visitors Announcement')
            ->assertSee('Registered Only Announcement')
            ->assertDontSee('Admins Only Announcement');
    });

    test('a cancelled registration does not count as registered', function () {
        [$event] = seedAnnouncementsForVisibilityTest();
        $cancelled = User::factory()->create();

        EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $cancelled->id,
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        Livewire::actingAs($cancelled)
            ->test(EventDetail::class, ['slug' => $event->slug])
            ->assertSee('All Visitors Announcement')
            ->assertDontSee('Registered Only Announcement')
            ->assertDontSee('Admins Only Announcement');
    });

    test('a pending registration does not count as registered', function () {
        // Only a confirmed registration grants registered-level visibility.
        // A pending registration (e.g. awaiting payment) must not.
        [$event] = seedAnnouncementsForVisibilityTest();
        $pending = User::factory()->create();

        EventRegistration::factory()->create([
            'event_id' => $event->id,
            'user_id' => $pending->id,
            'status' => 'pending',
        ]);

        Livewire::actingAs($pending)
            ->test(EventDetail::class, ['slug' => $event->slug])
            ->assertSee('All Visitors Announcement')
            ->assertDontSee('Registered Only Announcement')
            ->assertDontSee('Admins Only Announcement');
    });

    test('the event organizer sees every announcement including "private"', function () {
        [$event] = seedAnnouncementsForVisibilityTest();

        Livewire::actingAs($event->organizer)
            ->test(EventDetail::class, ['slug' => $event->slug])
            ->assertSee('All Visitors Announcement')
            ->assertSee('Registered Only Announcement')
            ->assertSee('Admins Only Announcement');
    });

    test('unpublished announcements are never shown regardless of visibility', function () {
        $organizer = User::factory()->create();
        $event = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $organizer->id,
            'title' => ['en' => 'Draft All Announcement'],
            'content' => ['en' => 'body'],
            'is_published' => false,
            'visibility' => 'all',
        ]);

        // Even the organizer does not see unpublished announcements on the public page.
        Livewire::actingAs($organizer)
            ->test(EventDetail::class, ['slug' => $event->slug])
            ->assertDontSee('Draft All Announcement');
    });
});

/**
 * Seed a public event with three published announcements (all / registered / private).
 *
 * @return array{0: Event}
 */
function seedAnnouncementsForVisibilityTest(): array
{
    $organizer = User::factory()->create();
    $event = Event::factory()->create([
        'organizer_id' => $organizer->id,
        'is_public' => true,
        'status' => 'registration_open',
    ]);

    EventAnnouncement::create([
        'event_id' => $event->id, 'author_id' => $organizer->id,
        'title' => ['en' => 'All Visitors Announcement'], 'content' => ['en' => 'body'],
        'is_published' => true, 'visibility' => 'all',
    ]);
    EventAnnouncement::create([
        'event_id' => $event->id, 'author_id' => $organizer->id,
        'title' => ['en' => 'Registered Only Announcement'], 'content' => ['en' => 'body'],
        'is_published' => true, 'visibility' => 'registered',
    ]);
    EventAnnouncement::create([
        'event_id' => $event->id, 'author_id' => $organizer->id,
        'title' => ['en' => 'Admins Only Announcement'], 'content' => ['en' => 'body'],
        'is_published' => true, 'visibility' => 'private',
    ]);

    return [$event];
}
