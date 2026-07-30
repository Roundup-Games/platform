<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserNotDisabled
{
    /**
     * Block disabled users from authenticated routes.
     *
     * Ejection is defense-in-depth: the admin:user:disable command already
     * wipes the sessions table at disable time. This guard catches the residual
     * paths the command can't reach — re-login (Auth::attempt does not filter
     * is_disabled, so a disabled user can still establish a fresh session),
     * remember-me restoration, and API requests authenticated by a Sanctum token
     * (the command does not revoke tokens).
     *
     * JSON/token clients (auth:sanctum) get a clean 401 so they can react
     * programmatically; session/web clients are logged out and redirected to
     * the landing page.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isDisabled()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => __('auth.error_your_account_has_been_disabled'),
                ], 401);
            }

            Auth::guard('web')->logout();

            // Guard against session-less request paths; invalidate when a
            // session is bound so the ejection sticks across the redirect.
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return redirect()->route('root')->with('status', __('auth.error_your_account_has_been_disabled'));
        }

        return $next($request);
    }
}
