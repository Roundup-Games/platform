<x-public-layout>

    {{-- Legal review banner: shown until a legal professional reviews the text --}}
    @if(!config('app.legal_text_reviewed', false))
        <div class="bg-tertiary-container text-on-tertiary-container px-4 py-3 text-center text-sm">
            <span class="material-symbols-outlined text-sm align-middle" aria-hidden="true">info</span>
            {{ __('common.content_legal_draft_notice', ['date' => config('policies.terms.last_updated')]) }}
        </div>
    @endif

    {{-- ── Hero ─────────────────────────────────────────────── --}}
    <section class="relative bg-primary text-on-primary overflow-hidden">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 right-0 w-72 h-72 bg-on-primary rounded-full -translate-y-1/2 translate-x-1/3"></div>
            <div class="absolute bottom-0 left-0 w-56 h-56 bg-on-primary rounded-full translate-y-1/2 -translate-x-1/3"></div>
        </div>
        <div class="relative max-w-6xl mx-auto px-4 sm:px-6 py-20 sm:py-28 lg:py-32 text-center">
            <h1 class="text-4xl sm:text-5xl lg:text-6xl font-heading font-bold tracking-tight leading-tight">
                {{ __('terms.heading_title') }}
            </h1>
            <p class="mt-6 text-lg sm:text-xl text-on-primary/80 max-w-2xl mx-auto leading-relaxed">
                {{ __('terms.content_introduction_2') }}
            </p>
        </div>
    </section>

    {{-- ── Introduction ─────────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-8">
                {{ __('terms.heading_introduction') }}
            </h2>
            <div class="space-y-4 text-on-surface-variant leading-relaxed">
                <p>{{ __('terms.content_introduction_1') }}</p>
                <p>{{ __('terms.content_introduction_3') }}</p>
            </div>
        </div>
    </section>

    {{-- ── Eligibility ──────────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface-container-low">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-6">
                {{ __('terms.heading_eligibility') }}
            </h2>
            <div class="space-y-4 text-on-surface-variant leading-relaxed">
                <p>{{ __('terms.content_eligibility_1') }}</p>
                <p>{{ __('terms.content_eligibility_2') }}</p>
            </div>
        </div>
    </section>

    {{-- ── Account ──────────────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-6">
                {{ __('terms.heading_account') }}
            </h2>
            <div class="space-y-4 text-on-surface-variant leading-relaxed">
                <p>{{ __('terms.content_account_1') }}</p>
                <p>{{ __('terms.content_account_2') }}</p>
            </div>

            <div class="mt-8 bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                <h3 class="font-heading font-semibold text-on-surface text-lg mb-4">
                    <span class="material-symbols-outlined text-primary text-lg mr-1 align-middle" aria-hidden="true">delete</span>
                    {{ __('terms.heading_account_deletion') }}
                </h3>
                <div class="space-y-3 text-on-surface-variant leading-relaxed">
                    <p>{{ __('terms.content_account_deletion_1') }}</p>
                    <div class="pl-4 space-y-2">
                        <div class="flex items-start gap-3">
                            <span class="material-symbols-outlined text-primary text-xl mt-0.5 flex-shrink-0" aria-hidden="true">check_circle</span>
                            <span>{{ __('terms.content_account_deletion_2') }}</span>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="material-symbols-outlined text-primary text-xl mt-0.5 flex-shrink-0" aria-hidden="true">check_circle</span>
                            <span>{{ __('terms.content_account_deletion_3') }}</span>
                        </div>
                        <div class="flex items-start gap-3">
                            <span class="material-symbols-outlined text-primary text-xl mt-0.5 flex-shrink-0" aria-hidden="true">check_circle</span>
                            <span>{{ __('terms.content_account_deletion_4') }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Community Conduct ─────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface-container-low">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-4">
                {{ __('terms.heading_community_conduct') }}
            </h2>
            <p class="text-on-surface-variant mb-6 leading-relaxed">{{ __('terms.content_conduct_intro') }}</p>

            <div class="space-y-3">
                @foreach (['respect', 'honesty', 'reliability', 'safety', 'legal'] as $rule)
                    <div class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-primary text-xl mt-0.5 flex-shrink-0" aria-hidden="true">check_circle</span>
                        <span class="text-on-surface-variant leading-relaxed">{{ __('terms.conduct_' . $rule) }}</span>
                    </div>
                @endforeach
            </div>

            <p class="mt-6 text-on-surface-variant leading-relaxed">
                {{ __('terms.content_conduct_enforcement') }}
            </p>
        </div>
    </section>

    {{-- ── Content & Ownership ────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-6">
                {{ __('terms.heading_content_ownership') }}
            </h2>
            <div class="space-y-4 text-on-surface-variant leading-relaxed">
                <div class="flex items-start gap-3">
                    <span class="material-symbols-outlined text-primary text-xl mt-0.5 flex-shrink-0" aria-hidden="true">check_circle</span>
                    <span>{{ __('terms.content_yours') }}</span>
                </div>
                <div class="flex items-start gap-3">
                    <span class="material-symbols-outlined text-primary text-xl mt-0.5 flex-shrink-0" aria-hidden="true">info</span>
                    <span>{{ __('terms.content_license') }}</span>
                </div>
                <div class="flex items-start gap-3">
                    <span class="material-symbols-outlined text-primary text-xl mt-0.5 flex-shrink-0" aria-hidden="true">warning</span>
                    <span>{{ __('terms.content_responsibility') }}</span>
                </div>
                <p>{{ __('terms.content_removal') }}</p>
            </div>
        </div>
    </section>

    {{-- ── Platform License ──────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface-container-low">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-6">
                {{ __('terms.heading_platform_license') }}
            </h2>
            <div class="space-y-4 text-on-surface-variant leading-relaxed">
                <p>{{ __('terms.content_license_1') }}</p>
                <p>{{ __('terms.content_license_2') }}</p>
                <p>{{ __('terms.content_license_3') }}</p>
                <div class="mt-4">
                    <a href="https://github.com/roundup-games/platform" target="_blank" rel="noopener noreferrer"
                       class="inline-flex items-center px-5 py-2.5 bg-surface-container-lowest text-on-surface rounded-xl font-semibold hover:bg-surface-container-low transition-colors text-sm shadow-ambient border border-outline-variant">
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">open_in_new</span>
                        GitHub
                    </a>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Subscriptions ─────────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-6">
                {{ __('terms.heading_subscriptions') }}
            </h2>
            <div class="space-y-4 text-on-surface-variant leading-relaxed">
                <p>{{ __('terms.content_subscriptions_1') }}</p>
                <p>{{ __('terms.content_subscriptions_2') }}</p>
                <p>{{ __('terms.content_subscriptions_3') }}</p>
            </div>
        </div>
    </section>

    {{-- ── Limitation of Liability ────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface-container-low">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-6">
                {{ __('terms.heading_liability') }}
            </h2>
            <div class="space-y-4 text-on-surface-variant leading-relaxed">
                <p>{{ __('terms.content_liability_1') }}</p>
                <p>{{ __('terms.content_liability_2') }}</p>
                <p>{{ __('terms.content_liability_3') }}</p>
                <p>{{ __('terms.content_liability_4') }}</p>
            </div>
        </div>
    </section>

    {{-- ── Privacy ────────────────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-6">
                {{ __('terms.heading_privacy') }}
            </h2>
            <p class="text-on-surface-variant leading-relaxed">
                {{ __('terms.content_privacy_ref', ['privacy_link' => '<a href="' . route('privacy', app()->getLocale()) . '" wire:navigate class="text-primary hover:underline font-medium">' . __('terms.content_privacy_link_text') . '</a>']) }}
            </p>
        </div>
    </section>

    {{-- ── Governing Law ──────────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface-container-low">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-6">
                {{ __('terms.heading_governing_law') }}
            </h2>
            <div class="space-y-4 text-on-surface-variant leading-relaxed">
                <p>{{ __('terms.content_governing_1') }}</p>
                <p>{{ __('terms.content_governing_2') }}</p>
            </div>
        </div>
    </section>

    {{-- ── Changes ────────────────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-6">
                {{ __('terms.heading_changes') }}
            </h2>
            <div class="space-y-4 text-on-surface-variant leading-relaxed">
                <p>{{ __('terms.content_changes_1') }}</p>
                <p>{{ __('terms.content_changes_2') }}</p>
            </div>
        </div>
    </section>

    {{-- ── Contact ────────────────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface-container-low">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 text-center">
            <div class="w-16 h-16 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-6">
                <span class="material-symbols-outlined text-primary text-3xl" aria-hidden="true">gavel</span>
            </div>
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-4">
                {{ __('terms.heading_contact') }}
            </h2>
            <p class="font-semibold text-on-surface">{{ __('terms.content_contact_org') }}</p>
            <p class="text-on-surface-variant">{{ __('terms.content_contact_email') }}</p>
        </div>
    </section>

    {{-- ── Last Updated ──────────────────────────────────────── --}}
    <section class="py-8 bg-surface">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 text-center">
            <p class="text-sm text-on-surface-variant">
                {{ __('terms.content_last_updated', ['date' => config('policies.terms.last_updated')]) }}
            </p>
        </div>
    </section>
</x-public-layout>
