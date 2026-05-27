@props([
    'message',
    'icon' => 'person_add',
])

@guest
<div class="rounded-xl border border-primary/20 bg-primary/5 p-4 sm:p-6">
    <div class="flex flex-col sm:flex-row sm:items-center gap-4">
        <span class="material-symbols-outlined text-3xl text-primary shrink-0" aria-hidden="true">{{ $icon }}</span>
        <div class="flex-1 min-w-0">
            <p class="text-sm sm:text-base text-on-surface">{{ $message }}</p>
        </div>
        <a href="{{ route('register') }}"
           class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-5 py-2.5 text-sm font-medium text-on-primary shadow-sm hover:bg-primary/90 transition-colors whitespace-nowrap shrink-0">
            {{ __('auth.content_sign_up_free') }}
        </a>
    </div>
</div>
@endguest
