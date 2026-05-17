<x-public-layout>

    {{-- ── Hero ─────────────────────────────────────────────── --}}
    <section class="relative bg-primary text-on-primary overflow-hidden">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 right-0 w-72 h-72 bg-on-primary rounded-full -translate-y-1/2 translate-x-1/3"></div>
            <div class="absolute bottom-0 left-0 w-56 h-56 bg-on-primary rounded-full translate-y-1/2 -translate-x-1/3"></div>
        </div>
        <div class="relative max-w-6xl mx-auto px-4 sm:px-6 py-20 sm:py-28 lg:py-32 text-center">
            <h1 class="text-4xl sm:text-5xl lg:text-6xl font-heading font-bold tracking-tight leading-tight">
                {{ __('impressum.heading_title') }}
            </h1>
        </div>
    </section>

    {{-- ── Company Information ────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-8">
                {{ __('impressum.heading_company') }}
            </h2>

            <div class="bg-surface-container-lowest rounded-xl p-8 shadow-ambient space-y-6">
                <div>
                    <p class="font-heading font-bold text-on-surface text-xl">{{ __('impressum.content_company_name') }}</p>
                </div>

                <div>
                    <p class="text-sm font-medium text-primary mb-1">{{ __('impressum.label_address') }}</p>
                    <p class="text-on-surface-variant">{{ __('impressum.content_address_line_1') }}</p>
                    <p class="text-on-surface-variant">{{ __('impressum.content_address_line_2') }}</p>
                    <p class="text-on-surface-variant">{{ __('impressum.content_address_line_3') }}</p>
                    <p class="text-on-surface-variant">{{ __('impressum.content_address_line_4') }}</p>
                </div>

                <div>
                    <p class="text-sm font-medium text-primary mb-1">{{ __('impressum.label_contact_email') }}</p>
                    <a href="mailto:{{ __('impressum.content_contact_email') }}" class="text-on-surface-variant hover:text-primary transition-colors">{{ __('impressum.content_contact_email') }}</a>
                </div>

                <div>
                    <p class="text-sm font-medium text-primary mb-1">{{ __('impressum.label_vat_id') }}</p>
                    <p class="text-on-surface-variant">{{ __('impressum.content_vat_id') }}</p>
                </div>

                <div>
                    <p class="text-sm font-medium text-primary mb-1">{{ __('impressum.label_responsible_person') }}</p>
                    <p class="text-on-surface-variant">{{ __('impressum.content_responsible_person') }}</p>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Registration ────────────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface-container-low">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-6">
                {{ __('impressum.heading_registration') }}
            </h2>
            <div class="space-y-3 text-on-surface-variant leading-relaxed">
                <p>{{ __('impressum.content_registration_1') }}</p>
                <p>{{ __('impressum.content_registration_2') }}</p>
                <p>{{ __('impressum.content_registration_3') }}</p>
            </div>
        </div>
    </section>

    {{-- ── Dispute Resolution ──────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-6">
                {{ __('impressum.heading_dispute') }}
            </h2>
            <div class="space-y-3 text-on-surface-variant leading-relaxed">
                <p>{{ __('impressum.content_dispute_1') }}</p>
                <p>
                    <a href="{{ __('impressum.content_dispute_url') }}" target="_blank" rel="noopener noreferrer" class="text-primary hover:underline font-medium">
                        {{ __('impressum.content_dispute_url') }}
                    </a>
                </p>
                <p>{{ __('impressum.content_dispute_2') }}</p>
            </div>
        </div>
    </section>

    {{-- ── Responsible for Content ──────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface-container-low">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-6">
                {{ __('impressum.heading_responsible') }}
            </h2>
            <p class="text-on-surface-variant leading-relaxed">{{ __('impressum.content_responsible_1') }}</p>
        </div>
    </section>

    {{-- ── Copyright ────────────────────────────────────────────── --}}
    <section class="py-16 sm:py-20 bg-surface">
        <div class="max-w-3xl mx-auto px-4 sm:px-6">
            <h2 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight text-on-surface mb-6">
                {{ __('impressum.heading_copyright') }}
            </h2>
            <p class="text-on-surface-variant leading-relaxed">{{ __('impressum.content_copyright_1') }}</p>
        </div>
    </section>
</x-public-layout>
