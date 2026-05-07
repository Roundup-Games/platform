{{-- Vibe Preferences --}}
<section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
    <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-1 flex items-center gap-2">
        <span class="material-symbols-outlined text-lg text-on-surface-variant" aria-hidden="true">mood</span>
        {{ __('profile.content_vibe_preferences') }}
    </h2>
    <p class="text-sm text-on-surface-variant mb-6">{{ __("profile.action_tell_us_which_play_styles_you_enjoy") }}</p>

    <livewire:components.vibe-preference-picker
        :wire:key="'vibe-prefs'"
        :preferences="$vibePreferences"
    />

    @php
        $vibeFavorites = count(array_filter($vibePreferences, fn ($v) => $v === 'favorite'));
        $vibeAvoids = count(array_filter($vibePreferences, fn ($v) => $v === 'avoid'));
    @endphp
    <p class="mt-4 text-xs text-on-surface-variant">
        {{ __('profile.content_favorites_favorite_avoids_avoided', [
            'favorites' => $vibeFavorites,
            'avoids' => $vibeAvoids,
        ]) }}
    </p>
</section>
