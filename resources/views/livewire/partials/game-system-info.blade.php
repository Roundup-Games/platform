{{-- Game System expandable info card --}}
{{-- Expects: $system (GameSystem model, eager-loaded with categories, mechanics, publishers) --}}

@php
    $system = $entity->gameSystem;
    if (!$system) return;

    $coverUrl = $system->getFirstMediaUrl('cover');
    if (!$coverUrl && $system->thumbnail_url) {
        $coverUrl = $system->thumbnail_url;
    }

    $hasRichData = $system->description
        || $system->categories->count()
        || $system->mechanics->count()
        || $system->creator
        || $system->publishers->count()
        || $system->min_players
        || $system->average_play_time
        || !empty($system->faq_content)
        || !empty($system->showcases)
        || ($system->instructions && !empty($system->instructions['description']))
        || $system->expansions->count()
        || $system->baseGame;
@endphp

<section class="bg-surface-container-low rounded-xl shadow-ambient overflow-hidden">
    {{-- Compact header: always visible --}}
    <div class="p-5 sm:p-6 flex items-center gap-4">
        @if($coverUrl)
            <div class="shrink-0 w-14 h-14 sm:w-16 sm:h-16 rounded-xl overflow-hidden shadow-md bg-surface-container-high">
                <img src="{{ $coverUrl }}" alt="{{ $system->name }}" class="w-full h-full object-cover" loading="lazy">
            </div>
        @else
            <div class="shrink-0 w-14 h-14 sm:w-16 sm:h-16 rounded-xl bg-primary/10 flex items-center justify-center">
                <span class="material-symbols-outlined text-2xl text-primary" aria-hidden="true">casino</span>
            </div>
        @endif

        <div class="flex-1 min-w-0">
            <a href="{{ route('game-systems.show', $system->slug) }}" wire:navigate
               class="text-lg font-heading font-bold text-on-surface hover:text-primary transition-colors truncate block">
                {{ $system->name }}
            </a>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-1 text-sm text-on-surface-variant">
                @if($system->isTtrpg())
                    <span class="inline-flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">auto_stories</span>
                        TTRPG
                    </span>
                @endif
                @if($system->min_players || $system->max_players)
                    <span class="inline-flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">group</span>
                        {{ $system->min_players ?? '?' }}–{{ $system->max_players ?? '?' }}
                    </span>
                @endif
                @if($system->average_play_time)
                    <span class="inline-flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">schedule</span>
                        {{ $system->average_play_time }} {{ strtolower(__('games.content_min')) }}
                    </span>
                @endif
                @if($system->sp_rating && $system->sp_review_count)
                    <span class="inline-flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm text-amber-500" aria-hidden="true">star</span>
                        {{ number_format($system->sp_rating, 1) }}
                        <span class="text-xs">({{ $system->sp_review_count }})</span>
                    </span>
                @endif
                @if($system->bgg_average_rating && $system->bgg_users_rated)
                    <span class="inline-flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm text-amber-500" aria-hidden="true">emoji_events</span>
                        {{ number_format($system->bgg_average_rating, 1) }}
                        <span class="text-xs">({{ number_format($system->bgg_users_rated) }})</span>
                    </span>
                @endif
            </div>
        </div>

        @if($hasRichData)
            <button type="button"
                    onclick="this.closest('section').querySelector('.system-details').classList.toggle('hidden'); this.querySelector('.chevron').classList.toggle('rotate-180')"
                    class="shrink-0 w-9 h-9 rounded-full bg-surface-container-high flex items-center justify-center hover:bg-primary/10 transition-colors"
                    aria-label="{{ __('games.action_show_game_system_details') }}">
                <span class="material-symbols-outlined text-lg text-on-surface-variant chevron transition-transform duration-200" aria-hidden="true">expand_more</span>
            </button>
        @else
            <a href="{{ route('game-systems.show', $system->slug) }}" wire:navigate
               class="shrink-0 text-sm font-medium text-primary hover:text-primary/80 transition-colors flex items-center gap-1">
                {{ __('games.action_view_full_details') }}
                <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_forward</span>
            </a>
        @endif
    </div>

    {{-- Expandable details --}}
    @if($hasRichData)
        <div class="system-details hidden border-t border-outline-variant/30">
            <div class="p-5 sm:p-6 space-y-5">

                {{-- Description (short) --}}
                @if($system->description)
                    <p class="text-sm text-on-surface-variant leading-relaxed line-clamp-4">{{ Str::limit($system->description, 280) }}</p>
                @endif

                {{-- Publisher + Creator + Year --}}
                @if($system->publishers->count() || $system->creator || $system->year_released)
                    <div class="flex flex-wrap gap-x-5 gap-y-2 text-sm">
                        @if($system->publishers->count())
                            <div>
                                <span class="text-on-surface-variant">{{ __('games.field_publisher') }}:</span>
                                <span class="font-medium text-on-surface ml-1">{{ $system->publishers->pluck('name')->join(', ') }}</span>
                            </div>
                        @endif
                        @if($system->creator)
                            <div>
                                <span class="text-on-surface-variant">{{ __('games.field_designer') }}:</span>
                                <span class="font-medium text-on-surface ml-1">{{ Str::limit($system->creator, 60) }}</span>
                            </div>
                        @endif
                        @if($system->year_released)
                            <div>
                                <span class="text-on-surface-variant">{{ __('games.field_year') }}:</span>
                                <span class="font-medium text-on-surface ml-1">{{ $system->year_released }}</span>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Categories + Mechanics --}}
                @if($system->categories->count() || $system->mechanics->count())
                    <div class="space-y-2">
                        @if($system->categories->count())
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($system->categories as $cat)
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                                        {{ $cat->translatedName() }}
                                    </span>
                                @endforeach
                            </div>
                        @endif
                        @if($system->mechanics->count())
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($system->mechanics as $mech)
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                                        {{ $mech->translatedName() }}
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Showcases (classes, etc.) — compact horizontal scroll --}}
                @php($showcases = $system->showcases ?? [])
                @if(!empty($showcases))
                    @foreach($showcases as $showcase)
                        <div>
                            <h4 class="text-xs font-heading font-bold uppercase tracking-wider text-on-surface-variant mb-2">{{ $showcase['title'] ?? __('games.heading_showcase') }}</h4>
                            <div class="flex gap-2 overflow-x-auto pb-2 -mx-1 px-1 snap-x snap-mandatory scrollbar-thin">
                                @foreach(array_slice($showcase['items'] ?? [], 0, 8) as $item)
                                    <div class="snap-start shrink-0 w-24 flex flex-col items-center gap-1.5 text-center">
                                        @if(!empty($item['image']))
                                            <div class="w-14 h-14 rounded-xl overflow-hidden bg-surface-container-high shadow-xs">
                                                <img src="{{ $item['image'] }}" alt="{{ $item['title'] ?? '' }}" class="w-full h-full object-cover" loading="lazy">
                                            </div>
                                        @endif
                                        <span class="text-xs font-medium text-on-surface leading-tight">{{ $item['title'] ?? '' }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                @endif

                {{-- Base game + Expansions --}}
                @if($system->baseGame || $system->expansions->count())
                    <div class="space-y-2">
                        @if($system->baseGame)
                            <a href="{{ route('game-systems.show', $system->baseGame->slug) }}" wire:navigate
                               class="flex items-center gap-3 p-2.5 bg-surface rounded-lg hover:bg-primary/5 transition-colors group">
                                @php($baseCover = $system->baseGame->getFirstMediaUrl('cover', 'thumb'))
                                <div class="shrink-0 w-8 h-8 rounded-lg overflow-hidden bg-surface-container-high">
                                    @if($baseCover)
                                        <img src="{{ $baseCover }}" alt="{{ $system->baseGame->name }}" class="w-full h-full object-cover" loading="lazy">
                                    @elseif($system->baseGame->thumbnail_url)
                                        <img src="{{ $system->baseGame->thumbnail_url }}" alt="{{ $system->baseGame->name }}" class="w-full h-full object-cover" loading="lazy">
                                    @else
                                        <div class="w-full h-full flex items-center justify-center">
                                            <span class="material-symbols-outlined text-sm text-on-surface-variant" aria-hidden="true">casino</span>
                                        </div>
                                    @endif
                                </div>
                                <div class="flex-1 min-w-0">
                                    <span class="text-xs text-on-surface-variant">{{ __('games.content_base_game') }}</span>
                                    <p class="text-sm font-medium text-on-surface group-hover:text-primary transition-colors truncate">{{ $system->baseGame->name }}</p>
                                </div>
                                <span class="material-symbols-outlined text-sm text-on-surface-variant group-hover:text-primary transition-colors" aria-hidden="true">arrow_forward</span>
                            </a>
                        @endif
                        @if($system->expansions->count())
                            <div>
                                <span class="text-xs text-on-surface-variant">{{ trans_choice('games.content_expansions_count', $system->expansions->count()) }}</span>
                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                    @foreach($system->expansions as $expansion)
                                        <a href="{{ route('game-systems.show', $expansion->slug) }}" wire:navigate
                                           class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-surface text-on-surface hover:bg-primary/10 hover:text-primary transition-colors">
                                            {{ $expansion->name }}
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- How to Play (compact) --}}
                @php($instructions = $system->instructions)
                @if($instructions && !empty($instructions['description']))
                    <div>
                        <h4 class="text-xs font-heading font-bold uppercase tracking-wider text-on-surface-variant mb-2 flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-sm" aria-hidden="true">menu_book</span>
                            {{ $instructions['title'] ?? __('games.heading_how_to_play') }}
                        </h4>
                        <p class="text-sm text-on-surface-variant leading-relaxed line-clamp-3">{{ $instructions['description'] }}</p>
                        @if(!empty($instructions['videoUrl']))
                            <a href="{{ $instructions['videoUrl'] }}" target="_blank" rel="noopener"
                               class="inline-flex items-center gap-1.5 mt-2 text-sm font-medium text-primary hover:text-primary/80 transition-colors">
                                <span class="material-symbols-outlined text-sm" aria-hidden="true">play_circle</span>
                                {{ __('games.action_watch_tutorial') }}
                            </a>
                        @endif
                    </div>
                @endif

                {{-- External Links (compact row) --}}
                @php($links = $system->external_links ?? [])
                @if(!empty($links))
                    <div class="flex flex-wrap gap-2">
                        @foreach($links as $link)
                            <a href="{{ $link['url'] ?? '#' }}" target="_blank" rel="noopener"
                               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-surface text-on-surface hover:bg-primary/10 hover:text-primary transition-colors">
                                @if(($link['type'] ?? '') === 'PURCHASE_OPTION')
                                    <span class="material-symbols-outlined text-sm" aria-hidden="true">shopping_cart</span>
                                @elseif(($link['type'] ?? '') === 'VTT')
                                    <span class="material-symbols-outlined text-sm" aria-hidden="true">computer</span>
                                @else
                                    <span class="material-symbols-outlined text-sm" aria-hidden="true">open_in_new</span>
                                @endif
                                {{ $link['title'] ?? __('games.action_visit') }}
                            </a>
                        @endforeach
                    </div>
                @endif

                {{-- Full details link --}}
                <a href="{{ route('game-systems.show', $system->slug) }}" wire:navigate
                   class="inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:text-primary/80 transition-colors">
                    {{ __('games.action_view_full_system_page', ['name' => $system->name]) }}
                    <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_forward</span>
                </a>
            </div>
        </div>
    @endif
</section>
