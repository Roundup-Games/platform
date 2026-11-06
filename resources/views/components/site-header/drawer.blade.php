{{--
    Mobile drawer (< 1140px) — native <dialog>: showModal() provides focus
    trapping, Escape and an inert backdrop; site-header.js wires open/close,
    backdrop-click dismissal and focus return. Grouped nav items become the
    "Project" group so the discovery row and utility links stay distinct.
--}}
@php
    $gameSystemsRouteExists = \Illuminate\Support\Facades\Route::has('game-systems');
    $howItWorksRouteExists = \Illuminate\Support\Facades\Route::has('how-it-works');
    $otherLocale = app()->getLocale() === 'en' ? 'de' : 'en';
    $segments = explode('/', request()->path(), 2);
    $localeRedirect = '/'.$otherLocale.'/'.($segments[1] ?? '');
    $navLink = 'flex min-h-13 w-full items-center rounded-2xl px-3.5 py-3.5 font-heading text-[17px] font-semibold text-on-surface transition-colors hover:bg-surface-container-high';
    $subLink = 'flex min-h-11.5 w-full items-center rounded-xl px-3.5 py-3 text-[15px] font-medium text-on-surface-variant transition-colors hover:bg-surface-container-high hover:text-primary';
@endphp
<dialog id="site-drawer"
        class="site-drawer m-0 ml-auto h-dvh w-[min(380px,94vw)] border-0 bg-surface p-0 text-on-surface"
        aria-label="{{ __('common.aria_toggle_navigation_menu') }}">
    <div class="flex h-full flex-col overflow-y-auto">
        <div class="flex h-16 shrink-0 items-center justify-between border-b border-outline-variant/15 pl-5 pr-4">
            <a href="{{ route('home') }}" wire:navigate>
                @include('partials.logo', ['class' => 'h-8 w-auto'])
            </a>
            <button type="button" id="site-drawer-close"
                    class="inline-grid size-10 place-items-center rounded-full border border-outline-variant/55 transition-colors hover:border-primary"
                    aria-label="{{ __('common.action_close') }}">
                <span class="material-symbols-outlined text-[20px]" aria-hidden="true">close</span>
            </button>
        </div>

        <nav class="grid gap-0.5 p-3" aria-label="{{ __('common.aria_main_navigation') }}">
            <a href="{{ route('discover') }}" wire:navigate class="{{ $navLink }} {{ request()->routeIs('discover') ? 'bg-primary/5 text-primary' : '' }}">{{ __('discovery.action_discover') }}</a>
            <a href="{{ route('gm.directory') }}" wire:navigate class="{{ $navLink }} {{ request()->routeIs('gm.directory') ? 'bg-primary/5 text-primary' : '' }}">{{ __('profile.nav_gm_directory') }}</a>
            <a href="{{ route('events.index') }}" wire:navigate class="{{ $navLink }} {{ request()->routeIs('events.*') ? 'bg-primary/5 text-primary' : '' }}">{{ __('events.content_events') }}</a>
            <a href="{{ $gameSystemsRouteExists ? route('game-systems') : url(app()->getLocale() . '/game-systems') }}" wire:navigate class="{{ $navLink }} {{ request()->routeIs('game-systems*') ? 'bg-primary/5 text-primary' : '' }}">{{ __('games.content_game_systems') }}</a>
            <a href="{{ route('venues.directory') }}" wire:navigate class="{{ $navLink }} {{ request()->routeIs('venues.*') ? 'bg-primary/5 text-primary' : '' }}">{{ __('venues.nav_venue_directory') }}</a>
        </nav>

        <div class="grid gap-0.5 px-3 pb-3">
            <span class="px-3.5 pb-1 pt-2 text-[11px] font-bold uppercase tracking-[0.08em] text-primary">
                {{ __('common.nav_project') }}
            </span>
            <a href="{{ route('about') }}" wire:navigate class="{{ $subLink }}">{{ __('pages.content_about') }}</a>
            <a href="{{ $howItWorksRouteExists ? route('how-it-works') : url(app()->getLocale() . '/how-it-works') }}" wire:navigate class="{{ $subLink }}">{{ __('pages.content_how_it_works') }}</a>
            <a href="{{ route('pledge', app()->getLocale()) }}" wire:navigate class="{{ $subLink }}">{{ __('common.nav_our_pledge') }}</a>
            <a href="{{ route('contact') }}" wire:navigate class="{{ $subLink }}">{{ __('common.content_contact') }}</a>
        </div>

        <div class="mt-auto grid gap-4 border-t border-outline-variant/15 px-5 pb-7 pt-4">
            <div class="grid gap-2">
                <span class="text-[11px] font-bold uppercase tracking-[0.08em] text-on-surface-variant">{{ __('common.content_language') }}</span>
                <div class="inline-flex w-full items-center gap-0.5 rounded-full border border-outline-variant/35 bg-surface-container-high p-[3px]" role="group" aria-label="{{ __('common.content_language') }}">
                    @foreach (['en' => 'English', 'de' => 'Deutsch'] as $code => $label)
                        <a href="{{ route('locale.switch', ['locale' => $code, 'redirect' => $localeRedirect]) }}"
                           aria-pressed="{{ app()->getLocale() === $code ? 'true' : 'false' }}"
                           class="flex h-8 flex-1 items-center justify-center rounded-full px-2.5 text-xs font-semibold text-on-surface-variant transition-colors hover:text-on-surface aria-pressed:bg-primary aria-pressed:text-on-primary{{ app()->getLocale() === $code ? ' pointer-events-none' : '' }}">
                            {{ $label }}
                        </a>
                    @endforeach
                </div>
            </div>
            <div class="grid gap-2">
                <span class="text-[11px] font-bold uppercase tracking-[0.08em] text-on-surface-variant">{{ __('common.content_appearance') }}</span>
                <x-theme-segments />
            </div>

            @guest
                <a href="{{ route('login') }}" wire:navigate
                   class="flex h-12 w-full items-center justify-center gap-2 rounded-2xl bg-primary text-[15px] font-semibold text-on-primary transition-[filter] hover:brightness-110">
                    <span class="material-symbols-outlined text-[20px]" aria-hidden="true">person_add</span>
                    {{ __('auth.content_sign_up') }}
                </a>
                <a href="{{ route('login') }}" wire:navigate
                   class="flex h-12 w-full items-center justify-center gap-2 rounded-2xl border border-outline-variant/70 text-[15px] font-semibold text-on-surface transition-colors hover:border-primary hover:text-primary">
                    <span class="material-symbols-outlined text-[20px]" aria-hidden="true">login</span>
                    {{ __('auth.content_log_in') }}
                </a>
            @else
                <a href="{{ route('dashboard') }}" wire:navigate
                   class="flex h-12 w-full items-center justify-center gap-2 rounded-2xl bg-primary text-[15px] font-semibold text-on-primary transition-[filter] hover:brightness-110">
                    <span class="material-symbols-outlined text-[20px]" aria-hidden="true">dashboard</span>
                    {{ __('profile.content_dashboard') }}
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            class="flex h-12 w-full items-center justify-center gap-2 rounded-2xl border border-outline-variant/70 text-[15px] font-semibold text-error transition-colors hover:border-error">
                        <span class="material-symbols-outlined text-[20px]" aria-hidden="true">logout</span>
                        {{ __('auth.content_log_out') }}
                    </button>
                </form>
            @endguest
        </div>
    </div>
</dialog>
