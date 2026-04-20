@props([
    'user' => null,
    'showAvatar' => true,
    'avatarSize' => 'w-8 h-8',
    'truncate' => false,
])

@if($user)
    <a href="{{ route('profile.public', ['locale' => app()->getLocale(), 'user' => $user]) }}"
       wire:navigate
       class="inline-flex items-center gap-2 group {{ $truncate ? 'min-w-0' : '' }}">
        @if($showAvatar)
            <x-user-avatar :user="$user" :size="$avatarSize" />
        @endif

        <span class="font-medium text-on-surface group-hover:text-primary transition-colors {{ $truncate ? 'truncate' : '' }}">
            {{ $user->name }}
        </span>
        <span class="sr-only">View {{ $user->name }}'s profile</span>
    </a>
@else
    <span class="inline-flex items-center gap-2 text-on-surface-variant">
        @if($showAvatar)
            <x-user-avatar :user="null" :size="$avatarSize" />
        @endif
        <span class="font-medium text-on-surface-variant">{{ __('common.content_unknown') }}</span>
    </span>
@endif
