<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    /**
     * Switch the active locale, persist it in session, and redirect.
     */
    public function switch(Request $request, string $locale): RedirectResponse
    {
        $locales = config('app.available_locales');
        if (! is_array($locales) || ! in_array($locale, $locales, true)) {
            abort(400);
        }

        session(['locale' => $locale]);

        $redirect = $request->query('redirect', '/');

        // Prevent open redirects — only allow relative paths
        if ($redirect !== '/'.$locale.'/' && ! str_starts_with($redirect, '/'.$locale.'/')) {
            $redirect = '/'.$locale.'/';
        }

        return redirect($redirect);
    }
}
