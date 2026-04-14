<x-guest-layout>
    <!-- Page Title -->
    <div class="mb-6 text-center">
        <h1 class="font-heading text-2xl font-bold text-on-surface">{{ __('Verify Email') }}</h1>
        <p class="mt-1 text-sm text-on-surface-variant">{{ __('Check your inbox for a verification link') }}</p>
    </div>

    <div class="mb-4 text-sm text-on-surface-variant">
        {{ __('Thanks for signing up! Before getting started, could you verify your email address by clicking on the link we just emailed to you? If you didn\'t receive the email, we will gladly send you another.') }}
    </div>

    @if (session('status') == 'verification-link-sent')
        <div class="mb-4 font-medium text-sm text-secondary bg-secondary-container p-3 rounded-lg flex items-center gap-2">
            <span class="material-symbols-outlined text-lg" aria-hidden="true">check_circle</span>
            {{ __('A new verification link has been sent to the email address you provided during registration.') }}
        </div>
    @endif

    <div class="mt-6 flex items-center justify-between">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-primary-button>
                {{ __('Resend Verification Email') }}
            </x-primary-button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="underline text-sm text-on-surface-variant hover:text-on-surface rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 transition">
                {{ __('Log Out') }}
            </button>
        </form>
    </div>
</x-guest-layout>
