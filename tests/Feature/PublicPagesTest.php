<?php

use App\Models\ContactMessage;
use Illuminate\Support\Facades\Mail;
use App\Mail\ContactFormSubmitted;
use function Pest\Laravel\{get, post, actingAs};

// ── Home Page ──────────────────────────────────────────

describe('HomePage', function () {
    it('renders the landing page with community identity', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee("There's a seat waiting for you.")
            ->assertSee('Find sessions near me')
            ->assertSee('Explore games');
    });

    it('shows the nearby sessions section with location gate', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee("What's happening near you?")
            ->assertSee('Show me sessions near me');
    });

    it('shows living stats section', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee('Sessions this week')
            ->assertSee('People joined sessions this week')
            ->assertSee('Active campaigns');
    });

    it('passes weekly stats to the view', function () {
        $response = get(route('home'));
        $response->assertOk();

        $sessionsThisWeek = $response->viewData('sessionsThisWeek');
        $activeCampaigns = $response->viewData('activeCampaigns');
        $peopleThisWeek = $response->viewData('peopleThisWeek');

        expect($sessionsThisWeek)->toBeInt();
        expect($activeCampaigns)->toBeInt();
        expect($peopleThisWeek)->toBeInt();
    });

    it('shows values strip', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee('Built for real connection')
            ->assertSee('Welcoming Community')
            ->assertSee('Imaginative Play')
            ->assertSee('Safe Spaces')
            ->assertSee('Discovery');
    });

    it('shows community CTA section', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee('Your next adventure starts here');
    });

    it('shows sign up link for guests', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee('Create Free Account');
    });

    it('shows browse sessions link for authenticated users', function () {
        $user = \App\Models\User::factory()->create();

        actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Browse Sessions');
    });

    it('does not show competition or tournament language', function () {
        get(route('home'))
            ->assertOk()
            ->assertDontSee('Organize. Compete.')
            ->assertDontSee('Ready to Compete?')
            ->assertDontSee('Browse Events')
            ->assertDontSee('Featured Events')
            ->assertDontSee('Everything You Need');
    });
});

// ── About Page ─────────────────────────────────────────

describe('AboutPage', function () {
    it('redirects /about to /how-it-works permanently', function () {
        get(route('about'))
            ->assertRedirect(route('how-it-works'));
    });

    it('renders how-it-works page with mission content', function () {
        get(route('how-it-works'))
            ->assertOk()
            ->assertSee('How Roundup Works');
    });
});

// ── Contact Page ───────────────────────────────────────

describe('ContactPage', function () {
    it('renders the contact page', function () {
        get(route('contact'))
            ->assertOk()
            ->assertSee('Contact Us')
            ->assertSee('Send Us a Message');
    });

    it('shows the contact form fields', function () {
        get(route('contact'))
            ->assertOk()
            ->assertSee('Name')
            ->assertSee('Email')
            ->assertSee('Subject')
            ->assertSee('Message');
    });

    it('shows FAQ section', function () {
        get(route('contact'))
            ->assertOk()
            ->assertSee('Frequently Asked');
    });

    it('can submit a valid contact form', function () {
        Mail::fake();

        post(route('contact.submit'), [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'subject' => 'Question about events',
            'message' => 'I have a question about upcoming events.',
        ])
            ->assertRedirect(route('contact'))
            ->assertSessionHas('success');

        // Verify stored in DB
        $this->assertDatabaseHas('contact_messages', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'subject' => 'Question about events',
            'status' => 'new',
        ]);

        // Verify email was queued
        Mail::assertQueued(ContactFormSubmitted::class);
    });

    it('works without a subject', function () {
        Mail::fake();

        post(route('contact.submit'), [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'message' => 'Hello there!',
        ])
            ->assertRedirect(route('contact'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('contact_messages', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);
    });

    it('validates required fields', function () {
        post(route('contact.submit'), [])
            ->assertSessionHasErrors(['name', 'email', 'message']);
    });

    it('validates email format', function () {
        post(route('contact.submit'), [
            'name' => 'John',
            'email' => 'not-an-email',
            'message' => 'Hello',
        ])
            ->assertSessionHasErrors(['email']);
    });

    it('validates max lengths', function () {
        post(route('contact.submit'), [
            'name' => str_repeat('a', 256),
            'email' => 'test@example.com',
            'subject' => str_repeat('a', 256),
            'message' => str_repeat('a', 5001),
        ])
            ->assertSessionHasErrors(['name', 'subject', 'message']);
    });

    it('displays success message after submission', function () {
        Mail::fake();

        $response = post(route('contact.submit'), [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'message' => 'Test message.',
        ]);

        get(route('contact'))
            ->assertOk()
            ->assertSee('Thank you for your message');
    });

    it('shows validation errors on invalid input', function () {
        post(route('contact.submit'), [
            'name' => '',
            'email' => '',
            'message' => '',
        ])
            ->assertSessionHasErrors(['name', 'email', 'message']);
    });
});

// ── Navigation Integration ─────────────────────────────

describe('PublicNavigation', function () {
    it('includes navigation links in header', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee('Discover')
            ->assertSee('Games')
            ->assertSee('Campaigns');
    });

    it('includes footer links', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee('How It Works')
            ->assertSee('Contact');
    });

    it('navigates from home to events', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(route('events.index'));
    });

    it('navigates from home to about', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(route('about'));
    });

    it('navigates from about (redirect) to contact', function () {
        $response = get(route('about'));
        // /about now redirects to /how-it-works, follow the redirect
        get(route('how-it-works'))
            ->assertOk()
            ->assertSee(route('contact'));
    });
});


