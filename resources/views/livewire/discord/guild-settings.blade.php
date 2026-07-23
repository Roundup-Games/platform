@section('title', 'Discord — '.$guildName)

<div class="py-6 sm:py-8">
    {{-- Page Header --}}
    <div class="max-w-2xl mx-auto mb-6">
        <div class="flex items-center gap-2 mb-1">
            <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">campaign</span>
            <span class="text-sm text-on-surface-variant">Discord</span>
        </div>
        <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ $guildName }}</h1>
        <p class="mt-1 text-sm text-on-surface-variant">Pick where roundup publishes event cards, and pause posting any time.</p>
    </div>

    <div class="max-w-2xl mx-auto space-y-8">

        {{-- Saved confirmation --}}
        @if($saved)
            <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
                 class="rounded-lg bg-secondary-container p-4" role="status" aria-live="polite">
                <p class="text-sm text-on-secondary-container flex items-center gap-2">
                    <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1" aria-hidden="true">check_circle</span>
                    Channels saved.
                </p>
            </div>
        @endif

        {{-- Pause banner --}}
        @if($paused)
            <div class="rounded-lg bg-tertiary-container p-4" role="status" aria-live="polite">
                <p class="text-sm text-on-tertiary-container flex items-center gap-2">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">pause_circle</span>
                    Posting is paused for this server. Event cards will not be published until you resume.
                </p>
            </div>
        @endif

        {{-- Channel Picker --}}
        <section class="bg-surface-container-low rounded-xl p-6">
            <h2 class="text-lg font-heading font-semibold text-on-surface mb-1">Channels</h2>
            <p class="text-sm text-on-surface-variant mb-5">
                roundup publishes enriched event cards to the <strong>games</strong> channel. The <strong>calendar</strong> channel
                is reserved for the upcoming-events surface.
            </p>

            @if($channelsLoadFailed)
                <div class="rounded-lg bg-error-container p-3 mb-4" role="alert">
                    <p class="text-sm text-on-error-container flex items-center gap-2">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">error</span>
                        Couldn't load channels from Discord. Check the bot has "View Channels" permission, then refresh.
                    </p>
                </div>
            @endif

            <div class="space-y-5">
                {{-- Games channel (required for posting) --}}
                <div>
                    <label for="games-channel" class="block text-sm font-medium text-on-surface mb-1.5">
                        Games channel
                        <span class="text-on-surface-variant font-normal">(where event cards appear)</span>
                    </label>
                    <select id="games-channel" wire:model="games_channel_id"
                            class="w-full bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm shadow-xs focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20"
                            aria-describedby="games-channel-help">
                        <option value="">— Not picked yet —</option>
                        @foreach($channels as $channel)
                            <option value="{{ $channel['id'] }}">{{ $channel['name'] }}</option>
                        @endforeach
                    </select>
                    <p id="games-channel-help" class="mt-1.5 text-xs text-on-surface-variant">
                        @if(empty($channels))
                            No channels loaded. 
                        @endif
                        Posting stays off until a games channel is picked.
                    </p>
                </div>

                {{-- Calendar channel (upcoming-events surface) --}}
                <div>
                    <label for="calendar-channel" class="block text-sm font-medium text-on-surface mb-1.5">
                        Calendar channel
                        <span class="text-on-surface-variant font-normal">(upcoming-events surface)</span>
                    </label>
                    <select id="calendar-channel" wire:model="calendar_channel_id"
                            class="w-full bg-surface-container-high border border-transparent rounded-lg text-on-surface text-sm shadow-xs focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20">
                        <option value="">— Not picked yet —</option>
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
                    Save channels
                </button>
                <button wire:click="refreshChannels"
                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium text-on-surface-variant hover:bg-surface-container transition-colors">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">refresh</span>
                    Refresh list
                </button>
            </div>
        </section>

        {{-- Pause Switch --}}
        <section class="bg-surface-container-low rounded-xl p-6">
            <h2 class="text-lg font-heading font-semibold text-on-surface mb-1">Posting</h2>
            <p class="text-sm text-on-surface-variant mb-5">
                Pause stops all event-card publishing to this server without uninstalling the bot. Resume anytime.
            </p>

            <div class="flex items-center justify-between gap-4 py-2">
                <div>
                    <p class="text-sm font-medium text-on-surface">
                        {{ $paused ? 'Posting paused' : 'Posting active' }}
                    </p>
                    <p class="text-xs text-on-surface-variant mt-0.5">
                        @if($paused)
                            New and updated events will not reach this server.
                        @else
                            Eligible public events publish automatically.
                        @endif
                    </p>
                </div>
                <button wire:click="togglePaused" wire:loading.attr="disabled"
                        role="switch" aria-checked="{{ $paused ? 'true' : 'false' }}"
                        aria-label="{{ $paused ? 'Resume posting' : 'Pause posting' }}"
                        class="relative inline-flex h-7 w-12 items-center rounded-full transition-colors disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-secondary/20
                               {{ $paused ? 'bg-tertiary' : 'bg-primary' }}">
                    <span class="inline-block h-5 w-5 transform rounded-full bg-on-primary shadow transition-transform {{ $paused ? 'translate-x-1' : 'translate-x-6' }}"></span>
                </button>
            </div>

            @if($pausedChanged)
                <p class="mt-3 text-xs text-on-surface-variant" aria-live="polite">
                    {{ $paused ? 'Paused.' : 'Resumed.' }}
                </p>
            @endif
        </section>

        {{-- Guild identity (read-only context) --}}
        <section class="bg-surface-container-low rounded-xl p-6">
            <h2 class="text-lg font-heading font-semibold text-on-surface mb-3">Server</h2>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                <div>
                    <dt class="text-on-surface-variant">Discord guild</dt>
                    <dd class="text-on-surface font-mono text-xs">{{ $guild->guild_id }}</dd>
                </div>
                <div>
                    <dt class="text-on-surface-variant">Moderation</dt>
                    <dd class="text-on-surface">{{ ucfirst($guild->moderation_mode) }}</dd>
                </div>
                @if($guild->locale)
                    <div>
                        <dt class="text-on-surface-variant">Locale</dt>
                        <dd class="text-on-surface">{{ $guild->locale }}</dd>
                    </div>
                @endif
            </dl>
        </section>

    </div>
</div>
