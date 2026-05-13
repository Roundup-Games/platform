<x-public-layout>

    {{-- ── Hero ─────────────────────────────────────────────── --}}
    <section class="relative bg-primary text-on-primary overflow-hidden">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 right-0 w-72 h-72 bg-on-primary rounded-full -translate-y-1/2 translate-x-1/3"></div>
            <div class="absolute bottom-0 left-0 w-56 h-56 bg-on-primary rounded-full translate-y-1/2 -translate-x-1/3"></div>
        </div>
        <div class="relative max-w-6xl mx-auto px-4 sm:px-6 py-20 sm:py-28 lg:py-32 text-center">
            <h1 class="text-4xl sm:text-5xl lg:text-6xl font-heading font-bold tracking-tight leading-tight">
                {{ __('pages.about_heading_vision') }}
            </h1>
            <p class="mt-6 text-lg sm:text-xl text-on-primary/80 max-w-2xl mx-auto leading-relaxed">
                {{ __('pages.about_intro') }}
            </p>
        </div>
    </section>

    {{-- ── What Drives Us ───────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface text-center mb-12">
                {{ __('pages.about_what_drives_us') }}
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8">
                {{-- Transparency --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">visibility</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('pages.about_transparency_title') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('pages.about_transparency_body') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Safety --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">shield_person</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('pages.about_safety_title') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('pages.about_safety_body') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Inclusivity & Diversity --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">diversity_3</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('pages.about_inclusivity_title') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('pages.about_inclusivity_body') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Investing in Community --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">favorite</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('pages.about_community_title') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('pages.about_community_body') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Try Something New --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">auto_awesome</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('pages.about_experiment_title') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('pages.about_experiment_body') }}
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Open Source --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">code</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('pages.about_open_source_title') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed">
                                {{ __('pages.about_open_source_body') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── CTA ─────────────────────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-primary text-on-primary">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 text-center">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight">
                {{ __('common.content_ready_to_find_your_table') }}
            </h2>
            <p class="mt-4 text-on-primary/80 max-w-xl mx-auto">
                {{ __('campaigns.content_create_a_free_account_set') }}
            </p>
            <div class="mt-8 flex flex-wrap justify-center gap-4">
                @guest
                    <a href="{{ route('register') }}" wire:navigate
                       class="inline-flex items-center px-6 py-3 bg-surface text-primary rounded-xl font-semibold hover:bg-surface-container-lowest transition-colors text-sm shadow-md">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">person_add</span>
                        {{ __('auth.content_sign_up_free') }}
                    </a>
                    <a href="{{ route('discover') }}" wire:navigate
                       class="inline-flex items-center px-6 py-3 bg-on-primary/20 text-on-primary rounded-xl font-semibold hover:bg-on-primary/30 transition-colors text-sm border border-on-primary/30">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">explore</span>
                        {{ __('campaigns.action_browse_sessions') }}
                    </a>
                @else
                    <a href="{{ route('discover') }}" wire:navigate
                       class="inline-flex items-center px-6 py-3 bg-surface text-primary rounded-xl font-semibold hover:bg-surface-container-lowest transition-colors text-sm shadow-md">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">explore</span>
                        {{ __('campaigns.action_browse_sessions') }}
                    </a>
                @endguest
            </div>
        </div>
    </section>
</x-public-layout>
