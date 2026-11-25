<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarantee a `locale` default for every route outside the {locale} prefix
 * group, so route() never throws MissingRequiredParameterException there.
 *
 * {locale} is a required route parameter. SetLocale fills the default inside
 * the group; Livewire update endpoints (and other out-of-group routes) never
 * pass through it, and without a default any route() call in a Blade template
 * during a Livewire re-render would throw.
 *
 * This is global middleware and runs before the `web` group's StartSession, so
 * on a real request the session read below sees no started session: only the
 * fallback locale is ever applied here. Inside the group SetLocale then
 * overrides it with the authoritative URL parameter. Livewire update requests
 * get the visitor's locale from App\Support\Livewire\RestoreLocaleUrlDefaults
 * (M084/S09). The session read stays in place on purpose — removing or
 * relocating it is a follow-up, outside this slice.
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
