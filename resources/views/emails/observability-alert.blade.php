@component('mail::message')
# Observability alert

{{ count($rows) }} critical log pattern(s) fired in the last 24 hours and had never been paged before. Rows are watermarked on send — they will not re-page; recurring noise still appears in the daily digest.

@component('mail::table')
| Level | Message | Occurrences | First seen (UTC) | Last seen (UTC) |
| --- | --- | --- | --- | --- |
@foreach($rows as $row)
| {{ $row->level }} | {{ \Illuminate\Support\Str::limit($row->message, 160) }} | {{ $row->occurrences }} | {{ $row->first_seen_at->format('Y-m-d H:i') }} | {{ $row->last_seen_at->format('Y-m-d H:i') }} |
@endforeach
@endcomponent

**Channel:** `{{ $rows[0]->channel }}` — inspect `alertable_errors` (message_hash `{{ \Illuminate\Support\Str::limit($rows[0]->message_hash, 16) }}…`) and the matching log lines for full context.

Sent automatically by `php artisan observability:scan` (every 5 minutes).
@endcomponent
