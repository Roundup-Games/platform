<x-public-layout>
@section('title', __('About'))

    <x-hero :title="__('About Roundup Games')" :subtitle="__('Building community through competitive gaming events.')" />

    {{-- Mission Section --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="max-w-3xl mx-auto">
                <h2 class="text-3xl font-heading font-bold tracking-tight text-on-surface">{{ __('Our Mission') }}</h2>
                <div class="mt-6 space-y-4 text-on-surface-variant text-base leading-relaxed">
                    <p>
                        {{ __('Roundup Games was born from a simple belief: that competitive gaming brings people together. Whether it\'s a weekend tournament, a weekly league night, or a community meetup, we\'ve seen firsthand how games create connections that last far beyond the final score.') }}
                    </p>
                    <p>
                        {{ __('Our platform makes it easy for organizers to create and manage events of any size — from small local meetups to large-scale competitions. For participants, we provide a seamless experience from discovery to registration to competition day.') }}
                    </p>
                    <p>
                        {{ __("We believe that everyone should have access to well-organized competitive events, regardless of their skill level or experience. That's why we've built tools that make event management accessible to organizers while keeping the participant experience front and center.") }}
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- Values Section — editorial shadows, Material Symbols --}}
    <section class="py-16 sm:py-20 bg-surface-container-low">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl font-heading font-bold tracking-tight text-on-surface text-center mb-12">{{ __('What We Stand For') }}</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8">
                <div class="text-center">
                    <div class="w-16 h-16 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-primary text-3xl" aria-hidden="true">groups</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg">{{ __('Community First') }}</h3>
                    <p class="mt-2 text-sm text-on-surface-variant">{{ __('Everything we build starts with the community in mind. Events are about people coming together, not just competition.') }}</p>
                </div>

                <div class="text-center">
                    <div class="w-16 h-16 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-primary text-3xl" aria-hidden="true">shield</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg">{{ __('Fair Play') }}</h3>
                    <p class="mt-2 text-sm text-on-surface-variant">{{ __("We're committed to creating a level playing field where everyone has an equal opportunity to compete and have fun.") }}</p>
                </div>

                <div class="text-center">
                    <div class="w-16 h-16 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-primary text-3xl" aria-hidden="true">bolt</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg">{!! __('Simple & Fast') !!}</h3>
                    <p class="mt-2 text-sm text-on-surface-variant">{{ __("From creating an event to registering a team, we keep things straightforward so you can focus on what matters — competing.") }}</p>
                </div>

                <div class="text-center">
                    <div class="w-16 h-16 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-primary text-3xl" aria-hidden="true">public</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg">{{ __('Open to All') }}</h3>
                    <p class="mt-2 text-sm text-on-surface-variant">{{ __("Whether you're a seasoned competitor or trying something new, there's a place for you in the Roundup Games community.") }}</p>
                </div>

                <div class="text-center">
                    <div class="w-16 h-16 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-primary text-3xl" aria-hidden="true">assignment</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg">{{ __('Organizer Empowerment') }}</h3>
                    <p class="mt-2 text-sm text-on-surface-variant">{{ __('We give organizers the tools they need to run professional events without the complexity of traditional management software.') }}</p>
                </div>

                <div class="text-center">
                    <div class="w-16 h-16 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="material-symbols-outlined text-primary text-3xl" aria-hidden="true">favorite</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg">{{ __('Passion for Games') }}</h3>
                    <p class="mt-2 text-sm text-on-surface-variant">{{ __("We're gamers ourselves. That passion drives us to build the best possible platform for the community we love.") }}</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Team Section --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="max-w-3xl mx-auto text-center">
                <h2 class="text-3xl font-heading font-bold tracking-tight text-on-surface">{{ __('Our Team') }}</h2>
                <p class="mt-4 text-on-surface-variant text-base leading-relaxed">
                    {{ __("We're a small team of passionate gamers and developers who believe that organized competition makes gaming better for everyone. We're constantly working to improve the platform and would love to hear your feedback.") }}
                </p>
            </div>
        </div>
    </section>

    {{-- Community CTA — warm amber gradient --}}
    <section class="py-16 sm:py-20 bg-gradient-to-br from-primary to-primary-container text-on-primary">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 text-center">
            <h2 class="text-3xl font-heading font-bold tracking-tight">{{ __('Join Our Community') }}</h2>
            <p class="mt-4 text-on-primary/80 max-w-xl mx-auto">
                {{ __("Whether you want to organize events or compete in them, we'd love to have you.") }}
            </p>
            <div class="mt-8 flex flex-wrap justify-center gap-4">
                <a href="{{ route('events.index') }}" wire:navigate class="inline-flex items-center px-6 py-3 bg-surface text-primary rounded-xl font-semibold hover:bg-surface-container-lowest transition-colors text-sm shadow-md">
                    {{ __('Browse Events') }}
                </a>
                <a href="{{ route('contact') }}" wire:navigate class="inline-flex items-center px-6 py-3 bg-on-primary/20 text-on-primary rounded-xl font-semibold hover:bg-on-primary/30 transition-colors text-sm border border-on-primary/30">
                    {{ __('Get in Touch') }}
                </a>
            </div>
        </div>
    </section>
</x-public-layout>
