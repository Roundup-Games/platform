@component('mail::message')
# Observability digest — {{ Carbon\Carbon::now()->format('Y-m-d') }}

Unresolved alertable log errors as of this morning (top {{ count($rows) }} by last sighting). The pager (`observability:scan`) only pages critical patterns that were never notified; everything listed here is still open.

@foreach(collect($rows)->groupBy('level')->sortKeys() as $level => $levelRows)
## {{ $level }} — {{ $levelRows->count() }} message(s), {{ $levelRows->sum('occurrences') }} occurrence(s)

@component('mail::table')
| Message | Occurrences | First seen (UTC) | Last seen (UTC) | Notified |
| --- | --- | --- | --- | --- |
@foreach($levelRows as $row)
| {{ \Illuminate\Support\Str::limit($row->message, 140) }} | {{ $row->occurrences }} | {{ $row->first_seen_at->format('Y-m-d H:i') }} | {{ $row->last_seen_at->format('Y-m-d H:i') }} | {{ $row->last_notified_at?->format('Y-m-d H:i') ?? 'never' }} |
@endforeach
@endcomponent
@endforeach

Sent automatically by `php artisan observability:digest` (daily).
@endcomponent
