{{-- PostHog Analytics meta tags and script inclusion.
     Shared between app and guest layouts to avoid duplicating
     config checks and exclusion logic.
     Excludes: admin routes, Livewire internal requests.
     API key is public-safe (analogous to Stripe publishable key). --}}

@php
    $posthogEnabled = config('posthog.enabled', true)
        && config('posthog.api_key')
        && !request()->is('admin/*')
        && !request()->is('livewire/update*');
@endphp

@if($posthogEnabled)
    <meta name="posthog-api-key" content="{{ config('posthog.api_key') }}">
    <meta name="posthog-api-host" content="{{ config('posthog.host', 'https://eu.i.posthog.com') }}">
    @if(config('posthog.session_replay.enabled', true))
    <meta name="posthog-replay-sample-rate" content="{{ config('posthog.session_replay.sample_rate', 0.5) }}">
    @endif
    @if(config('posthog.surveys.enabled', true))
    <meta name="posthog-surveys-enabled" content="true">
    @endif
@endif
