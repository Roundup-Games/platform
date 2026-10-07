<?php

use App\Filament\Resources\EventResource\Pages\EditEvent;
use App\Livewire\Events\EventAnnouncements;
use App\Livewire\Events\ManageEvent;
use App\Livewire\Events\RegisterForEvent;
use App\Models\Event;
use App\Models\EventAnnouncement;
use App\Models\EventRegistration;
use App\Models\User;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\PushChannel;
use App\Notifications\EventAnnouncementPublished;
use App\Notifications\EventCancelled;
use App\Notifications\EventRegistrationConfirmed;
use App\Services\EventLifecycleService;
use Filament\Facades\Filament;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\Helpers\PaddleWebhooks;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\post;

// ── Event Cancellation (M063/S03/T02) ────────────────

describe('Event cancellation notifications', function () {
    it('notifies every active registrant exactly once when cancelled via the ManageEvent cancel action', function () {
        $organizer = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        $confirmedUser = User::factory()->create();
        $pendingUser = User::factory()->create();
        $cancelledUser = User::factory()->create();
        $outsider = User::factory()->create();

        EventRegistration::factory()->confirmed()->create(['event_id' => $event->id, 'user_id' => $confirmedUser->id]);
        EventRegistration::factory()->pending()->create(['event_id' => $event->id, 'user_id' => $pendingUser->id]);
        EventRegistration::factory()->cancelled()->create(['event_id' => $event->id, 'user_id' => $cancelledUser->id]);

        Notification::fake();

        actingAs($organizer);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $event->slug])
            ->call('cancelEvent');

        expect($event->fresh()->status->value)->toBe('cancelled');

        // Active registrants (confirmed + pending payment) are notified exactly once
        Notification::assertSentTo($confirmedUser, EventCancelled::class, 1);
        Notification::assertSentTo($pendingUser, EventCancelled::class, 1);

        // Cancelled registrants, unregistered users, and the organizer are not
        Notification::assertNotSentTo($cancelledUser, EventCancelled::class);
        Notification::assertNotSentTo($outsider, EventCancelled::class);
        Notification::assertNotSentTo($organizer, EventCancelled::class);
    });

    it('routes a cancellation made through the status select in save() through the lifecycle service', function () {
        $organizer = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        $confirmedUser = User::factory()->create();
        EventRegistration::factory()->confirmed()->create(['event_id' => $event->id, 'user_id' => $confirmedUser->id]);

        Notification::fake();

        actingAs($organizer);
        Livewire\Livewire::test(ManageEvent::class, ['slug' => $event->slug])
            ->set('status', 'cancelled')
            ->call('save');

        expect($event->fresh()->status->value)->toBe('cancelled');
        Notification::assertSentTo($confirmedUser, EventCancelled::class, 1);
    });

    it('does not dispatch a second round when cancel is called for an already-cancelled event', function () {
        $event = Event::factory()->create();
        $confirmedUser = User::factory()->create();
        EventRegistration::factory()->confirmed()->create(['event_id' => $event->id, 'user_id' => $confirmedUser->id]);

        Notification::fake();

        $service = app(EventLifecycleService::class);
        $service->cancel($event);
        $service->cancel($event);

        expect($event->fresh()->status->value)->toBe('cancelled');
        Notification::assertSentTo($confirmedUser, EventCancelled::class, 1);
    });
});

// ── Event Announcement Publication (M063/S03/T03) ───

