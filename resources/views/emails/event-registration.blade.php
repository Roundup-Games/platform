@component('mail::message')
# Event Registration Confirmed! 🏆

Hey {{ $registration->user->name }},

You're all set for **{{ $registration->event->name }}**!

## Event Details

- **Event:** {{ $registration->event->name }}
- **Date:** {{ $registration->event->start_date->format('F j, Y') }}
@if($registration->event->end_date && $registration->event->start_date->ne($registration->event->end_date))
  — {{ $registration->event->end_date->format('F j, Y') }}
@endif
@if($registration->event->venue_name)
- **Venue:** {{ $registration->event->venue_name }}
@endif
@if($registration->division)
- **Division:** {{ $registration->division }}
@endif
@if($registration->registration_type)
- **Registration type:** {{ ucfirst($registration->registration_type) }}
@endif

@if($registration->event->contact_email)
**Questions?** Contact the organizer at {{ $registration->event->contact_email }}.
@endif

@component('mail::button', ['url' => config('app.url') . '/events/' . $registration->event->slug])
View Event Details
@endcomponent

See you there! 🎯
Roundup Games
@endcomponent
