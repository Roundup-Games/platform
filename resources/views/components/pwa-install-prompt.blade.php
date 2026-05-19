@props([
    'eligible' => false,
])

@if($eligible)
{{-- Early capture: grab beforeinstallprompt BEFORE Alpine initializes.
     On slow devices, the browser may fire this event before Alpine bootstraps,
     so we store it on window for the Alpine component to pick up later. --}}
<script>
window.addEventListener('beforeinstallprompt', function(e) {
    e.preventDefault();
    window.__pwaDeferredPrompt = e;
    console.log('[pwa-prompt] beforeinstallprompt captured (early)');
});
</script>

<div
    x-data="pwaInstallPrompt()"
    x-init="init()"
    x-show="shouldShow() && visible"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-y-4"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 translate-y-4"
    x-cloak
    class="fixed bottom-4 left-4 right-4 sm:left-auto sm:right-6 sm:bottom-6 sm:w-96 z-50"
    role="dialog"
    aria-label="{{ __('pwa.install_title', ['brand' => config('company.display_name')]) }}"
>
    {{-- Chrome / Android mode --}}
    <template x-if="isChrome">
        <div class="bg-surface-container-lowest rounded-xl shadow-ambient border border-outline-variant/15 p-4">
            <div class="flex items-start gap-3">
                <span class="material-symbols-outlined text-primary text-2xl shrink-0 mt-0.5" aria-hidden="true">install_mobile</span>
                <div class="flex-1 min-w-0">
                    <h3 class="font-heading text-sm font-semibold text-on-surface">{{ __('pwa.install_title', ['brand' => config('company.display_name')]) }}</h3>
                    <p class="text-xs text-on-surface-variant mt-1 leading-relaxed">{{ __('pwa.install_description') }}</p>
                    <div class="flex items-center gap-2 mt-3">
                        <button
                            type="button"
                            x-on:click="installChrome()"
                            class="inline-flex items-center gap-1.5 px-4 py-2 bg-primary text-on-primary rounded-lg text-xs font-semibold hover:opacity-90 transition-opacity"
                        >
                            <span class="material-symbols-outlined text-sm" aria-hidden="true">download</span>
                            {{ __('pwa.install_button') }}
                        </button>
                        <button
                            type="button"
                            x-on:click="dismiss()"
                            class="px-3 py-2 text-xs font-medium text-on-surface-variant hover:text-on-surface transition-colors rounded-lg hover:bg-on-surface/5"
                        >
                            {{ __('pwa.install_dismiss') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>

    {{-- Firefox Android mode --}}
    <template x-if="isFirefox">
        <div class="bg-surface-container-lowest rounded-xl shadow-ambient border border-outline-variant/15 p-4">
            <div class="flex items-start gap-3">
                <span class="material-symbols-outlined text-primary text-2xl shrink-0 mt-0.5" aria-hidden="true">install_mobile</span>
                <div class="flex-1 min-w-0">
                    <h3 class="font-heading text-sm font-semibold text-on-surface">{{ __('pwa.heading_firefox_install_title', ['brand' => config('company.display_name')]) }}</h3>
                    <ol class="text-xs text-on-surface-variant mt-2 space-y-1.5 leading-relaxed list-decimal list-inside">
                        <li class="flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-base text-primary" aria-hidden="true">more_vert</span>
                            {{ __('pwa.content_firefox_install_step_1') }}
                        </li>
                        <li>{{ __('pwa.content_firefox_install_step_2') }}</li>
                    </ol>
                    <div class="flex items-center gap-2 mt-3">
                        <button
                            type="button"
                            x-on:click="dismiss()"
                            class="inline-flex items-center gap-1.5 px-4 py-2 bg-primary text-on-primary rounded-lg text-xs font-semibold hover:opacity-90 transition-opacity"
                        >
                            {{ __('pwa.action_firefox_install_dismiss') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>

    {{-- iOS Safari mode --}}
    <template x-if="!isChrome && !isFirefox">
        <div class="bg-surface-container-lowest rounded-xl shadow-ambient border border-outline-variant/15 p-4">
            <div class="flex items-start gap-3">
                <span class="material-symbols-outlined text-primary text-2xl shrink-0 mt-0.5" aria-hidden="true">ios_share</span>
                <div class="flex-1 min-w-0">
                    <h3 class="font-heading text-sm font-semibold text-on-surface">{{ __('pwa.ios_install_title') }}</h3>
                    <ol class="text-xs text-on-surface-variant mt-2 space-y-1.5 leading-relaxed list-decimal list-inside">
                        <li class="flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-base text-primary" aria-hidden="true">share</span>
                            {{ __('pwa.ios_install_step_1') }}
                        </li>
                        <li>{{ __('pwa.ios_install_step_2') }}</li>
                        <li>{{ __('pwa.ios_install_step_3') }}</li>
                    </ol>
                    <div class="flex items-center gap-2 mt-3">
                        <button
                            type="button"
                            x-on:click="dismiss()"
                            class="inline-flex items-center gap-1.5 px-4 py-2 bg-primary text-on-primary rounded-lg text-xs font-semibold hover:opacity-90 transition-opacity"
                        >
                            {{ __('pwa.ios_install_dismiss') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>

<script>
function pwaInstallPrompt() {
    return {
        visible: true,
        isChrome: false,
        isFirefox: false,
        deferredPrompt: null,

        init() {
            // Detect browser mode
            const ua = navigator.userAgent;
            const isSafari = /^((?!chrome|android).)*safari/i.test(ua);
            const isFirefox = /Firefox\//i.test(ua) && !/Seamonkey/i.test(ua);
            const isAndroid = /Android/i.test(ua);
            const isStandalone = window.matchMedia('(display-mode: standalone)').matches
                || window.navigator.standalone === true;

            // If already installed as PWA, never show
            if (isStandalone) {
                this.visible = false;
                return;
            }

            // Firefox on Android supports PWA via menu → Install (no beforeinstallprompt)
            // Firefox on desktop does NOT support PWA installation — show nothing
            this.isFirefox = isFirefox && isAndroid;

            // Chrome: detect by API support (BeforeInstallPromptEvent) or UA fallback.
            // Using API support instead of pure UA to avoid false positives on Edge/Firefox.
            this.isChrome = !isSafari && !this.isFirefox
                && ('BeforeInstallPromptEvent' in window || /Chrome\//i.test(ua));

            // Firefox desktop: no PWA install support — never show the prompt
            if (isFirefox && !isAndroid) {
                this.visible = false;
            }

            // Chrome: use early-captured prompt or listen for the event
            if (this.isChrome) {
                if (window.__pwaDeferredPrompt) {
                    this.deferredPrompt = window.__pwaDeferredPrompt;
                    console.log('[pwa-prompt] beforeinstallprompt captured (from early cache)');
                }

                window.addEventListener('beforeinstallprompt', (e) => {
                    e.preventDefault();
                    this.deferredPrompt = e;
                    window.__pwaDeferredPrompt = e;

                    // Structured log
                    console.log('[pwa-prompt] beforeinstallprompt captured');
                });
            }
        },

        shouldShow() {
            // Check dismissal persistence (7-day cooldown)
            try {
                const stored = localStorage.getItem('pwa_prompt_dismissed');
                if (stored) {
                    const data = JSON.parse(stored);
                    if (data.date) {
                        const dismissedAt = new Date(data.date);
                        const daysSince = (Date.now() - dismissedAt.getTime()) / (1000 * 60 * 60 * 24);
                        if (daysSince < 7) {
                            return false;
                        }
                    }
                }
            } catch (e) {
                // Corrupted data — show prompt
            }

            return true;
        },

        dismiss() {
            this.visible = false;
            localStorage.setItem('pwa_prompt_dismissed', JSON.stringify({ date: new Date().toISOString() }));

            console.log('[pwa-prompt] dismissed');
        },

        installChrome() {
            if (!this.deferredPrompt) {
                // No prompt available yet (browser hasn't fired beforeinstallprompt)
                console.log('[pwa-prompt] no deferred prompt available');
                return;
            }

            this.deferredPrompt.prompt();

            this.deferredPrompt.userChoice.then((result) => {
                console.log('[pwa-prompt] install result:', result.outcome);

                if (result.outcome === 'accepted') {
                    console.log('[pwa-prompt] install confirmed');
                }

                this.deferredPrompt = null;
                this.visible = false;
            });
        },
    };
}
</script>
@endif