describe('Event announcement publication notifications', function () {
    it('notifies every active registrant exactly once when a registered-tier announcement is published via the organizer quick action', function () {
        [$organizer, $event, $confirmedUser, $pendingUser, $cancelledUser, $outsider] = seedAnnouncementNotificationFixture();

        $announcement = EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $organizer->id,
            'title' => ['en' => 'Schedule update'],
            'content' => ['en' => 'Doors open at 10:00.'],
            'is_published' => false,
            'visibility' => 'registered',
        ]);

        Notification::fake();

        actingAs($organizer);
        Livewire\Livewire::test(EventAnnouncements::class, ['slug' => $event->slug])
            ->call('publishAnnouncement', $announcement->id);

        expect($announcement->fresh()->is_published)->toBeTrue();

        // Active registrants (confirmed + pending payment) are notified exactly once
        Notification::assertSentTo($confirmedUser, EventAnnouncementPublished::class, 1);
        Notification::assertSentTo($pendingUser, EventAnnouncementPublished::class, 1);

        // Cancelled registrants, unregistered users, and the organizer are not
        Notification::assertNotSentTo($cancelledUser, EventAnnouncementPublished::class);
        Notification::assertNotSentTo($outsider, EventAnnouncementPublished::class);
        Notification::assertNotSentTo($organizer, EventAnnouncementPublished::class);
    });

    it('does not notify registrants for all-tier or private-tier announcements', function () {
        [$organizer, $event, $confirmedUser] = seedAnnouncementNotificationFixture();

        $allTier = EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $organizer->id,
            'title' => ['en' => 'Public news'],
            'content' => ['en' => 'body'],
            'is_published' => false,
            'visibility' => 'all',
        ]);
        $privateTier = EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $organizer->id,
            'title' => ['en' => 'Organizer note'],
            'content' => ['en' => 'body'],
            'is_published' => false,
            'visibility' => 'private',
        ]);

        Notification::fake();

        actingAs($organizer);
        Livewire\Livewire::test(EventAnnouncements::class, ['slug' => $event->slug])
            ->call('publishAnnouncement', $allTier->id)
            ->call('publishAnnouncement', $privateTier->id);

        // "all" is already public on the detail page; "private" is organizer-only.
        Notification::assertNothingSent();
        expect($confirmedUser->notifications)->toBeEmpty();
    });

    it('creating a draft announcement never notifies, and republishing an already-published one does not notify again', function () {
        [$organizer, $event, $confirmedUser] = seedAnnouncementNotificationFixture();

        Notification::fake();

        $draft = EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $organizer->id,
            'title' => ['en' => 'Still drafting'],
            'content' => ['en' => 'body'],
            'is_published' => false,
            'visibility' => 'registered',
        ]);

        Notification::assertNothingSent();

        // Created directly as published → the observer counts the create as the
        // one publication transition.
        $published = EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $organizer->id,
            'title' => ['en' => 'Live now'],
            'content' => ['en' => 'body'],
            'is_published' => true,
            'visibility' => 'registered',
        ]);

        Notification::assertSentTo($confirmedUser, EventAnnouncementPublished::class, 1);

        // The quick action on the already-published record is a logged no-op.
        actingAs($organizer);
        Livewire\Livewire::test(EventAnnouncements::class, ['slug' => $event->slug])
            ->call('publishAnnouncement', $published->id);

        Notification::assertSentTo($confirmedUser, EventAnnouncementPublished::class, 1);

        expect($draft->fresh()->is_published)->toBeFalse();
    });

    it('notifies exactly once when publication comes from a direct model write, the path the Filament edit form takes', function () {
        [$organizer, $event, $confirmedUser, , $cancelledUser] = seedAnnouncementNotificationFixture();

        $announcement = EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $organizer->id,
            'title' => ['en' => 'Filament edit form'],
            'content' => ['en' => 'body'],
            'is_published' => false,
            'visibility' => 'registered',
        ]);

        Notification::fake();

        // The Filament AnnouncementsRelationManager publishes by saving the
        // record with the is_published toggle — i.e. this model write. The
        // observer chokepoint must fire exactly once for it.
        $announcement->update(['is_published' => true]);

        Notification::assertSentTo($confirmedUser, EventAnnouncementPublished::class, 1);
        Notification::assertNotSentTo($cancelledUser, EventAnnouncementPublished::class);

        // Re-saving without a publish change is silent
        $announcement->update(['title' => ['en' => 'Retitled']]);
        Notification::assertSentTo($confirmedUser, EventAnnouncementPublished::class, 1);
    });

    it('unpublishing then republishing notifies again as a new publication', function () {
        [, $event, $confirmedUser] = seedAnnouncementNotificationFixture();

        $announcement = EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $event->organizer_id,
            'title' => ['en' => 'Back again'],
            'content' => ['en' => 'body'],
            'is_published' => false,
            'visibility' => 'registered',
        ]);

        Notification::fake();

        $announcement->update(['is_published' => true]);
        $announcement->update(['is_published' => false]);
        $announcement->update(['is_published' => true]);

        Notification::assertSentTo($confirmedUser, EventAnnouncementPublished::class, 2);
    });
});

/**
 * Seed an organizer-owned event plus the full registration matrix.
 *
 * @return array{0: User, 1: Event, 2: User, 3: User, 4: User, 5: User}
 */
