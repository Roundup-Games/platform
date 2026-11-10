{{--
    Content-review temporal note (GEO E-E-A-T).

    Renders "Last reviewed: <month year>" for evergreen pillar pages,
    sourced from config/content-reviews.php — a real review date
    maintained in the same commit as the content (never auto-refreshed;
    D179 honesty canon). Pages absent from the config map render
    nothing.

    Expects: string $page — a key from the config map (mirrors the
    MarkdownAlternateController::PAGES whitelist paths).
--}}
@php
    $reviewedAt = config("content-reviews.pages.{$page}");
@endphp
@if (is_string($reviewedAt) && $reviewedAt !== '')
    <p class="flex items-center gap-1.5 text-xs text-on-surface-variant">
        <span class="material-symbols-outlined text-sm" aria-hidden="true">event</span>
        <span>{{ __('pages.content_last_reviewed') }}: {{ format_date(\Illuminate\Support\Carbon::parse($reviewedAt), 'month_year') }}</span>
    </p>
@endif
