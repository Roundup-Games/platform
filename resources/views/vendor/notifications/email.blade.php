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

<x-mail::message>
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
@lang('Regards,')<br>
{{ config('app.name') }}
@endif

{{-- Unsubscribe footer --}}
@if ($unsubscribeLine)
<div style="margin-top: 16px; padding-top: 12px; border-top: 1px solid #e4e4e7; text-align: center;">
{{ $unsubscribeLine }}
</div>
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
@lang(
    "If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\n".
    'into your web browser:',
    [
        'actionText' => $actionText,
    ]
) <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})]</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
