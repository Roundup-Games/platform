<?php

use App\Mail\WelcomeEmail;
use App\Models\EmailSuppression;
use App\Models\User;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Facades\Socialite;

//
// Welcome mail wiring + copy (M081/S03/T01): the purpose-written
// single-CTA welcome mail, sent through ONE chokepoint — the Registered
// event listener SendWelcomeEmail — that BOTH signup paths fire (the
// manual form via RegisteredUserController, OAuth via the explicit
// dispatch in OAuthController, which this suite pins as load-bearing).
//
// Copy assertions resolve lang keys (K004: never pin literal lang
// strings); only structural HTML (the hidden preheader div, the single
// action button) is asserted literally by design, mirroring the branding
// corpus tests.
//

/**
 * Parallel-safe unique registration payload for the form-signup tests.
 *
 * @return array<string, string>
 */
function welcomeRegistrationPayload(): array
{
    return [
        'name' => 'Welcome Tester',
        'email' => 'welcome-'.uniqid('mail', true).'@test.test',
        'password' => 'Sup3rSecret!pass',
        'password_confirmation' => 'Sup3rSecret!pass',
    ];
}

/**
 * Send a WelcomeEmail through the real send surface (array mailer) and
 * capture the transport bodies via MessageSending — the same capture
 * pattern as RendersBrandedCorpusMail / MailCategoryTagTest, independent
 * of which mailer the send resolved to.
 *
 * @return array{html: string, text: string}
 */
function captureWelcomeMail(User $user): array
{
    $captured = [];

    Event::listen(MessageSending::class, function (MessageSending $event) use (&$captured): void {
        $captured[] = $event->message;
    });

    Mail::to($user)->send(new WelcomeEmail($user));

    test()->assertNotEmpty($captured, 'No welcome message captured via MessageSending.');

    $html = $captured[array_key_last($captured)]->getHtmlBody();
    $text = $captured[array_key_last($captured)]->getTextBody();

    test()->assertIsString($html, 'Captured welcome mail has no HTML body.');
    test()->assertIsString($text, 'Captured welcome mail has no text body.');

    return ['html' => (string) $html, 'text' => (string) $text];
}

// ── Copy: EN ─────────────────────────────────────────────────────────────

it('renders the English welcome mail with localized copy, one CTA, and the hidden preheader', function () {
    app()->setLocale('en');
    $user = User::factory()->create(['name' => 'Alice']);
    $bodies = captureWelcomeMail($user);

    $html = $bodies['html'];

    // Subject (kept key — the copy still fits) resolves with the brand.
    expect((new WelcomeEmail($user))->envelope()->subject)
        ->toBe(__('events.content_welcome_to_roundup_games', ['brand' => config('company.display_name')], 'en'));

    // Heading, greeting, intro, and CTA label — all via key resolution.
    expect($html)
        ->toContain(e(__('events.content_welcome_to_roundup_games', ['brand' => config('company.display_name')], 'en')))
        ->toContain(e(__('common.field_hey_name', ['name' => 'Alice'], 'en')))
        ->toContain(e(__('notifications.content_welcome_intro', ['brand' => config('company.display_name')], 'en')))
        ->toContain(e(__('notifications.action_complete_your_profile', [], 'en')))
        ->toContain(e(__('notifications.content_welcome_outro', [], 'en')));

    // The single CTA targets onboarding (the complete-profile gate every
    // fresh signup is redirected to), locale-prefixed.
    expect($html)->toContain('/en/onboarding');

    // Exactly ONE action button — purpose-written means never stacked CTAs.
    expect(substr_count($html, 'class="action"'))->toBe(1);

    // Hidden preheader: the wrapper-identical hidden div precedes the
    // brand header row and the heading (inbox preview slot). Before the
    // brand header specifically (M081 sixth-pass review): mail::message
    // renders the brand header before the body slot, so a body-slot
    // preheader let clients concatenate the brand name ahead of the
    // crafted preview copy — the header slot places it first instead.
    $preheader = __('notifications.preheader_welcome_email', [], 'en');
    expect($html)->toContain('display: none; max-height: 0; overflow: hidden; mso-hide: all;');
    $preheaderPos = strpos($html, e($preheader));
    $brandHeaderPos = strpos($html, 'class="header"');
    $headingPos = strpos($html, e(__('events.content_welcome_to_roundup_games', ['brand' => config('company.display_name')], 'en')));
    expect($preheaderPos)->not->toBeFalse('rendered welcome mail does not contain the preheader text');
    expect($headingPos)->not->toBeFalse('rendered welcome mail does not contain the heading');
    expect($brandHeaderPos)->not->toBeFalse('rendered welcome mail does not contain the brand header row');
    expect($preheaderPos)->toBeLessThan($brandHeaderPos, 'preheader must render before the brand header row (hidden inbox-preview slot)');
    expect($preheaderPos)->toBeLessThan($headingPos, 'preheader must render before the heading (hidden inbox-preview slot)');

    // The text part carries no HTML markup — the hidden div is stripped
    // by the mail text layout, so no markup leaks into the plain-text body.
    expect($bodies['text'])
        ->not->toContain('<div')
        ->not->toContain('display: none')
        ->not->toContain('mso-hide');
});

