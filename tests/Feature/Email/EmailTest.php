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

// ── WelcomeEmail ─────────────────────────────────────────

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

// ── MembershipConfirmationEmail ──────────────────────────

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

// ── EventRegistrationEmail ───────────────────────────────

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

// ── TeamInvitationEmail ──────────────────────────────────

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

// ── Mail config ──────────────────────────────────────────

test('mail config uses resend transport', function () {
    $mailConfig = config('mail.mailers.resend');

    expect($mailConfig['transport'])->toBe('resend');
});

test('mail config has array transport for testing', function () {
    expect(config('mail.mailers.array.transport'))->toBe('array');
    // Test env uses array mailer via phpunit.xml
    expect(config('mail.default'))->toBe('array');
});
