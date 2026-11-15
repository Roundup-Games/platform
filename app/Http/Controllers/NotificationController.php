<?php

namespace App\Http\Controllers;

use App\Enums\NotificationCategory;
use App\Models\User;
use App\Services\NotificationEngagementCounters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationEngagementCounters $counters) {}

    /**
     * Handle a signed email unsubscribe link.
     * Sets the mail channel OFF for the given notification category.
     */
    public function unsubscribe(Request $request, string $locale, User $user, string $category): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            abort(403, __('notifications.unsubscribe_invalid_link'));
        }

        // Validate category exists
        $categoryEnum = NotificationCategory::tryFrom($category);
        if ($categoryEnum === null) {
            abort(404, __('notifications.unsubscribe_unknown_category'));
        }

        // notification_settings is cast to array in User model
        /** @var array<string, mixed> $settings */
        $settings = $user->notification_settings;
        $existingCategory = is_array($settings[$category] ?? null) ? $settings[$category] : [];
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

        $this->countUnsubscribe($category);

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
     */
    public function unsubscribeDigest(Request $request, string $locale, User $user): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            abort(403, __('notifications.unsubscribe_invalid_link'));
        }

        $user->update(['weekly_digest_enabled' => false]);

        $this->countUnsubscribe('digest');

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
