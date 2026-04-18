@component('mail::message')
# {{ __('emails.content_membership_confirmed') }} ✅

{{ __('common.field_hey_name', ['name' => $user->name]) }}

{{ __('emails.content_your_plan_membership_is_now_active_welcome_aboard', ['plan' => $planName]) }}

## {{ __('billing.content_membership_details') }}

- **{{ __('billing.content_plan') }}:** {{ $planName }}
@if($amount)
- **{{ __('billing.field_amount') }}:** {{ $amount }}
@endif
@if($nextBillingDate)
- **{{ __('billing.field_next_billing_date') }}:** {{ $nextBillingDate }}
@endif

## {{ __("pages.content_what_s_included") }}

{{ __('billing.content_with_your_membership_you_get') }}

@component('mail::button', ['url' => config('app.url') . '/' . app()->getLocale() . '/billing'])
{{ __('billing.action_manage_your_membership') }}
@endcomponent

{{ __('emails.content_if_you_have_questions_about') }}

{{ __('events.content_thanks_for_supporting_roundup_games') }} 🎲
Roundup Games
@endcomponent
