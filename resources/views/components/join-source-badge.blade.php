@props(['participant'])

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
