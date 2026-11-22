        {{-- ── Active Sessions & Campaigns Discovery ────────────────── --}}
        @php($isTtrpg = $system->type === 'ttrpg')
        @php($sessionRoute = $isTtrpg ? 'discover.adventures' : 'discover.board-games')
        <section class="bg-surface-container rounded-xl shadow-ambient p-6 [content-visibility:auto] [contain-intrinsic-size:auto_320px]">
            <h2 class="text-lg font-heading font-bold text-on-surface mb-2 flex items-center gap-2">
                <span class="material-symbols-outlined text-primary" aria-hidden="true">explore</span>
                {{ __('games.heading_game_sessions') }}
            </h2>
            @php($sessionCount = $system->active_sessions_count)
            @php($campaignCount = $system->active_campaigns_count)
            @if($sessionCount > 0 || $campaignCount > 0)
                <p class="text-sm text-on-surface-variant mb-4">
                    {{ __('games.content_sessions_using_this_system', ['sessions' => $sessionCount, 'campaigns' => $campaignCount]) }}
                </p>
                <div class="flex flex-wrap gap-3">
                    {{-- No session_type scope: active_sessions_count includes
                         campaign-session games, and the oneshot tab would hide
                         them — the All tab is a superset of the count. --}}
                    @if($sessionCount > 0)
                        <a href="{{ route($sessionRoute, ['game_system_id' => $system->id]) }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2.5 bg-primary text-on-primary rounded-xl text-sm font-semibold shadow-xs hover:shadow-md transition-shadow">
                            <span class="material-symbols-outlined text-lg" aria-hidden="true">group</span>
                            {{ __('games.content_active_sessions', ['count' => $sessionCount]) }}
                        </a>
                    @endif
                    {{-- mode=campaigns lists campaign entities — what the count
                         counts. The adventures ?session_type=campaign tab is
                         session-centric (campaign entities excluded by design)
                         and renders empty for campaigns without upcoming
                         sessions. --}}
                    @if($campaignCount > 0)
                        <a href="{{ route('discover', ['mode' => 'campaigns', 'game_system_id' => $system->id]) }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2.5 bg-secondary-container text-on-secondary-container rounded-xl text-sm font-semibold shadow-xs hover:shadow-md transition-shadow">
                            <span class="material-symbols-outlined text-lg" aria-hidden="true">campaign</span>
                            {{ __('games.label_active_campaigns_count', ['count' => $campaignCount]) }}
                        </a>
                    @endif
                </div>
            @else
                <p class="text-sm text-on-surface-variant mb-4">
                    {{ __('games.content_check_back_soon') }}
                </p>
                <a href="{{ route($sessionRoute, ['game_system_id' => $system->id]) }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2.5 bg-surface-container-high text-on-surface-variant rounded-xl text-sm font-medium hover:text-primary transition-colors">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">explore</span>
                    {{ __('games.action_find_sessions') }}
                </a>
            @endif
