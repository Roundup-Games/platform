<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Resolve the active locale from the URL, persist it in session, and
     * set URL defaults so every route() call auto-injects the {locale} parameter.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->route('locale');

        if (! in_array($locale, config('app.available_locales'), true)) {
            abort(404);
        }

        app()->setLocale($locale);

        session(['locale' => $locale]);

        URL::defaults(['locale' => $locale]);

        return $next($request);
    }
}
