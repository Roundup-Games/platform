<?php

namespace App\Support\Livewire;

use Illuminate\Support\Facades\URL;
use Livewire\ComponentHook;

/**
 * Keep the visitor's locale in URL::defaults() across Livewire update requests.
 *
 * {locale} is a required route parameter, so every route() call fills it from
 * URL::defaults(). A Livewire update request (the POST to Livewire's update
 * endpoint, outside the {locale} group) has no {locale} segment and never
 * meets SetLocale; the only middleware that touches the default is the
 * global EnsureLocaleDefaults, which runs before StartSession and therefore
 * only ever pins the fallback locale. Livewire's own
 * SupportLocales restores the app locale (translations) from the snapshot
 * memo, but not the URL default — so a redirect and every re-rendered link
 * came out in the fallback language.
 *
 * This hook rides the memo key SupportLocales writes on every dehydrate: no
 * snapshot growth, no new client input. Hydrate completes before the
 * component's actions and render, so every route() of that request sees the
 * visitor's locale. It is idempotent, and every component of a page shares
 * the one memo locale.
 *
 * Registered in AppServiceProvider::register(), not boot(): Livewire wires
 * its component hooks once, inside LivewireServiceProvider::boot(), and app
 * providers boot after package providers — a hook added in boot() is never
 * called.
 */
final class RestoreLocaleUrlDefaults extends ComponentHook
{
    /**
     * Read the memo directly — never app()->getLocale() — so this does not
     * depend on the order Livewire calls its hooks in.
     *
     * @param  array<string, mixed>  $memo
     */
    public function hydrate(array $memo): void
    {
        $locale = $memo['locale'] ?? null;
        $available = config('app.available_locales');

        // The memo travels through the browser. Livewire checks the snapshot
        // checksum before hydrate; this second gate costs nothing.
        if (! is_string($locale) || ! is_array($available) || ! in_array($locale, $available, true)) {
            return;
        }

        URL::defaults(['locale' => $locale]);
    }
}
