<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\TimezoneCaptureService;
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
     * (Intl.DateTimeFormat().resolvedOptions().timeZone). TimezoneCapture-
     * Service owns the contract: alias-tolerant canonicalization, no write
     * when unchanged, and NEVER a write over a manually-set profile zone.
     * Silent degradation everywhere: absent input (no JS, API), a
     * non-string, or an identifier the DateTimeZone constructor rejects
     * stores nothing — the render side keeps its 'Europe/Berlin' fallback
     * via null-coalescing. Runs on EVERY form login so a travelling user's
     * zone refreshes automatically.
     */
    private function captureLoginTimezone(LoginRequest $request): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return;
        }

        app(TimezoneCaptureService::class)->capture($user, $request->input('timezone'));
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
