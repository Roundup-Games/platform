@props([
    'fields',          // array[] — list of field definitions, each with: name, label, type?, rows?, maxlength?, placeholder?
    'activeLocale',    // string  — currently active locale
    'baselineLocale',  // string  — baseline locale code
    'allLocales',      // string[] — all locales for the switcher
    'inputClass',      // string  — CSS class for inputs/textareas
    'required',        // array   — optional list of field names that are required (baseline only)
])

<div class="space-y-4">
    <x-forms.locale-switcher :locales="$allLocales" :baseline-locale="$baselineLocale" :active-locale="$activeLocale" />

    @php
        $isBaseline = $activeLocale === $baselineLocale || !$activeLocale;
        $requiredFields = $required ?? [];
    @endphp

    <div class="space-y-4">
        @foreach($fields as $field)
            <div>
                <div class="flex items-center justify-between">
                    <label for="{{ $field['name'] }}-{{ $activeLocale ?: $baselineLocale }}" class="block text-sm font-medium text-on-surface-variant mb-1">
                        {{ $field['label'] }}
                        @if($isBaseline && in_array($field['name'], $requiredFields))
                            <span class="text-error">*</span>
                        @endif
                    </label>
                    @if(!$isBaseline)
                        <x-forms.copy-from-baseline :field="$field['name']" :baseline-locale="$baselineLocale" :active-locale="$activeLocale" />
                    @endif
                </div>

                @if(($field['type'] ?? 'input') === 'textarea')
                    <textarea id="{{ $field['name'] }}-{{ $activeLocale ?: $baselineLocale }}"
                              @if($isBaseline)
                                  wire:model="{{ $field['name'] }}"
                              @else
                                  wire:model="pendingTranslations.{{ $activeLocale }}.{{ $field['name'] }}"
                              @endif
                              rows="{{ $field['rows'] ?? 3 }}"
                              @if(isset($field['maxlength'])) maxlength="{{ $field['maxlength'] }}" @endif
                              @if(isset($field['placeholder'])) placeholder="{{ $field['placeholder'] }}" @endif
                              class="{{ $inputClass }}"></textarea>
                @else
                    <input type="text"
                           id="{{ $field['name'] }}-{{ $activeLocale ?: $baselineLocale }}"
                           @if($isBaseline)
                               wire:model="{{ $field['name'] }}"
                           @else
                               wire:model="pendingTranslations.{{ $activeLocale }}.{{ $field['name'] }}"
                           @endif
                           @if(isset($field['maxlength'])) maxlength="{{ $field['maxlength'] }}" @endif
                           @if(isset($field['placeholder'])) placeholder="{{ $field['placeholder'] }}" @endif
                           class="{{ $inputClass }}" />
                @endif

                @if($isBaseline)
                    @error($field['name'])
                        <p class="mt-1 text-sm text-error">{{ $message }}</p>
                    @enderror
                @else
                    @error("pendingTranslations.{$activeLocale}.{$field['name']}")
                        <p class="mt-1 text-sm text-error">{{ $message }}</p>
                    @enderror
                @endif
            </div>
        @endforeach
    </div>
</div>
