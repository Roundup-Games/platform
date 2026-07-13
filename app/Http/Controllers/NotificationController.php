<?php

namespace App\Http\Controllers;

use App\Enums\NotificationCategory;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
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
        if (! $categoryEnum) {
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

        // If the user is logged in and is the same user, redirect to profile with flash message
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
}
