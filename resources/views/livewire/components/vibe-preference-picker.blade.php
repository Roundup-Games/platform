@php
    $pairedFlags = $this->getPairedFlags;
    $standaloneFlags = $this->getStandaloneFlags;

    // Build lookup: flag value → pair data, for per-group rendering of paired flags
    $flagToPair = [];
    $flagToGroupLabel = [];
    foreach ($pairedFlags as $pair) {
        $flagToPair[$pair['flagA']] = $pair;
        $flagToPair[$pair['flagB']] = $pair;
    }

    // Build lookup: flag value → group label
    $grouped = \App\Enums\VibeFlag::grouped();
    foreach ($grouped as $groupKey => $group) {
        foreach ($group['options'] as $flagValue => $flagLabel) {
            $flagToGroupLabel[$flagValue] = $group['label'];
        }
    }

    // Group standalone flags by their group label
    $standaloneByGroup = [];
    foreach ($standaloneFlags as $sf) {
        $standaloneByGroup[$sf['groupLabel']][] = $sf;
    }

    // Group paired flags by the group of flagA
    $pairedByGroup = [];
    foreach ($pairedFlags as $pair) {
        $groupLabel = $flagToGroupLabel[$pair['flagA']] ?? 'Other';
        $pairedByGroup[$groupLabel][] = $pair;
    }

    // Merge all groups in VibeFlag::grouped() order
    $allGroupLabels = [];
    foreach ($grouped as $group) {
        $allGroupLabels[] = $group['label'];
    }
@endphp

<div class="space-y-5" wire:key="vibe-preference-picker-{{ crc32(json_encode($preferences)) }}">
    @foreach($allGroupLabels as $groupLabel)
        @php
            $groupPaired = $pairedByGroup[$groupLabel] ?? [];
            $groupStandalone = $standaloneByGroup[$groupLabel] ?? [];
        @endphp

        @if(!empty($groupPaired) || !empty($groupStandalone))
            <div>
                <p class="text-xs font-medium text-on-surface-variant uppercase tracking-wider mb-3">{{ $groupLabel }}</p>

                {{-- Segmented controls for paired flags --}}
                @foreach($groupPaired as $pair)
                    <div class="mb-3">
                        <div class="flex rounded-lg overflow-hidden border border-outline/20">
                            {{-- Flag A (favorite) --}}
                            <button
                                type="button"
                                wire:click="togglePaired('{{ $pair['flagA'] }}', '{{ $pair['flagB'] }}', 'favorite')"
                                @class([
                                    'flex-1 px-3 py-2 text-sm font-medium transition-all flex items-center justify-center gap-1.5',
                                    'bg-primary text-on-primary shadow-sm' => $pair['valueA'] === 'favorite',
                                    'bg-surface-container-high text-on-surface-variant hover:bg-surface-container' => $pair['valueA'] !== 'favorite',
                                ])
                                aria-pressed="{{ $pair['valueA'] === 'favorite' ? 'true' : 'false' }}"
                                aria-label="{{ $pair['labelA'] }}: {{ __('profile.action_favorite') }}"
                            >
                                <span class="material-symbols-outlined text-sm" aria-hidden="true">thumb_up</span>
                                {{ $pair['labelA'] }}
                            </button>

                            {{-- Neutral center --}}
                            <button
                                type="button"
                                wire:click="togglePaired('{{ $pair['flagA'] }}', '{{ $pair['flagB'] }}', null)"
                                @class([
                                    'px-3 py-2 text-sm transition-all flex items-center justify-center border-x border-outline/20',
                                    'bg-secondary-container text-on-secondary-container' => $pair['valueA'] === null && $pair['valueB'] === null,
                                    'bg-surface-container-high text-on-surface-variant hover:bg-surface-container' => !($pair['valueA'] === null && $pair['valueB'] === null),
                                ])
                                aria-pressed="{{ ($pair['valueA'] === null && $pair['valueB'] === null) ? 'true' : 'false' }}"
                                aria-label="{{ __('profile.action_neutral') }}"
                            >
                                <span class="material-symbols-outlined text-sm" aria-hidden="true">remove</span>
                            </button>

                            {{-- Flag B (favorite) --}}
                            <button
                                type="button"
                                wire:click="togglePaired('{{ $pair['flagB'] }}', '{{ $pair['flagA'] }}', 'favorite')"
                                @class([
                                    'flex-1 px-3 py-2 text-sm font-medium transition-all flex items-center justify-center gap-1.5',
                                    'bg-primary text-on-primary shadow-sm' => $pair['valueB'] === 'favorite',
                                    'bg-surface-container-high text-on-surface-variant hover:bg-surface-container' => $pair['valueB'] !== 'favorite',
                                ])
                                aria-pressed="{{ $pair['valueB'] === 'favorite' ? 'true' : 'false' }}"
                                aria-label="{{ $pair['labelB'] }}: {{ __('profile.action_favorite') }}"
                            >
                                <span class="material-symbols-outlined text-sm" aria-hidden="true">thumb_up</span>
                                {{ $pair['labelB'] }}
                            </button>
                        </div>

                        {{-- Show avoid indicators if any flag is avoided but not via active favorite --}}
                        @if($pair['valueA'] === 'avoid' || $pair['valueB'] === 'avoid')
                            <div class="flex gap-2 mt-1">
                                @if($pair['valueA'] === 'avoid')
                                    <span class="inline-flex items-center gap-1 text-xs text-error">
                                        <span class="material-symbols-outlined text-xs" aria-hidden="true">thumb_down</span>
                                        {{ $pair['labelA'] }} {{ __('profile.content_avoided') }}
                                    </span>
                                @endif
                                @if($pair['valueB'] === 'avoid')
                                    <span class="inline-flex items-center gap-1 text-xs text-error">
                                        <span class="material-symbols-outlined text-xs" aria-hidden="true">thumb_down</span>
                                        {{ $pair['labelB'] }} {{ __('profile.content_avoided') }}
                                    </span>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach

                {{-- Tri-state chips for standalone flags --}}
                @if(!empty($groupStandalone))
                    <div class="flex flex-wrap gap-2">
                        @foreach($groupStandalone as $sf)
                            <button
                                type="button"
                                wire:click="toggleStandalone('{{ $sf['flag'] }}')"
                                @class([
                                    'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm cursor-pointer transition-all',
                                    'bg-surface-container-high border-outline/20 text-on-surface-variant hover:border-outline/40' => $sf['value'] === null,
                                    'bg-primary border-primary text-on-primary font-medium shadow-sm' => $sf['value'] === 'favorite',
                                    'bg-error-container border-error text-on-error-container' => $sf['value'] === 'avoid',
                                ])
                                aria-pressed="{{ $sf['value'] === 'favorite' ? 'true' : ($sf['value'] === 'avoid' ? 'false' : 'mixed') }}"
                                aria-label="{{ $sf['label'] }}: {{ $sf['value'] === 'favorite' ? __('profile.action_favorite') : ($sf['value'] === 'avoid' ? __('profile.action_avoid') : __('profile.action_neutral')) }}"
                            >
                                <span class="material-symbols-outlined text-sm" aria-hidden="true">
                                    {{ $sf['value'] === 'favorite' ? 'thumb_up' : ($sf['value'] === 'avoid' ? 'thumb_down' : 'remove') }}
                                </span>
                                {{ $sf['label'] }}
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    @endforeach
</div>
