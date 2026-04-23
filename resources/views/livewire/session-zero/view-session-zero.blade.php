<div>
    @section('title', $survey->title)

    {{-- ── Survey Header ──────────────────────────────────────────── --}}
    <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-8">
        <div class="flex items-center gap-4">
            <div class="w-14 h-14 bg-primary/10 rounded-2xl flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-primary text-2xl" style="font-variation-settings: 'FILL' 1">contract</span>
            </div>
            <div>
                <h2 class="font-heading text-2xl font-bold text-on-surface tracking-tight">
                    {{ $survey->title }}
                </h2>
                <p class="mt-1 text-on-surface-variant text-sm">
                    {{ __('session_zero.view_description_by_gm') }}
                </p>
            </div>
        </div>
    </div>

    {{-- ── Survey Content ─────────────────────────────────────────── --}}
    <div class="mt-6 space-y-6">

        {{-- Safety Tools ────────────────────────────────────────────── --}}
        @if(!empty($survey->content['safety_tools']) || !empty($survey->content['lines_and_veils_text']) || !empty($survey->content['safety_custom_note']))
        <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <div class="flex items-center gap-2 mb-4">
                <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">shield</span>
                <h3 class="font-heading font-semibold text-on-surface">{{ __('session_zero.heading_safety_tools') }}</h3>
            </div>

            @if(!empty($survey->content['safety_tools']))
                <div class="flex flex-wrap gap-2 mb-3">
                    @foreach($survey->content['safety_tools'] as $toolValue)
                        @php
                            $toolEnum = \App\Enums\SafetyTool::tryFrom($toolValue);
                        @endphp
                        @if($toolEnum)
                            <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-primary/10 text-primary text-xs font-medium">
                                <span class="material-symbols-outlined text-xs" aria-hidden="true">verified</span>
                                {{ $toolEnum->label() }}
                            </span>
                        @endif
                    @endforeach
                </div>
            @endif

            @if(!empty($survey->content['lines_and_veils_text']))
                <div class="mt-3 text-sm text-on-surface-variant whitespace-pre-line bg-surface-container-high rounded-lg p-4">
                    {{ $survey->content['lines_and_veils_text'] }}
                </div>
            @endif

            @if(!empty($survey->content['safety_custom_note']))
                <div class="mt-3 text-sm text-on-surface-variant whitespace-pre-line bg-surface-container-high rounded-lg p-4">
                    {{ $survey->content['safety_custom_note'] }}
                </div>
            @endif
        </div>
        @endif

        {{-- Tone & Genre ────────────────────────────────────────────── --}}
        @if(!empty($survey->content['tone_and_genre']))
        <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <div class="flex items-center gap-2 mb-4">
                <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">palette</span>
                <h3 class="font-heading font-semibold text-on-surface">{{ __('session_zero.heading_tone_and_genre') }}</h3>
            </div>
            <div class="text-sm text-on-surface-variant whitespace-pre-line leading-relaxed">
                {{ $survey->content['tone_and_genre'] }}
            </div>
        </div>
        @endif

        {{-- House Rules ─────────────────────────────────────────────── --}}
        @if(!empty($survey->content['house_rules']))
        <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <div class="flex items-center gap-2 mb-4">
                <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">gavel</span>
                <h3 class="font-heading font-semibold text-on-surface">{{ __('session_zero.heading_house_rules') }}</h3>
            </div>
            <div class="text-sm text-on-surface-variant whitespace-pre-line leading-relaxed">
                {{ $survey->content['house_rules'] }}
            </div>
        </div>
        @endif

        {{-- Content Warnings ──────────────────────────────────────────── --}}
        @if(!empty($survey->content['content_warnings']))
        <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <div class="flex items-center gap-2 mb-4">
                <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">warning</span>
                <h3 class="font-heading font-semibold text-on-surface">{{ __('session_zero.heading_content_warnings') }}</h3>
            </div>
            <div class="text-sm text-on-surface-variant whitespace-pre-line leading-relaxed">
                {{ $survey->content['content_warnings'] }}
            </div>
        </div>
        @endif

        {{-- Player Expectations ──────────────────────────────────────── --}}
        @if(!empty($survey->content['player_expectations']))
        <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <div class="flex items-center gap-2 mb-4">
                <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">groups</span>
                <h3 class="font-heading font-semibold text-on-surface">{{ __('session_zero.heading_player_expectations') }}</h3>
            </div>
            <div class="text-sm text-on-surface-variant whitespace-pre-line leading-relaxed">
                {{ $survey->content['player_expectations'] }}
            </div>
        </div>
        @endif

        {{-- ── Confirmation Section ──────────────────────────────────── --}}
        <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <div class="flex items-center gap-2 mb-4">
                <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">task_alt</span>
                <h3 class="font-heading font-semibold text-on-surface">{{ __('session_zero.heading_confirmation') }}</h3>
            </div>

            @if($confirmed)
                {{-- Already confirmed ────────────────────────────────────── --}}
                <div class="flex items-center gap-3 p-4 rounded-lg bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800">
                    <span class="material-symbols-outlined text-green-600 dark:text-green-400 text-xl" style="font-variation-settings: 'FILL' 1">check_circle</span>
                    <div>
                        <p class="text-sm font-medium text-green-800 dark:text-green-300">
                            {{ __('session_zero.confirmation_confirmed') }}
                        </p>
                        @if($confirmedAt)
                            <p class="text-xs text-green-600 dark:text-green-400 mt-0.5">
                                {{ $confirmedAt }}
                            </p>
                        @endif
                    </div>
                </div>
            @elseif(Auth::check())
                {{-- Authenticated but not yet confirmed ────────────────────── --}}
                <p class="text-sm text-on-surface-variant mb-4">
                    {{ __('session_zero.confirmation_prompt') }}
                </p>
                <button
                    type="button"
                    wire:click="confirm"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center gap-2 px-6 py-2.5 rounded-lg bg-primary text-on-primary text-sm font-medium hover:bg-primary/90 transition-colors"
                >
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">check</span>
                    <span wire:loading.remove>{{ __('session_zero.action_confirm') }}</span>
                    <span wire:loading>{{ __('session_zero.action_confirming') }}</span>
                </button>
            @else
                {{-- Not authenticated ──────────────────────────────────────── --}}
                <p class="text-sm text-on-surface-variant mb-4">
                    {{ __('session_zero.confirmation_login_prompt') }}
                </p>
                <a
                    href="{{ route('login', app()->getLocale()) }}"
                    wire:navigate
                    class="inline-flex items-center gap-2 px-6 py-2.5 rounded-lg bg-primary text-on-primary text-sm font-medium hover:bg-primary/90 transition-colors"
                >
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">login</span>
                    {{ __('session_zero.action_login_to_confirm') }}
                </a>
            @endif
        </div>

        {{-- ── GM Confirmation List (only visible to GM) ────────────── --}}
        @if($isGm && $confirmations->count() > 0)
        <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <div class="flex items-center gap-2 mb-4">
                <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">group</span>
                <h3 class="font-heading font-semibold text-on-surface">
                    {{ __('session_zero.heading_confirmations') }}
                    <span class="ml-2 text-xs font-normal text-on-surface-variant">({{ $confirmations->count() }})</span>
                </h3>
            </div>
            <div class="space-y-2">
                @foreach($confirmations as $confirmation)
                    <div class="flex items-center justify-between p-3 rounded-lg bg-surface-container-high">
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-green-600 text-lg" style="font-variation-settings: 'FILL' 1">check_circle</span>
                            <span class="text-sm font-medium text-on-surface">
                                {{ $confirmation->user?->name ?? __('session_zero.unknown_user') }}
                            </span>
                        </div>
                        <span class="text-xs text-on-surface-variant">
                            {{ $confirmation->confirmed_at->format('M j, Y \a\t g:i A') }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
        @endif

    </div>
</div>
