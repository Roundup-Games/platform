@section('title', __('common.content_source_discord') . ' — ' . $guildName)

<div class="py-6 sm:py-8">
    {{-- Page Header --}}
    <div class="max-w-2xl mx-auto mb-6">
        <div class="flex items-center gap-2 mb-1">
            <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">campaign</span>
            <span class="text-sm text-on-surface-variant">{{ __('common.content_source_discord') }}</span>
        </div>
        <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ $guildName }}</h1>
        <p class="mt-1 text-sm text-on-surface-variant">{{ __('discord.content_guild_settings_subtitle') }}</p>
    </div>

    <div class="max-w-2xl mx-auto space-y-8">

        {{-- Saved confirmation --}}
        @if($saved)
            <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
                 class="rounded-lg bg-secondary-container p-4" role="status" aria-live="polite">
                <p class="text-sm text-on-secondary-container flex items-center gap-2">
                    <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1" aria-hidden="true">check_circle</span>
                    {{ __('discord.flash_channels_saved') }}
                </p>
            </div>
        @endif

        {{-- Pause banner --}}
        @if($paused)
            <div class="rounded-lg bg-tertiary-container p-4" role="status" aria-live="polite">
                <p class="text-sm text-on-tertiary-container flex items-center gap-2">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">pause_circle</span>
                    {{ __('discord.content_posting_paused_banner') }}
                </p>
            </div>
        @endif

        {{-- Channel Picker --}}
        <section class="bg-surface-container-low rounded-xl p-6">
            <h2 class="text-lg font-heading font-semibold text-on-surface mb-1">{{ __('discord.heading_channels') }}</h2>
            <p class="text-sm text-on-surface-variant mb-5">
                {{ __('discord.content_channels_help') }}
            </p>

            @if($channelsLoadFailed)
                <div class="rounded-lg bg-error-container p-3 mb-4" role="alert">
                    <p class="text-sm text-on-error-container flex items-center gap-2">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">error</span>
                        {{ __('discord.error_channels_load_failed') }}
                    </p>
                </div>
            @endif

            <div class="space-y-5">
                {{-- Games channel (required for posting) --}}
                <div>
                    <label for="games-channel" class="block text-sm font-medium text-on-surface mb-1.5">
                        {{ __('discord.label_games_channel') }}
                        <span class="text-on-surface-variant font-normal">{{ __('discord.label_games_channel_hint') }}</span>
                    </label>
                    <select id="games-channel" wire:model="games_channel_id"
                            class="w-full bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm shadow-xs focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20"
                            aria-describedby="games-channel-help">
                        <option value="">{{ __('discord.content_not_picked_yet') }}</option>
                        @foreach($channels as $channel)
                            <option value="{{ $channel['id'] }}">{{ $channel['name'] }}</option>
                        @endforeach
                    </select>
                    <p id="games-channel-help" class="mt-1.5 text-xs text-on-surface-variant">
                        @if(empty($channels))
                            {{ __('discord.content_no_channels_loaded') }}
                        @endif
                        {{ __('discord.content_games_channel_help') }}
                    </p>
                </div>

                {{-- Calendar channel (upcoming-events surface) --}}
                <div>
                    <label for="calendar-channel" class="block text-sm font-medium text-on-surface mb-1.5">
                        {{ __('discord.label_calendar_channel') }}
                        <span class="text-on-surface-variant font-normal">{{ __('discord.label_calendar_channel_hint') }}</span>
                    </label>
                    <select id="calendar-channel" wire:model="calendar_channel_id"
                            class="w-full bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm shadow-xs focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                        <option value="">{{ __('discord.content_not_picked_yet') }}</option>
                        @foreach($channels as $channel)
                            <option value="{{ $channel['id'] }}">{{ $channel['name'] }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="mt-6 flex items-center gap-3">
                <button wire:click="save" wire:loading.attr="disabled"
                        class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium bg-primary text-on-primary shadow-ambient hover:bg-primary/90 transition-colors disabled:opacity-50">
                    <span wire:loading.remove wire:target="save" class="material-symbols-outlined text-base" aria-hidden="true">save</span>
                    <span wire:loading wire:target="save" class="material-symbols-outlined text-base animate-spin" aria-hidden="true">progress_activity</span>
                    {{ __('discord.action_save_channels') }}
                </button>
                <button wire:click="refreshChannels"
                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium text-on-surface-variant hover:bg-surface-container transition-colors">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">refresh</span>
                    {{ __('discord.action_refresh_list') }}
                </button>
            </div>
        </section>

        {{-- Language / Locale --}}
        <section class="bg-surface-container-low rounded-xl p-6">
            <h2 class="text-lg font-heading font-semibold text-on-surface mb-1">{{ __('common.content_language') }}</h2>
            <p class="text-sm text-on-surface-variant mb-5">
                {{ __('discord.content_language_help') }}
            </p>

            <div>
                <label for="guild-locale" class="block text-sm font-medium text-on-surface mb-1.5">
                    {{ __('discord.label_posting_language') }}
                </label>
                <select id="guild-locale" wire:model="locale"
                        class="w-full bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm shadow-xs focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20"
                        aria-describedby="guild-locale-help">
                    <option value="">{{ __('discord.content_app_default', ['locale' => config('app.fallback_locale', 'en')]) }}</option>
                    @foreach($availableLocales as $code => $name)
                        <option value="{{ $code }}">{{ $name }}</option>
                    @endforeach
                </select>
                <p id="guild-locale-help" class="mt-1.5 text-xs text-on-surface-variant">
                    {{ __('discord.content_locale_help') }}
                </p>
            </div>

            @if($localeSaved)
                <p class="mt-3 text-xs text-secondary" aria-live="polite">
                    {{ __('discord.flash_language_saved') }}
                </p>
            @endif

            <div class="mt-6">
                <button wire:click="saveLocale" wire:loading.attr="disabled"
                        class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium bg-primary text-on-primary shadow-ambient hover:bg-primary/90 transition-colors disabled:opacity-50">
                    <span wire:loading.remove wire:target="saveLocale" class="material-symbols-outlined text-base" aria-hidden="true">save</span>
                    <span wire:loading wire:target="saveLocale" class="material-symbols-outlined text-base animate-spin" aria-hidden="true">progress_activity</span>
                    {{ __('discord.action_save_language') }}
                </button>
            </div>
        </section>

        {{-- Pause Switch --}}
        <section class="bg-surface-container-low rounded-xl p-6">
            <h2 class="text-lg font-heading font-semibold text-on-surface mb-1">{{ __('discord.heading_posting') }}</h2>
            <p class="text-sm text-on-surface-variant mb-5">
                {{ __('discord.content_posting_help') }}
            </p>

            <div class="flex items-center justify-between gap-4 py-2">
                <div>
                    <p class="text-sm font-medium text-on-surface">
                        {{ $paused ? __('discord.status_posting_paused') : __('discord.status_posting_active') }}
                    </p>
                    <p class="text-xs text-on-surface-variant mt-0.5">
                        @if($paused)
                            {{ __('discord.content_posting_paused_detail') }}
                        @else
                            {{ __('discord.content_posting_active_detail') }}
                        @endif
                    </p>
                </div>
                <button wire:click="togglePaused" wire:loading.attr="disabled"
                        role="switch" aria-checked="{{ $paused ? 'true' : 'false' }}"
                        aria-label="{{ $paused ? __('discord.action_resume_posting') : __('discord.action_pause_posting') }}"
                        class="relative inline-flex h-7 w-12 items-center rounded-full transition-colors disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-secondary/20
                               {{ $paused ? 'bg-tertiary' : 'bg-primary' }}">
                    <span class="inline-block h-5 w-5 transform rounded-full bg-on-primary shadow transition-transform {{ $paused ? 'translate-x-1' : 'translate-x-6' }}"></span>
                </button>
            </div>

            @if($pausedChanged)
                <p class="mt-3 text-xs text-on-surface-variant" aria-live="polite">
                    {{ $paused ? __('discord.flash_paused') : __('discord.flash_resumed') }}
                </p>
            @endif
        </section>

        {{-- Guild identity (read-only context) --}}
        <section class="bg-surface-container-low rounded-xl p-6">
            <h2 class="text-lg font-heading font-semibold text-on-surface mb-3">{{ __('discord.heading_server') }}</h2>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                <div>
                    <dt class="text-on-surface-variant">{{ __('discord.label_discord_guild') }}</dt>
                    <dd class="text-on-surface font-mono text-xs">{{ $guild->guild_id }}</dd>
                </div>
                <div>
                    <dt class="text-on-surface-variant">{{ __('common.content_moderation') }}</dt>
                    <dd class="text-on-surface">{{ $guild->moderation_mode->label() }}</dd>
                </div>
            </dl>
        </section>

    </div>
</div>
