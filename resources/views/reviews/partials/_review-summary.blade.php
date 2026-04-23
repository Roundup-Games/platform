@props(['gmProfile'])

<div class="bg-surface-container-low rounded-xl shadow-ambient p-6">
    <h3 class="text-lg font-heading font-bold tracking-tight text-on-surface flex items-center gap-2 mb-4">
        <span class="material-symbols-outlined text-xl" aria-hidden="true">rate_review</span>
        {{ __('reviews.title_gm_reviews') }}
    </h3>

    @if($gmProfile->review_count > 0)
        <div class="flex items-center gap-4 mb-4">
            {{-- Average rating --}}
            <div class="flex items-center gap-2">
                <span class="text-2xl font-heading font-bold text-on-surface">{{ number_format($gmProfile->average_rating, 1) }}</span>
                <div>
                    <div class="flex items-center gap-0.5">
                        @for($i = 1; $i <= 5; $i++)
                            @php($filled = $i <= round($gmProfile->average_rating))
                            <span class="material-symbols-outlined text-sm {{ $filled ? 'text-primary' : 'text-outline/30' }}" aria-hidden="true">
                                {{ $filled ? 'star' : 'star_border' }}
                            </span>
                        @endfor
                    </div>
                    <p class="text-xs text-on-surface-variant">
                        {{ trans_choice('reviews.content_review_count', $gmProfile->review_count) }}
                    </p>
                </div>
            </div>
        </div>

        {{-- Top proficiency badges --}}
        @php($topBadges = $gmProfile->topProficiencies())
        @if($topBadges->count())
            <div class="flex flex-wrap gap-2 mb-4">
                @foreach($topBadges as $badge)
                    @php($enum = \App\Enums\GmProficiency::tryFrom($badge['name']))
                    @if($enum)
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                            <span class="material-symbols-outlined text-sm" aria-hidden="true">verified</span>
                            {{ $enum->label() }}
                            <span class="text-primary/60">({{ $badge['count'] }})</span>
                        </span>
                    @endif
                @endforeach
            </div>
        @endif
    @else
        <p class="text-sm text-on-surface-variant italic">{{ __('reviews.content_no_reviews_yet') }}</p>
    @endif
</div>
