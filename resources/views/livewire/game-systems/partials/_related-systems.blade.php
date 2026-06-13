        {{-- ── Base Game Quick Info (for expansions) ──────────────── --}}
        @if($system->baseGame)
            <section class="bg-surface-container rounded-xl shadow-ambient p-5">
                <h2 class="text-sm font-heading font-bold text-primary uppercase tracking-wide mb-3">{{ __('games.content_base_game_quick_info') }}</h2>
                <a href="{{ route('game-systems.show', $system->baseGame->slug) }}" wire:navigate class="flex items-center gap-4 p-3 bg-surface rounded-lg hover:bg-primary/5 transition-colors group">
                    <div class="shrink-0 w-16 h-16 rounded-lg overflow-hidden bg-surface-container-high">
                        @php($baseCover = $system->baseGame->coverImageUrl('thumb'))
                        @if($baseCover)
                            <img src="{{ $baseCover }}" alt="{{ $system->baseGame->name }}" class="w-full h-full object-cover" loading="lazy" data-fallback="placeholder">
                        @else
                            <div class="w-full h-full flex items-center justify-center">
                                <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">casino</span>
                            </div>
                        @endif
                    </div>
                    <div>
                        <h3 class="font-heading font-semibold text-on-surface group-hover:text-primary transition-colors">{{ $system->baseGame->name }}</h3>
                        @if($system->baseGame->bgg_average_rating)
                            <p class="text-sm text-on-surface-variant flex items-center gap-1 mt-1">
                                <span class="material-symbols-outlined text-sm text-amber-500" aria-hidden="true">star</span>
                                {{ number_format($system->baseGame->bgg_average_rating, 1) }}
                                @if($system->baseGame->bgg_rank)
                                    <span class="ml-2">Rank #{{ number_format($system->baseGame->bgg_rank) }}</span>
                                @endif
                            </p>
                        @endif
                    </div>
                    <span class="material-symbols-outlined text-on-surface-variant ml-auto group-hover:text-primary transition-colors" aria-hidden="true">arrow_forward</span>
                </a>
            </section>
        @endif

        {{-- ── Expansions Section (for base games) ────────────────── --}}
        @if($system->expansions->count() > 0)
            <section>
                <h2 class="text-lg font-heading font-bold text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary" aria-hidden="true">extension</span>
                    {{ __('games.heading_expansions') }}
                    <span class="text-sm font-normal text-on-surface-variant">({{ $system->expansions->count() }})</span>
                </h2>
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
                    @foreach($system->expansions as $expansion)
                        <a href="{{ route('game-systems.show', $expansion->slug) }}" wire:navigate class="block bg-surface-container rounded-xl shadow-ambient hover:shadow-md transition-shadow overflow-hidden group">
                            <div class="aspect-square bg-surface-container-high relative overflow-hidden">
                                @php($expCover = $expansion->coverImageUrl('thumb'))
                                @if($expCover)
                                    <img src="{{ $expCover }}" alt="{{ $expansion->name }}" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" loading="lazy" data-fallback="placeholder">
                                @else
                                    <div class="w-full h-full flex items-center justify-center">
                                        <span class="material-symbols-outlined text-3xl text-on-surface-variant/30" aria-hidden="true">extension</span>
                                    </div>
                                @endif
                                @if($expansion->bgg_average_rating)
                                    <span class="absolute bottom-1 right-1 px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-on-primary/80 text-primary backdrop-blur-xs">
                                        {{ number_format($expansion->bgg_average_rating, 1) }} ★
                                    </span>
                                @endif
                            </div>
                            <div class="p-2.5">
                                <h3 class="text-xs font-medium text-on-surface leading-tight line-clamp-2 group-hover:text-primary transition-colors">{{ $expansion->name }}</h3>
                                @if($expansion->year_released)
                                    <p class="text-[10px] text-on-surface-variant mt-1">{{ $expansion->year_released }}</p>
                                @endif
                            </div>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

