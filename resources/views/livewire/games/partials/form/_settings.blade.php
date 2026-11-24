{{-- Language and visibility, with the public-visibility hints.
     Shared by the create form and the edit mode (D315): included with no argument array, so it
     reads the including view's scope — $errors (it reads $this->hostingEvent itself).
     The edit mode passes $lockedFields (field => true), $editMode and $privateVisibilityLocked in
     the include's data array; the create view passes nothing. Language is Tier 1. Visibility is
     direction-dependent (D313): only public → private is locked, so on a locked public game just
     that one option is disabled — and an already-public game keeps its public option. --}}
    @php($lockedFields ??= [])
    @php($editMode ??= false)
    @php($privateVisibilityLocked ??= false)
    <div>
        <label for="game-language" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.content_language') }} <span class="text-error">*</span></label>
        <select id="game-language" wire:model="language"
                @disabled(isset($lockedFields['language']))
                class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors{{ isset($lockedFields['language']) ? ' opacity-60 cursor-not-allowed' : '' }}">
            @foreach($this->languageOptions as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        @error('language') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
    </div>

    <div>
        <label for="game-visibility" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.content_visibility') }} <span class="text-error">*</span></label>
        <select id="game-visibility" wire:model="visibility"
                class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors">
            @php($hostingEvent = $this->hostingEvent)
            @if($this->canCreatePublic || $hostingEvent?->is_public || ($editMode && $visibility === 'public'))
                {{-- Public-event tables may always be public: the umbrella
                     event is itself public, so the venue-trust gate that
                     guards standalone public discovery does not apply. In the
                     edit mode a game that is already public keeps the option,
                     whatever the owner's venue trust is today. --}}
                <option value="public">{{ __('common.content_public_anyone_can_find_and_join') }}</option>
            @endif
            <option value="protected">{{ __('common.field_protected_only_with_link') }}</option>
            <option value="private" @disabled($privateVisibilityLocked)>{{ __('common.content_private_invite_only') }}</option>
        </select>
        @if($hostingEvent?->is_public)
            {{-- Public-event rule: save() rejects restricted tables at
                 public get-togethers — say so before submit, not after. --}}
            <p class="mt-1 text-xs text-on-surface-variant/60">{{ __('events.content_table_visibility_hint') }}</p>
        @elseif(!$this->canCreatePublic)
            <p class="mt-1 text-xs text-on-surface-variant/60">{{ __('common.content_public_visibility_requires_admin_approval') }}</p>
        @endif
        @if($this->publicViaVenue)
            <p class="mt-1 text-xs text-success">{{ __('venues.content_public_unlocked_by_verified_venue') }}</p>
        @endif
        @error('visibility') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
    </div>
