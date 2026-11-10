{{-- Markdown alternate advertisement (M078/S04/T02).

Emits a <link rel="alternate" type="text/markdown"> so agents (and the
llms.txt v2 "Optional" map) can discover this page's hand-authored
/en/{page}.md mirror. EN-only by construction: the .md routes have no
non-EN counterpart, so any other locale renders nothing instead of a
link that would 404.

Expects: string $page — a static EN path key from
MarkdownAlternateController::PAGES (single source of truth; the href is
config app.url-derived, mirroring the .md route's canonical Link header
contract — never request-derived). --}}
@if(app()->getLocale() === 'en')
    <link rel="alternate" type="text/markdown" href="{{ rtrim((string) config('app.url'), '/') }}/en/{{ $page }}.md">
@endif
