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
            @php $avatarUrl = $user->avatar_url ?? null @endphp
            @if($avatarUrl)
                <img src="{{ $avatarUrl }}"
                     alt=""
                     class="{{ $avatarSize }} rounded-full object-cover shrink-0"
                     aria-hidden="true" />
            @else
                <span class="{{ $avatarSize }} rounded-full bg-primary/10 flex items-center justify-center text-primary font-heading font-bold shrink-0"
                      aria-hidden="true">
                    {{ strtoupper(\Illuminate\Support\Str::substr($user->name, 0, 1)) }}
                </span>
            @endif
        @endif

        <span class="font-medium text-on-surface group-hover:text-primary transition-colors {{ $truncate ? 'truncate' : '' }}">
            {{ $user->name }}
        </span>
        <span class="sr-only">View {{ $user->name }}'s profile</span>
    </a>
@else
    <span class="inline-flex items-center gap-2 text-on-surface-variant">
        @if($showAvatar)
            <span class="{{ $avatarSize }} rounded-full bg-surface-container-high flex items-center justify-center shrink-0" aria-hidden="true">
                <span class="material-symbols-outlined text-on-surface-variant text-sm">person</span>
            </span>
        @endif
        <span class="font-medium text-on-surface-variant">Unknown</span>
    </span>
@endif
