<?php

use App\Mail\EventRegistrationEmail;
use App\Mail\MembershipConfirmationEmail;
use App\Mail\TeamInvitationEmail;
use App\Mail\WelcomeEmail;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

describe('WelcomeEmail', function () {
    test('welcome email can be queued for a user', function () {
        $user = User::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

        Mail::to($user)->send(new WelcomeEmail($user));

        Mail::assertQueued(WelcomeEmail::class, 1);
        Mail::assertQueued(WelcomeEmail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email) && $mail->user->is($user);
        });
    });

    test('welcome email implements ShouldQueue', function () {
        $user = User::factory()->create();
        $mailable = new WelcomeEmail($user);

        expect($mailable)->toBeInstanceOf(Illuminate\Contracts\Queue\ShouldQueue::class);
    });

    test('welcome email has correct subject', function () {
        $user = User::factory()->create();
        $mailable = new WelcomeEmail($user);

        expect($mailable->envelope()->subject)->toBe('Welcome to Roundup Games!');
    });

    test('welcome email renders markdown content', function () {
        $user = User::factory()->create();
        $mailable = new WelcomeEmail($user);

        $content = $mailable->content();
        expect($content->markdown)->toBe('emails.welcome');
    });

    test('welcome email contains user name in rendered body', function () {
        $user = User::factory()->create(['name' => 'TestUser123']);
        $mailable = new WelcomeEmail($user);

        $rendered = $mailable->render();

        expect($rendered)->toContain('TestUser123');
    });

    test('welcome email renders in English', function () {
        app()->setLocale('en');
        $user = User::factory()->create(['name' => 'Alice']);
        $rendered = (new WelcomeEmail($user))->render();

        expect($rendered)->toContain('Welcome to Roundup Games!');
        expect($rendered)->toContain('Thanks for joining');
        expect($rendered)->toContain('Get Started');
        expect($rendered)->toContain('Go to Your Dashboard');
        expect($rendered)->toContain('Happy gaming!');
        // Button URL includes locale
        expect($rendered)->toContain('/en/dashboard');
    });

    test('welcome email renders in German', function () {
        app()->setLocale('de');
        $user = User::factory()->create(['name' => 'Hans']);
        $rendered = (new WelcomeEmail($user))->render();

        expect($rendered)->toContain('Willkommen bei Roundup Games!');
        expect($rendered)->toContain('Hans');
        expect($rendered)->toContain('Danke, dass du bei');
        expect($rendered)->toContain('Loslegen');
        expect($rendered)->toContain('Zu deinem Dashboard');
        expect($rendered)->toContain('Viel Spaß beim Spielen!');
        // Button URL includes locale
        expect($rendered)->toContain('/de/dashboard');
    });
});

describe('MembershipConfirmationEmail', function () {
    test('membership confirmation email can be queued', function () {
        $user = User::factory()->create();

        Mail::to($user)->send(new MembershipConfirmationEmail($user, 'Premium Plan', '$9.99/mo', 'May 1, 2026'));

        Mail::assertQueued(MembershipConfirmationEmail::class, 1);
        Mail::assertQueued(MembershipConfirmationEmail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email)
                && $mail->planName === 'Premium Plan'
                && $mail->amount === '$9.99/mo';
        });
    });

    test('membership confirmation email implements ShouldQueue', function () {
        $user = User::factory()->create();
        $mailable = new MembershipConfirmationEmail($user, 'Basic');

        expect($mailable)->toBeInstanceOf(Illuminate\Contracts\Queue\ShouldQueue::class);
    });

    test('membership confirmation email works with minimal data', function () {
        $user = User::factory()->create();
        $mailable = new MembershipConfirmationEmail($user, 'Free Plan');

        expect($mailable->envelope()->subject)->toBe('Your Roundup Games Membership is Confirmed!');

        $rendered = $mailable->render();
        expect($rendered)->toContain('Free Plan');
    });

    test('membership confirmation email includes plan details when provided', function () {
        $user = User::factory()->create(['name' => 'Alice']);
        $mailable = new MembershipConfirmationEmail($user, 'Premium', '$9.99/mo', 'June 1, 2026');

        $rendered = $mailable->render();
        expect($rendered)->toContain('Premium');
        expect($rendered)->toContain('$9.99/mo');
        expect($rendered)->toContain('June 1, 2026');
    });

    test('membership confirmation email renders in English', function () {
        app()->setLocale('en');
        $user = User::factory()->create(['name' => 'Alice']);
        $mailable = new MembershipConfirmationEmail($user, 'Premium', '$9.99/mo', 'June 1, 2026');
        $rendered = $mailable->render();

        expect($rendered)->toContain('Membership Confirmed!');
        expect($rendered)->toContain('Membership Details');
        expect($rendered)->toContain('Manage Your Membership');
        expect($rendered)->toContain('Thanks for supporting Roundup Games!');
        expect($rendered)->toContain('/en/billing');
    });

    test('membership confirmation email renders in German', function () {
        app()->setLocale('de');
        $user = User::factory()->create(['name' => 'Anna']);
        $mailable = new MembershipConfirmationEmail($user, 'Premium', '9,99 €/Monat', '1. Juni 2026');
        $rendered = $mailable->render();

        expect($rendered)->toContain('Mitgliedschaft bestätigt!');
        expect($rendered)->toContain('Anna');
        expect($rendered)->toContain('Mitgliedschaftsdetails');
        expect($rendered)->toContain('Mitgliedschaft verwalten');
        expect($rendered)->toContain('Danke, dass du Roundup Games unterstützt!');
        expect($rendered)->toContain('/de/billing');
    });
});

