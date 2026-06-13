<?php

namespace App\Http\Middleware;

use Filament\Facades\Filament;
use Illuminate\Auth\Middleware\Authenticate as Middleware;

class AuthenticateFilament extends Middleware
{
    /**
     * Redirect unauthenticated guests to the platform login page
     * (with an intended URL back to the admin panel).
     */
    protected function redirectTo($request): ?string
    {
        if (! $request->expectsJson()) {
            return route('login');
        }

        return null;
    }

    /**
     * Authenticate the user and verify panel access.
     *
     * - Guests → redirect to platform login
     * - Authenticated without panel access → 403 (handled by our exception handler)
     *
     * @param  array<string, mixed>  $guards
     */
    protected function authenticate($request, array $guards): void
    {
        $guard = Filament::auth();

        if (! $guard->check()) {
            $this->unauthenticated($request, $guards);
        }

        $this->auth->shouldUse(Filament::getAuthGuard());

        $user = $guard->user();

        if (! $user) {
            $this->unauthenticated($request, $guards);
        }

        $panel = Filament::getCurrentOrDefaultPanel();

        abort_unless($panel && $user->canAccessPanel($panel), 403);
    }
}
