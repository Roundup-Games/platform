@component('mail::message')
# {{ __('Welcome to Roundup Games!') }} 🎲

{{ __('Hey :name,', ['name' => $user->name]) }}

{{ __('Thanks for joining **Roundup Games** — your hub for organizing and participating in tabletop gaming events, campaigns, and tournaments.') }}

## {{ __('Get Started') }}

{{ __("Here's what you can do next:") }}

- {{ __('**Complete your profile** — Add your preferred game systems, pronouns, and a profile picture.') }}
- {{ __('**Find or create a team** — Team up with other players for events and campaigns.') }}
- {{ __('**Browse events** — Discover upcoming tournaments and game sessions near you.') }}
- {{ __('**Start a campaign** — Organize a recurring game with your group.') }}

@component('mail::button', ['url' => config('app.url') . '/' . app()->getLocale() . '/dashboard'])
{{ __('Go to Your Dashboard') }}
@endcomponent

{{ __("If you have any questions, just reply to this email — we're happy to help.") }}

{{ __('Happy gaming!') }} 🎯
Roundup Games
@endcomponent
