<?php

use function Pest\Laravel\get;

// ═══════════════════════════════════════════════════════════
// M066/S08/T03 — the Code of Conduct under /safety.
//
// Contracts:
//   1. Guest-visible in both locales with the four community
//      rules (respect, safety tools culture, honest attendance
//      reporting, anti-harassment) in plain language.
//   2. Documents the consequences ladder at a high level
//      (warning → content removal → suspension) — deliberately
//      minimal per the validation addendum §8 deferral.
//   3. The report + appeal path points at the contact page and
//      the configured support address.
//   4. Cross-linked from the safety-tools page and from signup,
//      so the rules are discoverable before joining.
// ═══════════════════════════════════════════════════════════

describe('CodeOfConductPage', function () {
    it('renders for guests with the four community rules', function () {
        get(route('safety.code-of-conduct'))
            ->assertOk()
            ->assertSee(__('safety.heading_code_of_conduct'))
            ->assertSee(__('safety.heading_coc_rules'))
            ->assertSee(__('safety.field_coc_rule_respect_title'))
            ->assertSee(__('safety.field_coc_rule_safety_tools_title'))
            ->assertSee(__('safety.field_coc_rule_attendance_title'))
            ->assertSee(__('safety.field_coc_rule_no_harassment_title'));
    })->group('smoke');

    it('documents the high-level consequences ladder', function () {
        get(route('safety.code-of-conduct'))
            ->assertOk()
            ->assertSee(__('safety.heading_coc_consequences'))
            ->assertSee(__('safety.label_coc_step_warning'))
            ->assertSee(__('safety.label_coc_step_removal'))
            ->assertSee(__('safety.label_coc_step_suspension'));
    });

    it('exposes the report and appeal path via contact and the support address', function () {
        get(route('safety.code-of-conduct'))
            ->assertOk()
            ->assertSee(__('safety.heading_coc_report'))
            ->assertSee(__('safety.content_coc_appeal_body'))
            ->assertSee(route('contact'))
            // The real support address from config, not a placeholder
            ->assertSee(config('company.contact.support'))
            ->assertSee(__('safety.content_coc_report_email_note', ['email' => config('company.contact.support')]));
    });

    it('cross-links to the safety-tools page', function () {
        get(route('safety.code-of-conduct'))
            ->assertOk()
            ->assertSee(route('safety-tools'))
            ->assertSee(__('safety.action_coc_read_safety_tools'));
    });

    it('renders in German under the de locale', function () {
        get('/de/safety/code-of-conduct')
            ->assertOk()
            ->assertSee(__('safety.heading_code_of_conduct', [], 'de'))
            ->assertSee(__('safety.field_coc_rule_attendance_title', [], 'de'))
            ->assertSee(__('safety.heading_coc_consequences', [], 'de'));
    });

    it('includes the SEO title and description', function () {
        get(route('safety.code-of-conduct'))
            ->assertOk()
            ->assertSee(__('safety.seo_title_code_of_conduct'))
            ->assertSee(__('safety.seo_description_code_of_conduct'));
    });
});

describe('CodeOfConductCrossLinks', function () {
    it('links the CoC from the safety-tools page', function () {
        get(route('safety-tools'))
            ->assertOk()
            ->assertSee(route('safety.code-of-conduct'))
            ->assertSee(__('safety.action_view_code_of_conduct'));
    });

    it('binds signup to the Terms and the Code of Conduct', function () {
        get(route('register'))
            ->assertOk()
            ->assertSee(__('safety.content_signup_agreement_prefix'))
            ->assertSee(route('terms'))
            ->assertSee(route('safety.code-of-conduct'))
            ->assertSee(__('safety.nav_code_of_conduct'));
    });
});
