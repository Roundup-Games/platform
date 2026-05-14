{{-- PostHog JS script inclusion. Reuses $posthogEnabled from posthog-meta partial
    when available, or computes it independently. This partial can be safely
    included even if posthog-meta was not rendered (e.g., in a partial layout). --}}
@php
    $posthogEnabled ??= config('posthog.enabled', true)
        && config('posthog.api_key')
        && !request()->is('admin/*')
        && !request()->is('livewire/update*');
@endphp
@if($posthogEnabled)
    @vite('resources/js/posthog.js')
@endif
