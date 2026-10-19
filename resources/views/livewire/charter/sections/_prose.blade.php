{{-- Escape-first prose: e() escapes the value exactly once, then nl2br
     converts newlines to <br>. The single raw echo in the charter view —
     user content itself is always escaped, only the <br /> markup is raw. --}}
<div class="mt-1.5 text-sm text-on-surface leading-relaxed">{!! nl2br(e($value)) !!}</div>
