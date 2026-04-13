@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-medium text-sm text-on-surface-variant']) }}>
    {{ $value ?? $slot }}
</label>
