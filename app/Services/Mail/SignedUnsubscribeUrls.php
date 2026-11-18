<?php

namespace App\Services\Mail;

use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * The single generator for the signed unsubscribe URLs (M081/S02/T01).
 *
 * Every consumer builds its URL here: the mail body's unsubscribe line
 * (BaseNotification::unsubscribeLine), the digest opt-out action
 * (WeeklyDigest), and the RFC 8058 List-Unsubscribe headers stamped by
 * TaggedMailChannel. One generator means the URL the reader clicks, the
 * URL the one-click POST validates, and the URL the mail headers carry
 * can never drift apart — route name, parameter order, and signing stay
 * in lockstep by construction.
 */
class SignedUnsubscribeUrls
{
    /**
     * Signed URL that flips the mail channel off for one notification
     * category (NotificationController::unsubscribe). The same URL serves
     * the browser GET link (redirect UX) and the RFC 8058 one-click POST
     * (bare 200): both validate the identical signature over path and
     * query string, so the mail header can carry this URL verbatim.
     */
    public function forCategory(User $user, string $locale, string $category): string
    {
        return URL::signedRoute('notifications.unsubscribe', [
            'locale' => $locale,
            'user' => $user->id,
            'category' => $category,
        ]);
    }

    /**
     * Signed URL that opts a user out of the weekly digest
     * (NotificationController::unsubscribeDigest, weekly_digest_enabled=false).
     * Same GET/POST duality as {@see forCategory()} — the digest mail's
     * List-Unsubscribe header carries this URL for one-click clients.
     */
    public function forDigest(User $user, string $locale): string
    {
        return URL::signedRoute('notifications.unsubscribe-digest', [
            'locale' => $locale,
            'user' => $user->id,
        ]);
    }
}
