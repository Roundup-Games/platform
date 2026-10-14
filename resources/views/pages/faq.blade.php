<x-public-layout>

    <x-hero :title="__('faq.heading_faq')" :subtitle="__('faq.content_faq_hero_subtitle', ['brand' => config('company.display_name')])" />

    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">

            {{-- Section: The basics --}}
            <h2 id="basics" class="text-2xl font-heading font-bold tracking-tight text-on-surface mb-6">{{ __('faq.heading_section_basics') }}</h2>
            <div class="space-y-3 mb-14">
                <details id="q-system-vs-game" class="group bg-surface-container rounded-xl">
                    <summary class="px-5 py-3.5 cursor-pointer text-sm font-semibold text-on-surface flex items-center justify-between gap-3 hover:bg-surface-container-high rounded-xl transition-colors">
                        {{ __('faq.content_q_system_vs_game') }}
                        <span class="material-symbols-outlined text-lg text-on-surface-variant group-open:rotate-180 transition-transform shrink-0" aria-hidden="true">expand_more</span>
                    </summary>
                    <div class="px-5 pb-4 text-sm text-on-surface-variant leading-relaxed">
                        {{ __('faq.content_a_system_vs_game') }}
                    </div>
                </details>

                <details id="q-event-vs-game" class="group bg-surface-container rounded-xl">
                    <summary class="px-5 py-3.5 cursor-pointer text-sm font-semibold text-on-surface flex items-center justify-between gap-3 hover:bg-surface-container-high rounded-xl transition-colors">
                        {{ __('faq.content_q_event_vs_game') }}
                        <span class="material-symbols-outlined text-lg text-on-surface-variant group-open:rotate-180 transition-transform shrink-0" aria-hidden="true">expand_more</span>
                    </summary>
                    <div class="px-5 pb-4 text-sm text-on-surface-variant leading-relaxed">
                        {{ __('faq.content_a_event_vs_game') }}
                    </div>
                </details>
            </div>

            {{-- Section: Joining & playing --}}
            <h2 id="playing" class="text-2xl font-heading font-bold tracking-tight text-on-surface mb-6">{{ __('faq.heading_section_playing') }}</h2>
            <div class="space-y-3 mb-14">
                <details id="q-bench-vs-waitlist" class="group bg-surface-container rounded-xl">
                    <summary class="px-5 py-3.5 cursor-pointer text-sm font-semibold text-on-surface flex items-center justify-between gap-3 hover:bg-surface-container-high rounded-xl transition-colors">
                        {{ __('faq.content_q_bench_vs_waitlist') }}
                        <span class="material-symbols-outlined text-lg text-on-surface-variant group-open:rotate-180 transition-transform shrink-0" aria-hidden="true">expand_more</span>
                    </summary>
                    <div class="px-5 pb-4 text-sm text-on-surface-variant leading-relaxed">
                        {{ __('faq.content_a_bench_vs_waitlist') }}
                    </div>
                </details>

                <details id="q-reliability" class="group bg-surface-container rounded-xl">
                    <summary class="px-5 py-3.5 cursor-pointer text-sm font-semibold text-on-surface flex items-center justify-between gap-3 hover:bg-surface-container-high rounded-xl transition-colors">
                        {{ __('faq.content_q_reliability') }}
                        <span class="material-symbols-outlined text-lg text-on-surface-variant group-open:rotate-180 transition-transform shrink-0" aria-hidden="true">expand_more</span>
                    </summary>
                    <div class="px-5 pb-4 text-sm text-on-surface-variant leading-relaxed">
                        {{ __('faq.content_a_reliability') }}
                        <a href="{{ route('pledge.algorithms', app()->getLocale()) }}" wire:navigate class="mt-2 inline-flex items-center text-sm font-medium text-primary hover:underline">
                            {{ __('faq.action_view_algorithms') }} →
                        </a>
                    </div>
                </details>

                <details id="q-free-to-use" class="group bg-surface-container rounded-xl">
                    <summary class="px-5 py-3.5 cursor-pointer text-sm font-semibold text-on-surface flex items-center justify-between gap-3 hover:bg-surface-container-high rounded-xl transition-colors">
                        {{ __('faq.content_q_free_to_use') }}
                        <span class="material-symbols-outlined text-lg text-on-surface-variant group-open:rotate-180 transition-transform shrink-0" aria-hidden="true">expand_more</span>
                    </summary>
                    <div class="px-5 pb-4 text-sm text-on-surface-variant leading-relaxed">
                        {{ __('faq.content_a_free_to_use') }}
                        <a href="{{ route('pricing', app()->getLocale()) }}" wire:navigate class="mt-2 inline-flex items-center text-sm font-medium text-primary hover:underline">
                            {{ __('faq.action_view_pricing') }} →
                        </a>
                    </div>
                </details>
            </div>

            {{-- Section: Hosting --}}
            <h2 id="hosting" class="text-2xl font-heading font-bold tracking-tight text-on-surface mb-6">{{ __('faq.heading_section_hosting') }}</h2>
            <div class="space-y-3 mb-14">
                <details id="q-host-first" class="group bg-surface-container rounded-xl">
                    <summary class="px-5 py-3.5 cursor-pointer text-sm font-semibold text-on-surface flex items-center justify-between gap-3 hover:bg-surface-container-high rounded-xl transition-colors">
                        {{ __('faq.content_q_host_first') }}
                        <span class="material-symbols-outlined text-lg text-on-surface-variant group-open:rotate-180 transition-transform shrink-0" aria-hidden="true">expand_more</span>
                    </summary>
                    <div class="px-5 pb-4 text-sm text-on-surface-variant leading-relaxed">
                        {{ __('faq.content_a_host_first') }}
                        <a href="{{ route('for-organizers') }}" wire:navigate class="mt-2 inline-flex items-center text-sm font-medium text-primary hover:underline">
                            {{ __('faq.action_view_for_organizers') }} →
                        </a>
                    </div>
                </details>

                <details id="q-create-event" class="group bg-surface-container rounded-xl">
                    <summary class="px-5 py-3.5 cursor-pointer text-sm font-semibold text-on-surface flex items-center justify-between gap-3 hover:bg-surface-container-high rounded-xl transition-colors">
                        {{ __('faq.content_q_create_event') }}
                        <span class="material-symbols-outlined text-lg text-on-surface-variant group-open:rotate-180 transition-transform shrink-0" aria-hidden="true">expand_more</span>
                    </summary>
                    <div class="px-5 pb-4 text-sm text-on-surface-variant leading-relaxed">
                        {{ __('faq.content_a_create_event') }}
                    </div>
                </details>
            </div>

            {{-- Section: Trust & privacy --}}
            <h2 id="trust" class="text-2xl font-heading font-bold tracking-tight text-on-surface mb-6">{{ __('faq.heading_section_trust') }}</h2>
            <div class="space-y-3 mb-14">
                <details id="q-venue-verified" class="group bg-surface-container rounded-xl">
                    <summary class="px-5 py-3.5 cursor-pointer text-sm font-semibold text-on-surface flex items-center justify-between gap-3 hover:bg-surface-container-high rounded-xl transition-colors">
                        {{ __('faq.content_q_venue_verified') }}
                        <span class="material-symbols-outlined text-lg text-on-surface-variant group-open:rotate-180 transition-transform shrink-0" aria-hidden="true">expand_more</span>
                    </summary>
                    <div class="px-5 pb-4 text-sm text-on-surface-variant leading-relaxed">
                        {{ __('faq.content_a_venue_verified') }}
                    </div>
                </details>

                <details id="q-data-rights" class="group bg-surface-container rounded-xl">
                    <summary class="px-5 py-3.5 cursor-pointer text-sm font-semibold text-on-surface flex items-center justify-between gap-3 hover:bg-surface-container-high rounded-xl transition-colors">
                        {{ __('faq.content_q_data_rights') }}
                        <span class="material-symbols-outlined text-lg text-on-surface-variant group-open:rotate-180 transition-transform shrink-0" aria-hidden="true">expand_more</span>
                    </summary>
                    <div class="px-5 pb-4 text-sm text-on-surface-variant leading-relaxed">
                        {{ __('faq.content_a_data_rights') }}
                        <a href="{{ route('privacy', app()->getLocale()) }}" wire:navigate class="mt-2 inline-flex items-center text-sm font-medium text-primary hover:underline">
                            {{ __('faq.action_view_privacy') }} →
                        </a>
                    </div>
                </details>
            </div>

            {{-- Contact CTA: the remaining-questions path --}}
            <div class="rounded-2xl bg-surface-container-low border border-outline-variant/30 px-6 py-8 sm:px-10 text-center">
                <span class="material-symbols-outlined text-3xl text-primary" aria-hidden="true">forum</span>
                <p class="mt-3 text-base text-on-surface max-w-xl mx-auto">{{ __('faq.content_faq_still_stuck') }}</p>
                <a href="{{ route('contact') }}" wire:navigate
                   class="mt-6 inline-flex items-center gap-2 px-6 py-3 bg-primary text-on-primary rounded-xl hover:brightness-110 transition-all font-semibold text-sm">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">mail</span>
                    {{ __('faq.action_contact_us') }}
                </a>
            </div>
        </div>
    </section>
</x-public-layout>
