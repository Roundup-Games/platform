@php($section = $content['ai_tools'] ?? [])
@php($customClause = (string) ($section['custom_clause'] ?? ''))
@php($choiceFields = ['ai_prep', 'ai_transcription', 'dice', 'sheets', 'photography', 'recording'])
@php($hasChoices = collect($choiceFields)
    ->contains(fn (string $field): bool => ($section[$field] ?? '') !== ''))

@if($hasChoices || $customClause !== '')
<section id="charter-section-ai-tools" class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 sm:p-8 scroll-mt-24">
    <div class="flex items-center gap-2 mb-6">
        <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">smart_toy</span>
        <h3 class="font-heading text-lg font-semibold text-on-surface">{{ __('charter.section_ai_tools_title') }}</h3>
    </div>

    <div class="space-y-6">
        @if($hasChoices)
            <div class="grid sm:grid-cols-2 gap-x-8 gap-y-4">
                @foreach($choiceFields as $field)
                    @if(($section[$field] ?? '') !== '')
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_ai_tools_'.$field.'_label') }}</p>
                            <p class="mt-1 text-sm text-on-surface">{{ __('charter.label_'.$field.'_'.$section[$field]) }}</p>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif

        @if($customClause !== '')
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_ai_tools_custom_clause_label') }}</p>
                @include('livewire.charter.sections._prose', ['value' => $customClause])
            </div>
        @endif
    </div>
</section>
@endif
