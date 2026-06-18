<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
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
        $locales = config('app.available_locales');

        if (! is_string($locale) || ! is_array($locales) || ! in_array($locale, $locales, true)) {
            abort(404);
        }

        app()->setLocale($locale);

        // Keep Carbon's locale in sync with the app locale so diffForHumans()
        // (session rows, bulletins, activity feed, reviews, …) renders in the
        // visitor's language rather than always English.
        Carbon::setLocale($locale);

        session(['locale' => $locale]);

        URL::defaults(['locale' => $locale]);

        return $next($request);
    }
}
