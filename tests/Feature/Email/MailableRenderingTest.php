<?php

use App\Mail\MembershipConfirmationEmail;
use App\Mail\TeamInvitationEmail;
use App\Mail\WelcomeEmail;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Team;
use App\Models\User;
use App\Notifications\EventRegistrationConfirmed;

describe('WelcomeEmail', function () {
    test('welcome email renders with correct content and locale-prefixed URLs', function () {
        app()->setLocale('en');
        $user = User::factory()->create(['name' => 'Alice']);
        $rendered = (new WelcomeEmail($user))->render();

        expect($rendered)->toContain('Welcome to Roundup Games!');
        expect($rendered)->toContain('Get Started');
        expect($rendered)->toContain('/en/dashboard');
    });
});

describe('MembershipConfirmationEmail', function () {
    test('membership confirmation email includes plan details when provided', function () {
        $user = User::factory()->create(['name' => 'Alice']);
        $mailable = new MembershipConfirmationEmail($user, 'Premium', '$9.99/mo', 'June 1, 2026');

        $rendered = $mailable->render();
        expect($rendered)->toContain('Premium');
        expect($rendered)->toContain('$9.99/mo');
        expect($rendered)->toContain('June 1, 2026');
    });

    test('membership confirmation email renders with locale-prefixed URLs', function () {
        app()->setLocale('en');
        $user = User::factory()->create(['name' => 'Alice']);
        $mailable = new MembershipConfirmationEmail($user, 'Premium', '$9.99/mo', 'June 1, 2026');
        $rendered = $mailable->render();

        expect($rendered)->toContain('Membership Confirmed!');
        expect($rendered)->toContain('/en/billing');
    });
});

describe('EventRegistrationConfirmed notification', function () {
    test('registration confirmation mail renders with event details, locale-prefixed URLs, and unsubscribe link', function () {
        app()->setLocale('en');
        $user = User::factory()->create(['name' => 'Bob']);
        $event = Event::factory()->create([
            'name' => 'Summer Open',
            'start_date' => '2026-07-15',
            'end_date' => '2026-07-17',
            'venue_name' => 'Convention Center',
        ]);
        $registration = EventRegistration::factory()->confirmed()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
        ]);

        $message = (new EventRegistrationConfirmed($registration))->toMail($user);

        expect($message->subject)->toContain('Event Registration Confirmed');
        expect($message->subject)->toContain('Summer Open');

        $rendered = $message->render()->toHtml();
        expect($rendered)->toContain('Summer Open');
        expect($rendered)->toContain('Convention Center');
        expect($rendered)->toContain('/en/events/');
        expect($rendered)->toContain('notifications/unsubscribe');
    });
});

describe('TeamInvitationEmail', function () {
    test('team invitation email subject includes inviter and team names', function () {
        $inviter = User::factory()->create(['name' => 'Sarah']);
        $team = Team::factory()->create(['name' => 'Board Game Kings']);

        $mailable = new TeamInvitationEmail($team, $inviter, 'invitee@example.com', 'https://example.com/accept');

        expect($mailable->envelope()->subject)->toContain('Sarah');
        expect($mailable->envelope()->subject)->toContain('Board Game Kings');
    });

    test('team invitation email renders with correct content', function () {
        app()->setLocale('en');
        $inviter = User::factory()->create(['name' => 'Sarah']);
        $team = Team::factory()->create(['name' => 'Board Game Kings']);

        $rendered = (new TeamInvitationEmail($team, $inviter, 'invitee@example.com', 'https://example.com/accept'))->render();

        expect($rendered)->toContain("You're Invited to Join a Team!");
        expect($rendered)->toContain('Sarah');
        expect($rendered)->toContain('Board Game Kings');
        expect($rendered)->toContain('Accept Invitation');
    });
});
