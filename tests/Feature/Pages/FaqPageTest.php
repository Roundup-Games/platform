<?php

use function Pest\Laravel\get;

// ═══════════════════════════════════════════════════════════
// M066/S08/T02 — the real /faq page absorbing the contact stub.
//
// Contracts:
//   1. Guest-visible in both locales with the fixed heading
//      ("Frequently Asked Questions", not the truncated stub
//      phrase) and all four topic sections.
//   2. Answers the audit's real questions (terminology, bench
//      vs waitlist, reliability, fees, GDPR, venue verification,
//      hosting) plus the two absorbed contact-stub questions.
//   3. Deep-links each answer to the page carrying the detail
//      (algorithms, pricing, for-organizers, privacy) and keeps
//      the remaining-questions path back to the contact form.
//   4. Emits FAQPage JSON-LD built from the same lang keys the
//      page renders — schema can never drift from visible copy.
// ═══════════════════════════════════════════════════════════

describe('FaqPage', function () {
    it('renders for guests with the full heading and all four sections', function () {
        get(route('faq'))
            ->assertOk()
            ->assertSee(__('faq.heading_faq'))
            ->assertSee(__('faq.heading_section_basics'))
            ->assertSee(__('faq.heading_section_playing'))
            ->assertSee(__('faq.heading_section_hosting'))
            ->assertSee(__('faq.heading_section_trust'));
    })->group('smoke');

    it('answers the audit questions across all sections', function () {
        get(route('faq'))
            ->assertOk()
            // Basics
            ->assertSee(__('faq.content_q_system_vs_game'))
            ->assertSee(__('faq.content_q_event_vs_game'))
            // Joining & playing
            ->assertSee(__('faq.content_q_bench_vs_waitlist'))
            ->assertSee(__('faq.content_q_reliability'))
            // Absorbed contact-stub question
            ->assertSee(__('faq.content_q_free_to_use'))
            // Hosting (incl. the second absorbed stub question)
            ->assertSee(__('faq.content_q_host_first'))
            ->assertSee(__('faq.content_q_create_event'))
            // Trust & privacy
            ->assertSee(__('faq.content_q_venue_verified'))
            ->assertSee(__('faq.content_q_data_rights'));
    });

    it('deep-links answers to the pages carrying the detail', function () {
        get(route('faq'))
            ->assertOk()
            ->assertSee(route('pledge.algorithms', app()->getLocale()))
            ->assertSee(__('faq.action_view_algorithms'))
            ->assertSee(route('pricing', app()->getLocale()))
            ->assertSee(route('for-organizers'))
            ->assertSee(route('privacy', app()->getLocale()))
            // The remaining-questions path back to the contact form
            ->assertSee(__('faq.content_faq_still_stuck'))
            ->assertSee(route('contact'));
    });

    it('emits FAQPage JSON-LD built from the lang files', function () {
        get(route('faq'))
            ->assertOk()
            ->assertSee('"@type":"FAQPage"', false)
            ->assertSee('"@type":"Question"', false);
    });

    it('renders in German under the de locale', function () {
        get('/de/faq')
            ->assertOk()
            ->assertSee(__('faq.heading_faq', [], 'de'))
            ->assertSee(__('faq.heading_section_playing', [], 'de'))
            ->assertSee(__('faq.content_q_bench_vs_waitlist', [], 'de'));
    });

    it('includes the SEO title and description', function () {
        get(route('faq'))
            ->assertOk()
            ->assertSee(__('faq.seo_title_faq'))
            ->assertSee(__('faq.seo_description_faq', ['brand' => config('company.display_name')]), false);
    });
});

describe('ContactFaqPointer', function () {
    it('points the contact page at the FAQ and drops the old stub questions', function () {
        get(route('contact'))
            ->assertOk()
            ->assertSee(__('faq.heading_faq'))
            ->assertSee(route('faq'))
            ->assertSee(__('pages.action_browse_the_faq'))
            ->assertSee(__('pages.content_faq_pointer_still_stuck'))
            // The two stub Q&As no longer render inline on /contact — the
            // negatives resolve through the translator so rewording the
            // questions keeps this guard live (K004).
            ->assertDontSeeText(__('faq.content_q_create_event', [], 'en'))
            ->assertDontSeeText(__('faq.content_q_free_to_use', [], 'en'));
    });
});

describe('FaqFooterLink', function () {
    it('keeps the FAQ reachable from the guest footer chrome', function () {
        get(route('home'))
            ->assertOk()
            ->assertSee(route('faq'))
            ->assertSee(__('faq.nav_faq'));
    });
});
