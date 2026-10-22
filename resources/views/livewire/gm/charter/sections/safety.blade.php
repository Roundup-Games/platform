@php($section = $content['safety'] ?? [])

<div class="space-y-6">
    {{-- Tools adopted --}}
    <div>
        <p class="text-sm font-medium text-on-surface mb-1">{{ __('charter.field_safety_tools_label') }}</p>
        <p class="text-xs text-on-surface-variant mb-3">{{ __('charter.field_safety_tools_help') }}</p>
        <div class="space-y-2">
            @foreach(\App\Enums\SafetyTool::cases() as $tool)
                @php($value = $tool->value)
                <label class="flex items-start gap-3 px-3 py-2.5 rounded-lg border transition-colors cursor-pointer {{ in_array($value, $section['tools'] ?? []) ? 'border-primary/40 bg-primary/5' : 'border-outline/20 bg-surface-container-high hover:border-outline/40' }}">
                    <input
                        type="checkbox"
                        value="{{ $value }}"
                        wire:model="content.safety.tools"
                        @checked(in_array($value, $section['tools'] ?? []))
                        class="mt-0.5 rounded-sm border-outline/40 text-primary focus:ring-primary/20"
                    />
                    <span>
                        <span class="text-sm font-medium text-on-surface block">{{ $tool->label() }}</span>
                        <span class="text-xs text-on-surface-variant">{{ $tool->shortDescription() }}</span>
                    </span>
                </label>
            @endforeach
        </div>
        @error('content.safety.tools')
            <p class="mt-1 text-xs text-error">{{ $message }}</p>
        @enderror
    </div>

    {{-- Custom tool note --}}
    <div>
        <label for="charter-safety-custom-note" class="block text-sm font-medium text-on-surface mb-1">
            {{ __('charter.field_safety_custom_note_label') }}
        </label>
        <textarea
            id="charter-safety-custom-note"
            wire:model="content.safety.custom_note"
            rows="3"
            placeholder="{{ __('charter.field_safety_custom_note_placeholder') }}"
            class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm placeholder:text-on-surface-variant focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors resize-y px-4 py-2.5"
        >{{ $section['custom_note'] ?? '' }}</textarea>
        <p class="mt-1 text-xs text-on-surface-variant">{{ __('charter.field_safety_custom_note_help') }}</p>
        @error('content.safety.custom_note')
            <p class="mt-1 text-xs text-error">{{ $message }}</p>
        @enderror
    </div>

    {{-- How to signal --}}
    <div>
        <label for="charter-safety-signal-how" class="block text-sm font-medium text-on-surface mb-1">
            {{ __('charter.field_safety_signal_how_label') }}
        </label>
        <textarea
            id="charter-safety-signal-how"
            wire:model="content.safety.signal_how"
            rows="2"
            placeholder="{{ __('charter.field_safety_signal_how_placeholder') }}"
            class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm placeholder:text-on-surface-variant focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors resize-y px-4 py-2.5"
        >{{ $section['signal_how'] ?? '' }}</textarea>
        <p class="mt-1 text-xs text-on-surface-variant">{{ __('charter.field_safety_signal_how_help') }}</p>
        @error('content.safety.signal_how')
            <p class="mt-1 text-xs text-error">{{ $message }}</p>
        @enderror
    </div>

    {{-- Revisit cadence --}}
    <div>
        <p class="text-sm font-medium text-on-surface mb-1">{{ __('charter.field_safety_revisit_label') }}</p>
        <p class="text-xs text-on-surface-variant mb-3">{{ __('charter.field_safety_revisit_help') }}</p>
        <div class="grid sm:grid-cols-3 gap-2">
            @foreach(\App\Dto\CharterContent::choicesFor('safety.revisit') as $revisit)
                <label class="flex items-center gap-2 px-3 py-2.5 rounded-lg border transition-colors cursor-pointer {{ ($section['revisit'] ?? '') === $revisit ? 'border-primary/40 bg-primary/5' : 'border-outline/20 bg-surface-container-high hover:border-outline/40' }}">
                    <input
                        type="radio"
                        name="charter-safety-revisit"
                        value="{{ $revisit }}"
                        wire:model="content.safety.revisit"
                        @checked(($section['revisit'] ?? '') === $revisit)
                        class="rounded-full border-outline/40 text-primary focus:ring-primary/20"
                    />
                    <span class="text-sm text-on-surface">{{ __('charter.label_revisit_'.$revisit) }}</span>
                </label>
            @endforeach
        </div>
        @error('content.safety.revisit')
            <p class="mt-1 text-xs text-error">{{ $message }}</p>
        @enderror
    </div>

    {{-- Open door --}}
    <div>
        <label class="flex items-start gap-3 px-3 py-2.5 rounded-lg border transition-colors cursor-pointer {{ ! empty($section['open_door']) ? 'border-primary/40 bg-primary/5' : 'border-outline/20 bg-surface-container-high' }}">
            <input
                type="checkbox"
                wire:model="content.safety.open_door"
                @checked(! empty($section['open_door']))
                class="mt-0.5 rounded-sm border-outline/40 text-primary focus:ring-primary/20"
            />
            <span>
                <span class="text-sm font-medium text-on-surface block">{{ __('charter.field_safety_open_door_label') }}</span>
                <span class="text-xs text-on-surface-variant">{{ __('charter.field_safety_open_door_help') }}</span>
            </span>
        </label>
    </div>
</div>
