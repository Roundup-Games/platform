@php
    /** @var array<string, mixed> $context */

    $contextLabels = [
        'entity_owner' => __('Entity owner'),
        'has_subscription' => __('Has subscription'),
        'subscription_status' => __('Subscription status'),
        'paddle_subscription_id' => __('Subscription ID'),
        'paddle_customer_id' => __('Customer ID'),
        'plan' => __('Plan'),
    ];
@endphp
<div class="mb-2 text-sm">
    <span class="font-medium text-on-surface-variant">{{ __('Context') }}:</span>
    <dl class="mt-1 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
        @foreach ($context as $key => $value)
            @php
                if (is_array($value)) {
                    $value = json_encode($value);
                }
                $label = $contextLabels[$key] ?? ucfirst(str_replace('_', ' ', $key));
                $displayValue = is_bool($value) ? ($value ? __('Yes') : __('No')) : $value;
            @endphp
            <dt class="text-on-surface-variant">{{ $label }}</dt>
            <dd class="text-on-surface">{{ $displayValue }}</dd>
        @endforeach
    </dl>
</div>
