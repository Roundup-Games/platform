<x-public-layout>

<x-hero :title="__('invite_optout.title_' . $status)" />

<section class="py-16 sm:py-20 bg-surface">
    <div class="max-w-2xl mx-auto px-4 text-center">
        @if($status === 'confirmed')
            <p class="text-lg text-on-surface/80 mb-8">
                {{ __('invite_optout.content_confirmed') }}
            </p>
        @else
            <p class="text-lg text-on-surface/80 mb-8">
                {{ __('invite_optout.content_invalid') }}
            </p>
        @endif

        <a href="{{ route('home', app()->getLocale()) }}"
           class="inline-block px-6 py-3 bg-primary text-on-primary rounded-lg hover:bg-primary/90 transition-colors">
            {{ __('invite_optout.action_back_home') }}
        </a>
    </div>
</section>

</x-public-layout>
