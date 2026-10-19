@php($section = $content['boundaries'] ?? [])

<div class="space-y-6">
    {{-- Lines: hard no --}}
    <div class="rounded-xl border border-outline/10 bg-surface-container-high/40 p-4">
        <p class="text-sm font-medium text-on-surface mb-1">{{ __('charter.field_boundaries_lines_label') }}</p>
        <p class="text-xs text-on-surface-variant mb-4">{{ __('charter.field_boundaries_lines_help') }}</p>

        <p class="text-xs font-medium text-on-surface-variant uppercase tracking-wider mb-2">{{ __('charter.field_boundaries_lines_categories_label') }}</p>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 mb-4">
            @foreach(\App\Dto\CharterContent::BOUNDARY_CATEGORIES as $category)
                <label class="flex items-center gap-2 px-3 py-2 rounded-lg border transition-colors cursor-pointer {{ in_array($category, $section['lines_categories'] ?? []) ? 'border-error/40 bg-error/5' : 'border-outline/20 bg-surface-container-high hover:border-outline/40' }}">
                    <input
                        type="checkbox"
                        value="{{ $category }}"
                        wire:model="content.boundaries.lines_categories"
                        @checked(in_array($category, $section['lines_categories'] ?? []))
                        class="rounded-sm border-outline/40 text-primary focus:ring-primary/20"
                    />
                    <span class="text-sm text-on-surface">{{ __('charter.label_boundary_'.$category) }}</span>
                </label>
            @endforeach
        </div>
        @error('content.boundaries.lines_categories')
            <p class="mb-2 text-xs text-error">{{ $message }}</p>
        @enderror

        <label for="charter-boundaries-lines-text" class="block text-xs font-medium text-on-surface-variant uppercase tracking-wider mb-2">
            {{ __('charter.field_boundaries_lines_text_label') }}
        </label>
        <textarea
            id="charter-boundaries-lines-text"
            wire:model="content.boundaries.lines_text"
            rows="3"
            placeholder="{{ __('charter.field_boundaries_lines_placeholder') }}"
            class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm placeholder:text-on-surface-variant focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors resize-y px-4 py-2.5"
        >{{ $section['lines_text'] ?? '' }}</textarea>
        @error('content.boundaries.lines_text')
            <p class="mt-1 text-xs text-error">{{ $message }}</p>
        @enderror
    </div>

    {{-- Veils: fade to black --}}
    <div class="rounded-xl border border-outline/10 bg-surface-container-high/40 p-4">
        <p class="text-sm font-medium text-on-surface mb-1">{{ __('charter.field_boundaries_veils_label') }}</p>
        <p class="text-xs text-on-surface-variant mb-4">{{ __('charter.field_boundaries_veils_help') }}</p>

        <p class="text-xs font-medium text-on-surface-variant uppercase tracking-wider mb-2">{{ __('charter.field_boundaries_veils_categories_label') }}</p>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 mb-4">
            @foreach(\App\Dto\CharterContent::BOUNDARY_CATEGORIES as $category)
                <label class="flex items-center gap-2 px-3 py-2 rounded-lg border transition-colors cursor-pointer {{ in_array($category, $section['veils_categories'] ?? []) ? 'border-tertiary/50 bg-tertiary/5' : 'border-outline/20 bg-surface-container-high hover:border-outline/40' }}">
                    <input
                        type="checkbox"
                        value="{{ $category }}"
                        wire:model="content.boundaries.veils_categories"
                        @checked(in_array($category, $section['veils_categories'] ?? []))
                        class="rounded-sm border-outline/40 text-primary focus:ring-primary/20"
                    />
                    <span class="text-sm text-on-surface">{{ __('charter.label_boundary_'.$category) }}</span>
                </label>
            @endforeach
        </div>
        @error('content.boundaries.veils_categories')
            <p class="mb-2 text-xs text-error">{{ $message }}</p>
        @enderror

        <label for="charter-boundaries-veils-text" class="block text-xs font-medium text-on-surface-variant uppercase tracking-wider mb-2">
            {{ __('charter.field_boundaries_veils_text_label') }}
        </label>
        <textarea
            id="charter-boundaries-veils-text"
            wire:model="content.boundaries.veils_text"
            rows="3"
            placeholder="{{ __('charter.field_boundaries_veils_placeholder') }}"
            class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm placeholder:text-on-surface-variant focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors resize-y px-4 py-2.5"
        >{{ $section['veils_text'] ?? '' }}</textarea>
        @error('content.boundaries.veils_text')
            <p class="mt-1 text-xs text-error">{{ $message }}</p>
        @enderror
    </div>

    {{-- Intro note --}}
    <div>
        <label for="charter-boundaries-intro-note" class="block text-sm font-medium text-on-surface mb-1">
            {{ __('charter.field_boundaries_intro_note_label') }}
        </label>
        <textarea
            id="charter-boundaries-intro-note"
            wire:model="content.boundaries.intro_note"
            rows="3"
            placeholder="{{ __('charter.field_boundaries_intro_note_placeholder') }}"
            class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm placeholder:text-on-surface-variant focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors resize-y px-4 py-2.5"
        >{{ $section['intro_note'] ?? '' }}</textarea>
        <p class="mt-1 text-xs text-on-surface-variant">{{ __('charter.field_boundaries_intro_note_help') }}</p>
        @error('content.boundaries.intro_note')
            <p class="mt-1 text-xs text-error">{{ $message }}</p>
        @enderror
    </div>
</div>
