@props(['preheader' => null])
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
{{-- Preheader (M081 sixth-pass review): the hidden inbox-preview line rides
    as the FIRST row of the header slot — before the brand header — because
    mail::message renders the brand header before the body slot. In the body
    slot, every client concatenated the visible brand name ahead of the
    crafted preview copy (or previewed only the brand). A hidden tr>td keeps
    the table structure valid while placing the preview text ahead of ALL
    visible content. Callers opt in by passing a :preheader attribute (the
    notifications wrapper passes MailMessage viewData; the welcome mailable
    passes its own copy). The header slot is echoed raw by the layout (no
    markdown parse), so the markup survives verbatim. --}}
@if ($preheader)
<tr>
<td>
<div style="display: none; max-height: 0; overflow: hidden; mso-hide: all;">{{ $preheader }}</div>
</td>
</tr>
@endif
<x-mail::header :url="config('app.url')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}
</x-mail:footer>
</x-slot:footer>
</x-mail::layout>
