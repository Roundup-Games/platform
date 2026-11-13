@props(['url', 'title', 'videoId', 'embedParams', 'posterUrl'])

@if($videoId !== '')
    {{-- Poster + play button only — the iframe is created on click by
         resources/js/video-facade.js (document-level delegation, so the
         facade survives Livewire morphs). Play interactions reach PostHog
         through autocapture on this <button> — no bespoke tracking needed. --}}
    <div class="relative aspect-video rounded-xl overflow-hidden shadow-md bg-surface-container-high group">
        <button type="button"
                data-youtube-facade-play
                data-video-id="{{ $videoId }}"
                data-embed-params="{{ $embedParams }}"
                data-video-title="{{ $title }}"
                aria-label="{{ __('common.action_play_video', ['title' => $title]) }}"
                class="absolute inset-0 w-full h-full cursor-pointer">
            <img src="{{ $posterUrl }}" alt="" loading="lazy" decoding="async" class="w-full h-full object-cover" aria-hidden="true">
            <span class="absolute inset-0 flex items-center justify-center bg-black/25 transition-colors group-hover:bg-black/40" aria-hidden="true">
                <span class="material-symbols-outlined text-white text-5xl drop-shadow-lg transition-transform group-hover:scale-110">play_circle</span>
            </span>
        </button>
    </div>
@else
    {{-- Unparseable stored URL: honest outbound link, never a broken embed. --}}
    <a href="{{ $url }}"
       target="_blank" rel="noopener noreferrer"
       class="inline-flex items-center gap-2 text-sm font-medium text-primary hover:underline">
        <span class="material-symbols-outlined text-base" aria-hidden="true">open_in_new</span>
        {{ __('common.action_watch_video_external', ['title' => $title]) }}
    </a>
@endif
