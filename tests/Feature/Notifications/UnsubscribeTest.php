<?php

use App\Enums\NotificationCategory;
use App\Models\Game;
use App\Models\NotificationEngagementCounter;
use App\Models\User;
use App\Notifications\EntityInvitation;
use App\Notifications\WeeklyDigest;
use App\Services\NotificationEngagementCounters;
use Carbon\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->create();
    // Freeze the clock: the unsubscribe counter buckets on the UTC calendar
    // date, so a test straddling UTC midnight would write and read different
    // daily rows and false-fail.
    $this->travelTo(Carbon::parse('2026-11-14 12:00:00'));
});

/** Today's (category, mail, unsubscribed) engagement counter — the D284 aggregate row (M080/S03/T04). */
function unsubscribeCounterCount(string $category): int
{
    return (int) NotificationEngagementCounter::query()
        ->where('date', now()->toDateString())
        ->where('category', $category)
        ->where('channel', 'mail')
        ->where('event', 'unsubscribed')
        ->value('count');
}

// smoke: signed URL unsubscribe
it('unsubscribes user from a notification category via signed URL', function () {
    $category = 'game_invitation';
    $url = URL::signedRoute('notifications.unsubscribe', [
        'locale' => 'en',
        'user' => $this->user->id,
        'category' => $category,
    ]);

    // Set initial settings with mail enabled
    $this->user->update([
        'notification_settings' => NotificationCategory::defaultSettings(),
    ]);

    // Step 1 — GET renders the scanner-safe confirmation page and performs
    // NO write and NO counter: URL-sandboxing mail scanners GET every link.
    $confirm = $this->actingAs($this->user)->get($url);

    $confirm->assertOk()
        // Content-based: a pipeline middleware unwraps the View, so
        // assertViewIs is unreliable here — assert the rendered copy.
        ->assertSee(__('notifications.unsubscribe_confirm_content', ['category' => NotificationCategory::GameInvitation->label()], 'en'));
    expect($this->user->fresh()->notification_settings[$category]['mail'])->toBeTrue()
        ->and(unsubscribeCounterCount('game_invitation'))->toBe(0);

    // Step 2 — the human confirmation POST (_from=link) performs the write
    // and answers with the redirect UX.
    $response = $this->actingAs($this->user)->post($url, ['_from' => 'link']);

    $response->assertRedirect(route('profile.show', ['locale' => 'en']));
    $response->assertSessionHas('status');

    // Verify mail is now disabled for that category
    $settings = $this->user->fresh()->notification_settings;
    expect($settings[$category]['mail'])->toBeFalse();
    expect($settings[$category]['database'])->toBeTrue();

    // D284: the unsubscribe is counted once under its category on the mail channel.
    expect(unsubscribeCounterCount('game_invitation'))->toBe(1)
        ->and(unsubscribeCounterCount('digest'))->toBe(0);
});

it('does not recount an unsubscribe when mail was already disabled', function () {
    $category = 'game_invitation';
    $url = URL::signedRoute('notifications.unsubscribe', [
        'locale' => 'en',
        'user' => $this->user->id,
        'category' => $category,
    ]);

    $settings = NotificationCategory::defaultSettings();
    $settings[$category]['mail'] = false;
    $this->user->update(['notification_settings' => $settings]);

    // Repeat opt-out (scanner replay, double click, one-click retry): the
    // settings write stays idempotent and the D284 funnel counts ONE event.
    $this->post($url);
    $this->post($url);

    expect($this->user->fresh()->notification_settings[$category]['mail'])->toBeFalse()
        ->and(unsubscribeCounterCount($category))->toBe(0);
});

