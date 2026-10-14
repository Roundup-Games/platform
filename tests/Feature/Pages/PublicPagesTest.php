<?php

use App\Models\User;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Ticket;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

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
            // The title is visible text (<title>), so assertSeeText works.
            // The description lives ONLY in meta attribute values, which
            // assertSeeText strips away — assert the escaped form against
            // the raw HTML instead.
            ->assertSeeText(__('pages.seo_title_home'))
            ->assertSee(e(__('pages.seo_description_home')), false);
    })->group('smoke');

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

// ── Contact Page ───────────────────────────────────────

describe('ContactPage', function () {
    beforeEach(function () {
        Department::firstOrCreate(
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

    it('shows the real support and general email addresses from config', function () {
        get(route('contact'))
            ->assertOk()
            // Values come from config/company.php — the same single source
            // of truth impressum and privacy use, not hardcoded addresses.
            ->assertSee('mailto:'.config('company.contact.support'), false)
            ->assertSee(config('company.contact.support'))
            ->assertSee('mailto:'.config('company.contact.general'), false)
            ->assertSee(config('company.contact.general'));
    });

    it('links to the FAQ page instead of the old two-question stub', function () {
        get(route('contact'))
            ->assertOk()
            ->assertSee(__('faq.heading_faq'))
            ->assertSee(route('faq'))
            ->assertSee(__('pages.action_browse_the_faq'));
    });

    it('can submit a valid contact form as guest creating a ticket', function () {
        $department = Department::where('name', 'Contact')->first();

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
            'requester_type' => (new User)->getMorphClass(),
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

        // Verify specific error messages are user-friendly
        $errors = session('errors')->getBag('default');
        expect($errors->first('name'))->toContain('required');
        expect($errors->first('email'))->toContain('required');
        expect($errors->first('message'))->toContain('required');
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
    it('includes the SEO title and description', function () {
        get(route('pledge.algorithms'))
            ->assertOk()
            ->assertSee(__('pages.seo_title_pledge_algorithms'))
            ->assertSee(__('pages.seo_description_pledge_algorithms'), false);
    });
});

describe('RoadAheadPage', function () {
    it('renders the road ahead page successfully with both sections', function () {
        get(route('pledge.roadmap'))
            ->assertOk()
            ->assertSee(__('pages.content_commitment_roadmap_title'))
            ->assertSee(__('roadmap.heading_future_ideas'))
            ->assertSee(__('roadmap.heading_delivered_recently'))
            ->assertSee(__('roadmap.content_road_ahead_intro_2'));
    });

    it('frames future ideas as aspirational and invites feedback via contact', function () {
        get(route('pledge.roadmap'))
            ->assertOk()
            ->assertSee(__('roadmap.content_future_ideas_intro'))
            ->assertSee(__('roadmap.content_future_ideas_closing'))
            ->assertSee(__('roadmap.action_future_ideas_tell_us'))
            ->assertSee(route('contact', app()->getLocale()));
    });

    it('renders under both locale prefixes', function () {
        get('/en/our-pledge/roadmap')->assertOk()->assertSee(__('roadmap.heading_future_ideas', [], 'en'));
        get('/de/our-pledge/roadmap')->assertOk()->assertSee(__('roadmap.heading_future_ideas', [], 'de'));
    });

    it('includes the SEO title and description', function () {
        get(route('pledge.roadmap'))
            ->assertOk()
            ->assertSee(__('roadmap.seo_title_road_ahead'))
            ->assertSee(__('roadmap.seo_description_road_ahead', ['brand' => config('company.display_name')]), false);
    });
});

describe('OurPledgeHub', function () {
    it('links to the algorithms page from the hub card', function () {
        get(route('pledge'))
            ->assertOk()
            ->assertSee(route('pledge.algorithms'));
    });

    it('links to the road ahead page from the live hub card', function () {
        get(route('pledge'))
            ->assertOk()
            ->assertSee(route('pledge.roadmap'))
            ->assertSee(__('pages.content_commitment_roadmap_title'))
            ->assertSee(__('pages.content_commitment_roadmap_action'));
    });

    it('links the Operations & Policies card to the live Code of Conduct with sub-links', function () {
        get(route('pledge'))
            ->assertOk()
            ->assertSee(__('pages.content_commitment_operations_title'))
            ->assertSee(__('pages.content_commitment_operations_action'))
            // The card's primary target is the Code of Conduct (M066/S08/T05).
            ->assertSee(route('safety.code-of-conduct'))
            // Sub-links deliver the rest of the policies promise.
            ->assertSee(__('pages.content_commitment_operations_link_safety'))
            ->assertSee(route('safety-tools'))
            ->assertSee(__('pages.content_commitment_operations_link_algorithms'))
            // The card is live — no coming-soon placeholder anywhere near it.
            ->assertDontSeeText(__('common.field_coming_soon'));
    });

    it('renders the Operations & Policies card in both locales', function () {
        get('/en/our-pledge')->assertOk()
            ->assertSee(__('pages.content_commitment_operations_title', [], 'en'))
            ->assertSee(__('pages.content_commitment_operations_action', [], 'en'));
        get('/de/our-pledge')->assertOk()
            ->assertSee(__('pages.content_commitment_operations_title', [], 'de'))
            ->assertSee(__('pages.content_commitment_operations_action', [], 'de'));
    });
});

// ── Removed pledge aliases (D179 honesty policy) ─────

describe('PledgeHonesty', function () {
    it('renders the pledge hub without coming-soon placeholders or the finances claim', function () {
        // "Operations & Policies" is no longer banned text: M066/S08/T05
        // reintroduced the card as a live link to the Code of Conduct — the
        // dead-promise guard now lives in OurPledgeHub above, which asserts
        // the card actually points somewhere.
        get(route('pledge'))
            ->assertDontSeeText(__('common.field_coming_soon'))
            ->assertDontSeeText('Financial Transparency');
    });

    it('renders the about commitments without coming-soon placeholders and links the live Road Ahead card', function () {
        get(route('about'))
            ->assertDontSeeText(__('common.field_coming_soon'))
            ->assertDontSeeText('Financial Transparency')
            ->assertSee(route('pledge.roadmap'))
            ->assertSee(__('pages.content_commitment_roadmap_action'));
    });

    it('points the open-by-default roadmap claim at the live Road Ahead page', function () {
        get(route('pledge'))
            ->assertSee(__('pages.content_pledge_open_body'))
            ->assertSee(route('pledge.roadmap', app()->getLocale()));
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

    it('shows About, Contact, and the Venue Directory in the guest nav', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(route('about'))
            ->assertSee(route('contact'))
            ->assertSee(route('venues.directory'));
    });

    it('expands Game Master on first use in the nav label', function () {
        expect(__('profile.nav_gm_directory'))->toBe('Game Masters');

        app()->setLocale('de');
        expect(__('profile.nav_gm_directory'))->toBe('Game Master');
    });

    it('keeps the pledge cluster reachable from the footer trust group', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(route('pledge', app()->getLocale()))
            ->assertSee(route('pledge.roadmap', app()->getLocale()))
            ->assertSee(route('pledge.algorithms', app()->getLocale()));
    });
});
