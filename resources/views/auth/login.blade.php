<x-guest-layout>
    <!-- Page Title -->
    <div class="mb-6 text-center">
        <h1 class="font-heading text-2xl font-bold text-on-surface">{{ __('auth.content_sign_in') }}</h1>
        <p class="mt-1 text-sm text-on-surface-variant">{{ __('events.content_welcome_back_to_roundup_games', ['brand' => config('company.display_name')]) }}</p>
    </div>

    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <!-- Discord OAuth (primary external-community surface) -->
    @if (\Illuminate\Support\Facades\Route::has('oauth.redirect'))
        <a href="{{ route('oauth.redirect', \App\Enums\OAuthProvider::Discord->value) }}" class="btn-discord mb-3">
            <x-oauth-provider-icon :provider="\App\Enums\OAuthProvider::Discord" class="w-5 h-5 mr-3 text-white" />
            {{ __('common.action_continue_with_discord') }}
        </a>

        <!-- Google OAuth -->
        <a href="{{ route('oauth.redirect', \App\Enums\OAuthProvider::Google->value) }}" class="btn-google mb-4">
            <x-oauth-provider-icon :provider="\App\Enums\OAuthProvider::Google" class="w-5 h-5 mr-3" />
            {{ __('common.action_continue_with_google') }}
        </a>

        <!-- Divider -->
        <div class="relative my-6">
            <div class="absolute inset-0 flex items-center">
                <div class="w-full border-t border-outline-variant"></div>
            </div>
            <div class="relative flex justify-center text-sm">
                <span class="px-3 bg-surface-container-lowest text-on-surface-variant">{{ __('emails.field_or_sign_in_with_email') }}</span>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        {{-- Device timezone for per-user notification times (R089). Stays empty
             without JS; the server silently ignores absent/invalid values. The
             same value is carried onto the OAuth buttons as ?timezone= so the
             OAuth callback can capture it too (stashed by OAuthController::redirect). --}}
        <input type="hidden" name="timezone" id="login-timezone" value="">
        <script>
            try {
                var tz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
                document.getElementById('login-timezone').value = tz;
                if (tz) {
                    document.querySelectorAll('a.btn-discord, a.btn-google').forEach(function (link) {
                        link.href += (link.href.indexOf('?') === -1 ? '?' : '&') + 'timezone=' + encodeURIComponent(tz);
                    });
                }
            } catch (e) { /* no Intl support — leave empty */ }
        </script>

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('emails.field_email')" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('auth.field_password')" />
            <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Remember Me -->
        <div class="block mt-4">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox" class="rounded-sm border-outline-variant text-primary shadow-xs focus:ring-primary/20" name="remember">
                <span class="ms-2 text-sm text-on-surface-variant">{{ __('auth.content_remember_me') }}</span>
            </label>
        </div>

        <div class="flex items-center justify-between mt-6">
            @if (Route::has('password.request'))
                <a class="text-sm text-primary hover:text-primary-fixed-dim font-medium rounded-md focus:outline-hidden focus:ring-2 focus:ring-primary focus:ring-offset-2 transition" href="{{ route('password.request') }}">
                    {{ __('auth.content_forgot_your_password') }}
                </a>
            @endif

            <x-primary-button>
                {{ __('auth.content_sign_in') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
