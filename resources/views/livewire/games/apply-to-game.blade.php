<div>
    {{-- Back link --}}
    <div class="bg-surface-container-low border-b border-outline-variant">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 py-3">
            <a href="{{ route('games.detail', $game->id) }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-on-surface transition-colors">
                <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_back</span>
                {{ __('Back to Game') }}
            </a>
        </div>
    </div>

    {{-- Game Header / Banner --}}
    <section class="bg-gradient-to-br from-primary to-primary-container text-on-primary">
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
                        {{ __('You are already a participant of this game.') }}
                    @else
                        {{ __('You have already applied to this game.') }}
                    @endif
                </p>
                <a href="{{ route('games.detail', $game->id) }}" wire:navigate class="mt-4 inline-flex items-center gap-2 px-5 py-2.5 bg-gradient-to-r from-primary to-primary-container text-on-primary text-sm font-medium rounded-lg shadow-ambient hover:opacity-90 transition-opacity">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">visibility</span>
                    {{ __('View Game') }}
                </a>
            </div>
        @else
            {{-- Application Form --}}
            <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">
                        @if($game->visibility === 'public')
                            login
                        @else
                            edit_note
                        @endif
                    </span>
                    @if($game->visibility === 'public')
                        {{ __('Join Game') }}
                    @else
                        {{ __('Apply to Join') }}
                    @endif
                </h2>

                @if($game->visibility === 'protected')
                    <p class="mb-4 text-sm text-on-surface-variant">
                        {{ __('This is a protected game. Your application will be reviewed by the game owner before you can join.') }}
                    </p>
                @endif

                <form wire:submit="submitApplication" class="space-y-4">
                    <div>
                        <label for="message" class="block text-sm font-medium text-on-surface mb-1">
                            {{ __('Message to the host') }} <span class="text-on-surface-variant">{{ __('(optional)') }}</span>
                        </label>
                        <textarea wire:model="message" id="message" rows="4"
                            placeholder="{{ __("Tell the host why you'd like to join...") }}"
                            class="block w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-sm transition-colors"
                            data-testid="application-message"></textarea>
                        @error('message')
                            <p class="mt-1 text-sm text-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <button type="submit"
                        class="inline-flex items-center gap-2 px-6 py-2.5 bg-gradient-to-r from-primary to-primary-container text-on-primary text-sm font-medium rounded-lg shadow-ambient hover:opacity-90 transition-opacity">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">send</span>
                        @if($game->visibility === 'public')
                            {{ __('Join Game') }}
                        @else
                            {{ __('Submit Application') }}
                        @endif
                    </button>
                </form>
            </section>
        @endif
    </div>
</div>
