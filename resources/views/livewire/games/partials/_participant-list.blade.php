{{-- Approved participants list --}}
<section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
    <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
        <span class="material-symbols-outlined text-xl" aria-hidden="true">groups</span>
        {{ __('common.content_participants') }}
    </h2>
    @if($game->participants->count())
        <div class="divide-y divide-outline-variant/30">
            @foreach($game->participants as $participant)
                <div class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
                    <x-user-link :user="$participant->user" avatar-size="w-10 h-10" :truncate="true" />
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                        {{ $participant->role === 'gm' ? 'bg-primary/10 text-primary' : 'bg-surface-container-high text-on-surface-variant' }}">
                        {{ __('games.field_role_' . $participant->role) }}
                    </span>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                        {{ $participant->status === \App\Enums\ParticipantStatus::Approved ? 'bg-secondary-container text-on-secondary-container' : ($participant->status === \App\Enums\ParticipantStatus::Waitlisted ? 'bg-tertiary/10 text-tertiary' : ($participant->status === \App\Enums\ParticipantStatus::Pending ? 'bg-primary/10 text-primary' : 'bg-error-container text-on-error-container')) }}">
                        {{ $participant->status instanceof \BackedEnum ? $participant->status->label() : __('games.status_' . $participant->status) }}
                    </span>
                </div>
            @endforeach
        </div>
    @else
        <p class="text-sm text-on-surface-variant italic py-4 text-center">{{ __('common.content_no_participants_yet') }}</p>
    @endif
</section>
