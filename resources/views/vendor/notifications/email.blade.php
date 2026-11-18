@php
// Partition outro lines: detect unsubscribe links (contain /notifications/unsubscribe)
$regularOutroLines = [];
$unsubscribeLine = null;

foreach ($outroLines as $line) {
    $isHtmlable = $line instanceof \Illuminate\Contracts\Support\Htmlable;
    $text = (string) $line;

    if ($isHtmlable && str_contains($text, '/notifications/unsubscribe')) {
        $unsubscribeLine = $line;
    } else {
        $regularOutroLines[] = $line;
    }
}
@endphp

<x-mail::message :preheader="$preheader ?? null">
{{-- Preheader: hidden inbox-preview text (T02 wires per-class copy through
    MailMessage viewData; absent viewData renders nothing). It is passed to
    the mail::message component, whose published override renders it as the
    first row of the header slot — BEFORE the brand header (M081 sixth-pass
    review: in the body slot, clients concatenated the brand name ahead of
    the crafted preview copy). --}}

{{-- Greeting --}}
@if (! empty($greeting))
# {{ $greeting }}
@else
@if ($level === 'error')
# @lang('Whoops!')
@else
# @lang('Hello!')
@endif
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines (excluding unsubscribe) --}}
@foreach ($regularOutroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
{{ $salutation }}
@else
{{ __('notifications.email_regards') }}<br>
{{ config('app.name') }}
@endif

{{-- Unsubscribe footer --}}
@if ($unsubscribeLine)
<div style="margin-top: 16px; padding-top: 12px; border-top: 1px solid #e8e2d4; text-align: center;">
{{ $unsubscribeLine }}
</div>
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
{{ __('notifications.email_trouble_clicking', ['actionText' => $actionText]) }} <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
