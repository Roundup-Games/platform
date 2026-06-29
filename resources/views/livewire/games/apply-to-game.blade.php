@use(App\Enums\Visibility)

<div>
    {{-- Back link --}}
    <div class="bg-surface-container-low border-b border-outline-variant">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 py-3">
            <a href="{{ route('games.show', $game->id) }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-on-surface transition-colors">
                <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_back</span>
                {{ __('games.action_back_to_game') }}
            </a>
        </div>
    </div>

    {{-- Game Header / Banner --}}
    <section class="bg-primary text-on-primary">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 py-10 sm:py-14">
            <h1 class="text-3xl sm:text-4xl font-heading font-bold tracking-tight">{{ $game->name }}</h1>
            @if($game->gameSystem)
                <p class="mt-2 text-sm text-on-primary/80">{{ $game->gameSystem?->name }}</p>
            @endif
        </div>
    </section>

    {{-- Content --}}
    <div class="max-w-4xl mx-auto px-4 sm:px-6 py-8 bg-surface space-y-6">

        {{-- Flash Messages --}}
        @if(session()->has('info'))
            <div class="rounded-xl bg-primary/10 p-4 flex items-center gap-3" role="status" aria-live="polite">
                <span class="material-symbols-outlined text-primary" aria-hidden="true">info</span>
                <p class="text-sm text-on-surface">{{ session('info') }}</p>
            </div>
        @endif

        {{-- Already participant or applied --}}
        @if($isParticipant || $hasExistingApplication)
            <div class="bg-surface-container-low rounded-xl shadow-ambient p-6 text-center">
                <span class="material-symbols-outlined text-4xl text-secondary mb-3" aria-hidden="true">check_circle</span>
                <p class="text-on-surface">
                    @if($isParticipant)
                        {{ __('games.content_you_are_already_a_participant_of_this_game') }}
                    @else
                        {{ __('games.content_you_have_already_applied_to_this_game') }}
                    @endif
                </p>
                <a href="{{ route('games.show', $game->id) }}" wire:navigate class="mt-4 inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-on-primary text-sm font-medium rounded-lg shadow-ambient hover:opacity-90 transition-opacity">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">visibility</span>
                    {{ __('games.action_view_game') }}
                </a>
            </div>
        @else
            {{-- Application Form --}}
            <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">
                        @if($game->visibility === Visibility::Public)
                            login
                        @else
                            edit_note
                        @endif
                    </span>
                    @if($game->visibility === Visibility::Public)
                        {{ __('games.action_join_game') }}
                    @else
                        {{ __('games.action_apply_to_join') }}
                    @endif
                </h2>

                @if($game->visibility === Visibility::Protected)
                    <p class="mb-4 text-sm text-on-surface-variant">
                        {{ __('games.content_this_is_a_protected_game') }}
                    </p>
                @endif

                <form wire:submit="submitApplication" class="space-y-4">
                    <div>
                        <label for="message" class="block text-sm font-medium text-on-surface mb-1">
                            {{ __('common.content_message_to_the_host') }} <span class="text-on-surface-variant">{{ __('common.content_optional') }}</span>
                        </label>
                        <textarea wire:model="message" id="message" rows="4" wire:loading.attr="disabled" wire:target="submitApplication"
                            placeholder="{{ __('common.content_tell_the_host_why_you_d_like_to_join') }}"
                            class="block w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-sm transition-colors disabled:opacity-60"
                            data-testid="application-message"></textarea>
                        @error('message')
                            <p class="mt-1 text-sm text-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <button type="submit" wire:target="submitApplication" wire:loading.attr="disabled"
                        class="inline-flex items-center gap-2 px-6 py-2.5 bg-primary text-on-primary text-sm font-medium rounded-lg shadow-ambient hover:opacity-90 transition-opacity disabled:opacity-60 disabled:cursor-not-allowed whitespace-nowrap">
                        {{-- A single, stable label element is never removed by wire:loading, so the submit
                            trigger stays in the DOM across the request and Livewire's navigate redirect
                            fires reliably. The spinner is a sibling that toggles visibility only; it never
                            replaces the label, which avoids both the two-line wrap and the dropped
                            redirect that swapping the trigger can cause (M054/S01 regression). --}}
                        <span class="inline-flex items-center gap-2">
                            <span wire:loading.remove wire:target="submitApplication" class="material-symbols-outlined text-base" aria-hidden="true">send</span>
                            <span wire:loading wire:target="submitApplication" class="animate-spin h-4 w-4" aria-hidden="true" role="status" aria-label="{{ __('common.action_submitting') }}">
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            </span>
                            @if($game->visibility === Visibility::Public)
                                {{ __('games.action_join_game') }}
                            @else
                                {{ __('common.action_submit_application') }}
                            @endif
                        </span>
                    </button>
                </form>
            </section>
        @endif
    </div>
</div>
