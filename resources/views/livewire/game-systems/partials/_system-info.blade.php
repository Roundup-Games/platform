                @if($system->description)
                    <section>
                        <h2 class="text-lg font-heading font-bold text-on-surface mb-3">{{ __('games.heading_about_this_game') }}</h2>
                        <div class="prose prose-sm max-w-none text-on-surface-variant prose-headings:text-on-surface prose-a:text-primary whitespace-pre-line">
                            {{ $system->description }}
                        </div>
                    </section>
                @endif

                {{-- Categories & Mechanics tags --}}
                @if($system->categories->count() || $system->mechanics->count())
                    <section class="space-y-4">
                        @if($system->categories->count())
                            <div>
                                <h3 class="text-sm font-heading font-bold text-on-surface mb-2">{{ __('games.heading_categories') }}</h3>
                                <div class="flex flex-wrap gap-2">
                                    @foreach($system->categories as $cat)
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-primary/10 text-primary">
                                            {{ $cat->translatedName() }}
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        @if($system->mechanics->count())
                            <div>
                                <h3 class="text-sm font-heading font-bold text-on-surface mb-2">{{ __('games.heading_mechanics') }}</h3>
                                <div class="flex flex-wrap gap-2">
                                    @foreach($system->mechanics as $mech)
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                                            {{ $mech->translatedName() }}
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </section>
                @endif

                {{-- ── How to Play (TTRPG instructions) ──────────── --}}
                @php($instructions = $system->instructions)
                @if($instructions && !empty($instructions['description']))
                    <section>
                        <h2 class="text-lg font-heading font-bold text-on-surface mb-3 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary" aria-hidden="true">menu_book</span>
                            {{ $instructions['title'] ?? __('games.heading_how_to_play') }}
                        </h2>
                        <div class="prose prose-sm max-w-none text-on-surface-variant prose-headings:text-on-surface prose-a:text-primary">
                            {!! nl2br(e($instructions['description'])) !!}
                        </div>
                        @if(!empty($instructions['videoUrl']))
                            <div class="mt-4 aspect-video rounded-xl overflow-hidden shadow-md">
                                <iframe src="{{ $instructions['videoUrl'] }}"
                                        title="{{ $instructions['title'] ?? $system->name . ' — How to Play' }}"
                                        class="w-full h-full"
                                        frameborder="0"
                                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                        allowfullscreen
                                        loading="lazy"></iframe>
                            </div>
                        @endif
                    </section>
                @endif

                {{-- ── Showcases (character classes, etc.) ────────── --}}
                @php($showcases = $system->showcases ?? [])
                @if(!empty($showcases))
                    @foreach($showcases as $showcase)
                        <section>
                            <h2 class="text-lg font-heading font-bold text-on-surface mb-3 flex items-center gap-2">
                                <span class="material-symbols-outlined text-primary" aria-hidden="true">theater_comedy</span>
                                {{ $showcase['title'] ?? __('games.heading_showcase') }}
                            </h2>
                            @if(!empty($showcase['description']))
                                <p class="text-sm text-on-surface-variant mb-4">{{ $showcase['description'] }}</p>
                            @endif
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                @foreach($showcase['items'] ?? [] as $item)
                                    <div class="bg-surface-container rounded-xl p-4 flex gap-3">
                                        @if(!empty($item['image']))
                                            <div class="shrink-0 w-12 h-12 rounded-lg overflow-hidden bg-surface-container-high">
                                                <img src="{{ $item['image'] }}" alt="{{ $item['title'] ?? '' }}" class="w-full h-full object-cover" loading="lazy">
                                            </div>
                                        @endif
                                        <div class="min-w-0">
                                            <h4 class="text-sm font-semibold text-on-surface">{{ $item['title'] ?? '' }}</h4>
                                            @if(!empty($item['description']))
                                                <p class="text-xs text-on-surface-variant mt-1 line-clamp-3">{{ $item['description'] }}</p>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                @endif

                {{-- ── FAQ ──────────────────────────────────────── --}}
                @php($faqs = $system->faq_content ?? [])
                @if(!empty($faqs))
                    <section>
                        <h2 class="text-lg font-heading font-bold text-on-surface mb-3 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary" aria-hidden="true">help</span>
                            {{ __('games.heading_faq') }}
                        </h2>
                        <div class="space-y-3">
                            @foreach($faqs as $faq)
                                <details class="group bg-surface-container rounded-xl">
                                    <summary class="px-5 py-3.5 cursor-pointer text-sm font-semibold text-on-surface flex items-center justify-between gap-3 hover:bg-surface-container-high rounded-xl transition-colors">
                                        {{ $faq['question'] ?? '' }}
                                        <span class="material-symbols-outlined text-lg text-on-surface-variant group-open:rotate-180 transition-transform shrink-0" aria-hidden="true">expand_more</span>
                                    </summary>
                                    <div class="px-5 pb-4 text-sm text-on-surface-variant leading-relaxed">
                                        {{ $faq['answer'] ?? '' }}
                                    </div>
                                </details>
                            @endforeach
                        </div>
                    </section>
                @endif

                {{-- ── External Links (buy, VTT, etc.) ──────────────── --}}
                @php($links = $system->external_links ?? [])
                @if(!empty($links))
                    <section>
                        <h2 class="text-lg font-heading font-bold text-on-surface mb-3 flex items-center gap-2">
                            <span class="material-symbols-outlined text-primary" aria-hidden="true">open_in_new</span>
                            {{ __('games.heading_get_this_game') }}
                        </h2>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                            @foreach($links as $link)
                                <a href="{{ $link['url'] ?? '#' }}"
                                   target="_blank" rel="noopener noreferrer"
                                   class="flex items-center gap-3 p-3 bg-surface-container rounded-xl hover:bg-surface-container-high hover:shadow-sm transition-all group">
                                    @if(!empty($link['image']))
                                        <div class="shrink-0 w-10 h-10 rounded-lg overflow-hidden bg-surface-container-high">
                                            <img src="{{ $link['image'] }}" alt="" class="w-full h-full object-cover" loading="lazy" aria-hidden="true">
                                        </div>
                                    @else
                                        <div class="shrink-0 w-10 h-10 rounded-lg bg-surface-container-high flex items-center justify-center">
                                            <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">
                                                {{ ($link['type'] ?? '') === 'VTT' ? 'computer' : 'shopping_cart' }}
                                            </span>
                                        </div>
                                    @endif
                                    <div class="min-w-0 flex-1">
                                        <span class="text-sm font-medium text-on-surface group-hover:text-primary transition-colors truncate block">{{ $link['title'] ?? '' }}</span>
                                        @if(!empty($link['type']))
                                            <span class="text-xs text-on-surface-variant">{{ $link['type'] === 'PURCHASE_OPTION' ? __('games.link_type_purchase') : $link['type'] }}</span>
                                        @endif
                                    </div>
                                    <span class="material-symbols-outlined text-on-surface-variant text-sm shrink-0 group-hover:text-primary transition-colors" aria-hidden="true">open_in_new</span>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif
