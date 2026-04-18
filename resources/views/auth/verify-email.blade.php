<x-guest-layout>
    <!-- Page Title -->
    <div class="mb-6 text-center">
        <h1 class="font-heading text-2xl font-bold text-on-surface">{{ __('emails.field_verify_email') }}</h1>
        <p class="mt-1 text-sm text-on-surface-variant">{{ __('emails.field_check_your_inbox_for_a_verification_link') }}</p>
    </div>

    <div class="mb-4 text-sm text-on-surface-variant">
        {{ __('emails.content_thanks_for_signing_up_before') }}
    </div>

    @if (session('status') == 'verification-link-sent')
        <div class="mb-4 font-medium text-sm text-secondary bg-secondary-container p-3 rounded-lg flex items-center gap-2">
            <span class="material-symbols-outlined text-lg" aria-hidden="true">check_circle</span>
            {{ __('emails.content_a_new_verification_link_has') }}
        </div>
    @endif

    <div class="mt-6 flex items-center justify-between">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-primary-button>
                {{ __('emails.field_resend_verification_email') }}
            </x-primary-button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="underline text-sm text-on-surface-variant hover:text-on-surface rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 transition">
                {{ __('auth.content_log_out') }}
            </button>
        </form>
    </div>
</x-guest-layout>