// ── Copy: DE ─────────────────────────────────────────────────────────────

it('renders the German welcome mail with localized copy and the DE onboarding URL', function () {
    app()->setLocale('de');
    $user = User::factory()->create(['name' => 'Lena']);
    $bodies = captureWelcomeMail($user);

    expect((new WelcomeEmail($user))->envelope()->subject)
        ->toBe(__('events.content_welcome_to_roundup_games', ['brand' => config('company.display_name')], 'de'));

    expect($bodies['html'])
        ->toContain(e(__('events.content_welcome_to_roundup_games', ['brand' => config('company.display_name')], 'de')))
        ->toContain(e(__('common.field_hey_name', ['name' => 'Lena'], 'de')))
        ->toContain(e(__('notifications.content_welcome_intro', ['brand' => config('company.display_name')], 'de')))
        ->toContain(e(__('notifications.action_complete_your_profile', [], 'de')))
        ->toContain('/de/onboarding');

    // The structural contract is locale-independent.
    expect(substr_count($bodies['html'], 'class="action"'))->toBe(1);
    expect($bodies['text'])->not->toContain('<div');
});

// ── Wiring: manual registration ──────────────────────────────────────────

it('queues exactly one welcome mail when a user registers through the form', function () {
    Mail::fake();

    $payload = welcomeRegistrationPayload();

    $response = $this->post('/en/register', $payload);

    $response->assertRedirect(route('onboarding.index'));

    $user = User::where('email', $payload['email'])->firstOrFail();

    // WelcomeEmail is a queued mailable dispatched by the queued
    // SendWelcomeEmail listener (sync queue in tests) — Mail::fake records
    // the send through the queue path, so the queue assertion IS the
    // "queued + sent" pin.
    Mail::assertQueuedTimes(WelcomeEmail::class, 1);
    Mail::assertQueued(function (WelcomeEmail $mail) use ($user): bool {
        return $mail->hasTo($user->email);
    });
});

// ── Wiring: suppression ──────────────────────────────────────────────────

it('sends no welcome mail to a suppressed address', function () {
    Mail::fake();

    $payload = welcomeRegistrationPayload();

    // The same gate NotificationService applies to notification mail:
    // a hard-bounced/complained address recorded by the Resend webhook.
    EmailSuppression::create([
        'email' => $payload['email'],
        'reason' => 'hard_bounce',
        'source' => 'resend_webhook',
        'suppressed_at' => now(),
    ]);

    $this->post('/en/register', $payload);

    Mail::assertNotQueued(WelcomeEmail::class);
});

// ── Wiring: OAuth registration (the dispatch is load-bearing) ────────────

it('queues the welcome mail when a new user signs up through OAuth', function () {
    $socialiteUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $socialiteUser->shouldReceive('getId')->andReturn('54321');
    $socialiteUser->shouldReceive('getEmail')->andReturn('oauth-welcome@test.test');
    $socialiteUser->shouldReceive('getName')->andReturn('OAuth Welcome');
    $socialiteUser->shouldReceive('getNickname')->andReturn(null);
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'tok';
    $socialiteUser->refreshToken = null;

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    Mail::fake();

    $response = $this->get('/auth/google/callback');

    $user = User::where('email', 'oauth-welcome@test.test')->firstOrFail();

    // OAuthController dispatches Registered explicitly on its new-user
    // path — nothing else would queue the welcome mail for an OAuth
    // signup, so this assertion pins that dispatch.
    $response->assertRedirect(route('onboarding.index'));
    Mail::assertQueuedTimes(WelcomeEmail::class, 1);
    Mail::assertQueued(function (WelcomeEmail $mail) use ($user): bool {
        return $mail->hasTo($user->email);
    });
});
