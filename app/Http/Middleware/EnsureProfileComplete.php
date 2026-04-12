<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProfileComplete
{
    /**
     * Redirect authenticated users to onboarding if their profile is incomplete.
     * Skip for onboarding routes themselves and logout.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->profile_complete) {
            $allowedRoutes = [
                'onboarding.index',
                'onboarding.complete',
                'logout',
                'profile.show',
                'profile.edit-form',
                'profile.edit',
                'profile.update',
                'profile.destroy',
            ];

            if (! in_array($request->route()?->getName(), $allowedRoutes)) {
                return redirect()->route('onboarding.index');
            }
        }

        return $next($request);
    }
}
