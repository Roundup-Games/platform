<x-public-layout>

    {{-- ── Hero ─────────────────────────────────────────────── --}}
    <section class="relative bg-primary text-on-primary overflow-hidden">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 right-0 w-72 h-72 bg-on-primary rounded-full -translate-y-1/2 translate-x-1/3"></div>
            <div class="absolute bottom-0 left-0 w-56 h-56 bg-on-primary rounded-full translate-y-1/2 -translate-x-1/3"></div>
        </div>
        <div class="relative max-w-6xl mx-auto px-4 sm:px-6 py-20 sm:py-28 lg:py-32 text-center">
            <h1 class="text-4xl sm:text-5xl lg:text-6xl font-heading font-bold tracking-tight leading-tight">
                {{ __('pages.content_about_heading_vision') }}
            </h1>
            <p class="mt-6 text-lg sm:text-xl text-on-primary/80 max-w-2xl mx-auto leading-relaxed">
                {{ __('pages.content_about_intro', ['brand' => config('company.display_name')]) }}
            </p>
        </div>
    </section>

    {{-- ── Why Local Matters ─────────────────────────────────── --}}
    <section id="why-local" class="py-16 sm:py-20 bg-surface">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 text-center">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-8">
                {{ __('pages.content_about_why_local_heading') }}
            </h2>
            <div class="space-y-6 text-on-surface-variant leading-relaxed">
                <p>{{ __('pages.content_about_why_local_p1') }}</p>
                <p>{{ __('pages.content_about_why_local_p2') }}</p>
                <p>{{ __('pages.content_about_why_local_p3', ['brand' => config('company.display_name')]) }}</p>
            </div>
        </div>
    </section>

    {{-- ── What Drives Us ───────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface text-center mb-12">
                {{ __('pages.content_about_what_drives_us') }}
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8">
                {{-- Community First --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">diversity_3</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('pages.content_about_value_community_first_title') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed mb-3">
                                {{ __('pages.content_about_value_community_first_body') }}
                            </p>
                            <a href="{{ route('pledge') }}" wire:navigate class="text-sm font-medium text-primary hover:underline">{{ __('pages.content_safe_spaces_pledge_link') }}</a>
                        </div>
                    </div>
                </div>

                {{-- Safe by Design --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">shield_person</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('pages.content_about_value_safe_by_design_title') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed mb-3">
                                {{ __('pages.content_about_value_safe_by_design_body') }}
                            </p>
                            <div class="flex gap-3 text-sm font-medium">
                                <a href="{{ route('safety-tools') }}" wire:navigate class="text-primary hover:underline">{{ __('safety.content_safety_tools') }}</a>
                                <a href="{{ route('pledge.algorithms') }}" wire:navigate class="text-primary hover:underline">{{ __('pages.content_about_commitment_algorithms_action') }}</a>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Open by Default --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                    <div class="flex items-start gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-full flex items-center justify-center flex-shrink-0">
                            <span class="material-symbols-outlined text-primary text-2xl" aria-hidden="true">code</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">{{ __('pages.content_about_value_open_by_default_title') }}</h3>
                            <p class="text-sm text-on-surface-variant leading-relaxed mb-3">
                                {{ __('pages.content_about_value_open_by_default_body') }}
                            </p>
                            <div class="flex gap-3 text-sm font-medium">
                                <a href="https://github.com/roundup-games/platform" target="_blank" rel="noopener" class="text-primary hover:underline">GitHub</a>
                                <a href="{{ route('pledge.algorithms') }}" wire:navigate class="text-primary hover:underline">{{ __('pages.content_about_commitment_algorithms_action') }}</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Our Commitments ─────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface-container-low">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface text-center mb-4">
                {{ __('pages.content_about_commitments_heading') }}
            </h2>
            <p class="text-center text-on-surface-variant mb-12 max-w-2xl mx-auto">
                {{ __('pages.content_about_commitments_subtitle') }}
            </p>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                {{-- Algorithms (live) --}}
                <a href="{{ route('pledge.algorithms') }}" wire:navigate class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient hover:shadow-md transition-shadow group">
                    <div class="w-10 h-10 bg-primary/10 rounded-full flex items-center justify-center mb-4">
                        <span class="material-symbols-outlined text-primary text-xl" aria-hidden="true">functions</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface mb-2 group-hover:text-primary transition-colors">{{ __('pages.content_about_commitment_algorithms_title') }}</h3>
                    <p class="text-sm text-on-surface-variant leading-relaxed">{{ __('pages.content_about_commitment_algorithms_body') }}</p>
                    <span class="inline-block mt-3 text-sm font-medium text-primary">{{ __('pages.content_about_commitment_algorithms_action') }} →</span>
                </a>

                {{-- Finances (coming soon) --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient opacity-75">
                    <div class="w-10 h-10 bg-primary/10 rounded-full flex items-center justify-center mb-4">
                        <span class="material-symbols-outlined text-primary text-xl" aria-hidden="true">account_balance</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface mb-2">{{ __('pages.content_about_commitment_finances_title') }}</h3>
                    <p class="text-sm text-on-surface-variant leading-relaxed">{{ __('pages.content_about_commitment_finances_body') }}</p>
                    <span class="inline-block mt-3 text-sm font-medium text-on-surface-variant">{{ __('pages.content_about_commitment_finances_status') }}</span>
                </div>

                {{-- Roadmap (coming soon) --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient opacity-75">
                    <div class="w-10 h-10 bg-primary/10 rounded-full flex items-center justify-center mb-4">
                        <span class="material-symbols-outlined text-primary text-xl" aria-hidden="true">map</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface mb-2">{{ __('pages.content_about_commitment_roadmap_title') }}</h3>
                    <p class="text-sm text-on-surface-variant leading-relaxed">{{ __('pages.content_about_commitment_roadmap_body') }}</p>
                    <span class="inline-block mt-3 text-sm font-medium text-on-surface-variant">{{ __('pages.content_about_commitment_roadmap_status') }}</span>
                </div>

                {{-- Operations (coming soon) --}}
                <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient opacity-75">
                    <div class="w-10 h-10 bg-primary/10 rounded-full flex items-center justify-center mb-4">
                        <span class="material-symbols-outlined text-primary text-xl" aria-hidden="true">gavel</span>
                    </div>
                    <h3 class="font-heading font-semibold text-on-surface mb-2">{{ __('pages.content_about_commitment_operations_title') }}</h3>
                    <p class="text-sm text-on-surface-variant leading-relaxed">{{ __('pages.content_about_commitment_operations_body') }}</p>
                    <span class="inline-block mt-3 text-sm font-medium text-on-surface-variant">{{ __('pages.content_about_commitment_operations_status') }}</span>
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
