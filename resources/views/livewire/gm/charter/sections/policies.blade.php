@php($section = $content['policies'] ?? [])

@php($choiceFields = \App\Dto\CharterContent::choicesForSection('policies', ['pvp', 'character_death', 'advancement', 'homebrew']))

<div class="space-y-6">
    {{-- Enumerated policy selects --}}
    <div class="grid sm:grid-cols-2 gap-5">
        @foreach($choiceFields as $field => $choices)
            <div>
                <label for="charter-policies-{{ $field }}" class="block text-sm font-medium text-on-surface mb-1">
                    {{ __('charter.field_policies_'.$field.'_label') }}
                </label>
                <select
                    id="charter-policies-{{ $field }}"
                    wire:model="content.policies.{{ $field }}"
                    class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors px-4 py-2.5"
                >
                    <option value="">{{ __('charter.placeholder_choose_option') }}</option>
                    @foreach($choices as $choice)
                        <option value="{{ $choice }}" @selected(($section[$field] ?? '') === $choice)>
                            {{ __('charter.label_'.$field.'_'.$choice) }}
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-on-surface-variant">{{ __('charter.field_policies_'.$field.'_help') }}</p>
                @error('content.policies.'.$field)
                    <p class="mt-1 text-xs text-error">{{ $message }}</p>
                @enderror
            </div>
        @endforeach
    </div>

    {{-- Loot policy --}}
    <div>
        <label for="charter-policies-loot" class="block text-sm font-medium text-on-surface mb-1">
            {{ __('charter.field_policies_loot_policy_label') }}
        </label>
        <textarea
            id="charter-policies-loot"
            wire:model="content.policies.loot_policy"
            rows="2"
            placeholder="{{ __('charter.field_policies_loot_policy_placeholder') }}"
            class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm placeholder:text-on-surface-variant focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors resize-y px-4 py-2.5"
        >{{ $section['loot_policy'] ?? '' }}</textarea>
        <p class="mt-1 text-xs text-on-surface-variant">{{ __('charter.field_policies_loot_policy_help') }}</p>
        @error('content.policies.loot_policy')
            <p class="mt-1 text-xs text-error">{{ $message }}</p>
        @enderror
    </div>

    {{-- Etiquette --}}
    <div>
        <p class="text-sm font-medium text-on-surface mb-1">{{ __('charter.field_policies_etiquette_label') }}</p>
        <p class="text-xs text-on-surface-variant mb-3">{{ __('charter.field_policies_etiquette_help') }}</p>
        <div class="grid grid-cols-2 gap-2">
            @foreach(\App\Dto\CharterContent::ETIQUETTE as $item)
                <label class="flex items-center gap-2 px-3 py-2 rounded-lg border transition-colors cursor-pointer {{ in_array($item, $section['etiquette'] ?? []) ? 'border-primary/40 bg-primary/5' : 'border-outline/20 bg-surface-container-high hover:border-outline/40' }}">
                    <input
                        type="checkbox"
                        value="{{ $item }}"
                        wire:model="content.policies.etiquette"
                        @checked(in_array($item, $section['etiquette'] ?? []))
                        class="rounded-sm border-outline/40 text-primary focus:ring-primary/20"
                    />
                    <span class="text-sm text-on-surface">{{ __('charter.label_etiquette_'.$item) }}</span>
                </label>
            @endforeach
        </div>
        @error('content.policies.etiquette')
            <p class="mt-1 text-xs text-error">{{ $message }}</p>
        @enderror
    </div>

    {{-- Etiquette in our own words --}}
    <div>
        <label for="charter-policies-etiquette-text" class="block text-sm font-medium text-on-surface mb-1">
            {{ __('charter.field_policies_etiquette_text_label') }}
        </label>
        <textarea
            id="charter-policies-etiquette-text"
            wire:model="content.policies.etiquette_text"
            rows="2"
            placeholder="{{ __('charter.field_policies_etiquette_text_placeholder') }}"
            class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm placeholder:text-on-surface-variant focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors resize-y px-4 py-2.5"
        >{{ $section['etiquette_text'] ?? '' }}</textarea>
        <p class="mt-1 text-xs text-on-surface-variant">{{ __('charter.field_policies_etiquette_text_help') }}</p>
        @error('content.policies.etiquette_text')
            <p class="mt-1 text-xs text-error">{{ $message }}</p>
        @enderror
    </div>
</div>
