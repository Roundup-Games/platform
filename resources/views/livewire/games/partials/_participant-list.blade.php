{{-- Approved participants list --}}
@php
    $approved = $game->participants->filter(fn ($p) => $p->status->value === 'approved');
    $waitlistedCount = $game->participants->filter(fn ($p) => $p->status->value === 'waitlisted')->count();
    $benchedCount = $game->participants->filter(fn ($p) => $p->status->value === 'benched')->count();
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
                    @if($participant->join_source)
                        @php
                            $joinSourceEnum = $participant->join_source;
                            if ($joinSourceEnum instanceof \App\Enums\JoinSource) {
                                $joinSourceBadge = $joinSourceEnum;
                            } else {
                                $joinSourceBadge = \App\Enums\JoinSource::tryFrom($joinSourceEnum);
                            }
                        @endphp
                        @if($joinSourceBadge)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium
                                {{ $joinSourceBadge === \App\Enums\JoinSource::ShareLink ? 'bg-tertiary/10 text-tertiary' : ($joinSourceBadge === \App\Enums\JoinSource::FriendInvite ? 'bg-primary/10 text-primary' : ($joinSourceBadge === \App\Enums\JoinSource::ShortLink ? 'bg-tertiary/10 text-tertiary' : 'bg-secondary-container text-on-secondary-container')) }}">
                                <span class="material-symbols-outlined text-xs" aria-hidden="true">
                                    {{ $joinSourceBadge === \App\Enums\JoinSource::ShareLink ? 'link' : ($joinSourceBadge === \App\Enums\JoinSource::FriendInvite ? 'person_add' : ($joinSourceBadge === \App\Enums\JoinSource::ShortLink ? 'tag' : 'edit_note')) }}
                                </span>
                                {{ $joinSourceBadge->label() }}
                            </span>
                            @if($participant->short_link_id && $participant->shortLink)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-tertiary/5 text-tertiary"
                                      title="{{ __('common.content_joined_via_link', ['label' => $participant->source_label]) }}">
                                    <span class="material-symbols-outlined text-xs" aria-hidden="true">tag</span>
                                    {{ $participant->source_label }}
                                </span>
                            @endif
                        @endif
                    @endif
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
