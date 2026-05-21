<?php

use App\Models\User;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Ticket;
use function Pest\Laravel\{get, post, actingAs};

// Max lengths must be one character over the validation rules
// (name: max:255, subject: max:255, message: max:5000)
const MAX_NAME_LENGTH = 256;
const MAX_SUBJECT_LENGTH = 256;
const MAX_MESSAGE_LENGTH = 5001;

// ── Homepage SEO ────────────────────────────────────────

describe('HomepageSEO', function () {
    it('renders the correct SEO title and description', function () {
        get(route('home'))
            ->assertOk()
            ->assertSeeText(__('pages.seo_title_home'))
            ->assertSeeText(__('pages.seo_description_home'));
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
            ->assertSee(__('pages.content_about_heading_vision'));
    });

    it('includes the SEO title and description', function () {
        get(route('about'))
            ->assertOk()
            // SEO title is rendered in <title> where & becomes &amp;.
            // assertSee() without false encodes the expected string too, so both sides match.
            // nosemgrep: php.laravel.assert-see-escaped — intentional default escaping for HTML context.
            ->assertSee(__('pages.seo_title_about'))
            // Description is in a <meta> content attribute — use false to match raw value.
            ->assertSee(__('pages.seo_description_about', ['brand' => config('company.display_name')]), false);
    });

});

// ── How It Works Page ──────────────────────────────────

describe('HowItWorksPage', function () {
    it('renders the how-it-works page successfully', function () {
        get(route('how-it-works'))->assertOk();
    });
});

// ── Contact Page ───────────────────────────────────────

describe('ContactPage', function () {
    beforeEach(function () {
        \Escalated\Laravel\Models\Department::firstOrCreate(
            ['name' => 'Contact'],
            ['description' => 'General inquiries and questions', 'is_active' => true],
        );
    });

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

    it('can submit a valid contact form as guest creating a ticket', function () {
        $department = Department::where('name', 'Contact')->first();
        expect($department)->not->toBeNull('Contact department must exist for ticket creation');

        post(route('contact.submit'), [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'subject' => 'Question about events',
            'message' => 'I have a question about upcoming events.',
        ])
            ->assertRedirect(route('contact'))
            ->assertSessionHas('success');

        // Verify ticket was created with guest fields
        $this->assertDatabaseHas('escalated_tickets', [
            'guest_name' => 'John Doe',
            'guest_email' => 'john@example.com',
            'subject' => 'Question about events',
            'description' => 'I have a question about upcoming events.',
            'department_id' => $department?->id,
        ]);

        // Verify guest_token is set
        $ticket = Ticket::where('guest_email', 'john@example.com')->first();
        expect($ticket->guest_token)->not->toBeNull();
        expect($ticket->status->value)->toBe('open');
    });

    it('creates ticket for authenticated user with requester relation', function () {
        $user = User::factory()->create();
        $department = Department::where('name', 'Contact')->first();
        expect($department)->not->toBeNull('Contact department must exist for ticket creation');

        actingAs($user)->post(route('contact.submit'), [
            'name' => $user->name,
            'email' => $user->email,
            'subject' => 'Auth user question',
            'message' => 'I am logged in and have a question.',
        ])
            ->assertRedirect(route('contact'))
            ->assertSessionHas('success');

        // Verify ticket created with requester morph
        $this->assertDatabaseHas('escalated_tickets', [
            'requester_type' => User::class,
            'requester_id' => $user->id,
            'subject' => 'Auth user question',
            'description' => 'I am logged in and have a question.',
            'department_id' => $department?->id,
        ]);
    });

    it('works without a subject', function () {
        post(route('contact.submit'), [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'message' => 'Hello there!',
        ])
            ->assertRedirect(route('contact'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('escalated_tickets', [
            'guest_name' => 'Jane Doe',
            'guest_email' => 'jane@example.com',
            'subject' => 'General Inquiry',
            'description' => 'Hello there!',
        ]);
    });

    it('validates required fields', function () {
        $response = post(route('contact.submit'), []);

        $response->assertSessionHasErrors(['name', 'email', 'message']);

        // Verify error messages are present for each required field
        $errors = session('errors')->getBag('default');
        expect($errors->has('name'))->toBeTrue();
        expect($errors->has('email'))->toBeTrue();
        expect($errors->has('message'))->toBeTrue();
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
            'name' => str_repeat('a', MAX_NAME_LENGTH),       // max:255 + 1
            'email' => 'test@example.com',
            'subject' => str_repeat('a', MAX_SUBJECT_LENGTH), // max:255 + 1
            'message' => str_repeat('a', MAX_MESSAGE_LENGTH), // max:5000 + 1
        ])
            ->assertSessionHasErrors(['name', 'subject', 'message']);
    });

    it('accepts values at exactly max length boundaries', function () {
        post(route('contact.submit'), [
            'name' => str_repeat('a', 255),       // exactly max:255
            'email' => 'test@example.com',
            'subject' => str_repeat('a', 255),    // exactly max:255
            'message' => str_repeat('a', 5000),   // exactly max:5000
        ])
            ->assertRedirect(route('contact'))
            ->assertSessionHas('success');
    });

    it('displays success message after submission', function () {
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

});

// ── Our Pledge / Algorithms Page ────────────────────────

describe('AlgorithmsPage', function () {
    it('renders the algorithms page successfully', function () {
        get(route('pledge.algorithms'))
            ->assertOk();
    });

    it('includes the SEO title and description', function () {
        get(route('pledge.algorithms'))
            ->assertOk()
            ->assertSee(__('pages.seo_title_pledge_algorithms'))
            ->assertSee(__('pages.seo_description_pledge_algorithms'), false);
    });
});

describe('OurPledgeHub', function () {
    it('links to the algorithms page from the hub card', function () {
        get(route('pledge'))
            ->assertOk()
            ->assertSee(route('pledge.algorithms'));
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
