@props([
    'milestoneCards' => [],
])

@php
    $hasCards = count($milestoneCards) > 0;
    $iconStyles = [
        'trophy' => 'bg-primary/10 text-primary',
        'users' => 'bg-secondary/10 text-secondary',
        'menu_book' => 'bg-tertiary/10 text-tertiary',
        'star' => 'bg-primary/10 text-primary',
        'explore' => 'bg-secondary/10 text-secondary',
    ];
@endphp

@if($hasCards)
<div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
    <h3 class="font-heading text-lg font-semibold text-on-surface flex items-center gap-2 mb-4">
        <span aria-hidden="true" class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">military_tech</span>
        {{ __('profile.dashboard_story_heading') }}
    </h3>

    {{-- Horizontal scrollable milestone cards --}}
    <div class="flex gap-3 overflow-x-auto pb-2 -mx-1 px-1 snap-x snap-mandatory" role="list" aria-label="{{ __('profile.dashboard_story_heading') }}">
        @foreach($milestoneCards as $card)
            @php
                $iconColorStyle = $iconStyles[$card['icon']] ?? 'bg-primary/10 text-primary';
                $earnedAt = isset($card['earned_at']) ? \Carbon\Carbon::parse($card['earned_at']) : null;
                $isNew = $card['is_new'] ?? false;
                $earnedText = $earnedAt ? $earnedAt->diffForHumans(['short' => true]) : null;
            @endphp
            <div class="shrink-0 w-40 sm:w-48 snap-start bg-surface-container-low rounded-xl border border-outline-variant/30 p-4 text-center"
                 role="listitem">
                {{-- Icon --}}
                <div class="w-10 h-10 rounded-full {{ $iconColorStyle }} flex items-center justify-center mx-auto">
                    <span class="material-symbols-outlined text-xl" style="font-variation-settings: 'FILL' 1" aria-hidden="true">{{ $card['icon'] }}</span>
                </div>

                {{-- Title --}}
                <p class="text-xs font-semibold text-on-surface mt-2 leading-tight">
                    {{ __($card['title_key']) }}
                </p>

                {{-- New badge --}}
                @if($isNew)
                    <span class="inline-block text-[9px] font-bold px-2 py-0.5 rounded-full bg-primary text-on-primary mt-1.5 uppercase tracking-wide">
                        {{ __('profile.dashboard_story_new_badge') }}
                    </span>
                @endif

                {{-- Earned date --}}
                @if($earnedText)
                    <p class="text-[10px] text-on-surface-variant mt-1.5">
                        {{ $earnedText }}
                    </p>
                @endif
            </div>
        @endforeach
    </div>
</div>
@endif
