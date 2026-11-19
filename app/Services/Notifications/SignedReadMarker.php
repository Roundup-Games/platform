<?php

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;

/**
 * The single generator/validator for the signed cross-channel read-marker
 * URLs (D288 item 1, M081/S08).
 *
 * Mail CTAs and push action URLs wrap their landing target in this marker:
 * hitting it marks the sibling in-app notification read via the existing
 * NotificationService::markReadByType machinery (idempotent) and 302s to
 * the real target — so the bell badge tells the truth after the user acts
 * on ANY channel, not just in-app.
 *
 * The marker binds (user, notification class, entity) — deliberately NOT
 * the notifications row id: the row may not exist yet when mail/push URLs
 * are rendered (channel jobs run independently of the database write), and
 * markReadByType already keys on type + entity. The HMAC signature makes
 * the triple unenumerable; the marker itself is the authorization (no
 * login required), which is safe because it can only ever mark rows of
 * the user it names.
 *
 * One generator shared by BaseNotification::actionUrlWithMarker and the
 * read_marker controller validation — the same shape SignedUnsubscribeUrls
 * established for the unsubscribe surface: URL the reader clicks and URL
 * the controller validates can never drift apart.
 */
class SignedReadMarker
{
    /**
     * Data keys an entity binding may reference — a deliberate SUBSET of
     * the keys NotificationService::markReadByType whitelists (which also
     * allows the 'type' discriminator): markers bind ENTITIES, not payload
     * discriminators, so 'type' is excluded here on purpose.
     *
     * @var list<string>
     */
    public const ALLOWED_DATA_KEYS = ['entity_id', 'game_id', 'campaign_id', 'team_id', 'participant_id'];

    /**
     * Signed marker URL for (user, notification type, entity) that marks
     * the matching in-app notifications read, then redirects to $redirect.
     *
     * $entityId/$dataKey are both null for one-off notices whose database
     * payload carries no whitelisted id key — the marker then binds
     * user+type and markReadByType clears every unread row of that type.
     *
     * $category (M082 review, D294 rider 1) carries the UNDERLYING
     * notification category for classes whose basename is not an enum
     * case — BatchedNotification landings must attribute to the burst's
     * category (participant_joined …), not a pseudo-category, so the
     * read_marker funnel joins the per-category rows its mail/push
     * deliveries already count under. The signature covers it like every
     * other param; the controller additionally validates it against the
     * enum before trusting it.
     *
     * @param  int|string|null  $entityId  Value stored under $dataKey in the row's data payload.
     * @param  string|null  $dataKey  Payload key the entity id lives under (null → markReadByType default 'entity_id' / no entity filter).
     * @param  string|null  $category  Underlying category value for counter attribution (null → controller basename fallback).
     */
    public function build(User $user, string $locale, string $notificationType, int|string|null $entityId, ?string $dataKey, string $redirect, ?string $category = null): string
    {
        if ($dataKey !== null && ! in_array($dataKey, self::ALLOWED_DATA_KEYS, true)) {
            throw new InvalidArgumentException("SignedReadMarker: data key [{$dataKey}] is not an allowed entity binding key — use one of SignedReadMarker::ALLOWED_DATA_KEYS.");
        }

        return URL::signedRoute('notifications.read_marker', array_filter([
            'locale' => $locale,
            'user' => $user->id,
            'type' => $notificationType,
            'entity' => $entityId,
            'key' => $dataKey,
            'category' => $category,
            'redirect' => $redirect,
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * Open-redirect guard for the marker's redirect target: it must be a
     * root-relative app path.
     *
     * Rules: leading '/'; never protocol-relative ('//'); no backslash
     * (browsers normalize '\' to '/', so '/\evil.test' would re-parse as a
     * host); no scheme separator (':') or userinfo marker ('@') in the path
     * portion — colons stay legal inside query/fragment where they occur in
     * legitimate values (e.g. timestamp filters).
     */
    public function safeRedirect(string $redirect): bool
    {
        if ($redirect === '' || ! str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
            return false;
        }

        if (str_contains($redirect, '\\')) {
            return false;
        }

        $parts = preg_split('/[?#]/', $redirect, 2);
        $path = is_array($parts) ? $parts[0] : '';

        return ! str_contains($path, ':') && ! str_contains($path, '@');
    }
}
