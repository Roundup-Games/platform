<div class="space-y-6">
    {{-- Section header --}}
    <div>
        <div class="flex items-center gap-2 mb-1">
            <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">groups</span>
            <h2 class="text-lg font-heading font-semibold text-on-surface">{{ __('discord.heading_discord_servers') }}</h2>
        </div>
        <p class="text-sm text-on-surface-variant">
            {{ __('discord.content_organizer_servers_subtitle') }}
        </p>
    </div>

    {{-- Action flash --}}
    @if($lastAction === 'opted_in')
        <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
             class="rounded-lg bg-secondary-container p-3" role="status" aria-live="polite">
            <p class="text-sm text-on-secondary-container flex items-center gap-2">
                <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1" aria-hidden="true">check_circle</span>
                {{ __('discord.flash_publishing_enabled') }}
            </p>
        </div>
    @elseif($lastAction === 'opted_out')
        <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
             class="rounded-lg bg-tertiary-container p-3" role="status" aria-live="polite">
            <p class="text-sm text-on-tertiary-container flex items-center gap-2">
                <span class="material-symbols-outlined text-base" aria-hidden="true">pause_circle</span>
                {{ __('discord.flash_publishing_paused') }}
            </p>
        </div>
    @endif

    {{-- Empty state: no Discord link --}}
    @if(!$hasDiscordLink)
        <div class="rounded-xl border border-dashed border-outline-variant p-6 text-center">
            <span class="material-symbols-outlined text-4xl text-on-surface-variant mb-2" aria-hidden="true">link</span>
            <p class="text-sm font-medium text-on-surface">{{ __('discord.content_link_discord_title') }}</p>
            <p class="text-xs text-on-surface-variant mt-1 max-w-sm mx-auto">
                {{ __('discord.content_link_discord_body') }}
            </p>
            <a href="{{ route('settings.show', app()->getLocale()) }}#linked-accounts"
               class="inline-flex items-center gap-1.5 mt-4 px-4 py-2 rounded-lg text-sm font-medium bg-primary text-on-primary hover:bg-primary/90 transition-colors">
                <span class="material-symbols-outlined text-base" aria-hidden="true">link</span>
                {{ __('discord.action_link_discord') }}
            </a>
        </div>

    {{-- Empty state: linked but no roundup-enabled servers --}}
    @elseif($surfaced === [])
        <div class="rounded-xl border border-dashed border-outline-variant p-6 text-center">
            <span class="material-symbols-outlined text-4xl text-on-surface-variant mb-2" aria-hidden="true">search_off</span>
            <p class="text-sm font-medium text-on-surface">{{ __('discord.content_no_roundup_servers_title') }}</p>
            <p class="text-xs text-on-surface-variant mt-1 max-w-sm mx-auto">
                {{ __('discord.content_no_roundup_servers_body') }}
            </p>
            <button wire:click="refresh"
                    class="inline-flex items-center gap-1.5 mt-4 px-3 py-2 rounded-lg text-sm font-medium text-on-surface-variant hover:bg-surface-container transition-colors">
                <span class="material-symbols-outlined text-base" aria-hidden="true">refresh</span>
                {{ __('discord.action_refresh') }}
            </button>
        </div>

    {{-- Surfaced guild list --}}
    @else
        <ul class="space-y-3" aria-label="{{ __('discord.aria_roundup_servers_list') }}">
            @foreach($surfaced as $item)
                @php
                    $guildId = $item['id'];
                    $publishOn = $item['publish_enabled'];
                    $optedInIso = $item['opted_in_at'];
                    $optedInHuman = $optedInIso
                        ? \Illuminate\Support\Carbon::parse($optedInIso)->diffForHumans()
                        : null;
                @endphp
                <li class="bg-surface-container-low rounded-xl p-4 flex items-center justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            @if(!empty($item['icon']))
                                <img src="https://cdn.discordapp.com/icons/{{ $item['guild_snowflake'] }}/{{ $item['icon'] }}.png"
                                     alt="" class="h-6 w-6 rounded-full" loading="lazy">
                            @else
                                <span class="material-symbols-outlined text-on-surface-variant text-base" aria-hidden="true">group</span>
                            @endif
                            <p class="text-sm font-medium text-on-surface truncate">{{ $item['name'] }}</p>
                        </div>
                        <p class="text-xs text-on-surface-variant mt-1">
                            @if($publishOn)
                                <span class="inline-flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm align-middle" aria-hidden="true">campaign</span>
                                    {{ __('discord.content_publishing_on') }}
                                    @if($optedInHuman)
                                        {{ __('discord.content_opted_in', ['when' => $optedInHuman]) }}
                                    @endif
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm align-middle" aria-hidden="true">lock</span>
                                    {{ __('discord.content_publishing_off') }}
                                </span>
                            @endif
                        </p>
                    </div>

                    {{-- Toggle (switch semantics) --}}
                    <div class="flex-shrink-0">
                        <button wire:click="{{ $publishOn ? "optOut('{$guildId}')" : "optIn('{$guildId}')" }}"
                                wire:loading.attr="disabled"
                                role="switch" aria-checked="{{ $publishOn ? 'true' : 'false' }}"
                                aria-label="{{ $publishOn ? __('discord.aria_stop_publishing_to', ['name' => $item['name']]) : __('discord.aria_publish_events_to', ['name' => $item['name']]) }}"
                                class="relative inline-flex h-7 w-12 items-center rounded-full transition-colors disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-secondary/20
                                       {{ $publishOn ? 'bg-primary' : 'bg-surface-container-high' }}">
                            <span class="inline-block h-5 w-5 transform rounded-full bg-on-primary shadow transition-transform {{ $publishOn ? 'translate-x-6' : 'translate-x-1' }}"></span>
                        </button>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
