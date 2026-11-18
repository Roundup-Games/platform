<?php

use App\Enums\NotificationCategory;
use App\Models\NotificationEngagementCounter;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\URL;

/*
|--------------------------------------------------------------------------
| RFC 8058 one-click unsubscribe (M081/S02/T01)
|--------------------------------------------------------------------------
|
| Mailbox providers that honour List-Unsubscribe=One-Click POST the signed
| unsubscribe URL directly from their servers — no session, no cookies, no
| HTML rendering. The RFC 8058 contract: perform the opt-out and answer a
| plain 200; a redirect or an error body counts as a FAILED unsubscribe.
|
| These tests pin the POST duality of the EXISTING signed routes (no new
| URL space): identical signature/category validation, identical settings
| write, identical D284 engagement-counter row as the GET link — plus the
| hard failure modes (tampered/unsigned/unknown-category POSTs must write
| nothing and count nothing). The GET redirect UX lives in UnsubscribeTest;
| the single GET regression here guards against the POST route shadowing it.
*/

beforeEach(function () {
    $this->user = User::factory()->create();
    // Freeze the clock like UnsubscribeTest: the counter buckets on the UTC
    // calendar date, so a test straddling UTC midnight would write and read
    // different daily rows and false-fail.
    $this->travelTo(Carbon::parse('2026-11-14 12:00:00'));
});

/**
 * Today's (category, mail, unsubscribed) engagement counter — the D284
 * aggregate row (M080/S03). Distinct helper name from UnsubscribeTest's
 * unsubscribeCounterCount: Pest helper functions are global per worker
 * process, so two files declaring the same name fatal under --parallel.
 */
function oneClickCounterCount(string $category): int
{
    return (int) NotificationEngagementCounter::query()
        ->where('date', now()->toDateString())
        ->where('category', $category)
        ->where('channel', 'mail')
        ->where('event', 'unsubscribed')
        ->value('count');
}

it('performs the category opt-out via one-click POST and answers bare 200', function () {
    $category = 'game_invitation';
    $this->user->update([
        'notification_settings' => NotificationCategory::defaultSettings(),
    ]);

    $url = URL::signedRoute('notifications.unsubscribe', [
        'locale' => 'en',
        'user' => $this->user->id,
        'category' => $category,
    ]);

    // Guest request — the one-click client is never authenticated (that is
    // the whole point of the signed-URL mechanism).
    $response = $this->post($url);

    // RFC 8058: a plain 200 with no body and no redirect.
    $response->assertNoContent(200);
    expect($response->headers->get('Location'))->toBeNull();

    // Same write as the GET link: mail off, in-app channel untouched.
    $settings = $this->user->fresh()->notification_settings;
    expect($settings[$category]['mail'])->toBeFalse()
        ->and($settings[$category]['database'])->toBeTrue()
        // Same telemetry row: the delivered-vs-unsubscribed funnel joins
        // one-click opt-outs with link clicks (D284).
        ->and(oneClickCounterCount('game_invitation'))->toBe(1)
        ->and(oneClickCounterCount('digest'))->toBe(0);
});

it('performs the digest opt-out via one-click POST and answers bare 200', function () {
    $this->user->update(['weekly_digest_enabled' => true]);

    $url = URL::signedRoute('notifications.unsubscribe-digest', [
        'locale' => 'en',
        'user' => $this->user->id,
    ]);

    $response = $this->post($url);

    $response->assertNoContent(200);
    expect($response->headers->get('Location'))->toBeNull()
        ->and($this->user->fresh()->weekly_digest_enabled)->toBeFalse()
        ->and(oneClickCounterCount('digest'))->toBe(1)
        ->and(oneClickCounterCount('game_invitation'))->toBe(0);
});

it('rejects a tampered one-click POST with 403, zero writes, zero counters', function () {
    $this->user->update([
        'notification_settings' => NotificationCategory::defaultSettings(),
    ]);

    $url = URL::signedRoute('notifications.unsubscribe', [
        'locale' => 'en',
        'user' => $this->user->id,
        'category' => 'game_invitation',
    ]);

    // Tamper after signing: swapping the category invalidates the HMAC.
    $tamperedUrl = str_replace('game_invitation', 'campaign_invitation', $url);

    $this->post($tamperedUrl)->assertForbidden();

    // Nothing changed and nothing was counted — neither the tampered
    // category nor the originally-signed one was flipped.
    $settings = $this->user->fresh()->notification_settings;
    expect($settings['game_invitation']['mail'])->toBeTrue()
        ->and($settings['campaign_invitation']['mail'])->toBeTrue()
        ->and(NotificationEngagementCounter::count())->toBe(0);
});

it('rejects an unsigned one-click POST with 403', function () {
    $url = url('/en/notifications/unsubscribe/'.$this->user->id.'/game_invitation');

    $this->post($url)->assertForbidden();

    expect(NotificationEngagementCounter::count())->toBe(0);
});

it('returns 404 for a one-click POST naming an unknown category', function () {
    $url = URL::signedRoute('notifications.unsubscribe', [
        'locale' => 'en',
        'user' => $this->user->id,
        'category' => 'nonexistent_category',
    ]);

    $this->post($url)->assertNotFound();

    // Enum validation aborts before the settings write — no counter either.
    expect(NotificationEngagementCounter::count())->toBe(0);
});

// GET regression: the POST route must not shadow the browser link flow — the
// GET leg is the scanner-safe confirmation step (no write, no counter).
it('renders the confirmation page for GET unsubscribe links without writing', function () {
    $this->user->update([
        'notification_settings' => NotificationCategory::defaultSettings(),
    ]);

    $url = URL::signedRoute('notifications.unsubscribe', [
        'locale' => 'en',
        'user' => $this->user->id,
        'category' => 'game_invitation',
    ]);

    $response = $this->actingAs($this->user)->get($url);

    $response->assertOk()->assertSee(__('notifications.unsubscribe_confirm_action'));
    expect($this->user->fresh()->notification_settings['game_invitation']['mail'])->toBeTrue()
        ->and(oneClickCounterCount('game_invitation'))->toBe(0);
});
