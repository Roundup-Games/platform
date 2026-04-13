@component('mail::message')
# Membership Confirmed! ✅

Hey {{ $user->name }},

Your **{{ $planName }}** membership is now active. Welcome aboard!

## Membership Details

- **Plan:** {{ $planName }}
@if($amount)
- **Amount:** {{ $amount }}
@endif
@if($nextBillingDate)
- **Next billing date:** {{ $nextBillingDate }}
@endif

## What's Included

With your membership you get access to exclusive events, priority registration, and full platform features.

@component('mail::button', ['url' => config('app.url') . '/billing'])
Manage Your Membership
@endcomponent

If you have questions about your membership, just reply to this email.

Thanks for supporting Roundup Games! 🎲
Roundup Games
@endcomponent
