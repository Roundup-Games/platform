<x-public-layout>

    {{-- ── Hero ─────────────────────────────────────────────── --}}
    <section class="relative bg-primary text-on-primary overflow-hidden">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 right-0 w-72 h-72 bg-on-primary rounded-full -translate-y-1/2 translate-x-1/3"></div>
            <div class="absolute bottom-0 left-0 w-56 h-56 bg-on-primary rounded-full translate-y-1/2 -translate-x-1/3"></div>
        </div>
        <div class="relative max-w-6xl mx-auto px-4 sm:px-6 py-20 sm:py-28 lg:py-32 text-center">
            <h1 class="text-4xl sm:text-5xl lg:text-6xl font-heading font-bold tracking-tight leading-tight">
                {{ __('pages.content_pledge_hero_heading') }}
            </h1>
            <p class="mt-6 text-lg sm:text-xl text-on-primary/80 max-w-2xl mx-auto leading-relaxed">
                {{ __('pages.content_pledge_hero_subtitle') }}
            </p>
        </div>
    </section>

    {{-- ── Non-profit by Design ────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="max-w-3xl mx-auto text-center">
                <div class="w-16 h-16 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-6">
                    <span class="material-symbols-outlined text-primary text-3xl" aria-hidden="true">volunteer_activism</span>
                </div>
                <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-6">
                    {{ __('pages.content_pledge_nonprofit_heading') }}
                </h2>
                <p class="text-on-surface-variant leading-relaxed text-lg">
                    {{ __('pages.content_pledge_nonprofit_body', ['brand' => config('company.display_name')]) }}
                </p>
                <ul class="mt-8 text-left max-w-xl mx-auto space-y-3">
                    <li class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-primary text-xl mt-0.5 shrink-0" aria-hidden="true">check_circle</span>
                        <span class="text-on-surface-variant">{{ __('pages.content_pledge_nonprofit_detail_1') }}</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-primary text-xl mt-0.5 shrink-0" aria-hidden="true">check_circle</span>
                        <span class="text-on-surface-variant">{{ __('pages.content_pledge_nonprofit_detail_2') }}</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-primary text-xl mt-0.5 shrink-0" aria-hidden="true">check_circle</span>
                        <span class="text-on-surface-variant">{{ __('pages.content_pledge_nonprofit_detail_3') }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </section>

    {{-- ── Open by Default ─────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface-container-low">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="max-w-3xl mx-auto text-center">
                <div class="w-16 h-16 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-6">
                    <span class="material-symbols-outlined text-primary text-3xl" aria-hidden="true">code</span>
                </div>
                <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-6">
                    {{ __('pages.content_pledge_open_heading') }}
                </h2>
                <p class="text-on-surface-variant leading-relaxed text-lg">
                    {{ __('pages.content_pledge_open_body') }}
                </p>
                <div class="mt-8 flex flex-wrap justify-center gap-4">
                    <a href="https://github.com/roundup-games/platform" target="_blank" rel="noopener noreferrer"
                       class="inline-flex items-center px-5 py-2.5 bg-surface-container-lowest text-on-surface rounded-xl font-semibold hover:bg-surface-container-low transition-colors text-sm shadow-ambient border border-outline-variant">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">open_in_new</span>
                        {{ __('pages.content_pledge_open_github') }}
                    </a>
                    <a href="{{ route('pledge.roadmap', app()->getLocale()) }}" wire:navigate
                       class="inline-flex items-center px-5 py-2.5 bg-surface-container-lowest text-on-surface rounded-xl font-semibold hover:bg-surface-container-low transition-colors text-sm shadow-ambient border border-outline-variant">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">map</span>
                        {{ __('pages.content_commitment_roadmap_action') }}
                    </a>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Community Investment ─────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12">
                <div class="w-16 h-16 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-6">
                    <span class="material-symbols-outlined text-primary text-3xl" aria-hidden="true">favorite</span>
                </div>
                <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-4">
                    {{ __('pages.content_pledge_investment_heading') }}
                </h2>
                <p class="text-on-surface-variant max-w-xl mx-auto leading-relaxed">
                    {{ __('pages.content_pledge_investment_body') }}
                </p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-8 max-w-4xl mx-auto">
                {{-- Local Events --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">event</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('pages.content_pledge_investment_events') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('pages.content_pledge_investment_events_body') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Organizer Education --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">school</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('pages.content_pledge_investment_education') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('pages.content_pledge_investment_education_body') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Trust & Safety --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">shield_person</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('pages.content_pledge_investment_trust') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('pages.content_pledge_investment_trust_body') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Promoting Tabletop Gaming --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">campaign</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('pages.content_pledge_investment_promotion') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('pages.content_pledge_investment_promotion_body') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Sub-page Cards ───────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface-container-low">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <div class="text-center mb-12">
                <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-4">
                    {{ __('pages.content_pledge_cards_heading') }}
                </h2>
                <p class="text-on-surface-variant max-w-xl mx-auto">
                    {{ __('pages.content_commitments_subtitle') }}
                </p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 max-w-5xl mx-auto">
                {{-- Open Algorithms (live) --}}
                <a href="{{ route('pledge.algorithms', app()->getLocale()) }}" wire:navigate
                   class="group bg-surface-container-lowest rounded-xl p-6 shadow-ambient hover:shadow-md transition-all hover:-translate-y-1 flex flex-col">
                    <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center mb-4">
                        <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">psychology</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('pages.content_commitment_algorithms_title') }}</h3>
                    <p class="text-sm text-on-surface-variant leading-relaxed flex-1">
                        {{ __('pages.content_commitment_algorithms_body') }}
                    </p>
                    <span class="mt-4 inline-flex items-center text-primary font-semibold text-sm group-hover:gap-2 transition-all">
                        {{ __('pages.content_commitment_algorithms_action') }}
                        <span class="material-symbols-outlined text-base ml-1" aria-hidden="true">arrow_forward</span>
                    </span>
                </a>

                {{-- Operations & Policies (live, M066/S08/T05) — the card is a
                     div, not a single anchor, because it carries sub-links to
                     safety tools + algorithms alongside the CoC link. --}}
                <div class="group bg-surface-container-lowest rounded-xl p-6 shadow-ambient hover:shadow-md transition-all hover:-translate-y-1 flex flex-col">
                    <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center mb-4">
                        <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">policy</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('pages.content_commitment_operations_title') }}</h3>
                    <p class="text-sm text-on-surface-variant leading-relaxed flex-1">
                        {{ __('pages.content_commitment_operations_body') }}
                    </p>
                    <a href="{{ route('safety.code-of-conduct') }}" wire:navigate
                       class="mt-4 inline-flex items-center text-primary font-semibold text-sm group-hover:gap-2 transition-all">
                        {{ __('pages.content_commitment_operations_action') }}
                        <span class="material-symbols-outlined text-base ml-1" aria-hidden="true">arrow_forward</span>
                    </a>
                    <div class="mt-3 pt-3 border-t border-outline-variant/60 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                        <a href="{{ route('safety-tools') }}" wire:navigate
                           class="text-on-surface-variant hover:text-primary underline-offset-2 hover:underline font-medium">
                            {{ __('pages.content_commitment_operations_link_safety') }}
                        </a>
                        <a href="{{ route('pledge.algorithms', app()->getLocale()) }}" wire:navigate
                           class="text-on-surface-variant hover:text-primary underline-offset-2 hover:underline font-medium">
                            {{ __('pages.content_commitment_operations_link_algorithms') }}
                        </a>
                    </div>
                </div>

                {{-- The Road Ahead (live) --}}
                <a href="{{ route('pledge.roadmap', app()->getLocale()) }}" wire:navigate
                   class="group bg-surface-container-lowest rounded-xl p-6 shadow-ambient hover:shadow-md transition-all hover:-translate-y-1 flex flex-col">
                    <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center mb-4">
                        <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">map</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('pages.content_commitment_roadmap_title') }}</h3>
                    <p class="text-sm text-on-surface-variant leading-relaxed flex-1">
                        {{ __('pages.content_commitment_roadmap_body') }}
                    </p>
                    <span class="mt-4 inline-flex items-center text-primary font-semibold text-sm group-hover:gap-2 transition-all">
                        {{ __('pages.content_commitment_roadmap_action') }}
                        <span class="material-symbols-outlined text-base ml-1" aria-hidden="true">arrow_forward</span>
                    </span>
                </a>
            </div>
        </div>
    </section>

    {{-- ── CTA (player audience — shared component) ─────────── --}}
    <x-cta-block variant="player" />
</x-public-layout>
