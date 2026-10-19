@php($section = $content['overview'] ?? [])

<section id="charter-section-overview" class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 sm:p-8 scroll-mt-24">
    <div class="flex items-center gap-2 mb-6">
        <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">menu_book</span>
        <h3 class="font-heading text-lg font-semibold text-on-surface">{{ __('charter.section_overview_title') }}</h3>
    </div>

    <div class="space-y-6">
        {{-- Pitch --}}
        @if(($section['pitch'] ?? '') !== '')
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_overview_pitch_label') }}</p>
                @include('livewire.charter.sections._prose', ['value' => $section['pitch']])
            </div>
        @endif

        {{-- System notes --}}
        @if(($section['system_notes'] ?? '') !== '')
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_overview_system_notes_label') }}</p>
                @include('livewire.charter.sections._prose', ['value' => $section['system_notes']])
            </div>
        @endif

        {{-- Tone dials --}}
        <div class="space-y-3">
            <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_overview_tone_label') }}</p>
            @foreach(['tone_serious_lighthearted', 'tone_gritty_heroic', 'tone_political_dungeon'] as $axis)
                @php($position = (int) ($section[$axis] ?? 3))
                <div class="flex items-center justify-between gap-4">
                    <span class="text-sm text-on-surface">{{ __('charter.field_overview_'.$axis.'_label') }}</span>
                    <span class="flex items-center gap-2">
                        <span class="text-xs text-on-surface-variant">{{ __('charter.field_overview_'.$axis.'_low') }}</span>
                        <span class="flex items-center gap-1" role="img" aria-label="{{ $position }} / 5">
                            @foreach(range(1, 5) as $dot)
                                <span class="w-2 h-2 rounded-full {{ $dot === $position ? 'bg-primary' : 'bg-outline/30' }}" aria-hidden="true"></span>
                            @endforeach
                        </span>
                        <span class="text-xs text-on-surface-variant">{{ __('charter.field_overview_'.$axis.'_high') }}</span>
                    </span>
                </div>
            @endforeach
        </div>

        {{-- Theme chips --}}
        @if(! empty($section['themes']))
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_overview_themes_label') }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach($section['themes'] as $theme)
                        <span class="inline-flex items-center px-3 py-1 rounded-full bg-primary/10 text-primary text-xs font-medium">
                            {{ __('charter.label_theme_'.$theme) }}
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Shape & table size --}}
        <div class="grid sm:grid-cols-2 gap-x-8 gap-y-4">
            @if(($section['expected_shape'] ?? '') !== '')
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_overview_expected_shape_label') }}</p>
                    <p class="mt-1 text-sm text-on-surface">{{ __('charter.label_expected_shape_'.$section['expected_shape']) }}</p>
                </div>
            @endif
            @if(isset($section['table_size']))
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_overview_table_size_label') }}</p>
                    <p class="mt-1 text-sm text-on-surface">{{ (int) $section['table_size'] }}</p>
                </div>
            @endif
        </div>

        {{-- Growing roster --}}
        @if(! empty($section['may_add_players']))
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-primary text-sm" aria-hidden="true">check_circle</span>
                <p class="text-sm text-on-surface">{{ __('charter.field_overview_may_add_players_label') }}</p>
            </div>
        @endif
    </div>
</section>
