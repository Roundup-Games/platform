<?php

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

//
// Google user-data disclosure on /privacy (Google OAuth sensitive-scope
// verification requirement — runbook kept in the internal GSD research notes,
// .gsd/research/google-verification/): the page must
// disclose per-scope use, the Google API Services User Data Policy
// Limited Use compliance statement, retention, and revocation — in every
// supported locale. Google reviewers click the consent-screen policy link
// and read this page; a silent regression here can cost a verification
// round-trip (weeks).
//
// Wording-agnostic per K004: expectations resolve the same keys the page
// renders, so copy edits never break the pin — only key removal/rename
// does, which is exactly the regression we want to catch.
//

it('renders the Google user-data disclosure on the privacy page in every supported locale', function (string $locale) {
    app()->setLocale($locale);

    $response = get("/{$locale}/privacy");

    $response->assertOk();

    foreach ([
        'privacy.heading_google_user_data',
        'privacy.content_google_signin',
        'privacy.content_google_calendar',
        'privacy.content_google_limited_use',
        'privacy.content_google_retention',
        'privacy.content_google_revoke',
    ] as $key) {
        $response->assertSee(__($key, [], $locale));
    }

    // The third-party services grid carries the Google entry card.
    $response->assertSee(__('privacy.heading_third_google', [], $locale));
})->with(['en', 'de']);

it('names the exact OAuth scopes in the disclosure', function () {
    app()->setLocale('en');

    $response = get('/en/privacy');

    // The scope identifiers are product facts (Google API constants), not
    // translatable copy — they appear verbatim in both locales.
    $response->assertOk()
        ->assertSee('openid, email, profile')
        ->assertSee('calendar.events');
});

it('keeps the disclosure accessible to signed-in members too', function () {
    $member = User::factory()->create();

    actingAs($member);

    get('/en/privacy')
        ->assertOk()
        ->assertSee(__('privacy.heading_google_user_data', [], 'en'));
});
