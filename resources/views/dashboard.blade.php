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

                <div class="bg-surface-container-lowest p-6 rounded-xl shadow-ambient">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-xl flex items-center justify-center">
                            <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">sports_esports</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface">{{ __('games.content_games') }}</h3>
                            <p class="text-sm text-on-surface-variant">{{ __('common.field_coming_soon_2') }}</p>
                        </div>
                    </div>
                </div>

                <div class="bg-surface-container-lowest p-6 rounded-xl shadow-ambient">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-primary/10 rounded-xl flex items-center justify-center">
                            <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">groups</span>
                        </div>
                        <div>
                            <h3 class="font-heading font-semibold text-on-surface">{{ __('pages.content_community') }}</h3>
                            <p class="text-sm text-on-surface-variant">{{ __('common.field_coming_soon_2') }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
