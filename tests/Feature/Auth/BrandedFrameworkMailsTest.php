<?php

use App\Enums\ContentLanguage;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;

// The createUrlUsing() stub is static state — reset it so later tests (and
// the dispatch test below) exercise the framework's real URL builder.
afterEach(function () {
    VerifyEmail::$createUrlCallback = null;
});

// ── Verify email ─────────────────────────────────────────

it('renders the framework verify-email mail branded and in the recipient locale', function () {
    $user = User::factory()->unverified()->create([
        'preferred_language' => ContentLanguage::De,
        'name' => 'Testnutzer',
    ]);

    // Control the framework-built signed URL so the assertion isolates mail
    // rendering (the URL itself is framework-owned).
    VerifyEmail::createUrlUsing(fn () => 'https://verify.roundup.test/link');

    // Mirror what the notification dispatcher does in production: set the
    // recipient's locale before rendering (direct toMail() calls don't).
    app()->setLocale('de');

    // K004: expectations are assembled through the translator with the same
    // parameters the provider passes — the copy may change freely.
    $mail = (new VerifyEmail)->toMail($user);

    expect($mail)->toBeInstanceOf(MailMessage::class)
        ->and($mail->subject)->toBe(__('emails.subject_verify_email', ['brand' => 'Roundup Games'], 'de'))
        ->and($mail->actionText)->toBe(__('emails.field_verify_email_address', [], 'de'))
        ->and($mail->actionUrl)->toBe('https://verify.roundup.test/link');

    $rendered = (string) $mail->render();
    expect($rendered)->toContain(__('emails.content_verify_email_intro', [], 'de'))
        ->and($rendered)->toContain(__('emails.content_verify_email_no_action', [], 'de'))
        // Markdown MailMessages render both HTML and plain-text parts;
        // the plain variant carries the same strings.
        ->and($mail->textView ?? $mail->markdown)->not->toBeEmpty();
});

it('renders the framework verify-email mail in English for English recipients', function () {
    $user = User::factory()->unverified()->create([
        'preferred_language' => ContentLanguage::En,
    ]);

    VerifyEmail::createUrlUsing(fn () => 'https://verify.roundup.test/link');
    app()->setLocale('en');

    $mail = (new VerifyEmail)->toMail($user);

    expect($mail->subject)->toBe(__('emails.subject_verify_email', ['brand' => 'Roundup Games'], 'en'))
        ->and($mail->actionText)->toBe(__('emails.field_verify_email_address', [], 'en'));
});

// ── Password reset ───────────────────────────────────────

it('renders the framework password-reset mail branded and in the recipient locale', function () {
    $user = User::factory()->create([
        'preferred_language' => ContentLanguage::De,
        'name' => 'Testnutzer',
    ]);

    app()->setLocale('de');

    $mail = (new ResetPassword('test-reset-token'))->toMail($user);

    expect($mail->subject)->toBe(__('emails.subject_reset_password', ['brand' => 'Roundup Games'], 'de'))
        ->and($mail->actionText)->toBe(__('auth.field_reset_password', [], 'de'))
        ->and($mail->actionUrl)->toContain('/de/reset-password/test-reset-token')
        ->and($mail->actionUrl)->toContain('email='.urlencode($user->email));

    $rendered = (string) $mail->render();
    expect($rendered)->toContain(__('emails.content_reset_password_expires', ['count' => config('auth.passwords.users.expire', 60)], 'de'))
        ->and($rendered)->toContain(__('emails.content_reset_password_no_action', [], 'de'));
});

it('builds the reset link from the recipient locale, not the ambient request locale', function () {
    // Ambient locale en, recipient prefers de: the link must follow the
    // recipient's preferredLocale() (the greeting copy follows the ambient
    // locale the dispatcher sets — the link never depends on it).
    $user = User::factory()->create(['preferred_language' => ContentLanguage::De]);

    app()->setLocale('en');

    $mail = (new ResetPassword('token-123'))->toMail($user);

    expect($mail->actionUrl)->toContain('/de/reset-password/token-123')
        ->and($mail->subject)->toBe('Reset your Roundup Games password');
});

it('falls back to the app locale when the recipient has no preferred language', function () {
    $user = User::factory()->create(['preferred_language' => null]);

    app()->setLocale('en');

    $mail = (new ResetPassword('token-123'))->toMail($user);

    expect($mail->actionUrl)->toContain('/en/reset-password/token-123');
});

// ── Dispatch wiring ──────────────────────────────────────

it('keeps the framework send paths dispatching the overridden notifications', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    $user->sendEmailVerificationNotification();
    Notification::assertSentTo($user, VerifyEmail::class);

    $user->sendPasswordResetNotification('test-reset-token');
    Notification::assertSentTo($user, ResetPassword::class);
});

// ── OAuth unverified-email rejection ─────────────────────

it('translates the OAuth unverified-email rejection in both locales', function () {
    // K004: pin the wiring invariants — the message resolves (never the raw
    // key), interpolates the provider, and DE is a real translation rather
    // than an English fallback. The wording itself lives in lang/.
    $en = __('auth.error_oauth_email_unverified', ['provider' => 'Discord'], 'en');
    $de = __('auth.error_oauth_email_unverified', ['provider' => 'Discord'], 'de');

    expect($en)
        ->not->toBe('auth.error_oauth_email_unverified')
        ->toContain('Discord');

    expect($de)
        ->not->toBe('auth.error_oauth_email_unverified')
        ->not->toBe($en)
        ->toContain('Discord');
});
