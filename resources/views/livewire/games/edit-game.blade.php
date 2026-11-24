<div class="py-6 sm:py-8">
    <div class="max-w-3xl mx-auto">
        {{-- Header --}}
        <div class="mb-6 sm:mb-8">
            <div class="flex items-center gap-3 mb-1">
                <span class="material-symbols-outlined text-xl text-on-surface-variant" aria-hidden="true">edit</span>
                <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('games.heading_edit_game') }}</h1>
            </div>
            @php($currentCoverUrl = $game->getFirstMediaUrl('cover'))
            @if($currentCoverUrl !== '')
                <div class="ml-9 flex items-center gap-3">
                    <img src="{{ $currentCoverUrl }}" alt="" class="h-16 w-16 object-cover rounded-lg border border-outline-variant">
                    <p class="text-sm text-on-surface-variant">{{ __('games.content_current_cover_image') }}</p>
                </div>
            @endif
        </div>

        {{-- The service's lock refusal lands under `edit` --}}
        @error('edit')
            <div class="mb-6 rounded-xl bg-error-container p-4 flex items-center gap-3" role="alert" aria-live="polite">
                <span class="material-symbols-outlined text-on-error-container" aria-hidden="true">error</span>
                <p class="text-sm text-on-error-container">{{ $message }}</p>
            </div>
        @enderror

        {{-- Agreement lock (D314): WHY the Tier-1 fields below are read-only --}}
        @if($lockedAt !== null)
            <div class="mb-6 rounded-xl bg-surface-container-high border border-outline-variant/40 p-4 flex items-start gap-3" role="status">
                <span class="material-symbols-outlined text-on-surface-variant shrink-0" aria-hidden="true">lock</span>
                <p class="text-sm text-on-surface">{{ __('games.content_agreement_locked', ['date' => format_date($lockedAt, 'datetime')]) }}</p>
            </div>
        @endif

        <form wire:submit="save" class="space-y-6" id="game-edit-form" tabindex="-1">

            {{-- Every field lives in the create form's partials (D315: one field markup). The
                 edit mode passes the lock state through the include's data array; the create
                 view passes nothing. Game type is NOT switchable here — deliberately: a type
                 change re-shapes the whole form (cancel + recreate instead). --}}

            {{-- Section 1: Game Details --}}
            <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                <x-form-section-header :number="1" :icon="'edit_note'" :title="__('games.heading_game_details')" />

                <div class="space-y-4">
                    @include('livewire.games.partials.form._identity')

                    @include('livewire.games.partials.form._basics', ['lockedFields' => $lockedFields])
                </div>
            </section>

            {{-- Section 2: Players & Duration --}}
            <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                <x-form-section-header :number="2" :icon="'group'" :title="__('location.field_players_capacity')" />

                <div class="space-y-4">
                    @include('livewire.games.partials.form._table', ['lockedFields' => $lockedFields])
                </div>
            </section>

            {{-- Section 3: Location --}}
            <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                <x-form-section-header :number="3" :icon="'location_on'" :title="__('common.field_location')" />

                <div>
                    @include('livewire.games.partials.form._venue')
                </div>
            </section>

            {{-- Section 4: Session Meta (TYPE-CONDITIONAL) --}}
            <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                <x-form-section-header :number="4" :icon="'tune'" :title="__('campaigns.content_discovery_session_meta')" />

                <div class="space-y-5">
                    @include('livewire.games.partials.form._hosting')
                </div>
            </section>

            {{-- Section 5: Visibility --}}
            <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-5 sm:p-6">
                <x-form-section-header :number="5" :icon="'visibility'" :title="__('common.content_visibility')" />

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @include('livewire.games.partials.form._settings', [
                        'lockedFields' => $lockedFields,
                        'editMode' => true,
                        'privateVisibilityLocked' => $privateVisibilityLocked,
                    ])
                </div>
            </section>

            {{-- Actions --}}
            <div class="flex items-center gap-4 pt-2">
                <button type="submit" wire:loading.attr="disabled" wire:loading.attr="aria-busy=true"
                        class="inline-flex items-center gap-2 px-6 py-2.5 bg-primary text-on-primary rounded-lg hover:brightness-110 active:scale-[0.96] transition-all text-sm font-medium shadow-ambient whitespace-nowrap">
                    <span class="inline-flex items-center gap-2">
                        <span class="material-symbols-outlined text-base" wire:loading.remove wire:target="save" aria-hidden="true">check_circle</span>
                        <span class="material-symbols-outlined text-base animate-spin" wire:loading wire:target="save" aria-hidden="true" role="status" aria-label="{{ __('common.content_saving') }}">progress_activity</span>
                        {{ __('games.action_save_game_changes') }}
                    </span>
                </button>
                @if($cover_image)
                    {{-- Cover is Tier 3 (D313): it can be stored on its own, also on a locked game --}}
                    <button type="button" wire:click="saveCover" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 px-4 py-2.5 bg-secondary-container text-on-secondary-container rounded-lg hover:brightness-110 active:scale-[0.96] transition-all text-sm font-medium whitespace-nowrap">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">image</span>
                        {{ __('games.action_save_cover_image') }}
                    </button>
                @endif
                <button type="button" wire:click="cancel"
                        class="px-4 py-2.5 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
                    {{ __('common.action_cancel') }}
                </button>
            </div>
        </form>
    </div>
</div>