function seedAnnouncementNotificationFixture(): array
{
    $organizer = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
    $event = Event::factory()->create(['organizer_id' => $organizer->id]);

    $confirmedUser = User::factory()->create();
    $pendingUser = User::factory()->create();
    $cancelledUser = User::factory()->create();
    $outsider = User::factory()->create();

    EventRegistration::factory()->confirmed()->create(['event_id' => $event->id, 'user_id' => $confirmedUser->id]);
    EventRegistration::factory()->pending()->create(['event_id' => $event->id, 'user_id' => $pendingUser->id]);
    EventRegistration::factory()->cancelled()->create(['event_id' => $event->id, 'user_id' => $cancelledUser->id]);

    return [$organizer, $event, $confirmedUser, $pendingUser, $cancelledUser, $outsider];
}

describe('Event cancellation notifications — Filament admin path', function () {
    beforeEach(function () {
        seedRoles();

        setPermissionsTeamId(null);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->platformAdmin = User::factory()->create();
        $this->platformAdmin->assignRole('Platform Admin');
        $this->platformAdmin->unsetRelations();

        Filament::setCurrentPanel('admin');
    });

    it('notifies active registrants exactly once when cancelled from the Filament edit form', function () {
        $organizer = User::factory()->create();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        $confirmedUser = User::factory()->create();
        $cancelledUser = User::factory()->create();
        EventRegistration::factory()->confirmed()->create(['event_id' => $event->id, 'user_id' => $confirmedUser->id]);
        EventRegistration::factory()->cancelled()->create(['event_id' => $event->id, 'user_id' => $cancelledUser->id]);

        Notification::fake();

        actingAs($this->platformAdmin);
        Livewire\Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->fillForm(['status' => 'cancelled'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($event->fresh()->status->value)->toBe('cancelled');
        Notification::assertSentTo($confirmedUser, EventCancelled::class, 1);
        Notification::assertNotSentTo($cancelledUser, EventCancelled::class);
    });

    it('re-saving an already-cancelled event from the Filament edit form does not notify again', function () {
        $organizer = User::factory()->create();
        $event = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'status' => 'cancelled',
        ]);

        $confirmedUser = User::factory()->create();
        EventRegistration::factory()->confirmed()->create(['event_id' => $event->id, 'user_id' => $confirmedUser->id]);

        Notification::fake();

        actingAs($this->platformAdmin);
        Livewire\Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->fillForm(['status' => 'cancelled'])
            ->call('save')
            ->assertHasNoFormErrors();

        Notification::assertNotSentTo($confirmedUser, EventCancelled::class);
    });
});

// ── Registration Confirmation (M063/S03/T04) ─────────

describe('Registration confirmation notifications', function () {
    it('sends exactly one confirmation to the registrant when a free registration completes', function () {
        $organizer = User::factory()->create(['profile_complete' => true, 'email_verified_at' => now()]);
        $event = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'individual_registration_fee' => 0,
        ]);
        $registrant = User::factory()->create(['profile_complete' => true]);

        Notification::fake();

        actingAs($registrant);
        Livewire\Livewire::test(RegisterForEvent::class, ['slug' => $event->slug])
            ->call('register');

        expect($registrant->eventRegistrations()->count())->toBe(1);

        // The free path confirms instantly, so the confirmation rides the
        // channel stack immediately.
        Notification::assertSentTo($registrant, EventRegistrationConfirmed::class, 1);

        // Only the registrant hears about their own registration.
        Notification::assertNotSentTo($organizer, EventRegistrationConfirmed::class);
    });

    it('stays silent while a paid registration is still awaiting payment', function () {
        $user = User::factory()->create(['profile_complete' => true]);
        // Paid event WITHOUT a Paddle price id — the registration lands in
        // the pending-payment state and no checkout dispatch happens.
        $event = Event::factory()->create([
            'organizer_id' => User::factory()->create()->id,
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'individual_registration_fee' => 2500,
        ]);

        Notification::fake();

        actingAs($user);
        Livewire\Livewire::test(RegisterForEvent::class, ['slug' => $event->slug])
            ->call('register');

        assertDatabaseHas('event_registrations', [
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        // The paid path is confirmed (and notified) by the webhook, not here.
        Notification::assertNothingSent();
    });
});

// ── Paid-path confirmation via Paddle webhook (M063/S03/T04) ──

