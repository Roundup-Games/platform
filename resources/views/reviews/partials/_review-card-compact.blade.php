@props(['review'])

<div class="py-4 first:pt-0 last:pb-0">
    <div class="flex items-start gap-3">
        {{-- Reviewer info --}}
        <x-user-link :user="$review->reviewer" avatar-size="w-9 h-9" :truncate="true" />

        <div class="flex-1 min-w-0">
            {{-- Star rating + date --}}
            <div class="flex items-center gap-2 flex-wrap">
                <div class="flex items-center gap-0.5" aria-label="{{ $review->rating }} {{ trans_choice('reviews.content_star_count', $review->rating) }}">
                    @for($i = 1; $i <= 5; $i++)
                        <span class="material-symbols-outlined text-sm {{ $i <= $review->rating ? 'text-primary' : 'text-outline/30' }}" style="font-variation-settings: 'FILL' {{ $i <= $review->rating ? 1 : 0 }}" aria-hidden="true">
                            star
                        </span>
                    @endfor
                </div>
                <span class="text-xs text-on-surface-variant">{{ $review->created_at->diffForHumans() }}</span>
            </div>

            {{-- Review body --}}
            @if($review->body)
                <p class="mt-1.5 text-sm text-on-surface whitespace-pre-line">{{ $review->body }}</p>
            @endif

            {{-- Proficiency tags --}}
            @if($review->proficiency_tags && count($review->proficiency_tags))
                <div class="mt-2 flex flex-wrap gap-1.5">
                    @foreach($review->proficiency_tags as $tag)
                        @php($enum = \App\Enums\GmProficiency::tryFrom($tag))
                        @if($enum)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                                {{ $enum->label() }}
                            </span>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
