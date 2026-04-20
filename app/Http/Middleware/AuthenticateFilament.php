<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuthenticateFilament extends Middleware
{
    /**
     * Redirect unauthenticated guests to the platform login page
     * (with an intended URL back to the admin panel).
     */
    protected function redirectTo($request): ?string
    {
        if (!$request->expectsJson()) {
            return route('login');
        }

        return null;
    }

    /**
     * Authenticate the user and verify panel access.
     *
     * - Guests → redirect to platform login
     * - Authenticated without panel access → 403 (handled by our exception handler)
     */
    protected function authenticate($request, array $guards): void
    {
        $guard = Filament::auth();

        if (!$guard->check()) {
            $this->unauthenticated($request, $guards);

            return;
        }

        $this->auth->shouldUse(Filament::getAuthGuard());

        /** @var Model $user */
        $user = $guard->user();

        $panel = Filament::getCurrentOrDefaultPanel();

        abort_if(
            $user instanceof FilamentUser
                ? (!$user->canAccessPanel($panel))
                : (config('app.env') !== 'local'),
            403,
        );
    }
}
