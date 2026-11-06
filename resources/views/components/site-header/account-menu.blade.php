{{--
    Account popover — composes the utility cluster (theme, language, auth,
    contact, cookie settings) behind the account chip. Native Popover API:
    light dismiss, Escape, top layer; site-header.js pins it under its
    trigger and syncs aria-expanded.
--}}
@php
    $otherLocale = app()->getLocale() === 'en' ? 'de' : 'en';
    $segments = explode('/', request()->path(), 2);
    $localeRedirect = '/'.$otherLocale.'/'.($segments[1] ?? '');
    $row = 'flex h-11 w-full items-center gap-2.5 rounded-xl px-3 text-start text-sm font-medium text-on-surface transition-colors hover:bg-surface-container-high hover:text-primary';
    $rowIcon = 'material-symbols-outlined text-[20px] text-on-surface-variant';
@endphp
<div id="account-menu"
     class="site-popover grid w-[min(340px,calc(100vw-2rem))] gap-0.5 overflow-y-auto p-2"
     popover="auto"
     aria-label="{{ __('profile.content_account') }}">
    <div class="flex items-baseline justify-between gap-3 px-3 pb-1 pt-2.5">
        <span class="text-[11px] font-bold uppercase tracking-[0.08em] text-primary">{{ __('profile.content_account') }}</span>
        @guest
            <span class="max-w-[60%] truncate text-[13px] font-semibold text-on-surface-variant">{{ __('auth.label_guest') }}</span>
        @endguest
        @auth
            <span class="max-w-[60%] truncate text-[13px] font-semibold text-on-surface-variant" data-ph-mask>{{ auth()->user()->name }}</span>
        @endauth
    </div>

    <div class="grid gap-2 px-3 pb-2.5 pt-2">
        <span class="text-[11px] font-bold uppercase tracking-[0.08em] text-on-surface-variant">{{ __('common.content_appearance') }}</span>
        <x-theme-segments />
    </div>

    <div class="grid gap-2 px-3 pb-2.5">
        <span class="text-[11px] font-bold uppercase tracking-[0.08em] text-on-surface-variant">{{ __('common.content_language') }}</span>
        <div class="inline-flex w-full items-center gap-0.5 rounded-full border border-outline-variant/35 bg-surface-container-high p-[3px]"
             role="group"
             aria-label="{{ __('common.content_language') }}">
            @foreach (['en' => 'English', 'de' => 'Deutsch'] as $code => $label)
                <a href="{{ route('locale.switch', ['locale' => $code, 'redirect' => $localeRedirect]) }}"
                   aria-pressed="{{ app()->getLocale() === $code ? 'true' : 'false' }}"
                   class="flex h-8 flex-1 items-center justify-center rounded-full px-2.5 text-xs font-semibold text-on-surface-variant transition-colors hover:text-on-surface aria-pressed:bg-primary aria-pressed:text-on-primary{{ app()->getLocale() === $code ? ' pointer-events-none' : '' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>
    </div>

    <hr class="mx-1 my-1 border-t border-outline-variant/35">

    @guest
        <a href="{{ route('login') }}" wire:navigate class="{{ $row }}">
            <span class="{{ $rowIcon }}" aria-hidden="true">login</span>
            {{ __('auth.content_log_in') }}
        </a>
    @else
        <a href="{{ route('dashboard') }}" wire:navigate
           class="flex h-11 w-full items-center gap-2.5 rounded-xl bg-primary/10 px-3 text-start text-sm font-semibold text-primary transition-colors hover:bg-primary/15">
            <span class="material-symbols-outlined text-[20px] text-primary" aria-hidden="true">dashboard</span>
            {{ __('profile.content_dashboard') }}
        </a>
        <a href="{{ route('profile.show') }}" wire:navigate class="{{ $row }}">
            <span class="{{ $rowIcon }}" aria-hidden="true">person</span>
            {{ __('profile.content_profile') }}
        </a>
        <a href="{{ route('settings.show') }}" wire:navigate class="{{ $row }}">
            <span class="{{ $rowIcon }}" aria-hidden="true">settings</span>
            {{ __('profile.content_settings') }}
        </a>
    @endguest

    <a href="{{ route('contact') }}" wire:navigate class="{{ $row }}">
        <span class="{{ $rowIcon }}" aria-hidden="true">forum</span>
        {{ __('common.content_contact') }}
    </a>
    <button type="button"
            onclick="if(window.laravelCookieConsent)window.laravelCookieConsent.showCookieDialog()"
            class="js-cookie-consent-settings cursor-pointer {{ $row }}">
        <span class="{{ $rowIcon }}" aria-hidden="true">cookie</span>
        {{ __('common.nav_cookie_settings') }}
    </button>

    @auth
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit"
                    class="{{ $row }} text-on-surface hover:bg-error/10 hover:text-error">
                <span class="material-symbols-outlined text-[20px] text-on-surface-variant" aria-hidden="true">logout</span>
                {{ __('auth.content_log_out') }}
            </button>
        </form>
    @endauth
</div>
