@props([
    'fields',          // array[] — list of field definitions, each with: name, label, type?, rows?, maxlength?
    'activeLocale',    // string  — currently active locale
    'baselineLocale',  // string  — baseline locale code
    'allLocales',      // string[] — all locales for the switcher
    'inputClass',      // string  — CSS class for inputs/textareas
])

<div class="border-t border-outline-variant/30 pt-4 mt-2">
    <x-forms.locale-switcher :locales="$allLocales" :baseline-locale="$baselineLocale" :active-locale="$activeLocale" />

    @if($activeLocale !== $baselineLocale)
        <div class="space-y-4">
            @foreach($fields as $field)
                <div>
                    <div class="flex items-center justify-between">
                        <label for="{{ $field['name'] }}-{{ $activeLocale }}" class="block text-sm font-medium text-on-surface-variant mb-1">{{ $field['label'] }}</label>
                        <x-forms.copy-from-baseline :field="$field['name']" :baseline-locale="$baselineLocale" :active-locale="$activeLocale" />
                    </div>

                    @if(($field['type'] ?? 'input') === 'textarea')
                        <textarea id="{{ $field['name'] }}-{{ $activeLocale }}"
                                  wire:model="pendingTranslations.{{ $activeLocale }}.{{ $field['name'] }}"
                                  rows="{{ $field['rows'] ?? 3 }}"
                                  @if(isset($field['maxlength'])) maxlength="{{ $field['maxlength'] }}" @endif
                                  class="{{ $inputClass }}"></textarea>
                    @else
                        <input type="text"
                               id="{{ $field['name'] }}-{{ $activeLocale }}"
                               wire:model="pendingTranslations.{{ $activeLocale }}.{{ $field['name'] }}"
                               @if(isset($field['maxlength'])) maxlength="{{ $field['maxlength'] }}" @endif
                               class="{{ $inputClass }}" />
                    @endif

                    @error("pendingTranslations.{$activeLocale}.{$field['name']}")
                        <p class="mt-1 text-sm text-error">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach
        </div>
    @endif
</div>
