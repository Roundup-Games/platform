<?php

use App\Mail\WelcomeEmail;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Notifications\EventRegistrationConfirmed;

describe('WelcomeEmail', function () {
    test('welcome email renders with correct content and locale-prefixed URLs', function () {
        app()->setLocale('en');
        $user = User::factory()->create(['name' => 'Alice']);
        $rendered = (new WelcomeEmail($user))->render();

        expect($rendered)
            ->toContain(e(__('events.content_welcome_to_roundup_games', ['brand' => config('company.display_name')], 'en')))
            ->toContain(e(__('common.field_hey_name', ['name' => 'Alice'], 'en')))
            ->toContain(e(__('notifications.action_complete_your_profile', [], 'en')))
            ->toContain('/en/onboarding');
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
        // Since M081/S08 the CTA is wrapped in the signed read-marker, so the
        // event path travels URL-encoded inside the redirect param.
        expect($rendered)->toContain('/notifications/read-marker/');
        expect($rendered)->toContain('redirect=%2Fen%2Fevents%2F');
        expect($rendered)->toContain('notifications/unsubscribe');
    });
});
