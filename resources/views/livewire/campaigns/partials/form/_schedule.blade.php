{{-- Recurrence, time of day, session duration and the price per session.
     Shared by the create form and the edit mode (D317): included with no argument array, so it
     reads the including view's scope — $errors (the option lists come off the component).
     The edit mode passes $lockedFields (field => true) in the include's data array; the create view
     passes nothing. Every field of this section is Tier 1 (D313): on a locked campaign all four
     render disabled but still show their values (D314). time_of_day is a plain `H:i` varchar on
     campaigns — a wall-clock time with no timezone conversion, unlike a game's date_time. --}}
    @php($lockedFields ??= [])
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label for="campaign-recurrence" class="block text-sm font-medium text-on-surface mb-1">{{ __('campaigns.content_recurrence') }} <span class="text-error">*</span></label>
            <select id="campaign-recurrence" wire:model="recurrence"
                    @disabled(isset($lockedFields['recurrence']))
                    class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors{{ isset($lockedFields['recurrence']) ? ' opacity-60 cursor-not-allowed' : '' }}">
                @foreach($this->recurrenceOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            @error('recurrence') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="campaign-time" class="block text-sm font-medium text-on-surface mb-1">{{ __('common.field_time_of_day') }} <span class="text-error">*</span></label>
            <input type="time" id="campaign-time" wire:model="time_of_day"
                   @disabled(isset($lockedFields['time_of_day']))
                   class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors{{ isset($lockedFields['time_of_day']) ? ' opacity-60 cursor-not-allowed' : '' }}" />
            @error('time_of_day') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label for="campaign-duration" class="block text-sm font-medium text-on-surface mb-1">{{ __('campaigns.content_session_duration_hours') }}</label>
            <input type="number" id="campaign-duration" wire:model="session_duration" step="0.5" min="0.5" max="24" placeholder="3"
                   @disabled(isset($lockedFields['session_duration']))
                   class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors{{ isset($lockedFields['session_duration']) ? ' opacity-60 cursor-not-allowed' : '' }}" />
            @error('session_duration') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="campaign-price" class="block text-sm font-medium text-on-surface mb-1">{{ __('campaigns.field_price_per_session_eur') }}</label>
            <input type="number" id="campaign-price" wire:model="price_per_session" step="0.01" min="0" placeholder="0.00"
                   @disabled(isset($lockedFields['price_per_session']))
                   class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 text-on-surface placeholder:text-on-surface-variant/50 focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors{{ isset($lockedFields['price_per_session']) ? ' opacity-60 cursor-not-allowed' : '' }}" />
            <p class="mt-1 text-xs text-on-surface-variant/60">{{ __('billing.content_amount_in_eur_leave_0_for_free') }}</p>
            @error('price_per_session') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
        </div>
    </div>
