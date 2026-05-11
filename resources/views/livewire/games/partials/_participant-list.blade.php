{{-- Approved participants list --}}
@php
    $approved = $game->participants->filter(fn ($p) => $p->status === \App\Enums\ParticipantStatus::Approved);
    $waitlistedCount = $game->participants->filter(fn ($p) => $p->status === \App\Enums\ParticipantStatus::Waitlisted)->count();
    $benchedCount = $game->participants->filter(fn ($p) => $p->status === \App\Enums\ParticipantStatus::Benched)->count();
    $hasOverflow = $waitlistedCount > 0 || $benchedCount > 0;
@endphp

<section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
    <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
        <span class="material-symbols-outlined text-xl" aria-hidden="true">groups</span>
        {{ __('common.content_participants') }}
    </h2>
    @if($approved->count())
        <div class="divide-y divide-outline-variant/30">
            @foreach($approved as $participant)
                <div class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                    <x-user-link :user="$participant->user" avatar-size="w-10 h-10" :truncate="true" />
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                        {{ $participant->role === 'gm' ? 'bg-primary/10 text-primary' : 'bg-surface-container-high text-on-surface-variant' }}">
                        {{ __('games.field_role_' . $participant->role) }}
                    </span>
                </div>
            @endforeach
        </div>
    @else
        <p class="text-sm text-on-surface-variant italic py-4 text-center">{{ __('common.content_no_participants_yet') }}</p>
    @endif

    @if($hasOverflow)
        <p class="text-xs text-on-surface-variant mt-3 pt-3 border-t border-outline-variant/30">
            @if($waitlistedCount > 0)
                <span class="inline-flex items-center gap-1">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">schedule</span>
                    {{ $waitlistedCount }} {{ trans_choice('games.content_waitlisted_count', $waitlistedCount) }}
                </span>
            @endif
            @if($waitlistedCount > 0 && $benchedCount > 0)
                <span class="mx-1.5" aria-hidden="true">·</span>
            @endif
            @if($benchedCount > 0)
                <span class="inline-flex items-center gap-1">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">event_seat</span>
                    {{ $benchedCount }} {{ trans_choice('games.content_benched_count', $benchedCount) }}
                </span>
            @endif
        </p>
    @endif
</section>
