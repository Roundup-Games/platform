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

    $mail = (new VerifyEmail)->toMail($user);

    expect($mail)->toBeInstanceOf(MailMessage::class)
        ->and($mail->subject)->toBe('Bestätige deine E-Mail-Adresse bei Roundup Games')
        ->and($mail->actionText)->toBe('E-Mail-Adresse bestätigen')
        ->and($mail->actionUrl)->toBe('https://verify.roundup.test/link');

    $rendered = (string) $mail->render();
    expect($rendered)->toContain('Danke für deine Registrierung!')
        ->and($rendered)->toContain('Falls du kein Konto erstellt hast, ist keine weitere Aktion erforderlich.')
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

    expect($mail->subject)->toBe('Verify your email address for Roundup Games')
        ->and($mail->actionText)->toBe('Verify Email Address');
});

// ── Password reset ───────────────────────────────────────

it('renders the framework password-reset mail branded and in the recipient locale', function () {
    $user = User::factory()->create([
        'preferred_language' => ContentLanguage::De,
        'name' => 'Testnutzer',
    ]);

    app()->setLocale('de');

    $mail = (new ResetPassword('test-reset-token'))->toMail($user);

    expect($mail->subject)->toBe('Setze dein Roundup Games-Passwort zurück')
        ->and($mail->actionText)->toBe('Passwort zurücksetzen')
        ->and($mail->actionUrl)->toContain('/de/reset-password/test-reset-token')
        ->and($mail->actionUrl)->toContain('email='.urlencode($user->email));

    $rendered = (string) $mail->render();
    expect($rendered)->toContain('läuft in 60 Minuten ab')
        ->and($rendered)->toContain('Falls du kein Zurücksetzen deines Passworts angefordert hast');
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
    expect(__('auth.error_oauth_email_unverified', ['provider' => 'Discord'], 'en'))
        ->toBe('Discord reports this email as unverified. Please verify it with Discord or sign in another way.')
        ->and(__('auth.error_oauth_email_unverified', ['provider' => 'Discord'], 'de'))
        ->toBe('Discord meldet diese E-Mail-Adresse als unbestätigt. Bitte bestätige sie bei Discord oder melde dich auf einem anderen Weg an.');
});
