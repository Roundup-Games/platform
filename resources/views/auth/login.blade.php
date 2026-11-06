{{--
    Combined sign-in entrypoint (menu/auth revamp): one door for sign-in and
    sign-up, identifier-first with a device-remembered account chooser.

    Progressive enhancement contract:
    · No JS = two complete forms — the classic login form, plus the sign-up
      form inside a <details> ("New here?"). Both POST to the unchanged
      Breeze endpoints, so the page is fully functional without JS.
    · With JS, the inline script transforms this into the stepped flow from
      docs/prototypes/auth-combined-flow.html: chooser (localStorage,
      device-side only — never a server-derived hint), email + Continue →
      POST /auth/lookup branch (password / OAuth-only / sign-up), show-
      password toggles, and the timezone decoration on every OAuth link.
    Autocomplete tokens stay per-research (username / current-password /
      new-password / name) so password managers pair fields correctly on
      every step.
--}}
<x-guest-layout>
    {{-- Recent-accounts chooser (JS-only; hidden without storage/JS) --}}
    <section id="auth-chooser" class="mb-6 hidden" aria-label="{{ __('auth.content_welcome_back') }}">
        <div class="mb-5 text-center">
            <h1 class="font-heading text-2xl font-bold text-on-surface">{{ __('auth.content_welcome_back') }}</h1>
            <p class="mt-1 text-sm text-on-surface-variant">{{ __('auth.content_welcome_back_sub') }}</p>
        </div>
        <p class="mb-2.5 text-[11px] font-bold uppercase tracking-[0.08em] text-primary">{{ __('auth.label_continue_as') }}</p>
        <div id="auth-chooser-rows" class="grid" role="list"></div>
        <button type="button" id="auth-use-other"
                class="mt-3 inline-flex items-center gap-1.5 text-[13.5px] font-semibold text-primary hover:underline hover:underline-offset-3">
            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">add_circle</span>
            {{ __('auth.action_use_another_account') }}
        </button>
    </section>

    {{-- Entry + sign-in form (the no-JS base; JS converts to steps) --}}
    <section id="auth-entry">
        <div id="auth-welcome-head" class="mb-6 text-center">
            <h1 class="font-heading text-2xl font-bold text-on-surface">{{ __('auth.content_welcome') }}</h1>
            <p class="mt-1 text-sm text-on-surface-variant">{{ __('auth.content_welcome_sub') }}</p>
        </div>

        <x-auth-session-status class="mb-4" :status="session('status')" />

        @if (\Illuminate\Support\Facades\Route::has('oauth.redirect'))
            <a href="{{ route('oauth.redirect', \App\Enums\OAuthProvider::Discord->value) }}" class="btn-discord js-oauth-link mb-3">
                <x-oauth-provider-icon :provider="\App\Enums\OAuthProvider::Discord" class="w-5 h-5 mr-3 text-white" />
                {{ __('common.action_continue_with_discord') }}
                <span id="auth-lastused-discord" class="ml-1 hidden rounded-full bg-primary/15 px-2 py-0.5 text-[10.5px] font-bold uppercase tracking-[0.05em] text-primary max-md:inline">{{ __('auth.label_last_used') }}</span>
            </a>

            <a href="{{ route('oauth.redirect', \App\Enums\OAuthProvider::Google->value) }}" class="btn-google js-oauth-link mb-4">
                <x-oauth-provider-icon :provider="\App\Enums\OAuthProvider::Google" class="w-5 h-5 mr-3" />
                {{ __('common.action_continue_with_google') }}
                <span id="auth-lastused-google" class="ml-1 hidden rounded-full bg-primary/15 px-2 py-0.5 text-[10.5px] font-bold uppercase tracking-[0.05em] text-primary max-md:inline">{{ __('auth.label_last_used') }}</span>
            </a>

            <div class="relative my-5">
                <div class="absolute inset-0 flex items-center">
                    <div class="w-full border-t border-outline-variant"></div>
                </div>
                <div class="relative flex justify-center text-sm">
                    <span class="px-3 bg-surface-container-lowest text-on-surface-variant">{{ __('auth.label_or_email') }}</span>
                </div>
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" id="auth-login-form" novalidate>
            @csrf

            {{-- Device timezone (R089) — carried onto the OAuth links by the
                 script below; same contract as the previous login page. --}}
            <input type="hidden" name="timezone" id="auth-timezone" value="">

            {{-- Email (identifier step + password step identity row) --}}
            <div>
                <x-input-label for="email" :value="__('emails.field_email')" />
                <span class="flex items-center gap-2">
                    <x-text-input id="email" class="mt-1 block w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
                    <button type="button" id="auth-change-email"
                            class="hidden shrink-0 items-center gap-1 py-2 text-[13px] font-semibold text-primary hover:underline hover:underline-offset-3">
                        <span class="material-symbols-outlined text-[17px]" aria-hidden="true">sync_alt</span>
                        {{ __('auth.field_change') }}
                    </button>
                </span>
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            {{-- Password (hidden during the identifier step by JS only) --}}
            <div id="auth-password-row" class="mt-4">
                <x-input-label for="password" :value="__('auth.field_password')" />
                <span class="relative mt-1 block">
                    <x-text-input id="password" class="block w-full pr-11" type="password" name="password" required autocomplete="current-password" />
                    <button type="button" data-auth-pw-toggle="password"
                            aria-label="{{ __('auth.aria_show_password') }}"
                            class="absolute right-1.5 top-1/2 grid size-9 -translate-y-1/2 place-items-center rounded-lg text-on-surface-variant hover:text-primary">
                        <span class="material-symbols-outlined text-[20px]" aria-hidden="true">visibility</span>
                    </button>
                </span>
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>

            <label id="auth-remember-row" class="mt-4 inline-flex items-center">
                <input type="checkbox" class="rounded-sm border-outline-variant text-primary shadow-xs focus:ring-primary/20" name="remember">
                <span class="ms-2 text-sm text-on-surface-variant">{{ __('auth.content_remember_me') }}</span>
            </label>

            <div id="auth-login-actions" class="mt-6 flex items-center justify-between">
                @if (Route::has('password.request'))
                    <a id="auth-forgot-link" class="text-sm text-primary font-medium rounded-md focus:outline-hidden focus:ring-2 focus:ring-primary focus:ring-offset-2 transition hover:text-primary-fixed-dim"
                       href="{{ route('password.request') }}">
                        {{ __('auth.content_forgot_your_password') }}
                    </a>
                @endif
                <x-primary-button id="auth-login-submit">
                    <span id="auth-login-submit-label">{{ __('auth.content_sign_in') }}</span>
                </x-primary-button>
            </div>
        </form>

        <p id="auth-entry-hint" class="mt-4 hidden text-center text-[13px] text-on-surface-variant">
            {{ __('auth.hint_new_here') }}
        </p>
    </section>

    {{-- Sign-up branch: <details> so no-JS visitors can still reach it --}}
    <details id="auth-signup-details" class="group mt-5 border-t border-outline-variant/25 pt-4">
        <summary class="cursor-pointer list-none text-center text-[13.5px] font-semibold text-primary group-open:hidden [&::-webkit-details-marker]:hidden">
            {{ __('auth.content_new_here_toggle') }}
        </summary>

        <div class="mt-1">
            <div class="mb-5 text-center">
                <h2 class="font-heading text-2xl font-bold text-on-surface">{{ __('auth.title_create_account') }}</h2>
                <p class="mt-1 text-sm text-on-surface-variant">{{ __('auth.content_signup_sub') }}</p>
            </div>

            {{-- Disable the submit button after first submit (duplicate-email
                 race guard — same pattern as the previous register page). --}}
            <form method="POST" action="{{ route('register') }}" id="auth-signup-form" novalidate
                  x-data="{ submitting: false }" @submit="submitting = true">
                @csrf

                <div>
                    <x-input-label for="signup-name" :value="__('common.field_name')" />
                    <x-text-input id="signup-name" class="mt-1 block w-full" type="text" name="name" :value="old('name')" required autocomplete="name" />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <div class="mt-4">
                    <x-input-label for="signup-email" :value="__('emails.field_email')" />
                    <x-text-input id="signup-email" class="mt-1 block w-full" type="email" name="email" :value="old('email')" required autocomplete="username" />
                    <x-input-error :messages="$errors->get('email')" class="mt-2" />
                </div>

                {{-- No confirm-password (D00x decision): show-password toggle
                     + the reset flow cover typos — one less field converts. --}}
                <div class="mt-4">
                    <x-input-label for="signup-password" :value="__('auth.field_password')" />
                    <span class="relative mt-1 block">
                        <x-text-input id="signup-password" class="block w-full pr-11" type="password" name="password" required autocomplete="new-password" />
                        <button type="button" data-auth-pw-toggle="signup-password"
                                aria-label="{{ __('auth.aria_show_password') }}"
                                class="absolute right-1.5 top-1/2 grid size-9 -translate-y-1/2 place-items-center rounded-lg text-on-surface-variant hover:text-primary">
                            <span class="material-symbols-outlined text-[20px]" aria-hidden="true">visibility</span>
                        </button>
                    </span>
                    <x-input-error :messages="$errors->get('password')" class="mt-2" />
                </div>

                <div class="mt-6 flex items-center justify-end">
                    <x-primary-button x-bind:disabled="submitting" x-bind:class="{ 'opacity-50 cursor-not-allowed pointer-events-none': submitting }">
                        {{ __('auth.action_create_account') }}
                    </x-primary-button>
                </div>
            </form>

            {{-- Signup binds the account to the community rules (M066/S08/T03) --}}
            <p class="mt-4 text-xs leading-relaxed text-center text-on-surface-variant">
                {{ __('safety.content_signup_agreement_prefix') }}
                <a href="{{ route('terms') }}" wire:navigate class="text-primary hover:underline">{{ __('common.nav_terms') }}</a>
                {{ __('safety.content_signup_agreement_connector') }}
                <a href="{{ route('safety.code-of-conduct') }}" wire:navigate class="text-primary hover:underline">{{ __('safety.nav_code_of_conduct') }}</a>.
            </p>
        </div>
    </details>

    {{-- OAuth-only branch (JS reveals after lookup; hidden server-side) --}}
    <section id="auth-oauth-only" class="hidden" aria-live="polite">
        <div class="mb-5 text-center">
            <h2 class="font-heading text-2xl font-bold text-on-surface">{{ __('auth.content_sign_in') }}</h2>
            <p class="mt-1 text-sm text-on-surface-variant">{{ __('auth.content_oauth_only_sub') }}</p>
        </div>
        <div class="mb-4 flex items-start gap-2.5 rounded-xl bg-primary/10 px-3.5 py-3 text-[13.5px] leading-relaxed text-on-surface">
            <span class="material-symbols-outlined mt-0.5 text-[18px] text-primary" aria-hidden="true">info</span>
            <span id="auth-oauth-hint"></span>
        </div>
        @if (\Illuminate\Support\Facades\Route::has('oauth.redirect'))
            <div class="mb-4">
                <a href="{{ route('oauth.redirect', \App\Enums\OAuthProvider::Discord->value) }}" class="btn-discord js-oauth-link mb-3">
                    <x-oauth-provider-icon :provider="\App\Enums\OAuthProvider::Discord" class="w-5 h-5 mr-3 text-white" />
                    {{ __('common.action_continue_with_discord') }}
                </a>
                <a href="{{ route('oauth.redirect', \App\Enums\OAuthProvider::Google->value) }}" class="btn-google js-oauth-link mb-1">
                    <x-oauth-provider-icon :provider="\App\Enums\OAuthProvider::Google" class="w-5 h-5 mr-3" />
                    {{ __('common.action_continue_with_google') }}
                </a>
            </div>
        @endif
        {{-- Password-set escape hatch: the standard reset flow already works
             for OAuth-only accounts (password null), so no new mechanism. --}}
        <form method="POST" action="{{ route('password.email') }}" class="mt-5 border-t border-outline-variant/25 pt-4">
            @csrf
            <input type="hidden" name="email" id="auth-setpw-email" value="">
            <button type="submit" class="flex h-11 w-full items-center justify-center gap-2 rounded-xl border border-outline-variant/70 text-sm font-semibold text-on-surface transition-colors hover:border-primary hover:text-primary">
                <span class="material-symbols-outlined text-[20px] text-on-surface-variant" aria-hidden="true">mail</span>
                {{ __('auth.action_set_password_link') }}
            </button>
        </form>
        <p class="mt-4 text-center">
            <button type="button" id="auth-oauth-change" class="text-[13px] font-semibold text-primary hover:underline hover:underline-offset-3">
                {{ __('auth.field_change') }} · {{ __('emails.field_email') }}
            </button>
        </p>
    </section>
