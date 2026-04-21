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

        // Update the user's notification settings: disable mail for this category
        $settings = $user->notification_settings ?? NotificationCategory::defaultSettings();
        $settings[$category] = [
            'database' => $settings[$category]['database'] ?? true,
            'mail' => false,
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
