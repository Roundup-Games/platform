@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'font-medium text-sm text-secondary']) }}>
        {{ $status }}
    </div>
@endif
