<x-public-layout>

    {{-- Hero --}}
    <section class="relative bg-primary text-on-primary overflow-hidden">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 right-0 w-72 h-72 bg-on-primary rounded-full -translate-y-1/2 translate-x-1/3"></div>
            <div class="absolute bottom-0 left-0 w-56 h-56 bg-on-primary rounded-full translate-y-1/2 -translate-x-1/3"></div>
        </div>
        <div class="relative max-w-6xl mx-auto px-4 sm:px-6 py-20 sm:py-28 text-center">
            <h1 class="text-4xl sm:text-5xl font-heading font-bold tracking-tight leading-tight">
                {{ __('safety.heading_code_of_conduct') }}
            </h1>
            <p class="mt-6 text-lg sm:text-xl text-on-primary/80 max-w-2xl mx-auto">
                {{ __('safety.content_coc_hero_subtitle') }}
            </p>
        </div>
    </section>

    {{-- Intro --}}
    <section class="py-16 bg-surface">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 text-center">
            <p class="text-lg text-on-surface-variant leading-relaxed max-w-3xl mx-auto">
                {{ __('safety.content_coc_intro') }}
            </p>
        </div>
    </section>

    {{-- The rules --}}
    <section id="rules" class="py-12 bg-surface-container-low">
        <div class="max-w-5xl mx-auto px-4 sm:px-6">
            <h2 class="text-2xl sm:text-3xl font-heading font-bold tracking-tight text-on-surface text-center mb-10">
                {{ __('safety.heading_coc_rules') }}
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="bg-surface-container-high rounded-xl shadow-ambient p-6">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-10 h-10 bg-primary/10 rounded-full flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">handshake</span>
                        </div>
                        <h3 class="text-lg font-heading font-semibold text-on-surface">{{ __('safety.field_coc_rule_respect_title') }}</h3>
                    </div>
                    <p class="text-sm text-on-surface-variant leading-relaxed">{{ __('safety.content_coc_rule_respect_body') }}</p>
                </div>

                <div class="bg-surface-container-high rounded-xl shadow-ambient p-6">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-10 h-10 bg-primary/10 rounded-full flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">shield</span>
                        </div>
                        <h3 class="text-lg font-heading font-semibold text-on-surface">{{ __('safety.field_coc_rule_safety_tools_title') }}</h3>
                    </div>
                    <p class="text-sm text-on-surface-variant leading-relaxed">{{ __('safety.content_coc_rule_safety_tools_body') }}</p>
                    <a href="{{ route('safety-tools') }}" wire:navigate class="mt-3 inline-flex items-center text-sm font-medium text-primary hover:underline">
                        {{ __('safety.action_coc_read_safety_tools') }} →
                    </a>
                </div>

                <div class="bg-surface-container-high rounded-xl shadow-ambient p-6">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-10 h-10 bg-primary/10 rounded-full flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">fact_check</span>
                        </div>
                        <h3 class="text-lg font-heading font-semibold text-on-surface">{{ __('safety.field_coc_rule_attendance_title') }}</h3>
                    </div>
                    <p class="text-sm text-on-surface-variant leading-relaxed">{{ __('safety.content_coc_rule_attendance_body') }}</p>
                </div>

                <div class="bg-surface-container-high rounded-xl shadow-ambient p-6">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-10 h-10 bg-primary/10 rounded-full flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">block</span>
                        </div>
                        <h3 class="text-lg font-heading font-semibold text-on-surface">{{ __('safety.field_coc_rule_no_harassment_title') }}</h3>
                    </div>
                    <p class="text-sm text-on-surface-variant leading-relaxed">{{ __('safety.content_coc_rule_no_harassment_body') }}</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Consequences ladder --}}
    <section id="consequences" class="py-16 bg-surface">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <h2 class="text-2xl sm:text-3xl font-heading font-bold tracking-tight text-on-surface text-center mb-4">
                {{ __('safety.heading_coc_consequences') }}
            </h2>
            <p class="text-base text-on-surface-variant leading-relaxed text-center max-w-3xl mx-auto mb-10">
                {{ __('safety.content_coc_consequences_intro') }}
            </p>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-surface-container-low rounded-xl p-6 text-center border-t-4 border-primary/30">
                    <span class="material-symbols-outlined text-3xl text-primary" aria-hidden="true">notifications</span>
                    <h3 class="mt-3 font-heading font-semibold text-on-surface">{{ __('safety.label_coc_step_warning') }}</h3>
                    <p class="mt-2 text-sm text-on-surface-variant leading-relaxed">{{ __('safety.content_coc_step_warning_body') }}</p>
                </div>
                <div class="bg-surface-container-low rounded-xl p-6 text-center border-t-4 border-primary/60">
                    <span class="material-symbols-outlined text-3xl text-primary" aria-hidden="true">delete</span>
                    <h3 class="mt-3 font-heading font-semibold text-on-surface">{{ __('safety.label_coc_step_removal') }}</h3>
                    <p class="mt-2 text-sm text-on-surface-variant leading-relaxed">{{ __('safety.content_coc_step_removal_body') }}</p>
                </div>
                <div class="bg-surface-container-low rounded-xl p-6 text-center border-t-4 border-primary">
                    <span class="material-symbols-outlined text-3xl text-primary" aria-hidden="true">pause_circle</span>
                    <h3 class="mt-3 font-heading font-semibold text-on-surface">{{ __('safety.label_coc_step_suspension') }}</h3>
                    <p class="mt-2 text-sm text-on-surface-variant leading-relaxed">{{ __('safety.content_coc_step_suspension_body') }}</p>
                </div>
            </div>

            <p class="mt-8 text-sm text-on-surface-variant text-center max-w-2xl mx-auto">
                {{ __('safety.content_coc_consequences_note') }}
            </p>
        </div>
    </section>

    {{-- How to report & appeal --}}
    <section id="report" class="py-12 bg-surface-container-low">
        <div class="max-w-4xl mx-auto px-4 sm:px-6">
            <h2 class="text-2xl sm:text-3xl font-heading font-bold tracking-tight text-on-surface text-center mb-6">
                {{ __('safety.heading_coc_report') }}
            </h2>

            <div class="bg-surface-container-high rounded-xl shadow-ambient p-8">
                <p class="text-sm text-on-surface-variant leading-relaxed">
                    {{ __('safety.content_coc_report_body') }}
                </p>
                <p class="mt-3 text-sm text-on-surface-variant leading-relaxed">
                    {{ __('safety.content_coc_report_email_note', ['email' => config('company.contact.support')]) }}
                </p>
                <a href="{{ route('contact') }}" wire:navigate
                   class="mt-6 inline-flex items-center gap-2 px-6 py-2.5 bg-primary text-on-primary rounded-lg hover:bg-primary/90 transition-colors font-medium text-sm">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">mail</span>
                    {{ __('safety.action_coc_report_contact') }}
                </a>
            </div>

            <div class="mt-6 rounded-xl border border-outline-variant/30 p-6">
                <p class="text-sm text-on-surface-variant leading-relaxed">
                    {{ __('safety.content_coc_appeal_body') }}
                </p>
            </div>
        </div>
    </section>
</x-public-layout>
