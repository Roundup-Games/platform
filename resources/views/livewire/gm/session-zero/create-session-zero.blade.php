<div>
    @section('title', __('session_zero.title_create_session_zero'))

    {{-- ── Header ──────────────────────────────────────────────── --}}
    <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-8">
        <div class="flex items-center gap-4">
            <div class="w-14 h-14 bg-primary/10 rounded-2xl flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-primary text-2xl" style="font-variation-settings: 'FILL' 1">contract_edit</span>
            </div>
            <div>
                <h2 class="font-heading text-2xl font-bold text-on-surface tracking-tight">
                    {{ __('session_zero.title_create_session_zero') }}
                </h2>
                <p class="mt-1 text-on-surface-variant text-sm">
                    {{ __('session_zero.description_build_your_session_zero_questionnaire') }}
                </p>
            </div>
        </div>
    </div>

    @if($saved)
        {{-- ── Success State: Shareable Link ────────────────────── --}}
        <div class="mt-6 bg-surface-container-lowest rounded-xl shadow-ambient p-8">
            <div class="text-center">
                <div class="w-16 h-16 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                    <span class="material-symbols-outlined text-primary text-3xl" style="font-variation-settings: 'FILL' 1">check_circle</span>
                </div>
                <h3 class="font-heading text-xl font-bold text-on-surface mb-2">
                    {{ __('session_zero.title_survey_created') }}
                </h3>
                <p class="text-on-surface-variant text-sm mb-6">
                    {{ __('session_zero.description_share_link_with_players') }}
                </p>

                <div class="max-w-lg mx-auto">
                    <label for="shareable-link" class="block text-sm font-medium text-on-surface mb-2">
                        {{ __('session_zero.label_shareable_link') }}
                    </label>
                    <div class="flex items-center gap-2">
                        <input
                            id="shareable-link"
                            type="text"
                            value="{{ $shareableLink }}"
                            readonly
                            class="flex-1 rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm px-4 py-2.5 font-mono"
                            x-ref="shareableLinkInput"
                        />
                        <button
                            type="button"
                            x-data
                            x-on:click="
                                navigator.clipboard.writeText($refs.shareableLinkInput.value);
                                $el.textContent = '{{ __('session_zero.action_copied') }}';
                                setTimeout(() => $el.textContent = '{{ __('session_zero.action_copy_link') }}', 2000)
                            "
                            class="shrink-0 inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-primary text-on-primary text-sm font-medium hover:bg-primary/90 transition-colors"
                        >
                            <span class="material-symbols-outlined text-sm" aria-hidden="true">content_copy</span>
                            {{ __('session_zero.action_copy_link') }}
                        </button>
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-center gap-3">
                    <a
                        href="{{ route('gm.workspace', app()->getLocale()) }}"
                        wire:navigate
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-surface-container-high text-on-surface text-sm font-medium hover:bg-surface-container-highest transition-colors"
                    >
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">home</span>
                        {{ __('session_zero.action_back_to_workspace') }}
                    </a>
                    <a
                        href="{{ $shareableLink }}"
                        target="_blank"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-secondary/10 text-secondary text-sm font-medium hover:bg-secondary/20 transition-colors"
                    >
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">open_in_new</span>
                        {{ __('session_zero.action_preview_survey') }}
                    </a>
                </div>
            </div>
        </div>
    @else
        {{-- ── Form State ──────────────────────────────────────────── --}}
        <form wire:submit="save" class="mt-6 space-y-6">

            {{-- Title ──────────────────────────────────────────────── --}}
            <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                <div class="flex items-center gap-2 mb-4">
                    <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">title</span>
                    <h3 class="font-heading font-semibold text-on-surface">{{ __('session_zero.heading_title') }}</h3>
                </div>

                <div>
                    <label for="sz-title" class="block text-sm font-medium text-on-surface mb-1">
                        {{ __('session_zero.label_survey_title') }}
                    </label>
                    <input
                        id="sz-title"
                        type="text"
                        wire:model="title"
                        class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm placeholder:text-on-surface-variant focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors px-4 py-2.5"
                        placeholder="{{ __('session_zero.placeholder_session_zero_for_your_game') }}"
                    />
                    @error('title')
                        <p class="mt-1 text-xs text-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- Section 1: Safety Tools ────────────────────────────── --}}
            <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                <div class="flex items-center gap-2 mb-4">
                    <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">shield</span>
                    <h3 class="font-heading font-semibold text-on-surface">{{ __('session_zero.heading_safety_tools') }}</h3>
                </div>
                <p class="text-xs text-on-surface-variant mb-4">{{ __('session_zero.description_select_safety_tools') }}</p>

                <livewire:components.safety-tool-picker
                    :selected="$selectedSafetyTools"
                    :linesAndVeilsText="$linesAndVeilsText"
                    :customNote="$safetyCustomNote"
                    mode="selection"
                />

                @error('selectedSafetyTools')
                    <p class="mt-2 text-xs text-error">{{ $message }}</p>
                @enderror
            </div>

            {{-- Section 2: Tone & Genre ────────────────────────────── --}}
            <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                <div class="flex items-center gap-2 mb-4">
                    <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">palette</span>
                    <h3 class="font-heading font-semibold text-on-surface">{{ __('session_zero.heading_tone_and_genre') }}</h3>
                </div>
                <p class="text-xs text-on-surface-variant mb-3">{{ __('session_zero.description_tone_and_genre') }}</p>

                <textarea
                    id="sz-tone"
                    wire:model="tone_and_genre"
                    rows="3"
                    class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm placeholder:text-on-surface-variant focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors resize-y px-4 py-2.5"
                    placeholder="{{ __('session_zero.placeholder_tone_example') }}"
                    aria-label="{{ __('session_zero.heading_tone_and_genre') }}"
                ></textarea>
                @error('tone_and_genre')
                    <p class="mt-1 text-xs text-error">{{ $message }}</p>
                @enderror
            </div>

            {{-- Section 3: House Rules ─────────────────────────────── --}}
            <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                <div class="flex items-center gap-2 mb-4">
                    <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">gavel</span>
                    <h3 class="font-heading font-semibold text-on-surface">{{ __('session_zero.heading_house_rules') }}</h3>
                </div>
                <p class="text-xs text-on-surface-variant mb-3">{{ __('session_zero.description_house_rules') }}</p>

                <textarea
                    id="sz-house-rules"
                    wire:model="house_rules"
                    rows="4"
                    class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm placeholder:text-on-surface-variant focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors resize-y px-4 py-2.5"
                    placeholder="{{ __('session_zero.placeholder_house_rules_example') }}"
                    aria-label="{{ __('session_zero.heading_house_rules') }}"
                ></textarea>
                @error('house_rules')
                    <p class="mt-1 text-xs text-error">{{ $message }}</p>
                @enderror
            </div>

            {{-- Section 4: Content Warnings ─────────────────────────── --}}
            <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                <div class="flex items-center gap-2 mb-4">
                    <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">warning</span>
                    <h3 class="font-heading font-semibold text-on-surface">{{ __('session_zero.heading_content_warnings') }}</h3>
                </div>
                <p class="text-xs text-on-surface-variant mb-3">{{ __('session_zero.description_content_warnings') }}</p>

                <textarea
                    id="sz-content-warnings"
                    wire:model="content_warnings"
                    rows="3"
                    class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm placeholder:text-on-surface-variant focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors resize-y px-4 py-2.5"
                    placeholder="{{ __('session_zero.placeholder_content_warnings_example') }}"
                    aria-label="{{ __('session_zero.heading_content_warnings') }}"
                ></textarea>
                @error('content_warnings')
                    <p class="mt-1 text-xs text-error">{{ $message }}</p>
                @enderror
            </div>

            {{-- Section 5: Player Expectations ──────────────────────── --}}
            <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                <div class="flex items-center gap-2 mb-4">
                    <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">groups</span>
                    <h3 class="font-heading font-semibold text-on-surface">{{ __('session_zero.heading_player_expectations') }}</h3>
                </div>
                <p class="text-xs text-on-surface-variant mb-3">{{ __('session_zero.description_player_expectations') }}</p>

                <textarea
                    id="sz-expectations"
                    wire:model="player_expectations"
                    rows="4"
                    class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm placeholder:text-on-surface-variant focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors resize-y px-4 py-2.5"
                    placeholder="{{ __('session_zero.placeholder_expectations_example') }}"
                    aria-label="{{ __('session_zero.heading_player_expectations') }}"
                ></textarea>
                @error('player_expectations')
                    <p class="mt-1 text-xs text-error">{{ $message }}</p>
                @enderror
            </div>

            {{-- Submit ──────────────────────────────────────────────── --}}
            <div class="flex items-center justify-end gap-3">
                <a
                    href="{{ route('gm.workspace', app()->getLocale()) }}"
                    wire:navigate
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-surface-container-high text-on-surface text-sm font-medium hover:bg-surface-container-highest transition-colors"
                >
                    {{ __('common.action_cancel') }}
                </a>
                <button
                    type="submit"
                    class="inline-flex items-center gap-2 px-6 py-2.5 rounded-lg bg-primary text-on-primary text-sm font-medium hover:bg-primary/90 transition-colors"
                    wire:loading.attr="disabled"
                >
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">save</span>
                    <span wire:loading.remove>{{ __('session_zero.action_create_survey') }}</span>
                    <span wire:loading>{{ __('session_zero.action_creating') }}</span>
                </button>
            </div>
        </form>
    @endif
</div>
