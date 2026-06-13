<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensure URL::defaults(['locale' => ...]) is set for ALL requests.
 *
 * The SetLocale middleware handles this for routes inside the {locale} prefix group,
 * but Livewire update endpoints (and other out-of-group routes) never pass through it.
 * Without URL::defaults(), any route() call in Blade templates during Livewire re-renders
 * throws MissingRequiredParameterException.
 *
 * This middleware reads the locale from session (set by SetLocale on the initial page load)
 * and restores the defaults. It runs before SetLocale, so when SetLocale does fire it
 * simply overrides with the authoritative route parameter value.
 */
class EnsureLocaleDefaults
{
    public function handle(Request $request, Closure $next): Response
    {
        // Only set defaults if they haven't been set already (e.g. by SetLocale middleware)
        $defaults = URL::getDefaultParameters();

        if (! isset($defaults['locale'])) {
            $locale = session('locale', config('app.fallback_locale'));
            $locales = config('app.available_locales');

            if (is_string($locale) && is_array($locales) && in_array($locale, $locales, true)) {
                URL::defaults(['locale' => $locale]);
            }
        }

        return $next($request);
    }
}
