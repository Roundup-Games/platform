@props([
    'hint' => null,
])

@php
    $isSessionMode = $mode === 'session';
    $isProfileMode = $mode === 'profile';
    $defaultHint = $isSessionMode
        ? __('location.hint_session_location')
        : __('location.hint_profile_location');
    $effectiveHint = $hint ?? $defaultHint;
@endphp

<div>
    @if($effectiveHint)
        <p class="text-xs text-on-surface-variant/70 mb-3">{{ $effectiveHint }}</p>
    @endif

    @if($editing && !$locationConfirmed)
        <div class="space-y-4">
            {{-- City field (always required) --}}
            <div>
                <label for="lp-city" class="block text-sm font-medium text-on-surface mb-1">
                    {{ __('location.field_city') }} <span class="text-error">*</span>
                </label>
                <input type="text" id="lp-city" wire:model="city" placeholder="{{ __('location.field_enter_your_city') }}"
                       class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant" />
                @error('city') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
            </div>

            @if($isProfileMode)
                {{-- Profile mode: neighborhood field, optional and casual --}}
                <div>
                    <label for="lp-neighborhood" class="block text-sm font-medium text-on-surface mb-1">
                        {{ __('location.field_neighborhood') }} <span class="text-on-surface-variant text-xs font-normal">{{ __('common.content_optional') }}</span>
                    </label>
                    <input type="text" id="lp-neighborhood" wire:model="address" placeholder="{{ __('location.placeholder_neighborhood_optional') }}"
                           class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant" />
                    <p class="mt-1 text-xs text-on-surface-variant">{{ __('location.hint_neighborhood_profile') }}</p>
                </div>
            @else
                {{-- Session mode: precise address field (optional) --}}
                <div>
                    <label for="lp-address" class="block text-sm font-medium text-on-surface mb-1">
                        {{ __('location.field_address_optional') }}
                    </label>
                    <input type="text" id="lp-address" wire:model="address" placeholder="{{ __('location.placeholder_street_address_neighborhood') }}"
                           class="w-full rounded-md bg-surface-container-high border border-transparent shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface placeholder:text-on-surface-variant" />
                    <p class="mt-1 text-xs text-on-surface-variant">{{ __('location.hint_address_private') }}</p>
                </div>
            @endif

            <button wire:click="findMyLocation"
                    type="button"
                    class="w-full px-4 py-2.5 bg-primary text-on-primary rounded-xl shadow-md hover:brightness-110 active:scale-95 transition-all duration-150 text-sm font-medium font-heading tracking-tight">
                <span wire:loading.remove wire:target="findMyLocation">{{ __('location.action_find_my_location') }}</span>
                <span wire:loading wire:target="findMyLocation">{{ __('common.content_searching') }}</span>
            </button>

            <button type="button" wire:click="cancelEditing"
                    class="text-sm text-on-surface-variant hover:text-primary transition-colors">
                {{ __('common.action_cancel') }}
            </button>
        </div>

    @elseif($locationSource === 'localStorage' && $city && !$locationConfirmed)
        {{-- Detected from browser, awaiting confirmation --}}
        <div class="space-y-4">
            <div class="flex items-center gap-3 p-4 rounded-xl bg-primary-container/20 border border-primary/20">
                <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">location_on</span>
                <div>
                    <p class="text-sm font-medium text-on-surface">
                        {{ __('location.content_we_think_you_re_in_city_is_that_right', ['city' => $city]) }}
                    </p>
                    @if($address && $isSessionMode)
                        <p class="text-xs text-on-surface-variant mt-0.5">{{ $address }}</p>
                    @endif
                </div>
            </div>

            <div class="flex gap-3">
                <button wire:click="confirmLocation"
                        type="button"
                        class="flex-1 px-4 py-2.5 bg-primary text-on-primary rounded-xl shadow-md hover:brightness-110 active:scale-95 transition-all duration-150 text-sm font-medium font-heading tracking-tight">
                    {{ __('common.content_yes_that_s_right') }}
                </button>
                <button wire:click="startEditing"
                        type="button"
                        class="px-4 py-2.5 border border-outline-variant/30 text-on-surface-variant rounded-xl hover:bg-surface-container-low transition-colors text-sm font-medium">
                    {{ __('common.action_no_let_me_search') }}
                </button>
            </div>
        </div>

    @elseif($locationConfirmed && $city)
        {{-- Location confirmed --}}
        <div class="flex items-center justify-between p-3 bg-surface-container-low rounded-lg">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-lg text-primary" style="font-variation-settings: 'FILL' 1">check_circle</span>
                <div>
                    <span class="text-sm text-on-surface">{{ $city }}</span>
                    @if($address)
                        <p class="text-xs text-on-surface-variant">{{ $address }}</p>
                    @endif
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" wire:click="startEditing"
                        class="text-xs text-on-surface-variant hover:text-primary transition-colors">
                    {{ __('common.action_edit') }}
                </button>
                <button type="button" wire:click="removeLocation"
                        class="text-xs text-error hover:brightness-110 transition-colors">
                    {{ __('common.action_remove') }}
                </button>
            </div>
        </div>

    @else
        {{-- No location set --}}
        <div class="flex items-center gap-2">
            <span class="text-xs text-on-surface-variant/60">{{ __('location.content_location_not_set') }}</span>
        </div>
        <button type="button" wire:click="startEditing"
                class="flex items-center gap-2 px-4 py-2 bg-surface-container-high text-on-surface-variant rounded-md text-sm font-medium hover:bg-surface-container transition-colors">
            <span class="material-symbols-outlined text-base">add_location</span>
            {{ $isProfileMode ? __('location.action_set_your_location') : __('location.action_add_location') }}
        </button>
    @endif
</div>
