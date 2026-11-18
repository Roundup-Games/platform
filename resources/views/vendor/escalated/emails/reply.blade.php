@component('mail::message')
{{-- Roundup override of escalated::emails/reply (M081/S03/T02, localized
    M081 follow-up).
    Customer-side replies mail the ticket requester (the brand surface
    this override exists for); agent-side replies mail the assigned
    agent. Same brand treatment as new-ticket: S01 theme handles chrome,
    copy lives here, vendor logo / hex accent / settings footer dropped.
    Copy is keyed and renders in the recipient's preferred language. --}}
@php($mailLocale = $ticket->requester?->preferred_language?->value ?? 'en')
# {{ __('escalation_mails.heading_ticket_reply', ['reference' => $ticket->reference], $mailLocale) }}

{{ __('escalation_mails.body_ticket_reply_intro', [], $mailLocale) }}

**{{ __('escalation_mails.field_subject', [], $mailLocale) }}:** {{ $ticket->subject }}

---

{{ $reply->body }}

---

@component('mail::button', ['url' => $url])
{{ __('escalation_mails.action_view_ticket', [], $mailLocale) }}
@endcomponent

{{ __('escalation_mails.content_signoff', [], $mailLocale) }},<br>
{{ config('app.name') }} Support
@endcomponent
