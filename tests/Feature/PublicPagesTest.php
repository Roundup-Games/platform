<?php

use Illuminate\Support\Facades\Mail;
use App\Mail\ContactFormSubmitted;
use function Pest\Laravel\{get, post, actingAs};

// ── Homepage SEO ────────────────────────────────────────

describe('HomepageSEO', function () {
    it('renders the correct SEO title and description', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(__('pages.seo_title_home'), false)
            ->assertSee(__('pages.seo_description_home'), false);
    });

    it('includes Organization schema markup', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee('"@type":"Organization"', false)
            ->assertSee('"name":"Roundup Games"', false);
    });

    it('includes BreadcrumbList schema markup', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee('"@type":"BreadcrumbList"', false);
    });
});

// ── About Page ─────────────────────────────────────────

describe('AboutPage', function () {
    it('renders the about page successfully', function () {
        get(route('about'))
            ->assertOk()
            ->assertSee(__('pages.about_heading_vision'));
    });

    it('includes the SEO title and description', function () {
        get(route('about'))
            ->assertOk()
            ->assertSee('About Roundup Games', false)
            ->assertSee(__('pages.seo_description_about'), false);
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

        // Verify stored in DB with all fields including message content
        $this->assertDatabaseHas('contact_messages', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'subject' => 'Question about events',
            'message' => 'I have a question about upcoming events.',
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

        post(route('contact.submit'), [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'message' => 'Test message.',
        ])
            ->assertRedirect(route('contact'))
            ->assertSessionHas('success');

        // Follow the redirect to verify the success message is displayed
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

