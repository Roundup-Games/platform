{{-- Badge shelf (M069 S04 T3, spec §2.4): the profile's badge display,
     rendered on PublicProfile and AuthenticatedProfile. Everything flows
     through x-badge-chip — no per-surface badge markup.

     Expected variables:
       $holds   — the profile user's BadgeUser collection with
                  badgeDefinition eager-loaded (one-query discipline).
       $isOwner — whether the current viewer owns the profile (toggles).

     Composition (planner-ratified display semantics): featured holds
     first (max 3, star marker via the chip's featured prop), then the
     remaining non-revoked holds ordered by definition sort_order, lapsed
     holds last greyed with the re-earn caption. Revoked holds are HIDDEN
     from the public shelf — revocation is an admin action; badge_events
     keeps the audit history. The whole section hides when nothing is
     displayable (no empty-shelf clutter). --}}
@php
    $shelfHolds = $holds
        ->filter(fn ($hold) => $hold->revoked_at === null)
        ->values();

    $featuredHolds = $shelfHolds
        ->filter(fn ($hold) => $hold->is_featured)
        ->sortBy(fn ($hold) => $hold->badgeDefinition->sort_order)
        ->values();

    $remainingHolds = $shelfHolds
        ->filter(fn ($hold) => ! $hold->is_featured)
        // Lapsed holds sink to the end of the group; otherwise definition
        // sort_order governs. Array keys compare element-wise.
        ->sortBy(fn ($hold) => [$hold->lapsed_at !== null, $hold->badgeDefinition->sort_order])
        ->values();
@endphp

@if($shelfHolds->isNotEmpty())
    <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
        <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-4 flex items-center gap-2">
            <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">workspace_premium</span>
            {{ __('badges.content_badge_shelf_title') }}
        </h2>

        {{-- Featured row: the shelf front (spec §2.4, max 3 enforced in toggleFeatured) --}}
        @if($featuredHolds->isNotEmpty())
            <div class="flex flex-wrap gap-2">
                @foreach($featuredHolds as $hold)
                    <span class="inline-flex items-center gap-1">
                        <x-badge-chip
                            :definition="$hold->badgeDefinition"
                            :tier="$hold->tier"
                            :state="$hold->lapsed_at !== null ? 'lapsed' : 'held'"
                            :featured="true"
                        />
                        @if($isOwner)
                            <button type="button"
                                    wire:click="toggleFeatured('{{ $hold->id }}')"
                                    aria-label="{{ __('badges.content_badge_shelf_unfeature') }}"
                                    title="{{ __('badges.content_badge_shelf_unfeature') }}"
                                    class="inline-flex items-center justify-center w-7 h-7 rounded-full text-amber-500 hover:bg-surface-container-high transition-colors">
                                <span class="material-symbols-outlined text-sm" style="font-variation-settings: 'FILL' 1" aria-hidden="true">star</span>
                            </button>
                        @endif
                    </span>
                @endforeach
            </div>
        @endif

        {{-- Remaining holds: definition order, lapsed greyed at the end --}}
        @if($remainingHolds->isNotEmpty())
            <div class="flex flex-wrap gap-2{{ $featuredHolds->isNotEmpty() ? ' mt-3 pt-3 border-t border-outline-variant/20' : '' }}">
                @foreach($remainingHolds as $hold)
                    <span class="inline-flex items-center gap-1">
                        <x-badge-chip
                            :definition="$hold->badgeDefinition"
                            :tier="$hold->tier"
                            :state="$hold->lapsed_at !== null ? 'lapsed' : 'held'"
                        />
                        @if($isOwner)
                            <button type="button"
                                    wire:click="toggleFeatured('{{ $hold->id }}')"
                                    aria-label="{{ __('badges.content_badge_shelf_feature') }}"
                                    title="{{ __('badges.content_badge_shelf_feature') }}"
                                    class="inline-flex items-center justify-center w-7 h-7 rounded-full text-on-surface-variant hover:bg-surface-container-high transition-colors">
                                <span class="material-symbols-outlined text-sm" aria-hidden="true">star</span>
                            </button>
                        @endif
                    </span>
                @endforeach
            </div>
        @endif

        {{-- Loud max-3 rejection (toggleFeatured addError) --}}
        @error('featuredLimit')
            <p class="mt-3 text-sm text-error">{{ $message }}</p>
        @enderror
    </section>
@endif
