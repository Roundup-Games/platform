@props([
    'user' => null,
    'size' => 'w-8 h-8',
    'textSize' => 'text-sm',
])

@if($user)
    @php $avatarUrl = $user->avatar_url ?? null @endphp
    @if($avatarUrl)
        <div class="{{ $size }} rounded-full bg-primary/10 flex items-center justify-center text-primary font-heading font-bold {{ $textSize }} shrink-0 overflow-hidden"
             data-initial="{{ strtoupper(\Illuminate\Support\Str::substr($user->name, 0, 1)) }}">
            <img src="{{ $avatarUrl }}"
                 alt=""
                 class="w-full h-full object-cover"
                 aria-hidden="true"
                 width="32"
                 height="32"
                 data-fallback="initial" />
        </div>
    @else
        <span class="{{ $size }} rounded-full bg-primary/10 flex items-center justify-center text-primary font-heading font-bold {{ $textSize }} shrink-0"
              aria-hidden="true">
            {{ strtoupper(\Illuminate\Support\Str::substr($user->name, 0, 1)) }}
        </span>
    @endif
@else
    <span class="{{ $size }} rounded-full bg-surface-container-high flex items-center justify-center shrink-0"
          aria-hidden="true">
        <span class="material-symbols-outlined text-on-surface-variant {{ $textSize }}" aria-hidden="true">person</span>
    </span>
@endif
