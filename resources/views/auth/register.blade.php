<x-guest-layout>
    <!-- Page Title -->
    <div class="mb-6 text-center">
        <h1 class="font-heading text-2xl font-bold text-on-surface">{{ __('profile.action_create_account') }}</h1>
        <p class="mt-1 text-sm text-on-surface-variant">{{ __('pages.action_join_roundup_games_today', ['brand' => config('company.display_name')]) }}</p>
    </div>

    <!-- Discord OAuth (primary external-community surface) -->
    @if (\Illuminate\Support\Facades\Route::has('oauth.redirect'))
        <a href="{{ route('oauth.redirect', \App\Enums\OAuthProvider::Discord->value) }}" class="btn-discord mb-3">
            <x-oauth-provider-icon :provider="\App\Enums\OAuthProvider::Discord" class="w-5 h-5 mr-3 text-white" />
            {{ __('auth.content_sign_up_with_discord') }}
        </a>

        <!-- Google OAuth -->
        <a href="{{ route('oauth.redirect', \App\Enums\OAuthProvider::Google->value) }}" class="btn-google mb-4">
            <x-oauth-provider-icon :provider="\App\Enums\OAuthProvider::Google" class="w-5 h-5 mr-3" />
            {{ __('auth.content_sign_up_with_google') }}
        </a>

        <!-- Divider -->
        <div class="relative my-6">
            <div class="absolute inset-0 flex items-center">
                <div class="w-full border-t border-outline-variant"></div>
            </div>
            <div class="relative flex justify-center text-sm">
                <span class="px-3 bg-surface-container-lowest text-on-surface-variant">{{ __('emails.field_or_sign_up_with_email') }}</span>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('register') }}">
        @csrf

        <!-- Name -->
        <div>
            <x-input-label for="name" :value="__('common.field_name')" />
            <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <!-- Email Address -->
        <div class="mt-4">
            <x-input-label for="email" :value="__('emails.field_email')" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('auth.field_password')" />
            <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('auth.field_confirm_password')" />
            <x-text-input id="password_confirmation" class="block mt-1 w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-6">
            <a class="text-sm text-primary hover:text-primary-fixed-dim font-medium rounded-md focus:outline-hidden focus:ring-2 focus:ring-primary focus:ring-offset-2 transition" href="{{ route('login') }}">
                {{ __('auth.content_already_registered') }}
            </a>

            <x-primary-button class="ms-4">
                {{ __('profile.action_create_account') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
