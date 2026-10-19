@php($section = $content['character'] ?? [])

<div class="space-y-6">
    {{-- Build constraints --}}
    <div>
        <label for="charter-character-build-constraints" class="block text-sm font-medium text-on-surface mb-1">
            {{ __('charter.field_character_build_constraints_label') }}
        </label>
        <textarea
            id="charter-character-build-constraints"
            wire:model="content.character.build_constraints"
            rows="4"
            placeholder="{{ __('charter.field_character_build_constraints_placeholder') }}"
            class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm placeholder:text-on-surface-variant focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors resize-y px-4 py-2.5"
        >{{ $section['build_constraints'] ?? '' }}</textarea>
        <p class="mt-1 text-xs text-on-surface-variant">{{ __('charter.field_character_build_constraints_help') }}</p>
        @error('content.character.build_constraints')
            <p class="mt-1 text-xs text-error">{{ $message }}</p>
        @enderror
    </div>

    {{-- Backstory hooks requested --}}
    <div>
        <label class="flex items-start gap-3 px-3 py-2.5 rounded-lg border transition-colors cursor-pointer {{ ! empty($section['backstory_requested']) ? 'border-primary/40 bg-primary/5' : 'border-outline/20 bg-surface-container-high' }}">
            <input
                type="checkbox"
                wire:model="content.character.backstory_requested"
                @checked(! empty($section['backstory_requested']))
                class="mt-0.5 rounded-sm border-outline/40 text-primary focus:ring-primary/20"
            />
            <span>
                <span class="text-sm font-medium text-on-surface block">{{ __('charter.field_character_backstory_requested_label') }}</span>
                <span class="text-xs text-on-surface-variant">{{ __('charter.field_character_backstory_requested_help') }}</span>
            </span>
        </label>
    </div>

    {{-- Backstory guidance --}}
    @if(! empty($section['backstory_requested']))
        <div>
            <label for="charter-character-backstory-guidance" class="block text-sm font-medium text-on-surface mb-1">
                {{ __('charter.field_character_backstory_guidance_label') }}
            </label>
            <textarea
                id="charter-character-backstory-guidance"
                wire:model="content.character.backstory_guidance"
                rows="3"
                placeholder="{{ __('charter.field_character_backstory_guidance_placeholder') }}"
                class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm placeholder:text-on-surface-variant focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors resize-y px-4 py-2.5"
            >{{ $section['backstory_guidance'] ?? '' }}</textarea>
            <p class="mt-1 text-xs text-on-surface-variant">{{ __('charter.field_character_backstory_guidance_help') }}</p>
            @error('content.character.backstory_guidance')
                <p class="mt-1 text-xs text-error">{{ $message }}</p>
            @enderror
        </div>
    @endif
</div>
