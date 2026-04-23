<x-app-layout>
    @section('title', __('profile.content_dashboard'))

    <div class="py-4">
        <div class="max-w-7xl mx-auto">
            {{-- Welcome Card --}}
            <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-8">
                <h2 class="font-heading text-2xl font-bold text-on-surface tracking-tight">
                    {{ __('common.content_welcome_back_name', ['name' => Auth::user()->name]) }}
                </h2>
                <p class="mt-2 text-on-surface-variant">
                    {{ __("events.content_you_re_logged_in_to") }}
                </p>
            </div>

            {{-- Quick Actions --}}
            <div class="mt-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <a href="{{ route('games.index') }}" wire:navigate class="bg-surface-container-lowest p-6 rounded-xl shadow-ambient hover:shadow-ambient-md transition-all group">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-xl flex items-center justify-center">
                            <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">stadium</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface group-hover:text-primary transition-colors">{{ __('profile.dashboard_card_my_games') }}</h3>
                            <p class="text-sm text-on-surface-variant">{{ __('profile.dashboard_card_my_games_desc') }}</p>
                        </div>
                    </div>
                    @if($gameCount > 0)
                        <div class="mt-3 inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-primary/10 text-primary text-sm font-medium">
                            <span class="material-symbols-outlined text-sm" style="font-variation-settings: 'FILL' 1">stadium</span>
                            {{ $gameCount }} {{ $gameCount === 1 ? __('games.content_game') : __('games.content_games') }}
                        </div>
                    @endif
                </a>

                <a href="{{ route('campaigns.index') }}" wire:navigate class="bg-surface-container-lowest p-6 rounded-xl shadow-ambient hover:shadow-ambient-md transition-all group">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-xl flex items-center justify-center">
                            <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">campaign</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface group-hover:text-primary transition-colors">{{ __('profile.dashboard_card_my_campaigns') }}</h3>
                            <p class="text-sm text-on-surface-variant">{{ __('profile.dashboard_card_my_campaigns_desc') }}</p>
                        </div>
                    </div>
                    @if($campaignCount > 0)
                        <div class="mt-3 inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full bg-primary/10 text-primary text-sm font-medium">
                            <span class="material-symbols-outlined text-sm" style="font-variation-settings: 'FILL' 1">campaign</span>
                            {{ $campaignCount }} {{ $campaignCount === 1 ? __('campaigns.content_campaign') : __('campaigns.content_campaigns') }}
                        </div>
                    @endif
                </a>

                <a href="{{ route('people') }}" wire:navigate class="bg-surface-container-lowest p-6 rounded-xl shadow-ambient hover:shadow-ambient-md transition-all group">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-xl flex items-center justify-center">
                            <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">people</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface group-hover:text-primary transition-colors">{{ __('people.content_people') }}</h3>
                            <p class="text-sm text-on-surface-variant">{{ __('people.content_manage_following_followers_blocked') }}</p>
                        </div>
                    </div>
                </a>

                <a href="{{ route('discover') }}" wire:navigate class="bg-surface-container-lowest p-6 rounded-xl shadow-ambient hover:shadow-ambient-md transition-all group">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-xl flex items-center justify-center">
                            <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">explore</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface group-hover:text-primary transition-colors">{{ __('discovery.action_discover') }}</h3>
                            <p class="text-sm text-on-surface-variant">{{ __('discovery.content_find_games_near_you') }}</p>
                        </div>
                    </div>
                </a>

                @if(Auth::user()?->isGM())
                <a href="{{ route('gm.workspace') }}" wire:navigate class="bg-surface-container-lowest p-6 rounded-xl shadow-ambient hover:shadow-ambient-md transition-all group">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-xl flex items-center justify-center">
                            <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">casino</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface group-hover:text-primary transition-colors">{{ __('profile.dashboard_card_gm_workspace') }}</h3>
                            <p class="text-sm text-on-surface-variant">{{ __('profile.dashboard_card_gm_workspace_desc') }}</p>
                        </div>
                    </div>
                </a>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
