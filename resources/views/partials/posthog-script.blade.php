{{-- PostHog JS script inclusion. Depends on $posthogEnabled set by posthog-meta partial.
     Uses the same flag so meta tags and script stay in sync. --}}
@if($posthogEnabled ?? false)
    @vite('resources/js/posthog.js')
@endif
