<?php

use App\Models\ContactMessage;
use Illuminate\Support\Facades\Mail;
use App\Mail\ContactFormSubmitted;
use function Pest\Laravel\{get, post, actingAs};

// ── Home Page ──────────────────────────────────────────

describe('HomePage', function () {
    it('renders the landing page successfully', function () {
        get(route('home'))->assertOk();
    });

    it('passes weekly stats to the view as integers', function () {
        $response = get(route('home'));
        $response->assertOk();

        expect($response->viewData('sessionsThisWeek'))->toBeInt();
        expect($response->viewData('activeCampaigns'))->toBeInt();
        expect($response->viewData('peopleThisWeek'))->toBeInt();
    });

    it('shows sign up link for guests', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(__('profile.action_create_free_account'));
    });

    it('shows browse sessions link for authenticated users', function () {
        $user = \App\Models\User::factory()->create();

        actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee(__('campaigns.action_browse_sessions'));
    });
});

// ── About Page ─────────────────────────────────────────

describe('AboutPage', function () {
    it('redirects /about to /how-it-works with 301', function () {
        get(route('about'))
            ->assertRedirect(route('how-it-works'))
            ->assertStatus(301);
    });

    it('renders how-it-works page successfully', function () {
        get(route('how-it-works'))->assertOk();
    });
});

// ── Contact Page ───────────────────────────────────────

describe('ContactPage', function () {
    // smoke: contact page renders for guests
    it('renders the contact page', function () {
        get(route('contact'))
            ->assertOk()
            ->assertSee(__('pages.content_contact_us'))
            ->assertSee(__('pages.field_send_us_a_message'));
    })->group('smoke');

    it('shows the contact form fields', function () {
        get(route('contact'))
            ->assertOk()
            ->assertSee(__('common.field_name'))
            ->assertSee(__('emails.field_email'))
            ->assertSee(__('common.content_subject'))
            ->assertSee(__('common.content_message'));
    });

    it('shows FAQ section', function () {
        get(route('contact'))
            ->assertOk()
            ->assertSee(__('common.content_frequently_asked'));
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
            ->assertSee(__('common.content_thank_you_for_your_message'));
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
    it('includes footer links', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(route('how-it-works'))
            ->assertSee(route('contact'));
    });

    it('navigates from home to events', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(route('events.index'));
    });
});

