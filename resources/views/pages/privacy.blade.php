<x-public-layout>

    {{-- Legal review banner: shown until a legal professional reviews the text --}}
    @if(!config('app.legal_text_reviewed', false))
        <div class="bg-tertiary-container text-on-tertiary-container px-4 py-3 text-center text-sm">
            <span class="material-symbols-outlined text-sm align-middle" aria-hidden="true">info</span>
            {{ __('common.content_legal_draft_notice', ['date' => config('policies.privacy.last_updated')]) }}
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
                {{ __('privacy.heading_title') }}
            </h1>
            <p class="mt-6 text-lg sm:text-xl text-on-primary/80 max-w-2xl mx-auto leading-relaxed">
                {{ __('privacy.content_introduction_2') }}
            </p>
        </div>
    </section>

    {{-- ── Introduction ─────────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-8">
                {{ __('privacy.heading_introduction') }}
            </h2>
            <div class="space-y-4 text-on-surface-variant leading-relaxed">
                <p>{{ __('privacy.content_introduction_1') }}</p>
                <p>{{ __('privacy.content_introduction_3') }}</p>
                <p>
                    <a href="{{ route('pledge', app()->getLocale()) }}" wire:navigate class="text-primary hover:underline font-medium">
                        {{ __('common.nav_our_pledge') }} →
                    </a>
                </p>
            </div>
        </div>
    </section>

    {{-- ── Data We Collect ──────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface-container-low">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-4">
                {{ __('privacy.heading_data_we_collect') }}
            </h2>
            <p class="text-on-surface-variant mb-8 leading-relaxed">{{ __('privacy.content_data_intro') }}</p>

            <div class="space-y-6">
                @foreach ([
                    'account' => 'data_account',
                    'location' => 'data_location',
                    'gaming' => 'data_gaming',
                    'activity' => 'data_activity',
                    'communication' => 'data_communication',
                    'invitations' => 'data_invitations',
                    'sensitive' => 'data_sensitive',
                    'technical' => 'data_technical',
                    'payment' => 'data_payment',
                ] as $key => $prefix)
                    <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                        <h3 class="font-heading font-semibold text-on-surface text-lg mb-1">
                            <span class="material-symbols-outlined text-primary text-lg mr-1 align-middle" aria-hidden="true">
                                {{ $key === 'account' ? 'person' : ($key === 'location' ? 'location_on' : ($key === 'gaming' ? 'casino' : ($key === 'activity' ? 'event_note' : ($key === 'communication' ? 'mail' : ($key === 'invitations' ? 'group_add' : ($key === 'sensitive' ? 'shield' : ($key === 'technical' ? 'settings' : 'payment'))))))) }}
                            </span>
                            {{ __('privacy.heading_' . $prefix) }}
                        </h3>
                        <p class="text-sm text-primary font-medium mb-2">{{ __('privacy.content_' . $prefix . '_purpose') }}</p>
                        <p class="text-sm text-on-surface-variant leading-relaxed">{{ __('privacy.content_' . $prefix . '_items') }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Legal Bases (GDPR) ───────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-4">
                {{ __('privacy.heading_legal_bases') }}
            </h2>
            <p class="text-on-surface-variant mb-8 leading-relaxed">{{ __('privacy.content_legal_intro') }}</p>

            <div class="space-y-3">
                @foreach (['contract', 'consent', 'legitimate', 'obligation'] as $basis)
                    <div class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-primary text-xl mt-0.5 flex-shrink-0" aria-hidden="true">check_circle</span>
                        <span class="text-on-surface-variant leading-relaxed">{{ __('privacy.content_legal_' . $basis) }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Cookies & Tracking ────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface-container-low">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-4">
                {{ __('privacy.heading_cookies') }}
            </h2>
            <p class="text-on-surface-variant mb-6 leading-relaxed">{{ __('privacy.content_cookies_intro') }}</p>

            <div class="space-y-3">
                <div class="flex items-start gap-3">
                    <span class="material-symbols-outlined text-primary text-xl mt-0.5 flex-shrink-0" aria-hidden="true">lock</span>
                    <span class="text-on-surface-variant leading-relaxed">{{ __('privacy.content_cookies_necessary') }}</span>
                </div>
                <div class="flex items-start gap-3">
                    <span class="material-symbols-outlined text-primary text-xl mt-0.5 flex-shrink-0" aria-hidden="true">analytics</span>
                    <span class="text-on-surface-variant leading-relaxed">{{ __('privacy.content_cookies_analytics') }}</span>
                </div>
            </div>
            <p class="mt-6 text-on-surface-variant leading-relaxed">{{ __('privacy.content_cookies_control') }}</p>
        </div>
    </section>

    {{-- ── Third-Party Services ──────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-4">
                {{ __('privacy.heading_third_parties') }}
            </h2>
            <p class="text-on-surface-variant mb-8 leading-relaxed">{{ __('privacy.content_third_intro') }}</p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                @foreach (['posthog', 'paddle', 'cloudflare', 'nominatim'] as $service)
                    <div class="bg-surface-container-lowest rounded-xl p-6 shadow-ambient">
                        <h3 class="font-heading font-semibold text-on-surface text-lg mb-2">
                            {{ __('privacy.heading_third_' . $service) }}
                        </h3>
                        <p class="text-sm text-on-surface-variant leading-relaxed">
                            {{ __('privacy.content_third_' . $service . '_body') }}
                        </p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Your Rights ────────────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface-container-low">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-4">
                {{ __('privacy.heading_your_rights') }}
            </h2>
            <p class="text-on-surface-variant mb-8 leading-relaxed">{{ __('privacy.content_rights_intro') }}</p>

            <div class="space-y-3">
                @foreach (['access', 'rectification', 'erasure', 'portability', 'objection', 'restriction', 'withdraw'] as $right)
                    <div class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-primary text-xl mt-0.5 flex-shrink-0" aria-hidden="true">check_circle</span>
                        <span class="text-on-surface-variant leading-relaxed">{{ __('privacy.content_rights_' . $right) }}</span>
                    </div>
                @endforeach
            </div>

            <p class="mt-6 text-on-surface-variant leading-relaxed">
                {{ __('privacy.content_rights_exercise') }} <a href="mailto:{{ config('company.contact.privacy') }}" class="text-primary hover:underline font-medium">{{ config('company.contact.privacy') }}</a>.
            </p>
            <p class="mt-3 text-on-surface-variant leading-relaxed">{{ __('privacy.content_rights_complaint') }}</p>
        </div>
    </section>

    {{-- ── Data Retention ────────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-4">
                {{ __('privacy.heading_data_retention') }}
            </h2>
            <p class="text-on-surface-variant mb-6 leading-relaxed">{{ __('privacy.content_retention_intro') }}</p>

            <div class="space-y-3">
                @foreach (['account', 'activity', 'analytics', 'legal'] as $type)
                    <div class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-primary text-xl mt-0.5 flex-shrink-0" aria-hidden="true">schedule</span>
                        <span class="text-on-surface-variant leading-relaxed">{{ __('privacy.content_retention_' . $type) }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Contact ────────────────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface-container-low">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 text-center">
            <div class="w-16 h-16 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-6">
                <span class="material-symbols-outlined text-primary text-3xl" aria-hidden="true">mail</span>
            </div>
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-4">
                {{ __('privacy.heading_contact') }}
            </h2>
            <p class="text-on-surface-variant mb-2">{{ __('privacy.content_contact_intro') }}</p>
            <p class="font-semibold text-on-surface">{{ __('privacy.content_contact_org') }}</p>
            <p class="text-on-surface-variant">{{ __('privacy.content_contact_email') }}</p>
        </div>
    </section>

    {{-- ── Last Updated ──────────────────────────────────────── --}}
    <section class="py-8 bg-surface">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 text-center">
            <p class="text-sm text-on-surface-variant">
                {{ __('privacy.content_last_updated', ['date' => config('policies.privacy.last_updated')]) }}
            </p>
        </div>
    </section>
</x-public-layout>
