@component('mail::message')
# {{ __('Membership Confirmed!') }} ✅

{{ __('Hey :name,', ['name' => $user->name]) }}

{{ __('Your **:plan** membership is now active. Welcome aboard!', ['plan' => $planName]) }}

## {{ __('Membership Details') }}

- **{{ __('Plan') }}:** {{ $planName }}
@if($amount)
- **{{ __('Amount') }}:** {{ $amount }}
@endif
@if($nextBillingDate)
- **{{ __('Next billing date') }}:** {{ $nextBillingDate }}
@endif

## {{ __("What's Included") }}

{{ __('With your membership you get access to exclusive events, priority registration, and full platform features.') }}

@component('mail::button', ['url' => config('app.url') . '/' . app()->getLocale() . '/billing'])
{{ __('Manage Your Membership') }}
@endcomponent

{{ __('If you have questions about your membership, just reply to this email.') }}

{{ __('Thanks for supporting Roundup Games!') }} 🎲
Roundup Games
@endcomponent
