        {{-- ── Active Sessions & Campaigns Discovery ────────────────── --}}
        @php($isTtrpg = $system->type === 'ttrpg')
        @php($sessionRoute = $isTtrpg ? 'discover.adventures' : 'discover.board-games')
        <section class="bg-surface-container rounded-xl shadow-ambient p-6">
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
                    @if($sessionCount > 0)
                        @if($isTtrpg)
                            <a href="{{ route($sessionRoute, ['game_system_id' => $system->id, 'session_type' => 'oneshot']) }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2.5 bg-primary text-on-primary rounded-xl text-sm font-semibold shadow-sm hover:shadow-md transition-shadow">
                        @else
                            <a href="{{ route($sessionRoute, ['game_system_id' => $system->id]) }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2.5 bg-primary text-on-primary rounded-xl text-sm font-semibold shadow-sm hover:shadow-md transition-shadow">
                        @endif
                            <span class="material-symbols-outlined text-lg" aria-hidden="true">group</span>
                            {{ __('games.content_active_sessions', ['count' => $sessionCount]) }}
                        </a>
                    @endif
                    @if($campaignCount > 0)
                        <a href="{{ route('discover.adventures', ['game_system_id' => $system->id, 'session_type' => 'campaign']) }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2.5 bg-secondary-container text-on-secondary-container rounded-xl text-sm font-semibold shadow-sm hover:shadow-md transition-shadow">
                            <span class="material-symbols-outlined text-lg" aria-hidden="true">campaign</span>
                            {{ __('games.content_active_campaigns', ['count' => $campaignCount]) }}
                        </a>
                    @endif
                </div>
            @else
                <p class="text-sm text-on-surface-variant mb-4">
                    {{ __('games.content_no_game_systems_available_yet_short') }}
                </p>
                <a href="{{ route($sessionRoute, ['game_system_id' => $system->id]) }}" wire:navigate class="inline-flex items-center gap-2 px-4 py-2.5 bg-surface-container-high text-on-surface-variant rounded-xl text-sm font-medium hover:text-primary transition-colors">
                    <span class="material-symbols-outlined text-lg" aria-hidden="true">explore</span>
                    {{ __('games.action_find_sessions') }}
                </a>
            @endif
