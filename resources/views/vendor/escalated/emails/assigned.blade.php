@component('mail::message')
{{-- Roundup override of escalated::emails/assigned (M081/S03/T02).
    Goes to the agent the ticket was assigned to. Same brand treatment as
    new-ticket: S01 theme handles chrome, copy lives here, vendor logo /
    hex accent / settings footer dropped. EN only (K002 exemption). --}}
# A ticket was assigned to you

A support ticket has landed in your agent queue and needs your attention.

**Reference:** {{ $ticket->reference }}
**Subject:** {{ $ticket->subject }}
**Priority:** {{ $ticket->priority->label() }}
**Status:** {{ $ticket->status->label() }}

@component('mail::button', ['url' => $url])
Open Ticket
@endcomponent

Please review it and respond at your earliest convenience.

Thanks,<br>
{{ config('app.name') }} Support
@endcomponent