// M081/S02/T01: RFC 8058 one-click parity — the provider POST performs the
// identical settings write and counter increment as the human confirmation
// form, but answers the bare 200 mailbox-provider one-click clients require
// instead of the browser redirect. Detailed POST behavior lives in
// OneClickUnsubscribeTest; this case pins the two flows can never drift.
it('unsubscribes a category via one-click POST with the same effects as GET', function () {
    $category = 'game_invitation';
    $url = URL::signedRoute('notifications.unsubscribe', [
        'locale' => 'en',
        'user' => $this->user->id,
        'category' => $category,
    ]);

    $this->user->update([
        'notification_settings' => NotificationCategory::defaultSettings(),
    ]);

    $response = $this->post($url);

    // RFC 8058: bare 200 — no redirect, no body, no flash session.
    $response->assertNoContent(200);
    expect($response->headers->get('Location'))->toBeNull();

    // Identical write to the GET path: mail off, in-app channel untouched.
    $settings = $this->user->fresh()->notification_settings;
    expect($settings[$category]['mail'])->toBeFalse()
        ->and($settings[$category]['database'])->toBeTrue()
        // Identical telemetry row: one-click opt-outs join link clicks in
        // the delivered-vs-unsubscribed funnel (D284).
        ->and(unsubscribeCounterCount('game_invitation'))->toBe(1)
        ->and(unsubscribeCounterCount('digest'))->toBe(0);
});

it('preserves other category settings when unsubscribing', function () {
    $category = 'new_follower';
    $url = URL::signedRoute('notifications.unsubscribe', [
        'locale' => 'en',
        'user' => $this->user->id,
        'category' => $category,
    ]);

    $this->user->update([
        'notification_settings' => NotificationCategory::defaultSettings(),
    ]);

    $this->actingAs($this->user)->post($url, ['_from' => 'link']);

    $settings = $this->user->fresh()->notification_settings;
    // new_follower mail should be off
    expect($settings['new_follower']['mail'])->toBeFalse();
    // game_invitation mail should still be on (default)
    expect($settings['game_invitation']['mail'])->toBeTrue();
});

it('rejects unsigned URLs with 403', function () {
    $url = url('/en/notifications/unsubscribe/'.$this->user->id.'/game_invitation');

    $this->actingAs($this->user)
        ->get($url)
        ->assertForbidden();

    // An unverified request must never reach the settings write or the counter.
    expect(NotificationEngagementCounter::count())->toBe(0);
});

it('rejects tampered signatures with 403', function () {
    $url = URL::signedRoute('notifications.unsubscribe', [
        'locale' => 'en',
        'user' => $this->user->id,
        'category' => 'game_invitation',
    ]);

    // Tamper with the URL
    $tamperedUrl = str_replace('game_invitation', 'campaign_invitation', $url);

    $this->actingAs($this->user)
        ->get($tamperedUrl)
        ->assertForbidden();

    expect(NotificationEngagementCounter::count())->toBe(0);
});

it('returns 404 for unknown category', function () {
    $url = URL::signedRoute('notifications.unsubscribe', [
        'locale' => 'en',
        'user' => $this->user->id,
        'category' => 'nonexistent_category',
    ]);

    $this->actingAs($this->user)
        ->get($url)
        ->assertNotFound();

    // Unknown category aborts before the settings write — no counter either.
    expect(NotificationEngagementCounter::count())->toBe(0);
});

it('answers a flat 403 for an unknown user id — no account-existence oracle', function () {
    // Implicit User binding runs before the signed middleware, so a binding
    // miss used to answer 404 while a known id with a bad signature answered
    // 403 — distinguishing registered ids from unknown ones on a no-auth
    // surface (M081 sixth-pass review). The missing() handler now aborts 403
    // exactly like the signature rejection, keeping the tamper posture flat.
    $url = url('/en/notifications/unsubscribe/'.Str::uuid().'/game_invitation');

    $this->get($url)->assertForbidden();

    // The digest route carries the same posture.
    $digestUrl = url('/en/notifications/unsubscribe-digest/'.Str::uuid());

    $this->get($digestUrl)->assertForbidden();
});

