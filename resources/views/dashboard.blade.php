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
            <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-4">
                <a href="{{ route('profile.show') }}" wire:navigate class="bg-surface-container-lowest p-6 rounded-xl shadow-ambient hover:shadow-ambient-md transition-all group">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-xl flex items-center justify-center">
                            <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">person</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface group-hover:text-primary transition-colors">{{ __('profile.content_my_profile') }}</h3>
                            <p class="text-sm text-on-surface-variant">{{ __('profile.action_view_and_edit_your_profile') }}</p>
                        </div>
                    </div>
                </a>

                <a href="{{ route('people') }}" wire:navigate class="bg-surface-container-lowest p-6 rounded-xl shadow-ambient hover:shadow-ambient-md transition-all group">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-xl flex items-center justify-center">
                            <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">people</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface group-hover:text-primary transition-colors">People</h3>
                            <p class="text-sm text-on-surface-variant">Manage following, followers & blocked</p>
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
            </div>
        </div>
    </div>
</x-app-layout>