describe('Paid-path confirmation via Paddle webhook', function () {
    beforeEach(function () {
        config(['cashier.webhook_secret' => null]);
    });

    it('confirms the pending registration and notifies the registrant exactly once, including across at-least-once redelivery', function () {
        // Same fixture shape as the S02 webhook suite (EventPaymentWebhookTest):
        // a Paddle customer row so Cashier's parent transaction sync resolves
        // the billable, and a pending registration tagged by custom_data.
        $registrant = PaddleWebhooks::createUser();
        $registrant->forceFill(['paddle_id' => 'ctm_event_notify'])->save();
        PaddleWebhooks::createCustomer($registrant, 'ctm_event_notify');

        $organizer = User::factory()->create();
        $event = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'individual_registration_fee' => 2500,
            'metadata' => ['paddle_price_id' => 'pri_event_ticket'],
        ]);
        $registration = EventRegistration::factory()->pending()->create([
            'event_id' => $event->id,
            'user_id' => $registrant->id,
        ]);

        $payload = fn (string $paddleEventId): array => [
            'event_type' => 'transaction.completed',
            'event_id' => $paddleEventId,
            'data' => [
                'id' => 'txn_event_notify',
                'customer_id' => 'ctm_event_notify',
                'subscription_id' => null,
                'invoice_number' => 'INV-EVT-001',
                'status' => 'completed',
                'currency_code' => 'USD',
                'billed_at' => now()->toIso8601String(),
                'details' => [
                    'totals' => ['total' => '25.00', 'tax' => '0.00'],
                    'line_items' => [
                        ['price' => ['id' => 'pri_event_ticket', 'product_id' => 'pro_event_ticket']],
                    ],
                ],
                'custom_data' => [
                    'event_id' => $event->id,
                    'registration_id' => $registration->id,
                ],
            ],
        ];

        Notification::fake();

        post('/paddle/webhook', $payload('evt_notify_1'))->assertOk();

        assertDatabaseHas('event_registrations', [
            'id' => $registration->id,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'payment_id' => 'txn_event_notify',
        ]);

        Notification::assertSentTo($registrant, EventRegistrationConfirmed::class, 1);
        Notification::assertNotSentTo($organizer, EventRegistrationConfirmed::class);

        // Redelivery #1: the exact same Paddle event id — the cache-level
        // dedupe key short-circuits before any registration lookup.
        post('/paddle/webhook', $payload('evt_notify_1'))->assertOk();
        Notification::assertSentTo($registrant, EventRegistrationConfirmed::class, 1);

        // Redelivery #2: a fresh event id (the dedupe key has a 2-day TTL)
        // carrying the same transaction — the already-paid state guard no-ops.
        post('/paddle/webhook', $payload('evt_notify_2'))->assertOk();
        Notification::assertSentTo($registrant, EventRegistrationConfirmed::class, 1);
    });
});

// ── Channel compliance across the matrix (M063/S03/T04) ──