it('never lets expired or tampered signatures burn the rate limiter', function () {
    // Middleware ordering is signed BEFORE throttle: an invalid signature
    // must abort the pipeline before the limiter counts the request. Twelve
    // consecutive tampered GETs (over the 10/min limit) all answer 403 — a
    // single 429 would mean the limiter ran first and expired links
    // lock the user out (M081 sixth-pass review pins the fifth-pass fix).
    $url = URL::signedRoute('notifications.unsubscribe', [
        'locale' => 'en',
        'user' => $this->user->id,
        'category' => 'game_invitation',
    ]);
    $tamperedUrl = str_replace('game_invitation', 'campaign_invitation', $url);

    for ($i = 0; $i < 12; $i++) {
        $this->get($tamperedUrl)->assertForbidden();
    }
});

it('redirects to home for non-logged-in users', function () {
    $category = 'game_invitation';
    $url = URL::signedRoute('notifications.unsubscribe', [
        'locale' => 'en',
        'user' => $this->user->id,
        'category' => $category,
    ]);

    $this->user->update([
        'notification_settings' => NotificationCategory::defaultSettings(),
    ]);

    // Guest clicking through the confirmation form (no login required —
    // CAN-SPAM/GDPR path): the mail recipient is often not signed in.
    $response = $this->post($url, ['_from' => 'link']);

    $response->assertRedirect(route('home', ['locale' => 'en']));
    $response->assertSessionHas('status');

    // Verify settings were still updated
    $settings = $this->user->fresh()->notification_settings;
    expect($settings[$category]['mail'])->toBeFalse();

    // The no-login path counts the same as the logged-in one (CAN-SPAM/GDPR telemetry).
    expect(unsubscribeCounterCount('game_invitation'))->toBe(1);
});

it('generates valid unsubscribe URLs in notification emails', function () {
    $notification = new EntityInvitation(
        entity: Game::factory()->create(),
        inviter: User::factory()->create(),
    );

    $mail = $notification->toMail($this->user);
    $rendered = (string) $mail->render();

    // Should contain an unsubscribe link
    expect($rendered)->toContain('notifications/unsubscribe/'.$this->user->id.'/game_invitation');
    expect($rendered)->toContain('signature');
});

// smoke: one-click weekly-digest opt-out (CAN-SPAM/GDPR) — two-step like the
// category flow: GET confirms, POST (_from=link) performs the write.
it('unsubscribes a user from the weekly digest via signed URL', function () {
    $this->user->update(['weekly_digest_enabled' => true]);

    $url = URL::signedRoute('notifications.unsubscribe-digest', [
        'locale' => 'en',
        'user' => $this->user->id,
    ]);

    // GET never writes — scanner-safe confirmation page.
    $this->actingAs($this->user)->get($url)
        ->assertOk()
        ->assertSee(__('notifications.unsubscribe_confirm_action'));
    expect($this->user->fresh()->weekly_digest_enabled)->toBeTrue()
        ->and(unsubscribeCounterCount('digest'))->toBe(0);

    $response = $this->actingAs($this->user)->post($url, ['_from' => 'link']);

    $response->assertRedirect(route('profile.show', ['locale' => 'en']));
    $response->assertSessionHas('status');
    expect($this->user->fresh()->weekly_digest_enabled)->toBeFalse();

    // D284: the digest opt-out counts under the dedicated 'digest' category.
    expect(unsubscribeCounterCount('digest'))->toBe(1);
});

it('rejects unsigned digest unsubscribe URLs with 403', function () {
    $url = url('/en/notifications/unsubscribe-digest/'.$this->user->id);

    $this->get($url)->assertForbidden();

    expect(NotificationEngagementCounter::count())->toBe(0);
});

