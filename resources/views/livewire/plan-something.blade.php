<div class="py-8">
    <div class="max-w-2xl mx-auto px-4 sm:px-6">

        {{-- Page Header --}}
        <div class="mb-6 sm:mb-8">
            <div class="flex items-center gap-3 mb-1">
                <a href="{{ route('dashboard') }}" wire:navigate class="text-on-surface-variant hover:text-on-surface transition-colors">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">arrow_back</span>
                </a>
                <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('plan.heading_plan_something') }}</h1>
            </div>
            <p class="ml-8 sm:ml-9 text-sm text-on-surface-variant">{{ __('plan.content_choose_frequency') }}</p>
        </div>

        {{-- Frequency choice --}}
        <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
            <div class="flex items-center gap-2.5 mb-5">
                <span class="material-symbols-outlined text-lg text-on-surface-variant" aria-hidden="true">repeat</span>
                <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface">{{ __('plan.content_how_often') }}</h2>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                {{-- One-time session --}}
                <button type="button" wire:click="planOneShot()"
                        class="group flex flex-col items-center gap-3 p-6 rounded-xl border-2 border-outline-variant/30 bg-surface-container-lowest hover:border-primary/50 hover:bg-surface-container-high transition-all active:scale-[0.98] cursor-pointer text-center">
                    <span class="material-symbols-outlined text-4xl text-primary group-hover:scale-110 transition-transform" aria-hidden="true">event</span>
                    <span class="text-base font-heading font-semibold text-on-surface">{{ __('plan.content_one_time') }}</span>
                    <span class="text-xs text-on-surface-variant">{{ __('plan.content_one_time_desc') }}</span>
                </button>

                {{-- Recurring event --}}
                <button type="button" wire:click="planRecurring()"
                        class="group flex flex-col items-center gap-3 p-6 rounded-xl border-2 border-outline-variant/30 bg-surface-container-lowest hover:border-primary/50 hover:bg-surface-container-high transition-all active:scale-[0.98] cursor-pointer text-center">
                    <span class="material-symbols-outlined text-4xl text-primary group-hover:scale-110 transition-transform" aria-hidden="true">repeat</span>
                    <span class="text-base font-heading font-semibold text-on-surface">{{ __('plan.content_recurring') }}</span>
                    <span class="text-xs text-on-surface-variant">{{ __('plan.content_recurring_desc') }}</span>
                </button>
            </div>
        </section>

        {{-- Helper text --}}
        <p class="mt-4 text-xs text-on-surface-variant text-center">
            {{ __('plan.content_smart_defaults_hint') }}
        </p>
    </div>
</div>
