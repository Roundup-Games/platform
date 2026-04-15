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
     * On login: the auth attempt fails because Auth::attempt uses the
     * retriever which doesn't filter by is_disabled. Instead we check
     * immediately after authentication and log them back out.
     *
     * On subsequent requests: disabled users are kicked out and their
     * session is invalidated.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isDisabled()) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('root')->with('status', __('Your account has been disabled.'));
        }

        return $next($request);
    }
}
