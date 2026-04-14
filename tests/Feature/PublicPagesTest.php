<?php

use App\Models\ContactMessage;
use App\Models\Event;
use Illuminate\Support\Facades\Mail;
use App\Mail\ContactFormSubmitted;
use function Pest\Laravel\{get, post, actingAs};

// ── Home Page ──────────────────────────────────────────

describe('HomePage', function () {
    it('renders the landing page', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee('Organize. Compete.')
            ->assertSee('Roundup')
            ->assertSee('Browse Events');
    });

    it('shows upcoming events', function () {
        $event = Event::factory()->create([
            'name' => 'Spring Championship',
            'is_public' => true,
            'status' => 'registration_open',
            'start_date' => now()->addDays(10),
        ]);

        get(route('home'))
            ->assertOk()
            ->assertSee('Spring Championship');
    });

    it('shows featured events', function () {
        Event::factory()->create([
            'name' => 'Featured Tournament',
            'is_public' => true,
            'is_featured' => true,
            'status' => 'registration_open',
            'start_date' => now()->addDays(10),
        ]);

        get(route('home'))
            ->assertOk()
            ->assertSee('Featured Tournament')
            ->assertSee('Featured Events');
    });

    it('hides non-public events from listing', function () {
        Event::factory()->create([
            'name' => 'Private Event',
            'is_public' => false,
            'status' => 'registration_open',
        ]);

        get(route('home'))
            ->assertOk()
            ->assertDontSee('Private Event');
    });

    it('hides past events from listing', function () {
        Event::factory()->create([
            'name' => 'Past Event',
            'is_public' => true,
            'status' => 'completed',
            'start_date' => now()->subDays(10),
            'end_date' => now()->subDays(5),
        ]);

        get(route('home'))
            ->assertOk()
            ->assertDontSee('Past Event');
    });

    it('shows features section', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee('Everything You Need')
            ->assertSee('Find Events')
            ->assertSee('Easy Registration')
            ->assertSee('Team Management');
    });

    it('shows CTA section', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee('Ready to Compete?');
    });

    it('shows sign up link for guests', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee('Get Started');
    });

    it('shows create event link for authenticated users', function () {
        $user = \App\Models\User::factory()->create();

        actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Create Event');
    });

    it('limits upcoming events to 6', function () {
        Event::factory()->count(8)->create([
            'is_public' => true,
            'status' => 'registration_open',
            'start_date' => now()->addDays(10),
        ]);

        $response = get(route('home'));
        $response->assertOk();

        // The view receives 'upcomingEvents' limited to 6
        $upcomingEvents = $response->viewData('upcomingEvents');
        expect($upcomingEvents)->toHaveCount(6);
    });
});

// ── About Page ─────────────────────────────────────────

describe('AboutPage', function () {
    it('renders the about page', function () {
        get(route('about'))
            ->assertOk()
            ->assertSee('About Roundup Games')
            ->assertSee('Our Mission');
    });

    it('shows values section', function () {
        get(route('about'))
            ->assertOk()
            ->assertSee('What We Stand For')
            ->assertSee('Community First')
            ->assertSee('Fair Play')
            ->assertSee('Simple & Fast', escape: false);
    });

    it('shows team section', function () {
        get(route('about'))
            ->assertOk()
            ->assertSee('Our Team');
    });

    it('shows community CTA', function () {
        get(route('about'))
            ->assertOk()
            ->assertSee('Join Our Community');
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
            ->assertSee('Events')
            ->assertSee('Teams');
    });

    it('includes footer links', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee('About')
            ->assertSee('Contact Us');
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

    it('navigates from about to contact', function () {
        get(route('about'))
            ->assertOk()
            ->assertSee(route('contact'));
    });
});

// ── Event Card Component ───────────────────────────────

describe('EventCardComponent', function () {
    it('renders event name and details', function () {
        $event = Event::factory()->create([
            'name' => 'Test Tournament',
            'city' => 'Denver',
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        get(route('home'))
            ->assertOk()
            ->assertSee('Test Tournament')
            ->assertSee('Denver');
    });

    it('shows registration open badge', function () {
        Event::factory()->create([
            'name' => 'Open Event',
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        get(route('home'))
            ->assertOk()
            ->assertSee('Registration Open');
    });

    it('shows featured badge for featured events', function () {
        Event::factory()->create([
            'name' => 'Star Event',
            'is_public' => true,
            'is_featured' => true,
            'status' => 'registration_open',
            'start_date' => now()->addDays(10),
        ]);

        get(route('home'))
            ->assertOk()
            ->assertSee('Featured');
    });

    it('shows free entry for free events', function () {
        Event::factory()->create([
            'name' => 'Free Event',
            'is_public' => true,
            'status' => 'registration_open',
            'individual_registration_fee' => 0,
            'team_registration_fee' => 0,
            'start_date' => now()->addDays(10),
        ]);

        get(route('home'))
            ->assertOk()
            ->assertSee('Free Entry');
    });

    it('shows fee for paid events', function () {
        Event::factory()->create([
            'name' => 'Paid Event',
            'is_public' => true,
            'status' => 'registration_open',
            'individual_registration_fee' => 2500,
            'start_date' => now()->addDays(10),
        ]);

        get(route('home'))
            ->assertOk()
            ->assertSee('$25.00');
    });

    it('links to event detail page', function () {
        $event = Event::factory()->create([
            'name' => 'Linkable Event',
            'is_public' => true,
            'status' => 'registration_open',
        ]);

        get(route('home'))
            ->assertOk()
            ->assertSee(route('events.detail', $event->slug));
    });
});
