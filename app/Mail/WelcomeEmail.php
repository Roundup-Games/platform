<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\SerializesModels;

/**
 * Purpose-written welcome mail (M081/S03): ONE clear CTA — complete your
 * profile, the onboarding flow every fresh signup is gated on — never a
 * stack of buttons.
 *
 * Dispatch: SendWelcomeEmail (app/Listeners) behind
 * Illuminate\Auth\Events\Registered, which BOTH signup paths fire — the
 * manual form (RegisteredUserController::store) and OAuth signup
 * (OAuthController dispatches Registered explicitly after its
 * signup-race/slug-race retry blocks resolve). Suppressed addresses
 * (EmailSuppression) are dropped by the listener before sending.
 *
 * Rendering: markdown view emails/welcome — the mail:: components
 * inherit the S01 roundup theme automatically. Mailables do NOT render
 * through the published notifications wrapper (that executes only for
 * notification MailMessages), so the inbox preheader is embedded in the
 * blade itself: a hidden div as the first body element, fed from the
 * localized content data below.
 *
 * Locale: envelope()/content() evaluate at send time inside Laravel's
 * withLocale wrapper, and Mail::to($user) resolves the recipient's
 * preferredLocale() automatically (User implements HasLocalePreference).
 * Without a stored preference the app default applies — identical to
 * every other mailable.
 */
#[Backoff([10, 60])]
class WelcomeEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('events.content_welcome_to_roundup_games', ['brand' => is_string($b = config('company.display_name')) ? $b : '']),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.welcome',
            with: [
                // Hidden inbox preview line — rendered by the welcome blade
                // as the first body element (see the blade comment).
                'preheader' => __('notifications.preheader_welcome_email'),
                // The single CTA target: onboarding (the complete-profile
                // gate). Locale-prefixed like every mail URL, resolved at
                // send time under the recipient's locale.
                'ctaUrl' => (is_string($appUrl = config('app.url')) ? $appUrl : '').'/'.app()->getLocale().'/onboarding',
            ],
        );
    }
}
