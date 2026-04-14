@component('mail::message')
# {{ __('Event Registration Confirmed!') }} 🏆

{{ __('Hey :name,', ['name' => $registration->user->name]) }}

{{ __('You\'re all set for **:event**!', ['event' => $registration->event->name]) }}

## {{ __('Event Details') }}

- **{{ __('Event') }}:** {{ $registration->event->name }}
- **{{ __('Date') }}:** {{ format_date($registration->event->start_date) }}
@if($registration->event->end_date && $registration->event->start_date->ne($registration->event->end_date))
  — {{ format_date($registration->event->end_date) }}
@endif
@if($registration->event->venue_name)
- **{{ __('Venue') }}:** {{ $registration->event->venue_name }}
@endif
@if($registration->division)
- **{{ __('Division') }}:** {{ $registration->division }}
@endif
@if($registration->registration_type)
- **{{ __('Registration type') }}:** {{ ucfirst($registration->registration_type) }}
@endif

@if($registration->event->contact_email)
**{{ __('Questions?') }}** {{ __('Contact the organizer at :email.', ['email' => $registration->event->contact_email]) }}
@endif

@component('mail::button', ['url' => config('app.url') . '/' . app()->getLocale() . '/events/' . $registration->event->slug])
{{ __('View Event Details') }}
@endcomponent

{{ __('See you there!') }} 🎯
Roundup Games
@endcomponent
