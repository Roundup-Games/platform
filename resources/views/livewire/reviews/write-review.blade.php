<div>
    {{-- Back link --}}
    <div class="bg-surface-container-low border-b border-outline-variant">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 py-3">
            @if($reviewableType === 'game' || $reviewableType === 'App\\Models\\Game')
                <a href="{{ route('games.show', $reviewableId) }}" wire:navigate
                   class="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-on-surface transition-colors">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_back</span>
                    {{ __('reviews.action_back_to_game') }}
                </a>
            @else
                <a href="{{ route('campaigns.show', $reviewableId) }}" wire:navigate
                   class="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-on-surface transition-colors">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_back</span>
                    {{ __('reviews.action_back_to_campaign') }}
                </a>
            @endif
        </div>
    </div>

    <div class="max-w-2xl mx-auto px-4 sm:px-6 py-8 bg-surface">
        @if($errorMessage)
            <div class="rounded-xl bg-error-container p-6 text-center" role="alert">
                <span class="material-symbols-outlined text-3xl text-on-error-container mb-2" aria-hidden="true">error</span>
                <p class="text-on-error-container font-medium">{{ $errorMessage }}</p>
                @if($reviewableType === 'game' || $reviewableType === 'App\\Models\\Game')
                    <a href="{{ route('games.show', $reviewableId) }}" wire:navigate
                       class="mt-4 inline-flex items-center gap-1 text-sm text-on-error-container underline hover:no-underline">
                        {{ __('reviews.action_go_back') }}
                    </a>
                @else
                    <a href="{{ route('campaigns.show', $reviewableId) }}" wire:navigate
                       class="mt-4 inline-flex items-center gap-1 text-sm text-on-error-container underline hover:no-underline">
                        {{ __('reviews.action_go_back') }}
                    </a>
                @endif
            </div>
        @else
            <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface mb-1">
                {{ __('reviews.title_write_review') }}
            </h1>
            @if($reviewableName)
                <p class="text-sm text-on-surface-variant mb-6">
                    {{ __('reviews.content_for_name', ['name' => $reviewableName]) }}
                </p>
            @endif

            <form wire:submit="submit" class="space-y-6">
                {{-- Star Rating --}}
                <div>
                    <label class="block text-sm font-medium text-on-surface mb-2">
                        {{ __('reviews.label_rating') }} <span class="text-error">*</span>
                    </label>
                    <div class="flex items-center gap-1" role="radiogroup" aria-label="{{ __('reviews.label_rating') }}">
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

                {{-- Review Text --}}
                <div>
                    <label for="review-body" class="block text-sm font-medium text-on-surface mb-2">
                        {{ __('reviews.label_your_review') }}
                    </label>
                    <textarea id="review-body"
                              wire:model="body"
                              rows="4"
                              maxlength="2000"
                              class="w-full rounded-lg border border-outline bg-surface-container-low text-on-surface px-4 py-3 text-sm focus:outline-hidden focus:ring-2 focus:ring-primary focus:border-primary transition-colors resize-y"
                              placeholder="{{ __('reviews.placeholder_tell_us_about_experience') }}"></textarea>
                    <div class="flex justify-between mt-1">
                        @error('body')
                            <p class="text-sm text-error">{{ $message }}</p>
                        @enderror
                        <p class="text-xs text-on-surface-variant ml-auto">{{ strlen($body) }}/2000</p>
                    </div>
                </div>

                {{-- Proficiency Tags --}}
                <div>
                    <label class="block text-sm font-medium text-on-surface mb-2">
                        {{ __('reviews.label_gm_strengths') }}
                        <span class="text-xs font-normal text-on-surface-variant ml-1">
                            ({{ __('reviews.content_max_3') }})
                        </span>
                    </label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        @foreach($proficiencies as $proficiency)
                            <label class="flex items-center gap-3 px-3 py-2 rounded-lg border border-outline/50 hover:border-primary/50 transition-colors cursor-pointer
                                {{ in_array($proficiency->value, $proficiency_tags) ? 'bg-primary/5 border-primary/50' : 'bg-surface-container-low' }}">
                                <input type="checkbox"
                                       wire:change="toggleTag('{{ $proficiency->value }}')"
                                       {{ in_array($proficiency->value, $proficiency_tags) ? 'checked' : '' }}
                                       class="rounded-sm border-outline text-primary focus:ring-primary">
                                <div>
                                    <span class="text-sm font-medium text-on-surface">{{ $proficiency->label() }}</span>
                                    <span class="block text-xs text-on-surface-variant">{{ $proficiency->description() }}</span>
                                </div>
                            </label>
                        @endforeach
                    </div>
                    @error('proficiency_tags')
                        <p class="mt-1 text-sm text-error">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Submit --}}
                <div class="flex items-center gap-4 pt-2">
                    <button type="submit"
                            wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 px-6 py-2.5 bg-primary text-on-primary text-sm font-medium rounded-lg shadow-ambient hover:opacity-90 transition-opacity disabled:opacity-50">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">send</span>
                        {{ __('reviews.action_submit_review') }}
                    </button>
                    <span wire:loading class="text-sm text-on-surface-variant">
                        {{ __('reviews.content_submitting') }}
                    </span>
                </div>
            </form>
        @endif
    </div>
</div>
