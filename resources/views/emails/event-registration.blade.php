@component('mail::message')
# {{ __('emails.content_event_registration_confirmed') }} 🏆

{{ __('common.field_hey_name', ['name' => $registration->user->name]) }}

{{ __('events.content_you_re_all_set_for_event', ['event' => $registration->event->name]) }}

## {{ __('events.content_event_details') }}

- **{{ __('events.content_event') }}:** {{ $registration->event->name }}
- **{{ __('common.field_date') }}:** {{ format_date($registration->event->start_date) }}
@if($registration->event->end_date && $registration->event->start_date->ne($registration->event->end_date))
  — {{ format_date($registration->event->end_date) }}
@endif
@if($registration->event->venue_name)
- **{{ __('location.content_venue') }}:** {{ $registration->event->venue_name }}
@endif
@if($registration->division)
- **{{ __('events.content_division') }}:** {{ $registration->division }}
@endif
@if($registration->registration_type)
- **{{ __('events.content_registration_type_3') }}:** {{ ucfirst($registration->registration_type) }}
@endif

@if($registration->event->contact_email)
**{{ __('common.content_questions') }}** {{ __('emails.content_contact_the_organizer_at_email', ['email' => $registration->event->contact_email]) }}
@endif

@component('mail::button', ['url' => config('app.url') . '/' . app()->getLocale() . '/events/' . $registration->event->slug])
{{ __('events.action_view_event_details') }}
@endcomponent

{{ __('common.content_see_you_there') }} 🎯
Roundup Games
@endcomponent
