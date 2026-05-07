{{-- Game System Preferences: Favorite + Avoid --}}
<section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
    <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-1 flex items-center gap-2">
        <span class="material-symbols-outlined text-lg text-on-surface-variant" aria-hidden="true">casino</span>
        {{ __('games.content_game_preferences') }}
    </h2>
    <p class="text-sm text-on-surface-variant mb-6">{{ __("profile.action_select_the_games_you_enjoy_and_those") }}</p>

    {{-- Favorite Games --}}
    <div class="mb-6">
        <h3 class="text-sm font-semibold text-on-surface mb-1 flex items-center gap-1.5">
            <span class="material-symbols-outlined text-sm text-primary" style="font-variation-settings: 'FILL' 1" aria-hidden="true">favorite</span>
            {{ __('games.content_favorite_games') }}
        </h3>
        <p class="text-xs text-on-surface-variant mb-3">{{ __("games.content_selecting_a_base_game_as_a_favorite_implies") }}</p>

        <livewire:components.game-system-preference-picker
            :wire:key="'picker-favorite'"
            preferenceType="favorite"
            :selectedIds="$favoriteGameSystemIds"
            :conflictIds="$avoidedGameSystemIds"
        />
    </div>

    {{-- Games to Avoid --}}
    <div>
        <h3 class="text-sm font-semibold text-on-surface mb-1 flex items-center gap-1.5">
            <span class="material-symbols-outlined text-sm text-error" style="font-variation-settings: 'FILL' 1" aria-hidden="true">block</span>
            {{ __('games.content_games_to_avoid') }}
        </h3>
        <p class="text-xs text-on-surface-variant mb-3">{{ __("profile.content_avoid_preferences_take_priority_over_favorites") }}</p>

        <livewire:components.game-system-preference-picker
            :wire:key="'picker-avoid'"
            preferenceType="avoid"
            :selectedIds="$avoidedGameSystemIds"
            :conflictIds="$favoriteGameSystemIds"
        />
    </div>

    @error('favoriteGameSystemIds') <p class="mt-2 text-sm text-error">{{ $message }}</p> @enderror
    @error('avoidedGameSystemIds') <p class="mt-2 text-sm text-error">{{ $message }}</p> @enderror

    <p class="mt-4 text-xs text-on-surface-variant">
        {{ __('profile.content_favorites_favorite_avoids_avoided', [
            'favorites' => count($favoriteGameSystemIds),
            'avoids' => count($avoidedGameSystemIds),
        ]) }}
    </p>
</section>