it('does not fail the unsubscribe when the engagement counter write fails', function () {
    $counters = Mockery::mock(NotificationEngagementCounters::class);
    $counters->shouldReceive('incrementFor')->andThrow(new RuntimeException('counters unavailable'));
    $this->app->instance(NotificationEngagementCounters::class, $counters);

    $this->user->update([
        'notification_settings' => NotificationCategory::defaultSettings(),
    ]);

    $url = URL::signedRoute('notifications.unsubscribe', [
        'locale' => 'en',
        'user' => $this->user->id,
        'category' => 'game_invitation',
    ]);

    // Telemetry is best-effort (D284): the settings write is load-bearing, so
    // a counter failure is logged and swallowed — the redirect still happens
    // and the user is still unsubscribed.
    $this->actingAs($this->user)
        ->post($url, ['_from' => 'link'])
        ->assertRedirect(route('profile.show', ['locale' => 'en']));

    expect($this->user->fresh()->notification_settings['game_invitation']['mail'])->toBeFalse();
});

it('renders a translated digest unsubscribe link in the weekly digest email', function () {
    $germanUser = User::factory()->create(['preferred_language' => 'de', 'weekly_digest_enabled' => true]);
    // Mirror what the notification dispatcher does in production: set the
    // recipient's locale before rendering (direct toMail() calls don't).
    app()->setLocale('de');
    $notification = WeeklyDigest::fromNotifications($germanUser->unreadNotifications);

    $mail = $notification->toMail($germanUser);
    $rendered = (string) $mail->render();

    expect($rendered)->toContain('unsubscribe-digest/'.$germanUser->id)
        ->and($rendered)->toContain('signature')
        // German action label is present (not the English fallback) —
        // wording-agnostic per K004: resolve the key, never pin the literal.
        ->and($rendered)->toContain(__('notifications.action_unsubscribe_digest', [], 'de'));
});

it('labels digest groups by the per-notification data.type discriminator', function () {
    // EntityInvitation stores data.type = 'game_invitation' / 'campaign_invitation',
    // which are the keys digest_labels actually defines. The class basename
    // ('entity_invitation') has no key — so resolving from data.type is what makes
    // these render their specific translated label instead of the generic fallback.
    $user = User::factory()->create(['preferred_language' => 'en']);
    app()->setLocale('en');

    $user->notifications()->createMany([
        ['id' => Str::uuid(), 'type' => EntityInvitation::class, 'data' => ['type' => 'game_invitation']],
        ['id' => Str::uuid(), 'type' => EntityInvitation::class, 'data' => ['type' => 'game_invitation']],
        ['id' => Str::uuid(), 'type' => EntityInvitation::class, 'data' => ['type' => 'campaign_invitation']],
    ]);

    $rendered = (string) (WeeklyDigest::fromNotifications($user->unreadNotifications))->toMail($user)->render();

    // Wording-agnostic per K004: resolve the digest label keys the renderer
    // itself resolves, instead of pinning the English literals.
    expect($rendered)->toContain(__('notifications.label_digest_game_invitation', [], 'en'))
        ->and($rendered)->toContain(__('notifications.label_digest_campaign_invitation', [], 'en'));
});

// SimpleMessage holds exactly ONE action slot — the digest's former second
// ->action() (unsubscribe) silently overwrote the primary CTA, making
// "Unsubscribe from the weekly digest" the only hero button on every
// Monday mail (M081 fifth-pass review). The opt-out now rides as the small
// footer link and the button belongs to the notifications index.
it('keeps the notifications index as the digest primary CTA and the opt-out as a footer link', function () {
    $user = User::factory()->create(['preferred_language' => 'en']);
    app()->setLocale('en');

    $rendered = (string) WeeklyDigest::fromNotifications($user->unreadNotifications)
        ->toMail($user)
        ->render();

    // The hero button anchors the notifications index (the x-mail::button
    // component renders the action URL as the anchor's href attribute).
    expect($rendered)->toContain('<a href="'.route('notifications.index', ['locale' => 'en']).'"')
        // The digest opt-out survives as the small muted footer link — the
        // exact anchor shape WeeklyDigest::toMail builds for it.
        ->and($rendered)->toContain('<small><a href="')
        ->and($rendered)->toContain('unsubscribe-digest/'.$user->id);
    // If the unsubscribe ever reclaimed the single action slot, the
    // index anchor above would vanish — that is the discrimination.
});
