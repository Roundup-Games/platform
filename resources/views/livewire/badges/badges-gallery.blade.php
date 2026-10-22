<div>
    {{-- ── Header ────────────────────────────────────────────────── --}}
    <section class="bg-primary text-on-primary">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 sm:py-10">
            <h1 class="text-2xl sm:text-3xl font-heading font-bold tracking-tight">{{ __('badges.title_badge_gallery') }}</h1>
            <p class="mt-1 text-sm text-on-primary/80 max-w-2xl">{{ __('badges.content_badge_gallery_intro') }}</p>
        </div>
    </section>

    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-6">
        {{-- ── Category pills (GmDirectory specialization pattern) ── --}}
        <div class="mb-6">
            <p class="text-xs font-medium text-on-surface-variant mb-2">{{ __('badges.filter_category_label') }}</p>
            <div class="flex flex-wrap gap-1.5">
                <button
                    wire:click="toggleCategory('')"
                    class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium transition-colors
                        {{ $category === '' ? 'bg-primary/15 text-primary ring-1 ring-primary/30' : 'bg-surface-container-high text-on-surface-variant hover:bg-surface-container' }}"
                >
                    {{ __('badges.filter_all_categories') }}
                </button>
                @foreach($categories as $badgeCategory)
                    <button
                        wire:click="toggleCategory('{{ $badgeCategory->value }}')"
                        class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium transition-colors
                            {{ $category === $badgeCategory->value ? 'bg-primary/15 text-primary ring-1 ring-primary/30' : 'bg-surface-container-high text-on-surface-variant hover:bg-surface-container' }}"
                    >
                        {{ $badgeCategory->label() }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- ── Badge cards ─────────────────────────────────────────── --}}
        @if($definitions->count())
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                @foreach($definitions as $definition)
                    @php
                        // Dormant: registered but the signal does not exist
                        // yet — greyed card, chip renders the coming-soon
                        // caption (aspirational loop, spec §2.4).
                        $isDormant = $definition->is_dormant;

                        // Boundary-map honesty rule (D195): the raw holder
                        // count always shows; the eligible share only once
                        // the eligible population reaches the threshold —
                        // no rare-% claims on a tiny population.
                        $showShare = $definition->eligible_count >= \App\Enums\BadgeRarity::ELIGIBLE_THRESHOLD;
                        $sharePercent = $definition->eligible_count > 0
                            ? round($definition->holders_count / $definition->eligible_count * 100, 1)
                            : 0.0;

                        // Description resolves like the chip resolves names:
                        // lang key when present, nothing for unknown keys
                        // (registry rows always carry real keys).
                        $description = \Illuminate\Support\Facades\Lang::has($definition->description_key)
                            ? (string) __($definition->description_key)
                            : null;
                    @endphp
                    <article class="bg-surface-container rounded-2xl border border-outline-variant/15 p-5{{ $isDormant ? ' opacity-60' : '' }}">
                        {{-- Identity chip: icon + name (+ coming-soon caption when dormant) --}}
                        <x-badge-chip
                            :definition="$definition"
                            :state="$isDormant ? 'dormant' : 'held'"
                        />

                        @if($description)
                            <p class="mt-3 text-sm text-on-surface-variant">{{ $description }}</p>
                        @endif

                        {{-- Tier ladder for tiered badges (badge.content_badge_tier_* labels) --}}
                        @if($definition->tiers && count($definition->tiers))
                            <div class="mt-3 flex flex-wrap gap-1.5">
                                @foreach($definition->orderedTierKeys() as $tierKey)
                                    @php
                                        $tierLabel = \App\Services\Badges\BadgeCopy::tierLabel($tierKey);
                                    @endphp
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-surface-container-highest text-on-surface-variant">
                                        {{ $tierLabel }}
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        {{-- Rarity meta: count always, % gated by the eligible threshold --}}
                        <div class="mt-3 space-y-1 text-xs text-on-surface-variant">
                            @if($definition->rarity_class)
                                <p class="font-medium">{{ $definition->rarity_class->label() }}</p>
                            @endif
                            <p>{{ trans_choice('badges.content_badge_holders', $definition->holders_count) }}</p>
                            @if($showShare)
                                <p>{{ __($definition->eligibility === \App\Enums\BadgeEligibility::GameMasters
                                        ? 'badges.content_badge_share_of_eligible_gms'
                                        : 'badges.content_badge_share_of_eligible', ['percent' => \Illuminate\Support\Number::format($sharePercent, maxPrecision: 1)]) }}</p>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        @else
            {{-- ── Empty State ────────────────────────────────────── --}}
            <div class="text-center py-16">
                <span class="material-symbols-outlined text-5xl text-on-surface-variant/40 mb-3 block" aria-hidden="true">emoji_events</span>
                <h2 class="text-lg font-heading font-semibold text-on-surface-variant">{{ __('badges.content_badge_gallery_empty') }}</h2>
                <button wire:click="toggleCategory('')"
                        class="mt-4 px-5 py-2 bg-primary text-on-primary rounded-xl text-sm font-medium hover:shadow-md active:scale-95 transition-all">
                    {{ __('badges.filter_all_categories') }}
                </button>
            </div>
        @endif
    </div>
</div>
