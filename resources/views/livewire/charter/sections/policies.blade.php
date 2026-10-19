@php($section = $content['policies'] ?? [])
@php($lootPolicy = (string) ($section['loot_policy'] ?? ''))
@php($etiquetteText = (string) ($section['etiquette_text'] ?? ''))
@php($hasPolicyChoices = collect(['pvp', 'character_death', 'advancement', 'homebrew'])
    ->contains(fn (string $field): bool => ($section[$field] ?? '') !== ''))

@if($hasPolicyChoices || $lootPolicy !== '' || ! empty($section['etiquette']) || $etiquetteText !== '')
<section id="charter-section-policies" class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 sm:p-8 scroll-mt-24">
    <div class="flex items-center gap-2 mb-6">
        <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">gavel</span>
        <h3 class="font-heading text-lg font-semibold text-on-surface">{{ __('charter.section_policies_title') }}</h3>
    </div>

    <div class="space-y-6">
        @if($hasPolicyChoices)
            <div class="grid sm:grid-cols-2 gap-x-8 gap-y-4">
                @foreach(['pvp', 'character_death', 'advancement', 'homebrew'] as $field)
                    @if(($section[$field] ?? '') !== '')
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_policies_'.$field.'_label') }}</p>
                            <p class="mt-1 text-sm text-on-surface">{{ __('charter.label_'.$field.'_'.$section[$field]) }}</p>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif

        @if($lootPolicy !== '')
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_policies_loot_policy_label') }}</p>
                @include('livewire.charter.sections._prose', ['value' => $lootPolicy])
            </div>
        @endif

        @if(! empty($section['etiquette']))
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_policies_etiquette_label') }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach($section['etiquette'] as $rule)
                        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-primary/10 text-primary text-xs font-medium">
                            <span class="material-symbols-outlined text-xs" aria-hidden="true">check_circle</span>
                            {{ __('charter.label_etiquette_'.$rule) }}
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        @if($etiquetteText !== '')
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_policies_etiquette_text_label') }}</p>
                @include('livewire.charter.sections._prose', ['value' => $etiquetteText])
            </div>
        @endif
    </div>
</section>
@endif
