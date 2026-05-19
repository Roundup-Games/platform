@props([
    'action',              // wire:click action string, e.g. "cancelInvite('123')"
    'id',                  // unique identifier for this confirm instance, e.g. "cancel-invite-123"
    'confirmLabel' => '',  // label for the confirm button
    'cancelLabel' => '',   // label for the cancel/dismiss button
    'message' => '',       // confirmation message (empty for compact variant)
    'variant' => 'inline', // inline | standalone | compact
    'severity' => 'destructive', // destructive | caution | neutral
    'icon' => '',          // material icon name for trigger button
    'triggerLabel' => '',  // text for trigger button
    'triggerClass' => '',  // classes for the trigger button
    'confirmIcon' => '',   // icon for confirm button (optional override)
    'disabled' => false,
])

@php
// The parent Livewire component must have a public ?string $confirmingAction property.
// Only one confirmation can be active at a time across the component.

// Severity-based classes for confirm button
$confirmBtnClass = match($severity) {
    'destructive' => 'bg-error-container text-on-error-container hover:opacity-90',
    'caution' => 'bg-tertiary-container text-on-tertiary-container hover:opacity-90',
    'neutral' => 'bg-surface-container-high text-on-surface hover:bg-surface-container-highest',
    default => 'bg-error-container text-on-error-container hover:opacity-90',
};

// Confirm icon defaults
$effectiveConfirmIcon = $confirmIcon ?: match($severity) {
    'destructive' => 'delete',
    'caution' => 'warning',
    default => 'check',
};
@endphp

<div x-data="{
        confirmingId: @entangle('confirmingAction'),
        get isConfirming() { return this.confirmingId === '{{ $id }}' }
    }"
     @keydown.escape.window="if (isConfirming) { confirmingId = null }"
     class="inline-flex items-center gap-2">

    {{-- Trigger button --}}
    <button @if($disabled) disabled @else
            @click="confirmingId = '{{ $id }}'"
            x-show="!isConfirming"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            @endif
            {{ $attributes->merge(['class' => $triggerClass]) }}>
        @if($icon)
            <span class="material-symbols-outlined text-sm" aria-hidden="true">{{ $icon }}</span>
        @endif
        @if($triggerLabel)
            {{ $triggerLabel }}
        @endif
    </button>

    {{-- Confirmation UI --}}
    <div x-show="isConfirming"
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-100"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0 scale-95"
         role="alert"
         aria-live="polite">

        @if($variant === 'inline')
            {{-- Inline: message + two buttons in a row --}}
            <div class="flex items-center gap-2 text-sm">
                @if($message)
                    <span class="text-on-surface-variant text-xs">{{ $message }}</span>
                @endif
                <button wire:click="{{ $action }}"
                        x-init="$nextTick(() => { if (isConfirming) $el.focus() })"
                        class="inline-flex items-center gap-1 px-2.5 py-1 {{ $confirmBtnClass }} text-xs font-medium rounded-lg transition-opacity">
                    @if($effectiveConfirmIcon)
                        <span class="material-symbols-outlined text-xs" aria-hidden="true">{{ $effectiveConfirmIcon }}</span>
                    @endif
                    {{ $confirmLabel ?: __('common.action_confirm') }}
                </button>
                <button @click="confirmingId = null"
                        class="inline-flex items-center gap-1 px-2.5 py-1 bg-surface-container-high text-on-surface-variant text-xs font-medium rounded-lg hover:bg-surface-container-highest transition-colors">
                    {{ $cancelLabel ?: __('common.action_keep') }}
                </button>
            </div>

        @elseif($variant === 'standalone')
            {{-- Standalone: expands below the trigger --}}
            <div class="mt-2 p-3 rounded-lg bg-surface-container ring-1 ring-outline-variant/20">
                @if($message)
                    <p class="text-sm text-on-surface mb-3">{{ $message }}</p>
                @endif
                <div class="flex items-center gap-2">
                    <button wire:click="{{ $action }}"
                            x-init="$nextTick(() => { if (isConfirming) $el.focus() })"
                            class="inline-flex items-center gap-1.5 px-4 py-2 {{ $confirmBtnClass }} text-sm font-medium rounded-lg transition-opacity">
                        @if($effectiveConfirmIcon)
                            <span class="material-symbols-outlined text-sm" aria-hidden="true">{{ $effectiveConfirmIcon }}</span>
                        @endif
                        {{ $confirmLabel ?: __('common.action_confirm') }}
                    </button>
                    <button @click="confirmingId = null"
                            class="px-4 py-2 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
                        {{ $cancelLabel ?: __('common.action_cancel') }}
                    </button>
                </div>
            </div>

        @elseif($variant === 'compact')
            {{-- Compact: just confirm/dismiss buttons, no message --}}
            <div class="flex items-center gap-1.5">
                <button wire:click="{{ $action }}"
                        x-init="$nextTick(() => { if (isConfirming) $el.focus() })"
                        class="inline-flex items-center gap-1 px-2 py-1 {{ $confirmBtnClass }} text-xs font-medium rounded transition-opacity">
                    {{ $confirmLabel ?: __('common.action_yes') }}
                </button>
                <button @click="confirmingId = null"
                        class="inline-flex items-center gap-1 px-2 py-1 text-on-surface-variant text-xs hover:text-on-surface transition-colors">
                    {{ $cancelLabel ?: __('common.action_no') }}
                </button>
            </div>
        @endif
    </div>
</div>