describe('EventRegistrationEmail', function () {
    test('event registration email can be queued', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create(['name' => 'Spring Tournament 2026']);
        $registration = EventRegistration::factory()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
        ]);

        Mail::to($user)->send(new EventRegistrationEmail($registration));

        Mail::assertQueued(EventRegistrationEmail::class, 1);
        Mail::assertQueued(EventRegistrationEmail::class, function ($mail) use ($user, $event) {
            return $mail->hasTo($user->email)
                && $mail->registration->event->is($event);
        });
    });

    test('event registration email implements ShouldQueue', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $registration = EventRegistration::factory()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
        ]);

        $mailable = new EventRegistrationEmail($registration);
        expect($mailable)->toBeInstanceOf(Illuminate\Contracts\Queue\ShouldQueue::class);
    });

    test('event registration email subject includes event name', function () {
        $user = User::factory()->create();
        $event = Event::factory()->create(['name' => 'Grand Championship']);
        $registration = EventRegistration::factory()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
        ]);

        $mailable = new EventRegistrationEmail($registration);
        expect($mailable->envelope()->subject)->toContain('Grand Championship');
    });

    test('event registration email renders with event details', function () {
        $user = User::factory()->create(['name' => 'Bob']);
        $event = Event::factory()->create([
            'name' => 'Summer Open',
            'venue_name' => 'Convention Center',
        ]);
        $registration = EventRegistration::factory()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'division' => 'Open Division',
        ]);

        $mailable = new EventRegistrationEmail($registration);
        $rendered = $mailable->render();

        expect($rendered)->toContain('Summer Open');
        expect($rendered)->toContain('Convention Center');
        expect($rendered)->toContain('Open Division');
    });

    test('event registration email renders in English', function () {
        app()->setLocale('en');
        $user = User::factory()->create(['name' => 'Bob']);
        $event = Event::factory()->create([
            'name' => 'Summer Open',
            'start_date' => '2026-07-15',
            'end_date' => '2026-07-17',
            'venue_name' => 'Convention Center',
        ]);
        $registration = EventRegistration::factory()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'division' => 'Open Division',
        ]);

        $rendered = (new EventRegistrationEmail($registration))->render();

        expect($rendered)->toContain('Event Registration Confirmed!');
        expect($rendered)->toContain('Event Details');
        expect($rendered)->toContain('View Event Details');
        expect($rendered)->toContain('See you there!');
        // Date formatted in English style
        expect($rendered)->toContain('Jul');
        expect($rendered)->toContain('/en/events/');
    });

    test('event registration email renders in German', function () {
        app()->setLocale('de');
        $user = User::factory()->create(['name' => 'Hans']);
        $event = Event::factory()->create([
            'name' => 'Sommerturnier',
            'start_date' => '2026-07-15',
            'end_date' => '2026-07-17',
            'venue_name' => 'Messezentrum',
        ]);
        $registration = EventRegistration::factory()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'division' => 'Offene Division',
        ]);

        $rendered = (new EventRegistrationEmail($registration))->render();

        expect($rendered)->toContain('Veranstaltungsanmeldung bestätigt!');
        expect($rendered)->toContain('Veranstaltungsdetails');
        expect($rendered)->toContain('Veranstaltungsdetails ansehen');
        expect($rendered)->toContain('Wir sehen uns dort!');
        expect($rendered)->toContain('Hans');
        // Date formatted in German style
        expect($rendered)->toContain('Juli');
        expect($rendered)->toContain('/de/events/');
    });
});

