@php($section = $content['overview'] ?? [])

<div class="space-y-6">
    {{-- Pitch --}}
    <div>
        <label for="charter-overview-pitch" class="block text-sm font-medium text-on-surface mb-1">
            {{ __('charter.field_overview_pitch_label') }}
        </label>
        <textarea
            id="charter-overview-pitch"
            wire:model="content.overview.pitch"
            rows="4"
            placeholder="{{ __('charter.field_overview_pitch_placeholder') }}"
            class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm placeholder:text-on-surface-variant focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors resize-y px-4 py-2.5"
        >{{ $section['pitch'] ?? '' }}</textarea>
        <p class="mt-1 text-xs text-on-surface-variant">{{ __('charter.field_overview_pitch_help') }}</p>
        @error('content.overview.pitch')
            <p class="mt-1 text-xs text-error">{{ $message }}</p>
        @enderror
    </div>

    {{-- System notes --}}
    <div>
        <label for="charter-overview-system-notes" class="block text-sm font-medium text-on-surface mb-1">
            {{ __('charter.field_overview_system_notes_label') }}
        </label>
        <input
            id="charter-overview-system-notes"
            type="text"
            wire:model="content.overview.system_notes"
            placeholder="{{ __('charter.field_overview_system_notes_placeholder') }}"
            class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm placeholder:text-on-surface-variant focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors px-4 py-2.5"
            value="{{ $section['system_notes'] ?? '' }}"
        />
        <p class="mt-1 text-xs text-on-surface-variant">{{ __('charter.field_overview_system_notes_help') }}</p>
        @error('content.overview.system_notes')
            <p class="mt-1 text-xs text-error">{{ $message }}</p>
        @enderror
    </div>

    {{-- Tone sliders --}}
    <div>
        <p class="text-sm font-medium text-on-surface mb-1">{{ __('charter.field_overview_tone_label') }}</p>
        <p class="text-xs text-on-surface-variant mb-4">{{ __('charter.field_overview_tone_help') }}</p>

        @foreach(['tone_serious_lighthearted', 'tone_gritty_heroic', 'tone_political_dungeon'] as $axis)
            <div class="mb-5 last:mb-0">
                <div class="flex items-center justify-between mb-1">
                    <label for="charter-overview-{{ $axis }}" class="text-sm text-on-surface">
                        {{ __('charter.field_overview_'.$axis.'_label') }}
                    </label>
                    <span class="text-xs font-medium text-primary">{{ $section[$axis] ?? 3 }}</span>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-xs text-on-surface-variant w-28 text-right shrink-0">{{ __('charter.field_overview_'.$axis.'_low') }}</span>
                    <input
                        id="charter-overview-{{ $axis }}"
                        type="range"
                        min="1"
                        max="5"
                        step="1"
                        wire:model="content.overview.{{ $axis }}"
                        class="flex-1 accent-primary"
                    />
                    <span class="text-xs text-on-surface-variant w-28 shrink-0">{{ __('charter.field_overview_'.$axis.'_high') }}</span>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Themes --}}
    <div>
        <p class="text-sm font-medium text-on-surface mb-1">{{ __('charter.field_overview_themes_label') }}</p>
        <p class="text-xs text-on-surface-variant mb-3">{{ __('charter.field_overview_themes_help') }}</p>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
            @foreach(\App\Dto\CharterContent::THEMES as $theme)
                <label class="flex items-center gap-2 px-3 py-2 rounded-lg border transition-colors cursor-pointer {{ in_array($theme, $section['themes'] ?? []) ? 'border-primary/40 bg-primary/5' : 'border-outline/20 bg-surface-container-high hover:border-outline/40' }}">
                    <input
                        type="checkbox"
                        value="{{ $theme }}"
                        wire:model="content.overview.themes"
                        @checked(in_array($theme, $section['themes'] ?? []))
                        class="rounded-sm border-outline/40 text-primary focus:ring-primary/20"
                    />
                    <span class="text-sm text-on-surface">{{ __('charter.label_theme_'.$theme) }}</span>
                </label>
            @endforeach
        </div>
        @error('content.overview.themes')
            <p class="mt-1 text-xs text-error">{{ $message }}</p>
        @enderror
    </div>

    {{-- Expected shape --}}
    <div>
        <p class="text-sm font-medium text-on-surface mb-1">{{ __('charter.field_overview_expected_shape_label') }}</p>
        <p class="text-xs text-on-surface-variant mb-3">{{ __('charter.field_overview_expected_shape_help') }}</p>
        <div class="grid sm:grid-cols-3 gap-2">
            @foreach(\App\Dto\CharterContent::choicesFor('overview.expected_shape') as $shape)
                <label class="flex items-center gap-2 px-3 py-2.5 rounded-lg border transition-colors cursor-pointer {{ ($section['expected_shape'] ?? '') === $shape ? 'border-primary/40 bg-primary/5' : 'border-outline/20 bg-surface-container-high hover:border-outline/40' }}">
                    <input
                        type="radio"
                        name="charter-overview-expected-shape"
                        value="{{ $shape }}"
                        wire:model="content.overview.expected_shape"
                        @checked(($section['expected_shape'] ?? '') === $shape)
                        class="rounded-full border-outline/40 text-primary focus:ring-primary/20"
                    />
                    <span class="text-sm text-on-surface">{{ __('charter.label_expected_shape_'.$shape) }}</span>
                </label>
            @endforeach
        </div>
        @error('content.overview.expected_shape')
            <p class="mt-1 text-xs text-error">{{ $message }}</p>
        @enderror
    </div>

    {{-- Table size + may add players --}}
    <div class="grid sm:grid-cols-2 gap-6">
        <div>
            <label for="charter-overview-table-size" class="block text-sm font-medium text-on-surface mb-1">
                {{ __('charter.field_overview_table_size_label') }}
            </label>
            <input
                id="charter-overview-table-size"
                type="number"
                min="2"
                max="12"
                wire:model="content.overview.table_size"
                class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors px-4 py-2.5"
                value="{{ $section['table_size'] ?? 4 }}"
            />
            <p class="mt-1 text-xs text-on-surface-variant">{{ __('charter.field_overview_table_size_help') }}</p>
            @error('content.overview.table_size')
                <p class="mt-1 text-xs text-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex flex-col justify-center">
            <label class="flex items-start gap-3 px-3 py-2.5 rounded-lg border transition-colors cursor-pointer {{ ! empty($section['may_add_players']) ? 'border-primary/40 bg-primary/5' : 'border-outline/20 bg-surface-container-high' }}">
                <input
                    type="checkbox"
                    wire:model="content.overview.may_add_players"
                    @checked(! empty($section['may_add_players']))
                    class="mt-0.5 rounded-sm border-outline/40 text-primary focus:ring-primary/20"
                />
                <span>
                    <span class="text-sm font-medium text-on-surface block">{{ __('charter.field_overview_may_add_players_label') }}</span>
                    <span class="text-xs text-on-surface-variant">{{ __('charter.field_overview_may_add_players_help') }}</span>
                </span>
            </label>
        </div>
    </div>
</div>