</x-guest-layout>

<script>
(function () {
    'use strict';

    /* i18n + endpoints for the enhanced flow (server-rendered values). */
    var I18N = {
        continue: @json(__('auth.action_continue')),
        signIn: @json(__('auth.content_sign_in')),
        hintProvider: @json(__('auth.hint_oauth_provider')),
        oauthOnlySub: @json(__('auth.content_oauth_only_sub')),
        lastUsed: @json(__('auth.label_last_used')),
        removeAccount: @json(__('auth.aria_forget_account')),
        showPassword: @json(__('auth.aria_show_password')),
        hidePassword: @json(__('auth.aria_hide_password'))
    };
    var LOOKUP_URL = @json(route('auth.lookup'));
    var CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var STORAGE_KEY = 'roundup.auth.recent';
    var PROVIDERS = {
        discord: { label: 'Discord', redirect: @json(route('oauth.redirect', 'discord')) },
        google: { label: 'Google', redirect: @json(route('oauth.redirect', 'google')) }
    };

    var $ = function (id) { return document.getElementById(id); };

    /* ── Storage (device-side only; guarded everywhere) ─────────────── */
    function readRecent() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            var list = raw ? JSON.parse(raw) : null;
            if (!Array.isArray(list)) return [];
            return list.filter(function (row) {
                return row && typeof row.email === 'string' && typeof row.method === 'string';
            }).slice(0, 3);
        } catch (error) {
            return [];
        }
    }

    /* ── Section switching ──────────────────────────────────────────── */
    var currentEmail = '';

    function show(section) {
        $('auth-chooser').classList.toggle('hidden', section !== 'chooser');
        $('auth-entry').hidden = section !== 'entry' && section !== 'password';
        $('auth-oauth-only').classList.toggle('hidden', section !== 'oauth');
        var signup = $('auth-signup-details');
        signup.open = section === 'signup';
        signup.hidden = section !== 'signup' && section !== 'entry';
        signup.querySelector('summary').classList.toggle('hidden', section === 'signup');
        window.scrollTo({ top: 0 });
    }

    function enterStep(step) {
        var passwordStep = step === 'password';
        $('auth-password-row').hidden = !passwordStep;
        $('auth-remember-row').hidden = !passwordStep;
        $('auth-forgot-link').classList.toggle('hidden', !passwordStep);
        $('auth-login-actions').classList.toggle('justify-end', !passwordStep);
        $('auth-change-email').classList.toggle('hidden', !passwordStep);
        $('auth-change-email').classList.toggle('inline-flex', passwordStep);
        $('auth-login-submit-label').textContent = passwordStep ? I18N.signIn : I18N.continue;
        $('auth-entry-hint').classList.toggle('hidden', passwordStep);
        $('auth-welcome-head').classList.toggle('hidden', passwordStep);
        $('email').readOnly = passwordStep;
    }

    function goSignup(email) {
        $('signup-email').value = email;
        show('signup');
        setTimeout(function () { $('signup-name').focus(); }, 30);
    }

    function goOauthOnly(provider, email) {
        // provider can be null (passwordless account with no linked provider)
        // — the generic copy carries the message without naming one.
        var known = PROVIDERS[provider];
        $('auth-oauth-hint').textContent = known
            ? I18N.hintProvider.replace(':provider', known.label)
            : I18N.oauthOnlySub;
        $('auth-setpw-email').value = email;
        show('oauth');
    }

    /* ── Chooser ────────────────────────────────────────────────────── */
    function initials(name) {
        var parts = String(name || '?').trim().split(/\s+/);
        return (parts[0].charAt(0) + (parts[1] ? parts[1].charAt(0) : '')).toUpperCase() || '?';
    }

    function methodMark(method) {
        if (method === 'discord') {
            return '<svg width="15" height="15" viewBox="0 0 24 24" fill="#5865f2" aria-hidden="true"><path d="M20.317 4.369a19.79 19.79 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.6 12.6 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028c.462-.63.874-1.295 1.226-1.994a.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128c.126-.094.252-.192.372-.291a.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.3 12.3 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/></svg>';
        }
        if (method === 'google') {
            return '<svg width="14" height="14" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.611 20.083H42V20H24v8h11.303c-1.649 4.657-6.08 8-11.303 8-6.627 0-12-5.373-12-12s5.373-12 12-12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4 12.955 4 4 12.955 4 24s8.955 20 20 20 20-8.955 20-20c0-1.341-.138-2.65-.389-3.917z"/><path fill="#FF3D00" d="m6.306 14.691 6.571 4.819C14.655 15.108 18.961 12 24 12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4 16.318 4 9.656 8.337 6.306 14.691z"/><path fill="#4CAF50" d="M24 44c5.166 0 9.86-1.977 13.409-5.192l-6.19-5.238A11.91 11.91 0 0 1 24 36c-5.202 0-9.619-3.317-11.283-7.946l-6.522 5.025C9.505 39.556 16.227 44 24 44z"/><path fill="#1976D2" d="M43.611 20.083H42V20H24v8h11.303a12.04 12.04 0 0 1-4.087 5.571l.003-.002 6.19 5.238C36.971 39.205 44 34 44 24c0-1.341-.138-2.65-.389-3.917z"/></svg>';
        }
        return '<span class="material-symbols-outlined text-[17px] text-on-surface-variant" aria-hidden="true">password</span>';
    }

    function renderChooser() {
        var rows = readRecent();
        var wrap = $('auth-chooser-rows');
        wrap.textContent = '';
        rows.forEach(function (row, index) {
            var el = document.createElement('button');
            el.type = 'button';
            el.className = 'account-row flex w-full items-center gap-3 rounded-2xl px-3 py-2.5 text-start transition-colors hover:bg-surface-container';
            el.setAttribute('role', 'listitem');
            var avatar = document.createElement('span');
            avatar.className = 'grid size-9.5 shrink-0 place-items-center rounded-full bg-primary-container text-[14px] font-bold text-on-primary-container';
            avatar.textContent = initials(row.name);
            var who = document.createElement('span');
            who.className = 'grid min-w-0 flex-1 justify-items-start gap-0.5';
            var name = document.createElement('span');
            name.className = 'account-name block max-w-full truncate text-sm font-semibold';
            name.textContent = row.name;
            var email = document.createElement('span');
            email.className = 'account-email block max-w-full truncate text-[12.5px] text-on-surface-variant';
            email.textContent = row.email;
            who.appendChild(name);
            who.appendChild(email);
            el.appendChild(avatar);
            el.appendChild(who);
            if (index === 0) {
                var tag = document.createElement('span');
                tag.className = 'shrink-0 rounded-full bg-primary/15 px-2 py-0.5 text-[10.5px] font-bold uppercase tracking-[0.05em] text-primary';
                tag.textContent = I18N.lastUsed;
                el.appendChild(tag);
            }
            var mark = document.createElement('span');
            mark.className = 'grid size-8 shrink-0 place-items-center rounded-full border border-outline-variant/55 bg-surface-container-high/65';
            mark.title = row.method;
            mark.innerHTML = methodMark(row.method);
            el.appendChild(mark);
            var remove = document.createElement('span');
            remove.className = 'grid size-8 shrink-0 place-items-center rounded-full text-on-surface-variant hover:bg-surface-container-high hover:text-error';
            remove.setAttribute('role', 'button');
            remove.setAttribute('aria-label', I18N.removeAccount);
            remove.innerHTML = '<span class="material-symbols-outlined text-[17px]" aria-hidden="true">close</span>';
            remove.addEventListener('click', function (event) {
                event.stopPropagation();
                try {
                    var kept = readRecent().filter(function (other) { return other.email !== row.email; });
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(kept));
                } catch (error) { /* blocked storage */ }
                renderChooser();
                if (!readRecent().length) {
                    show('entry');
                    enterStep('entry');
                }
            });
            el.appendChild(remove);
            el.addEventListener('click', function () {
                currentEmail = row.email;
                decorateOauthLinks(row.email);
                if (row.method === 'password') {
                    $('email').value = row.email;
                    show('entry'); // reveal the form section (the chooser hid it)
                    enterStep('password');
                    setTimeout(function () { $('password').focus(); }, 30);
                } else if (PROVIDERS[row.method]) {
                    // Same contract as the decorated buttons: timezone (R089)
                    // rides along, login_hint skips the IdP account picker.
                    window.location.href = oauthHref(row.method, row.email);
                }
            });
            wrap.appendChild(el);
        });

        /* Entry-screen "Last used" pills (fallback when the chooser is empty). */
        var newest = rows[0];
        ['discord', 'google'].forEach(function (provider) {
            var pill = $('auth-lastused-' + provider);
            if (pill) pill.classList.toggle('hidden', !(newest && newest.method === provider));
        });
    }

    /* ── Timezone decoration (R089) + login_hint on every OAuth hop ─── */
    function currentTimezone() {
        try {
            return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
        } catch (error) {
            return ''; /* no Intl support */
        }
    }

    function oauthHref(providerKey, email) {
        var parts = [];
        var timezone = currentTimezone();
        if (timezone !== '') {
            parts.push('timezone=' + encodeURIComponent(timezone));
        }
        if (email) {
            parts.push('login_hint=' + encodeURIComponent(email));
        }
        return PROVIDERS[providerKey].redirect + (parts.length > 0 ? '?' + parts.join('&') : '');
    }

    function decorateOauthLinks(email) {
        var timezone = currentTimezone();
        var suffix = (timezone !== '' ? 'timezone=' + encodeURIComponent(timezone) : '')
            + (email ? (timezone !== '' ? '&' : '') + 'login_hint=' + encodeURIComponent(email) : '');
        document.querySelectorAll('a.js-oauth-link').forEach(function (link) {
            link.href = link.href.split('?')[0] + (suffix !== '' ? '?' + suffix : '');
        });
    }

    /* ── Lookup branch ──────────────────────────────────────────────── */
    function lookup(email) {
        fetch(LOOKUP_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': CSRF,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ email: email })
        }).then(function (response) {
            return response.json().then(function (data) { return { ok: response.ok, data: data }; });
        }).then(function (result) {
            if (!result.ok) {
                enterStep('password'); /* degraded: let the classic form run */
                return;
            }
            if (result.data.status === 'new') {
                goSignup(email);
            } else if (result.data.status === 'oauth') {
                goOauthOnly(result.data.provider || 'discord', email);
            } else {
                enterStep('password');
                setTimeout(function () { $('password').focus(); }, 30);
            }
        }).catch(function () {
            enterStep('password'); /* network failure → classic path */
        });
    }

    /* ── Wiring ─────────────────────────────────────────────────────── */
    $('auth-login-form').addEventListener('submit', function (event) {
        var label = $('auth-login-submit-label').textContent;
        if (label !== I18N.continue) {
            return; /* password step — real login POST proceeds */
        }
        event.preventDefault();
        var email = $('email').value.trim();
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            $('email').focus();
            return;
        }
        currentEmail = email;
        decorateOauthLinks(email);
        lookup(email);
    });

    $('auth-change-email').addEventListener('click', function () {
        enterStep('entry');
        $('email').readOnly = false;
        $('email').focus();
    });

    $('auth-use-other').addEventListener('click', function () {
        show('entry');
        enterStep('entry');
        $('email').focus();
    });

    $('auth-oauth-change').addEventListener('click', function () {
        show('entry');
        enterStep('entry');
        $('email').focus();
    });

    document.querySelectorAll('[data-auth-pw-toggle]').forEach(function (toggle) {
        toggle.addEventListener('click', function () {
            var input = document.getElementById(toggle.getAttribute('data-auth-pw-toggle'));
            var showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            toggle.setAttribute('aria-label', showing ? I18N.showPassword : I18N.hidePassword);
            toggle.firstElementChild.textContent = showing ? 'visibility' : 'visibility_off';
        });
    });

    /* Hidden-by-JS rows: the no-JS base shows everything, JS applies the
       identifier-first layout once. A bounced POST (failed credentials or
       validation) boots straight into the password step — the email is
       already known, and hiding the password field next to the error
       would orphan the message. */
    var bouncedBack = @json($errors->has('email') || $errors->has('password'));
    enterStep(bouncedBack ? 'password' : 'entry');
    if (bouncedBack) {
        $('email').readOnly = true;
    }
    decorateOauthLinks('');
    renderChooser();
    if (! bouncedBack && readRecent().length > 0) {
        show('chooser');
    }
})();
</script>
