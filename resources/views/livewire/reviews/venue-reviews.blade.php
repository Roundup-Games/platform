@php use Illuminate\Support\Facades\Auth; @endphp

<div>
    {{-- Aggregate summary: shows the venue average + count, mirroring the GM
         _review-summary pattern. Renders for everyone (public display). --}}
    @if($location->review_count > 0)
        <div class="flex items-center gap-4 mb-6">
            <div class="flex items-center gap-2">
                <span class="text-2xl font-heading font-bold text-on-surface">{{ number_format($location->average_rating, 1) }}</span>
                <div>
                    <div class="flex items-center gap-0.5">
                        @for($i = 1; $i <= 5; $i++)
                            @php($filled = $i <= round((float) $location->average_rating))
                            <span class="material-symbols-outlined text-sm {{ $filled ? 'text-primary' : 'text-outline/30' }}" style="font-variation-settings: 'FILL' {{ $filled ? 1 : 0 }}" aria-hidden="true">
                                star
                            </span>
                        @endfor
                    </div>
                    <p class="text-xs text-on-surface-variant">
                        {{ trans_choice('venue.label_reviews_count', $location->review_count) }}
                    </p>
                </div>
            </div>
        </div>
    @endif

    {{-- Flash success --}}
    @if(session('success'))
        <div class="mb-4 rounded-lg bg-primary-container px-4 py-3 text-sm text-on-primary-container" role="status">
            {{ session('success') }}
        </div>
    @endif

    {{-- Component-level error (e.g. not eligible at submit time) --}}
    @if($errorMessage)
        <div class="mb-4 rounded-lg bg-error-container px-4 py-3 text-sm text-on-error-container" role="alert">
            {{ $errorMessage }}
        </div>
    @endif

    {{-- Review list. The shared _review-card partial already renders reviewer,
         rating, body, and the report-review livewire for authenticated
         non-authors (so venue-reported reviews flow into the generic
         report→Escalated-ticket→admin-moderation pipeline unchanged). --}}
    @if($reviews->isNotEmpty())
        <div class="divide-y divide-outline-variant/30">
            @foreach($reviews as $review)
                @include('reviews.partials._review-card', ['review' => $review])
            @endforeach
        </div>
    @else
        <p class="text-sm text-on-surface-variant italic py-4 text-center">{{ __('venue.content_no_reviews') }}</p>
    @endif

    {{-- Write affordance. Attendees see the form; authenticated non-attendees
         see a hint; guests see nothing here (they can still read the list). --}}
    @if($canReview)
        <form wire:submit="submit" class="mt-6 space-y-4 border-t border-outline-variant pt-6">
            <h3 class="text-base font-heading font-semibold text-on-surface">{{ __('venue.action_submit_venue_review') }}</h3>

            {{-- Star rating selector --}}
            <div>
                <span class="block text-sm font-medium text-on-surface mb-2">
                    {{ __('venue.label_your_rating') }} <span class="text-error">*</span>
                </span>
                <div class="flex items-center gap-1" role="radiogroup" aria-label="{{ __('venue.label_your_rating') }}">
                    @for($i = 1; $i <= 5; $i++)
                        <button type="button"
                                wire:click="$set('rating', {{ $i }})"
                                class="focus:outline-hidden focus-visible:ring-2 focus-visible:ring-primary rounded-sm p-0.5 transition-colors"
                                role="radio"
                                aria-checked="{{ $rating === $i ? 'true' : 'false' }}"
                                aria-label="{{ $i }} {{ trans_choice('reviews.content_star_count', $i) }}">
                            <span class="material-symbols-outlined text-3xl {{ $i <= $rating ? 'text-primary' : 'text-outline/30' }}" style="font-variation-settings: 'FILL' {{ $i <= $rating ? 1 : 0 }}" aria-hidden="true">
                                star
                            </span>
                        </button>
                    @endfor
                    @if($rating > 0)
                        <span class="ml-2 text-sm text-on-surface-variant">{{ $rating }}/5</span>
                    @endif
                </div>
                @error('rating')
                    <p class="mt-1 text-sm text-error">{{ $message }}</p>
                @enderror
            </div>

            {{-- Review body --}}
            <div>
                <label for="venue-review-body" class="block text-sm font-medium text-on-surface mb-2">
                    {{ __('reviews.label_your_review') }}
                </label>
                <textarea id="venue-review-body"
                          wire:model="body"
                          rows="4"
                          maxlength="2000"
                          class="w-full rounded-lg border border-outline bg-surface-container-low text-on-surface px-4 py-3 text-sm focus:outline-hidden focus:ring-2 focus:ring-primary focus:border-primary transition-colors resize-y"
                          placeholder="{{ __('venue.placeholder_venue_review') }}"></textarea>
                <div class="flex justify-between mt-1">
                    @error('body')
                        <p class="text-sm text-error">{{ $message }}</p>
                    @enderror
                    <p class="text-xs text-on-surface-variant ml-auto">{{ strlen($body) }}/2000</p>
                </div>
            </div>

            {{-- Submit --}}
            <div class="flex items-center gap-4">
                <button type="submit"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-on-primary text-sm font-medium rounded-lg shadow-ambient hover:opacity-90 transition-opacity disabled:opacity-50">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">send</span>
                    {{ __('venue.action_submit_venue_review') }}
                </button>
                <span wire:loading class="text-sm text-on-surface-variant">{{ __('reviews.content_submitting') }}</span>
            </div>
        </form>
    @elseif(Auth::check())
        {{-- Authenticated but not eligible to review this venue. --}}
        <p class="mt-6 text-sm text-on-surface-variant italic border-t border-outline-variant pt-6">{{ __('venue.content_not_eligible') }}</p>
    @endif
</div>
