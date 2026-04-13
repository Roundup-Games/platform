@component('mail::message')
# Welcome to Roundup Games! 🎲

Hey {{ $user->name }},

Thanks for joining **Roundup Games** — your hub for organizing and participating in tabletop gaming events, campaigns, and tournaments.

## Get Started

Here's what you can do next:

- **Complete your profile** — Add your preferred game systems, pronouns, and a profile picture.
- **Find or create a team** — Team up with other players for events and campaigns.
- **Browse events** — Discover upcoming tournaments and game sessions near you.
- **Start a campaign** — Organize a recurring game with your group.

@component('mail::button', ['url' => config('app.url') . '/dashboard'])
Go to Your Dashboard
@endcomponent

If you have any questions, just reply to this email — we're happy to help.

Happy gaming! 🎯
Roundup Games
@endcomponent
