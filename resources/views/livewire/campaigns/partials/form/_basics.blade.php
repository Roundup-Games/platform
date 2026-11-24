{{-- The offered game system(s) — single for focused types, multi for a gathering — and the cover image.
     Shared by the create form and the edit mode (D317): included with no argument array, so it
     reads the including view's scope — $cover_image, $game_type, $game_system_id, $game_systems, $errors.
     The edit mode passes $lockedFields (field => true) in the include's data array; the create view
     passes nothing, so a picker is disabled only where a Tier-1 lock says so (D314). The offered
     system is Tier 1 (it is the thing itself); the cover is Tier 3 and never disabled. --}}
    @php($lockedFields ??= [])
    @if($game_type !== 'gathering')
        {{-- Focused types: single game system --}}
        <div>
            <livewire:components.game-system-picker
                :fieldId="'campaign-system'"
                :label="__('games.content_game_system')"
                :error="$errors->first('game_system_id')"
                :gameType="$game_type"
                :value="$game_system_id"
                wire:model.live="game_system_id"
                wire:key="campaign-system-picker-{{ $game_type ?? 'default' }}"
                :disabled="isset($lockedFields['game_system_id'])"
            />
        </div>
    @else
        {{-- Gathering: multi-system picker (the host picks what to play) --}}
        <div>
            <label class="block text-sm font-medium text-on-surface mb-1">{{ __('games.content_game_system') }}</label>
            <livewire:components.game-system-preference-picker :mode="'creation'" :preferenceType="'favorite'" :selectedIds="$game_systems" :maxSystems="6" :disabled="isset($lockedFields['game_systems'])" wire:key="campaign-system-picker-gathering" />
            @error('game_systems') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
        </div>
    @endif

    {{-- Host-uploaded cover image (S07). Optional — resolveCoverUrl() falls back to the representative system cover, then og-default.jpg. --}}
    <div>
        <label for="campaign-cover-image" class="block text-sm font-medium text-on-surface mb-1">{{ __('games.field_cover_image') }}</label>
        <div class="mt-1">
            @if($cover_image && str_starts_with($cover_image->getMimeType(), 'image/'))
                <div class="relative inline-block">
                    <img src="{{ $cover_image->temporaryUrl() }}" alt="" class="h-32 w-32 object-cover rounded-lg border border-outline-variant">
                    <button type="button" wire:click="$set('cover_image', null)" class="absolute -top-2 -right-2 bg-surface rounded-full p-1 shadow-md hover:bg-surface-container-high transition-colors" aria-label="{{ __('games.action_remove_cover_image') }}">
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">close</span>
                    </button>
                </div>
            @elseif($cover_image)
                {{-- Non-image file selected (will fail the image validation rule).
                     Rendered without temporaryUrl(), which throws for non-previewable mimes. --}}
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm text-on-surface-variant" aria-hidden="true">description</span>
                    <span class="text-sm text-on-surface-variant">{{ $cover_image->getClientOriginalName() }}</span>
                    <button type="button" wire:click="$set('cover_image', null)" class="text-on-surface-variant hover:text-error transition-colors" aria-label="{{ __('games.action_remove_cover_image') }}">
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">close</span>
                    </button>
                </div>
            @else
                <input type="file" id="campaign-cover-image" wire:model="cover_image" accept="image/jpeg,image/png,image/webp"
                       class="block w-full text-sm text-on-surface file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-primary file:text-on-primary file:font-medium hover:file:brightness-110 cursor-pointer" />
                <p class="mt-1 text-xs text-on-surface-variant/60">{{ __('games.content_cover_image_hint') }}</p>
                <div wire:loading wire:target="cover_image" class="mt-1 text-xs text-on-surface-variant flex items-center gap-1">
                    <span class="material-symbols-outlined text-sm animate-spin" aria-hidden="true" role="status">progress_activity</span>
                    {{ __('common.content_uploading') }}
                </div>
            @endif
        </div>
        @error('cover_image') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
    </div>
