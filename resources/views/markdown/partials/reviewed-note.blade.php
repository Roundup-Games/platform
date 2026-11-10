{{-- Markdown counterpart of the content-review-note component (GEO
     E-E-A-T): renders "Last reviewed: <month year>" from the same
     config/content-reviews.php map the HTML pillar reads, so HTML and
     .md never drift. Raw interpolation on purpose — same no-entities
     rule as the mirrors (pinned by MarkdownAlternateTest).

     Expects: string $page — a key from the config map. --}}
@php
    $reviewedAt = config("content-reviews.pages.{$page}");
@endphp
@if(is_string($reviewedAt) && $reviewedAt !== '')

{!! __('pages.content_last_reviewed') !!}: {!! format_date(\Illuminate\Support\Carbon::parse($reviewedAt), 'month_year') !!}
@endif
