<div>
    @if($panelState !== 'none')
        {{-- Publish feedback. The parent Journal tab's banners only
             re-render on parent updates, so the panel surfaces its own
             flashes — same markup as the campaign-journal banners
             (MEM1379). --}}
        @if(session()->has('success'))
            <div class="mb-4 rounded-xl bg-secondary-container p-4 flex items-center gap-3" role="status" aria-live="polite">
                <span class="material-symbols-outlined text-on-secondary-container" aria-hidden="true">check_circle</span>
                <p class="text-sm text-on-secondary-container">{{ session('success') }}</p>
            </div>
        @endif

        @if(session()->has('error'))
            <div class="mb-4 rounded-xl bg-error-container p-4 flex items-center gap-3" role="alert" aria-live="polite">
                <span class="material-symbols-outlined text-on-error-container" aria-hidden="true">error</span>
                <p class="text-sm text-on-error-container">{{ session('error') }}</p>
            </div>
        @endif

        @if($panelState === 'gate')
            {{-- Member gate (D185 via the D223 seam): lock icon + copy +
                 membership CTA, mirroring the charter upsell locked-state
                 pattern. Never shows draft content. --}}
            <div class="mb-6 bg-surface-container-lowest rounded-xl shadow-ambient p-6">
                <div class="flex items-center gap-3 mb-3">
                    <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1" aria-hidden="true">lock</span>
                    <h3 class="font-heading text-lg font-bold text-on-surface">{{ __('journal.title_previously_on_gate') }}</h3>
                </div>
                <p class="text-sm text-on-surface-variant max-w-2xl mb-4">
                    {{ __('journal.content_previously_on_gate') }}
                </p>
                <a href="{{ route('membership') }}" wire:navigate
                   class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-lg bg-primary text-on-primary text-sm font-medium hover:bg-primary/90 transition-colors whitespace-nowrap">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">card_membership</span>
                    {{ __('journal.action_view_membership') }}
                </a>
            </div>
        @elseif($panelState === 'draft')
            {{-- Draft review (D221 draft-first): plain-text preview of the
                 composed digest, published only on explicit GM click. --}}
            <section class="mb-6 bg-surface-container-low rounded-xl shadow-ambient p-5" aria-label="{{ __('journal.title_previously_on_panel') }}">
                <div class="flex items-center gap-3 mb-1">
                    <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1" aria-hidden="true">history</span>
                    <h3 class="font-heading text-lg font-bold text-on-surface">{{ __('journal.title_previously_on_panel') }}</h3>
                </div>
                <p class="text-sm text-on-surface-variant mb-3">
                    {{ __('journal.content_previously_on_description') }}
                </p>

                <p class="text-xs text-on-surface-variant mb-3 flex flex-wrap items-center gap-1.5">
                    <span class="inline-flex items-center gap-1 font-medium text-on-surface">
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">event</span>
                        {{ __('journal.label_previously_on_target_session') }}:
                        {{ $latestDraft->game?->name }}
                    </span>
                    @if($latestDraft->game?->date_time)
                        <span>&middot;</span>
                        <span>{{ format_date($latestDraft->game->date_time, 'weekday_datetime') }}</span>
                    @endif
                </p>

                {{-- The digest body EXACTLY as stored: whitespace-pre-line,
                     escaped, no markup interpretation — it is composed
                     plain text (spec §3.1). --}}
                <div class="border border-outline-variant rounded-lg bg-surface-container-lowest p-4 text-sm text-on-surface leading-relaxed whitespace-pre-line mb-4">
                    {{ $latestDraft->body }}
                </div>

                <button type="button" wire:click="publish" wire:loading.attr="disabled" wire:target="publish"
                        class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-lg bg-primary text-on-primary text-sm font-medium hover:bg-primary/90 active:scale-95 transition-all">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">publish</span>
                    {{ __('journal.action_publish_previously_on') }}
                </button>
            </section>
        @elseif($panelState === 'published')
            {{-- Published confirmation: timestamp + link to the session's
                 bulletin board (the audience surface the observer's
                 fan-out delivered to). --}}
            <div class="mb-6 bg-surface-container-low rounded-xl shadow-ambient p-5 flex flex-wrap items-center gap-3" role="status">
                <span class="material-symbols-outlined text-secondary" style="font-variation-settings: 'FILL' 1" aria-hidden="true">task_alt</span>
                <p class="text-sm text-on-surface">
                    {{ __('journal.label_previously_on_published_at', ['date' => format_date($latestDraft->published_at, 'datetime')]) }}
                </p>
                @if($latestDraft->game)
                    <a href="{{ route('games.show', $latestDraft->game) }}" wire:navigate
                       class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline transition-colors">
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">campaign</span>
                        {{ $latestDraft->game->name }}
                    </a>
                @endif
            </div>
        @endif
    @endif
</div>
