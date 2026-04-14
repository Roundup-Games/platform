<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

class LocaleController extends Controller
{
    /**
     * Switch the active locale, persist it in session, and redirect.
     */
    public function switch(Request $request, string $locale): RedirectResponse
    {
        if (! in_array($locale, config('app.available_locales'), true)) {
            abort(400);
        }

        session(['locale' => $locale]);

        $redirect = $request->query('redirect', '/');

        // Prevent open redirects — only allow relative paths
        if ($redirect !== '/' . $locale . '/' && ! str_starts_with($redirect, '/' . $locale . '/')) {
            $redirect = '/' . $locale . '/';
        }

        return redirect($redirect);
    }
}
