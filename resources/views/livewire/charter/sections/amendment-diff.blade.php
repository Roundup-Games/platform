@php
    // Shared amendment diff renderer (M070/S03): field-level rows from
    // App\Support\CharterDiff::changes() — used by the GM review-step
    // preview (CharterComposer) and the participant re-agreement view.
    // Field labels reuse field_{section}_{field}_label; vocabulary values
    // reuse label_{value} keys, with the SafetyTool enum as fallback.

    /** Resolve the label key for a field, tolerating FK-style *_id names. */
    $fieldLabelKey = static function (string $section, string $field): string {
        $key = 'charter.field_'.$section.'_'.$field.'_label';
        if (\Illuminate\Support\Facades\Lang::has($key)) {
            return $key;
        }

        // logistics.location_id labels the venue, not the id column.
        $entityKey = 'charter.field_'.$section.'_'.\Illuminate\Support\Str::before($field, '_id').'_label';

        return \Illuminate\Support\Facades\Lang::has($entityKey) ? $entityKey : $key;
    };

    /** String-list vocab values localize through their VOCABULARY
         * prefix (label_theme_*, label_boundary_* — the same keys
         * every composed section uses), never through the plural
         * field name: themes/lines_categories/veils_categories have
         * no label_{field}_{value} keys, and without this map the
         * re-agreement diff would render raw slugs ("violence") in
         * the most consent-critical rows. */
    $vocabPrefixes = [
        'themes' => 'theme',
        'lines_categories' => 'boundary',
        'veils_categories' => 'boundary',
    ];

    /** Human display for one diff value: vocab-prefixed keys first for
         * string-list fields, then the field-scoped
         * label_{field}_{value} convention, then the unscoped
         * label_{value}, SafetyTool chips resolve via the enum, long
         * free text is clipped. */
    $displayValue = static function (mixed $value, ?string $field = null) use ($vocabPrefixes): string {
        if ($value === null || $value === '') {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? (string) __('common.action_yes') : (string) __('common.action_no');
        }
        if (is_int($value) || is_float($value)) {
            // Tone axes (1–5) localize through the same low/high labels
            // the sliders carry: a material §1 tone change renders
            // "Serious (1) → Lighthearted (5)" in the re-agreement
            // banner, not bare integers. The middle position (3) has no
            // label key and stays numeric.
            if (is_int($value) && $field !== null && str_starts_with($field, 'tone_')
                && ($value <= 2 || $value >= 4)) {
                $endKey = 'charter.field_overview_'.$field.'_'.($value <= 2 ? 'low' : 'high');

                if (\Illuminate\Support\Facades\Lang::has($endKey)) {
                    return (string) __($endKey).' ('.$value.')';
                }
            }

            return (string) $value;
        }
        if (is_string($value)) {
            $candidateKeys = [];

            if ($field !== null && isset($vocabPrefixes[$field])) {
                $candidateKeys[] = 'charter.label_'.$vocabPrefixes[$field].'_'.$value;
            }

            if ($field !== null) {
                $candidateKeys[] = 'charter.label_'.$field.'_'.$value;
            }

            $candidateKeys[] = 'charter.label_'.$value;

            foreach ($candidateKeys as $key) {
                if (\Illuminate\Support\Facades\Lang::has($key)) {
                    return (string) __($key);
                }
            }

            $tool = \App\Enums\SafetyTool::tryFrom($value);

            return $tool !== null ? $tool->label() : (string) \Illuminate\Support\Str::limit($value, 160);
        }

        return '—';
    };

    /** Type badge tone: list membership and additions read informational, removals/deletions warning-toned. */
    $badgeTone = static fn (string $type): string => match ($type) {
        'items_added', 'added' => 'bg-secondary-container text-on-secondary-container',
        'items_removed', 'removed' => 'bg-error/10 text-error',
        default => 'bg-primary/10 text-primary',
    };
@endphp

<div class="rounded-xl border border-outline/15 bg-surface-container-high/40 p-4 sm:p-5">
    <div class="flex items-center gap-2 mb-4">
        <span class="material-symbols-outlined text-on-surface-variant text-lg" aria-hidden="true">difference</span>
        <p class="font-heading font-semibold text-on-surface">{{ __('charter.heading_diff_changes') }}</p>
    </div>

    @if(count($changes) === 0)
        <p class="text-sm text-on-surface-variant">{{ __('charter.content_diff_empty') }}</p>
    @else
        <div class="space-y-4">
            @foreach($changes as $change)
                @php($previous = $loop->index > 0 ? $changes[$loop->index - 1] : null)
                @if($previous === null || $previous['section'] !== $change['section'])
                    <p class="text-xs font-semibold uppercase tracking-wide text-on-surface-variant pt-1">
                        {{ __('charter.section_'.$change['section'].'_title') }}
                    </p>
                @endif

                <div class="rounded-lg border border-outline/15 bg-surface-container-lowest px-4 py-3">
                    <div class="flex flex-wrap items-center gap-2 mb-1.5">
                        <p class="text-sm font-medium text-on-surface">
                            {{ __($fieldLabelKey($change['section'], $change['field'])) }}
                        </p>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $badgeTone($change['type']) }}">
                            {{ __('charter.label_diff_'.$change['type']) }}
                        </span>
                    </div>

                    @if($change['type'] === 'items_added' || $change['type'] === 'items_removed')
                        @php($items = $change['type'] === 'items_added' ? $change['new'] : $change['old'])
                        <div class="flex flex-wrap gap-1.5">
                            @foreach($items as $item)
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-surface-container-high text-on-surface border border-outline/15">
                                    {{ $displayValue($item, $change['field']) }}
                                </span>
                            @endforeach
                        </div>
                    @elseif($change['type'] === 'added')
                        <p class="text-sm">
                            <span class="text-xs font-medium text-on-surface-variant">{{ __('charter.label_diff_new') }}:</span>
                            <span class="text-on-surface">{{ $displayValue($change['new'], $change['field']) }}</span>
                        </p>
                    @elseif($change['type'] === 'removed')
                        <p class="text-sm">
                            <span class="text-xs font-medium text-on-surface-variant">{{ __('charter.label_diff_old') }}:</span>
                            <span class="text-on-surface">{{ $displayValue($change['old'], $change['field']) }}</span>
                        </p>
                    @else
                        <div class="space-y-1">
                            <p class="text-sm">
                                <span class="text-xs font-medium text-on-surface-variant">{{ __('charter.label_diff_old') }}:</span>
                                <span class="text-on-surface-variant line-through decoration-outline/50">{{ $displayValue($change['old'], $change['field']) }}</span>
                            </p>
                            <p class="text-sm">
                                <span class="text-xs font-medium text-on-surface-variant">{{ __('charter.label_diff_new') }}:</span>
                                <span class="text-on-surface font-medium">{{ $displayValue($change['new'], $change['field']) }}</span>
                            </p>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