describe('TeamInvitationEmail', function () {
    test('team invitation email can be queued', function () {
        $inviter = User::factory()->create(['name' => 'Captain']);
        $team = Team::factory()->create(['name' => 'Dice Rollers']);

        Mail::to('newplayer@example.com')
            ->send(new TeamInvitationEmail($team, $inviter, 'newplayer@example.com', 'https://example.com/accept/123'));

        Mail::assertQueued(TeamInvitationEmail::class, 1);
        Mail::assertQueued(TeamInvitationEmail::class, function ($mail) {
            return $mail->hasTo('newplayer@example.com')
                && $mail->team->name === 'Dice Rollers'
                && $mail->inviter->name === 'Captain';
        });
    });

    test('team invitation email implements ShouldQueue', function () {
        $inviter = User::factory()->create();
        $team = Team::factory()->create();

        $mailable = new TeamInvitationEmail($team, $inviter, 'someone@example.com', 'https://example.com/accept');
        expect($mailable)->toBeInstanceOf(Illuminate\Contracts\Queue\ShouldQueue::class);
    });

    test('team invitation email subject includes inviter and team names', function () {
        $inviter = User::factory()->create(['name' => 'Sarah']);
        $team = Team::factory()->create(['name' => 'Board Game Kings']);

        $mailable = new TeamInvitationEmail($team, $inviter, 'invitee@example.com', 'https://example.com/accept');

        expect($mailable->envelope()->subject)->toContain('Sarah');
        expect($mailable->envelope()->subject)->toContain('Board Game Kings');
    });

    test('team invitation email renders with accept link', function () {
        $inviter = User::factory()->create(['name' => 'Sarah']);
        $team = Team::factory()->create(['name' => 'Board Game Kings']);

        $mailable = new TeamInvitationEmail($team, $inviter, 'invitee@example.com', 'https://example.com/accept/abc123');
        $rendered = $mailable->render();

        expect($rendered)->toContain('Board Game Kings');
        expect($rendered)->toContain('Sarah');
        expect($rendered)->toContain('invitee@example.com');
    });

    test('team invitation email renders in English', function () {
        app()->setLocale('en');
        $inviter = User::factory()->create(['name' => 'Sarah']);
        $team = Team::factory()->create(['name' => 'Board Game Kings']);

        $rendered = (new TeamInvitationEmail($team, $inviter, 'invitee@example.com', 'https://example.com/accept'))->render();

        expect($rendered)->toContain("You're Invited to Join a Team!");
        expect($rendered)->toContain('Accept Invitation');
        expect($rendered)->toContain('Happy gaming!');
    });

    test('team invitation email renders in German', function () {
        app()->setLocale('de');
        $inviter = User::factory()->create(['name' => 'Lukas']);
        $team = Team::factory()->create(['name' => 'Brettspiel-Könige']);

        $rendered = (new TeamInvitationEmail($team, $inviter, 'invitee@example.de', 'https://example.com/accept'))->render();

        expect($rendered)->toContain('Du wurdest eingeladen, einem Team beizutreten!');
        expect($rendered)->toContain('Einladung annehmen');
        expect($rendered)->toContain('Viel Spaß beim Spielen!');
        expect($rendered)->toContain('Lukas');
        expect($rendered)->toContain('Brettspiel-Könige');
    });
});

describe('Locale-aware email subjects', function () {
    test('welcome email subject is translated in German', function () {
        app()->setLocale('de');
        $user = User::factory()->create();
        $mailable = new WelcomeEmail($user);

        expect($mailable->envelope()->subject)->toBe('Willkommen bei Roundup Games!');
    });

    test('event registration email subject is translated in German', function () {
        app()->setLocale('de');
        $user = User::factory()->create();
        $event = Event::factory()->create(['name' => 'Sommerturnier']);
        $registration = EventRegistration::factory()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
        ]);

        $mailable = new EventRegistrationEmail($registration);
        expect($mailable->envelope()->subject)->toBe('Veranstaltungsanmeldung bestätigt — Sommerturnier');
    });

    test('team invitation email subject is translated in German', function () {
        app()->setLocale('de');
        $inviter = User::factory()->create(['name' => 'Lukas']);
        $team = Team::factory()->create(['name' => 'Brettspiel-Könige']);

        $mailable = new TeamInvitationEmail($team, $inviter, 'invitee@example.de', 'https://example.com/accept');
        expect($mailable->envelope()->subject)->toBe('Lukas hat dich eingeladen, Brettspiel-Könige beizutreten');
    });

    test('membership confirmation email subject is translated in German', function () {
        app()->setLocale('de');
        $user = User::factory()->create();
        $mailable = new MembershipConfirmationEmail($user, 'Premium');

        expect($mailable->envelope()->subject)->toBe('Deine Roundup-Games-Mitgliedschaft ist bestätigt!');
    });
});

describe('Mail configuration', function () {
    test('mail config uses resend transport', function () {
        $mailConfig = config('mail.mailers.resend');

        expect($mailConfig['transport'])->toBe('resend');
    });

    test('mail config has array transport for testing', function () {
        expect(config('mail.mailers.array.transport'))->toBe('array');
        // Test env uses array mailer via phpunit.xml
        expect(config('mail.default'))->toBe('array');
    });
});
