@php($section = $content['ai_tools'] ?? [])

@php($choiceFields = \App\Dto\CharterContent::choicesForSection('ai_tools', ['ai_prep', 'ai_transcription', 'dice', 'sheets', 'photography', 'recording']))

<div class="space-y-6">
    <div class="grid sm:grid-cols-2 gap-5">
        @foreach($choiceFields as $field => $choices)
            <div>
                <label for="charter-ai-tools-{{ $field }}" class="block text-sm font-medium text-on-surface mb-1">
                    {{ __('charter.field_ai_tools_'.$field.'_label') }}
                </label>
                <select
                    id="charter-ai-tools-{{ $field }}"
                    wire:model="content.ai_tools.{{ $field }}"
                    class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors px-4 py-2.5"
                >
                    <option value="">{{ __('charter.placeholder_choose_option') }}</option>
                    @foreach($choices as $choice)
                        <option value="{{ $choice }}" @selected(($section[$field] ?? '') === $choice)>
                            {{ __('charter.label_'.$field.'_'.$choice) }}
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-on-surface-variant">{{ __('charter.field_ai_tools_'.$field.'_help') }}</p>
                @error('content.ai_tools.'.$field)
                    <p class="mt-1 text-xs text-error">{{ $message }}</p>
                @enderror
            </div>
        @endforeach
    </div>

    {{-- Custom clause --}}
    <div>
        <label for="charter-ai-tools-custom-clause" class="block text-sm font-medium text-on-surface mb-1">
            {{ __('charter.field_ai_tools_custom_clause_label') }}
        </label>
        <textarea
            id="charter-ai-tools-custom-clause"
            wire:model="content.ai_tools.custom_clause"
            rows="2"
            placeholder="{{ __('charter.field_ai_tools_custom_clause_placeholder') }}"
            class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm placeholder:text-on-surface-variant focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors resize-y px-4 py-2.5"
        >{{ $section['custom_clause'] ?? '' }}</textarea>
        <p class="mt-1 text-xs text-on-surface-variant">{{ __('charter.field_ai_tools_custom_clause_help') }}</p>
        @error('content.ai_tools.custom_clause')
            <p class="mt-1 text-xs text-error">{{ $message }}</p>
        @enderror
    </div>
</div>
