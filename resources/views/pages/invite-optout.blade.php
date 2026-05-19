<x-public-layout>

<x-hero :title="__($status === 'confirm' ? 'invite_optout.title_confirm' : 'invite_optout.title_' . $status)" />

<section class="py-16 sm:py-20 bg-surface">
    <div class="max-w-2xl mx-auto px-4 text-center">

        @if($status === 'confirm')
            {{-- Confirmation step: POST form prevents email scanners from triggering suppression --}}
            <p class="text-lg text-on-surface/80 mb-8">
                {{ __('invite_optout.content_confirm', ['brand' => config('company.display_name')]) }}
            </p>

            <form method="POST" action="{{ route('invite.optout.confirm', ['locale' => app()->getLocale(), 'emailHash' => $emailHash]) }}">
                @csrf
                <button type="submit"
                        class="inline-block px-6 py-3 bg-primary text-on-primary rounded-lg hover:bg-primary/90 transition-colors">
                    {{ __('invite_optout.action_confirm') }}
                </button>
            </form>

            <p class="mt-6 text-sm text-on-surface/60">
                {{ __('invite_optout.content_confirm_note') }}
            </p>

        @elseif($status === 'confirmed')
            <p class="text-lg text-on-surface/80 mb-8">
                {{ __('invite_optout.content_confirmed', ['brand' => config('company.display_name')]) }}
            </p>

            <a href="{{ route('home', app()->getLocale()) }}"
               class="inline-block px-6 py-3 bg-primary text-on-primary rounded-lg hover:bg-primary/90 transition-colors">
                {{ __('invite_optout.action_back_home') }}
            </a>

        @else
            <p class="text-lg text-on-surface/80 mb-8">
                {{ __('invite_optout.content_invalid') }}
            </p>

            <a href="{{ route('home', app()->getLocale()) }}"
               class="inline-block px-6 py-3 bg-primary text-on-primary rounded-lg hover:bg-primary/90 transition-colors">
                {{ __('invite_optout.action_back_home') }}
            </a>
        @endif

    </div>
</section>

</x-public-layout>
