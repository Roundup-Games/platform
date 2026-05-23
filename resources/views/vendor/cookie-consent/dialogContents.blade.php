@php
    $categories = $cookieConsentConfig['categories'] ?? [];
@endphp

<div class="js-cookie-consent cookie-consent fixed bottom-0 inset-x-0 pb-2 z-50" role="dialog" aria-label="{{ __('cookie-consent.heading_banner_title') }}">
    <div class="max-w-2xl mx-auto px-4">
        <div class="rounded-2xl bg-surface-container-highest border border-outline-variant/30 shadow-lg p-5 sm:p-6">
            {{-- Header --}}
            <div class="flex items-center gap-2 mb-3">
                <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">shield</span>
                <h2 class="text-base font-semibold text-on-surface">{{ __('cookie-consent.heading_banner_title') }}</h2>
            </div>

            {{-- Message --}}
            <p class="text-sm text-on-surface-variant mb-4">
                {{ __('cookie-consent.content_banner_message') }}
            </p>

            {{-- Categories --}}
            <div class="space-y-3 mb-5">
                @foreach ($categories as $key => $category)
                    <label class="flex items-start gap-3 cursor-pointer group">
                        @if ($category['required'] ?? false)
                            <input
                                type="checkbox"
                                checked
                                disabled
                                class="js-cookie-consent-category mt-0.5 h-4 w-4 rounded border-outline text-primary focus:ring-secondary/20 accent-primary cursor-not-allowed opacity-60"
                                data-category="{{ $key }}"
                                data-default="true"
                            />
                        @else
                            <input
                                type="checkbox"
                                class="js-cookie-consent-category mt-0.5 h-4 w-4 rounded border-outline text-primary focus:ring-secondary/20 accent-primary cursor-pointer"
                                data-category="{{ $key }}"
                                data-default="{{ ($category['default'] ?? false) ? 'true' : 'false' }}"
                            />
                        @endif
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-on-surface">
                                    {{ __($category['label_key']) }}
                                </span>
                                @if ($category['required'] ?? false)
                                    <span class="text-xs font-medium text-primary bg-primary/10 px-1.5 py-0.5 rounded-full">
                                        {{ __('cookie-consent.label_always_active') }}
                                    </span>
                                @endif
                            </div>
                            <p class="text-xs text-on-surface-variant mt-0.5">
                                {{ __($category['description_key']) }}
                            </p>
                        </div>
                    </label>
                @endforeach
            </div>

            {{-- Action buttons --}}
            <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                <button
                    type="button"
                    class="js-cookie-consent-reject-optional inline-flex items-center justify-center px-4 py-2 rounded-xl text-sm font-medium text-on-surface-variant bg-surface-container-high hover:bg-surface-container hover:text-on-surface transition-colors focus:outline-none focus:ring-2 focus:ring-secondary/20"
                >
                    {{ __('cookie-consent.action_reject_optional') }}
                </button>
                <button
                    type="button"
                    class="js-cookie-consent-accept-selected inline-flex items-center justify-center px-4 py-2 rounded-xl text-sm font-medium text-on-secondary-container bg-secondary-container hover:opacity-90 transition-colors focus:outline-none focus:ring-2 focus:ring-secondary/20"
                >
                    {{ __('cookie-consent.action_save_selected') }}
                </button>
                <button
                    type="button"
                    class="js-cookie-consent-accept-all inline-flex items-center justify-center px-4 py-2 rounded-xl text-sm font-medium text-on-primary bg-primary hover:opacity-90 active:scale-[0.98] transition-all focus:outline-none focus:ring-2 focus:ring-secondary/20"
                >
                    {{ __('cookie-consent.action_accept_all') }}
                </button>
            </div>
        </div>
    </div>
</div>
