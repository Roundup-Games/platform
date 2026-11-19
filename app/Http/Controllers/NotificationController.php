<?php

namespace App\Http\Controllers;

use App\Enums\NotificationCategory;
use App\Models\User;
use App\Services\NotificationEngagementCounters;
use App\Services\Notifications\SignedReadMarker;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationEngagementCounters $counters,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * Handle a signed email unsubscribe link.
     * Sets the mail channel OFF for the given notification category.
     *
     * Two-step flow (M081 fifth-pass review, invite-optout precedent): GET
     * renders a confirmation page and performs NO write — URL-sandboxing
     * mail scanners (Microsoft SafeLinks, Proofpoint URL defense) server-
     * side-GET every link in a mail, and a bare-GET write let them silently
     * disable categories the user never touched. POST performs the write:
     * the RFC 8058 one-click POST (no form fields) answers the bare 200
     * mailbox-provider clients require, while the human confirmation form
     * (carrying _from=link) gets the redirect-with-flash UX. The counter
     * fires only when mail was actually ON (scanner replays and repeat
     * clicks must not inflate the D284 funnel).
     */
    public function unsubscribe(Request $request, string $locale, User $user, string $category): Response|RedirectResponse|View
    {
        if (! $request->hasValidSignature()) {
            abort(403, __('notifications.unsubscribe_invalid_link'));
        }

        // Validate category exists
        $categoryEnum = NotificationCategory::tryFrom($category);
        if ($categoryEnum === null) {
            abort(404, __('notifications.unsubscribe_unknown_category'));
        }

        // Scanner defense: the confirmation step. The form POSTs back to the
        // exact signed URL (fullUrl keeps the signature query intact).
        if ($request->isMethod('get')) {
            return view('pages.notification-unsubscribe', [
                'unsubscribeTarget' => $categoryEnum->label(),
                'actionUrl' => $request->fullUrl(),
            ]);
        }

        // notification_settings is cast to array in User model
        /** @var array<string, mixed> $settings */
        $settings = $user->notification_settings;
        $existingCategory = is_array($settings[$category] ?? null) ? $settings[$category] : [];
        $wasMailEnabled = (bool) ($existingCategory['mail'] ?? true);
        // Unsubscribe means "stop emailing me about this category" — it must not
        // touch the in-app or push channels, which the user manages separately
        // in their settings page. Preserving all three keys also keeps the
        // stored record schema-consistent (database/mail/push).
        $settings[$category] = [
            'database' => (bool) ($existingCategory['database'] ?? true),
            'mail' => false,
            'push' => (bool) ($existingCategory['push'] ?? false),
        ];

        $user->update(['notification_settings' => $settings]);

        // Count the funnel only on an actual state change: the settings write
        // is idempotent, so a scanner-fed POST replay or a double click must
        // not write a second unsubscribed event for one human opt-out.
        if ($wasMailEnabled) {
            $this->countUnsubscribe($category);
        }

        // RFC 8058: a one-click client expects a plain 200 — no
        // redirect, no body — before it will treat the opt-out as done.
        if (! $request->filled('_from')) {
            return response('', 200);
        }

        if (Auth::check() && Auth::id() === $user->id) {
            return redirect()
                ->route('profile.show', ['locale' => $locale])
                ->with('status', __('notifications.unsubscribe_success', ['category' => $categoryEnum->label()]));
        }

        // For non-logged-in users, redirect to home with flash message
        return redirect()
            ->route('home', ['locale' => $locale])
            ->with('status', __('notifications.unsubscribe_success', ['category' => $categoryEnum->label()]));
    }

    /**
     * Handle a signed one-click opt-out from the weekly digest email.
     *
     * The digest is controlled by the weekly_digest_enabled flag, not the
     * per-category channel matrix, so it gets its own signed route. A recurring
     * automated email must offer a no-login opt-out path (CAN-SPAM / GDPR).
     *
     * Same two-step scanner-safe shape as unsubscribe(): GET renders the
     * confirmation page (no write), POST performs it — bare 200 for RFC
     * 8058 one-click clients (no form fields), redirect-with-flash for the
     * human confirmation form (_from=link). Counter fires only on an actual
     * enabled→disabled transition.
     */
    public function unsubscribeDigest(Request $request, string $locale, User $user): Response|RedirectResponse|View
    {
        if (! $request->hasValidSignature()) {
            abort(403, __('notifications.unsubscribe_invalid_link'));
        }

        if ($request->isMethod('get')) {
            return view('pages.notification-unsubscribe', [
                'unsubscribeTarget' => __('notifications.heading_weekly_digest'),
                'actionUrl' => $request->fullUrl(),
            ]);
        }

        $wasEnabled = $user->weekly_digest_enabled;

        $user->update(['weekly_digest_enabled' => false]);

        if ($wasEnabled) {
            $this->countUnsubscribe('digest');
        }

        if (! $request->filled('_from')) {
            // RFC 8058: bare 200 for one-click clients (see unsubscribe()).
            return response('', 200);
        }

        if (Auth::check() && Auth::id() === $user->id) {
            return redirect()
                ->route('profile.show', ['locale' => $locale])
                ->with('status', __('notifications.unsubscribe_digest_success'));
        }

        return redirect()
            ->route('home', ['locale' => $locale])
            ->with('status', __('notifications.unsubscribe_digest_success'));
    }

    /**
     * Handle a signed cross-channel read-marker landing (D288 item 1,
     * M081/S08): mail CTA / push action URLs hit this before the real
     * target, the sibling in-app notification is marked read via
     * markReadByType (idempotent), then the user is redirected.
     *
     * Authorization is the signature, not the session: the marker is
     * user-bound and unguessable, so a logged-in mismatch is harmless — the
     * handler can only ever write rows of the {user} it names (the route
     * model binding), never the authenticated viewer's. Invalid signature
     * or an unsafe redirect target is a flat 404 — existence-neutral (the
     * landing is a click-through, unlike the human-facing unsubscribe link
     * that explains its 403).
     */
    public function readMarker(Request $request, string $locale, User $user): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            abort(404);
        }

        $redirect = $request->query('redirect');

        if (! is_string($redirect) || ! app(SignedReadMarker::class)->safeRedirect($redirect)) {
            // Valid signature but an unsafe target: never redirect, never
            // mark — a signed URL must not smuggle an open redirect.
            abort(404);
        }

        $type = $request->query('type');
        $entity = $request->query('entity');
        $key = $request->query('key');

        // Numeric entity ids arrive as query strings; markReadByType binds
        // them against JSON payload values, so ints stay ints.
        $entityId = is_numeric($entity) ? (int) $entity : (is_string($entity) && $entity !== '' ? $entity : null);

        $this->notificationService->markReadByType(
            $user,
            is_string($type) ? $type : '',
            $entityId,
            is_string($key) && $key !== '' ? $key : null,
        );

        // D294 rider 1: the landing itself is a signal — daily volume per
        // category of markers users actually arrive on (cross-channel
        // click-through, the mail/push counterpart of the in-app open).
        // Both guards above already passed, so tampered-signature and
        // unsafe-redirect 404s return before this line and are never
        // counted as landings.
        $this->countReadMarkerLanding($type, $request->query('category'));

        return redirect()->to($redirect);
    }

    /**
     * Best-effort read-marker landing counter (D294 rider 1, M082/S03):
     * the mark-read write above is the load-bearing behavior, so a counter
     * failure is logged and swallowed (countUnsubscribe pattern) — the
     * redirect must never degrade. Daily volume per category is the
     * signal; landings are idempotent-safe to double-count (a repeat
     * click is a repeat landing) and need no distinct-actor semantics.
     */
    private function countReadMarkerLanding(mixed $type, mixed $category = null): void
    {
        $resolved = $this->resolveMarkerCategory($type, $category);

        try {
            $this->counters->incrementFor($resolved, 'read_marker', 'landed');
        } catch (Throwable $e) {
            Log::warning('notifications.engagement_counter_failed', [
                'category' => $resolved,
                'channel' => 'read_marker',
                'event' => 'landed',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Best-effort category for a marker landing. The marker's type query
     * param carries a notification CLASS name (static::class), not a
     * category value: class_basename() is matched against
     * NotificationCategory case names (NewFollower → new_follower),
     * falling back to the raw basename string — the same basename-fallback
     * philosophy as ResolvesNotificationCategory, so unknown classes
     * attribute under their basename instead of throwing.
     *
     * The optional category param (M082 review) resolves the attribution
     * for classes whose basename is not an enum case: BatchedNotification
     * markers carry the UNDERLYING category, so their landings join the
     * per-category rows the batched mail/push deliveries already count
     * under instead of splitting the D284 funnel into a pseudo-category.
     * The value is enum-validated — the signature already covers it, and
     * an unknown value falls through to the basename mapping as before.
     */
    private function resolveMarkerCategory(mixed $type, mixed $category = null): string
    {
        if (is_string($category) && $category !== '') {
            $validated = NotificationCategory::tryFrom($category);

            if ($validated !== null) {
                return $validated->value;
            }
        }

        if (! is_string($type) || $type === '') {
            return 'untagged';
        }

        $basename = class_basename($type);

        foreach (NotificationCategory::cases() as $case) {
            if ($case->name === $basename) {
                return $case->value;
            }
        }

        return $basename;
    }

    /**
     * Best-effort unsubscribe counter (D284, M080/S03): the settings write
     * above is the load-bearing behavior, so a counter failure is logged and
     * swallowed — it must never turn a successful unsubscribe into an error
     * page for the user.
     */
    private function countUnsubscribe(string $category): void
    {
        try {
            $this->counters->incrementFor($category, 'mail', 'unsubscribed');
        } catch (Throwable $e) {
            Log::warning('notifications.engagement_counter_failed', [
                'category' => $category,
                'channel' => 'mail',
                'event' => 'unsubscribed',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
