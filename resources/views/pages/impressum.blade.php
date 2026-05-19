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
                    <p class="font-heading font-bold text-on-surface text-xl">{{ config('company.legal_name') }}</p>
                </div>

                @if(config('company.entity_type') === 'pre-incorporation')
                    <div class="bg-tertiary-container text-on-tertiary-container rounded-lg p-4 text-sm leading-relaxed">
                        {{ __('impressum.content_pre_incorporation', [
                            'name' => config('company.legal_name'),
                            'city' => config('company.address.city'),
                            'country' => config('company.address.country'),
                            'person' => config('company.responsible_person.name'),
                        ]) }}
                    </div>
                @endif

                @if(collect(config('company.address'))->filter()->count() > 1)
                    <div>
                        <p class="text-sm font-medium text-primary mb-1">{{ __('impressum.label_address') }}</p>
                        @foreach(array_filter([
                            config('company.address.line_1'),
                            config('company.address.line_2'),
                            trim(collect([config('company.address.postal_code'), config('company.address.city')])->filter()->implode(' ')),
                            config('company.address.country'),
                        ]) as $line)
                            <p class="text-on-surface-variant">{{ $line }}</p>
                        @endforeach
                    </div>
                @endif

                <div>
                    <p class="text-sm font-medium text-primary mb-1">{{ __('impressum.label_contact_email') }}</p>
                    <a href="mailto:{{ config('company.contact.general') }}" class="text-on-surface-variant hover:text-primary transition-colors">{{ config('company.contact.general') }}</a>
                </div>

                @if(config('company.tax.vat_id'))
                    <div>
                        <p class="text-sm font-medium text-primary mb-1">{{ __('impressum.label_vat_id') }}</p>
                        <p class="text-on-surface-variant">{{ config('company.tax.vat_id') }}</p>
                    </div>
                @endif

                <div>
                    <p class="text-sm font-medium text-primary mb-1">{{ __('impressum.label_responsible_person') }}</p>
                    <p class="text-on-surface-variant">{{ config('company.responsible_person.name') }}, {{ config('company.responsible_person.location') }}</p>
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
                @if(config('company.entity_type') === 'pre-incorporation')
                    <p>{{ __('impressum.content_registration_pending') }}</p>
                @else
                    <p>{{ config('company.legal_name') }} is a registered entity under German law.</p>
                    @if(config('company.registration.court'))
                        <p>{{ __('impressum.label_registration_court', ['court' => config('company.registration.court')]) }}</p>
                    @endif
                    @if(config('company.registration.number'))
                        <p>{{ __('impressum.label_registration_number', ['number' => config('company.registration.number')]) }}</p>
                    @endif
                @endif
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
                    <a href="{{ config('company.dispute_resolution.url') }}" target="_blank" rel="noopener noreferrer" class="text-primary hover:underline font-medium">
                        {{ config('company.dispute_resolution.url') }}
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