describe('Channel compliance', function () {
    it('honors channel defaults and per-user notification_settings on the confirmation', function () {
        $organizer = User::factory()->create();
        $event = Event::factory()->create([
            'organizer_id' => $organizer->id,
            'status' => 'registration_open',
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(7),
            'is_public' => true,
            'individual_registration_fee' => 0,
        ]);

        // Null settings → the event_registration category defaults apply:
        // database + mail + push + discord are all on.
        $defaultUser = User::factory()->create(['profile_complete' => true]);

        // Partial row: mail explicitly off, every other channel falls back
        // to the category default (per-channel fallback semantics).
        $maillessUser = User::factory()->create([
            'profile_complete' => true,
            'notification_settings' => ['event_registration' => ['mail' => false]],
        ]);

        // Explicit database-only preference.
        $databaseOnlyUser = User::factory()->create([
            'profile_complete' => true,
            'notification_settings' => ['event_registration' => [
                'database' => true, 'mail' => false, 'push' => false, 'discord' => false,
            ]],
        ]);

        // Everything off → NotificationService skips dispatch entirely.
        $mutedUser = User::factory()->create([
            'profile_complete' => true,
            'notification_settings' => ['event_registration' => [
                'database' => false, 'mail' => false, 'push' => false, 'discord' => false,
            ]],
        ]);

        Notification::fake();

        foreach ([$defaultUser, $maillessUser, $databaseOnlyUser, $mutedUser] as $registrant) {
            actingAs($registrant);
            Livewire\Livewire::test(RegisterForEvent::class, ['slug' => $event->slug])
                ->call('register');
        }

        Notification::assertSentTo($defaultUser, EventRegistrationConfirmed::class,
            fn (EventRegistrationConfirmed $notification, array $channels): bool => $channels === [
                DatabaseChannel::class, MailChannel::class, PushChannel::class, DiscordChannel::class,
            ]);

        Notification::assertSentTo($maillessUser, EventRegistrationConfirmed::class,
            fn (EventRegistrationConfirmed $notification, array $channels): bool => $channels === [
                DatabaseChannel::class, PushChannel::class, DiscordChannel::class,
            ]);

        Notification::assertSentTo($databaseOnlyUser, EventRegistrationConfirmed::class,
            fn (EventRegistrationConfirmed $notification, array $channels): bool => $channels === [
                DatabaseChannel::class,
            ]);

        Notification::assertNotSentTo($mutedUser, EventRegistrationConfirmed::class);
        expect($mutedUser->notifications)->toBeEmpty();
    });

    it('honors per-user channel preferences when a cancellation fans out to every active registrant', function () {
        $organizer = User::factory()->create();
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);

        $defaultUser = User::factory()->create();
        $databaseOnlyUser = User::factory()->create([
            'notification_settings' => ['event_registration' => [
                'database' => true, 'mail' => false, 'push' => false, 'discord' => false,
            ]],
        ]);

        EventRegistration::factory()->confirmed()->create(['event_id' => $event->id, 'user_id' => $defaultUser->id]);
        EventRegistration::factory()->pending()->create(['event_id' => $event->id, 'user_id' => $databaseOnlyUser->id]);

        Notification::fake();

        app(EventLifecycleService::class)->cancel($event);

        Notification::assertSentTo($defaultUser, EventCancelled::class,
            fn (EventCancelled $notification, array $channels): bool => $channels === [
                DatabaseChannel::class, MailChannel::class, PushChannel::class, DiscordChannel::class,
            ]);

        Notification::assertSentTo($databaseOnlyUser, EventCancelled::class,
            fn (EventCancelled $notification, array $channels): bool => $channels === [
                DatabaseChannel::class,
            ]);
    });
});

// ── Unsubscribe links in mail rendering (M063/S03/T04) ──

describe('Unsubscribe links in registrant mail', function () {
    it('renders a signed event_registration unsubscribe link in the cancellation mail', function () {
        app()->setLocale('en');
        $user = User::factory()->create(['name' => 'Rosa']);
        $event = Event::factory()->create([
            'name' => 'Winter Gala',
            'start_date' => '2026-12-05',
            'end_date' => '2026-12-06',
            'venue_name' => 'Grand Hall',
        ]);

        $message = (new EventCancelled($event))->toMail($user);

        expect($message->subject)->toContain('Winter Gala');

        $rendered = $message->render()->toHtml();
        expect($rendered)
            ->toContain('Winter Gala')
            ->toContain('/en/events/'.$event->slug)
            ->toContain('/notifications/unsubscribe/'.$user->id.'/event_registration')
            ->toContain('signature=');
    });

    it('renders a signed event_registration unsubscribe link in the announcement mail', function () {
        app()->setLocale('en');
        $organizer = User::factory()->create();
        $event = Event::factory()->create(['organizer_id' => $organizer->id, 'name' => 'Autumn Con']);

        // Created unpublished so the observer does not fan the notification
        // out to registrants as a side effect of this rendering test.
        $announcement = EventAnnouncement::create([
            'event_id' => $event->id,
            'author_id' => $organizer->id,
            'title' => ['en' => 'Doors moved'],
            'content' => ['en' => 'Doors open at 09:00.'],
            'is_published' => false,
            'visibility' => 'registered',
        ]);

        $user = User::factory()->create(['name' => 'Sami']);
        $message = (new EventAnnouncementPublished($announcement, $event))->toMail($user);

        expect($message->subject)->toContain('Autumn Con');

        $rendered = $message->render()->toHtml();
        expect($rendered)
            ->toContain('Autumn Con')
            ->toContain('Doors moved')
            ->toContain('/en/events/'.$event->slug)
            ->toContain('/notifications/unsubscribe/'.$user->id.'/event_registration')
            ->toContain('signature=');
    });
});
