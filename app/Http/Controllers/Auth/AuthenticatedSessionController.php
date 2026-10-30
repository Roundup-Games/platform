<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $this->captureLoginTimezone($request);

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Persist the login device's IANA timezone on the user (R089).
     *
     * The login form posts a hidden `timezone` input filled by inline JS
     * (Intl.DateTimeFormat().resolvedOptions().timeZone). Silent degradation
     * everywhere: absent input (no JS, OAuth callback, API), a non-string,
     * or an identifier unknown to DateTimeZone stores nothing — the render
     * side keeps its 'Europe/Berlin' fallback via null-coalescing. Runs on
     * EVERY form login so a travelling user's zone refreshes automatically;
     * it only writes when the value actually changed.
     */
    private function captureLoginTimezone(LoginRequest $request): void
    {
        $timezone = $request->input('timezone');

        if (! is_string($timezone) || ! in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            return;
        }

        $user = Auth::user();
        if (! $user instanceof User || $user->timezone === $timezone) {
            return;
        }

        $user->forceFill(['timezone' => $timezone])->saveQuietly();
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect()->route('root')
            ->header('Clear-Site-Data', '"cache", "storage"');
    }
}
