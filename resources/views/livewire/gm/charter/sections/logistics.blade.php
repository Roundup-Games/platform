@php($section = $content['logistics'] ?? [])

@php($choiceFields = [
    'food_drink' => ['venue_rules', 'snack_rotation', 'byo', 'group_orders'],
    'cadence' => ['weekly', 'biweekly', 'monthly', 'irregular'],
    'session_length' => ['2h', '3h', '4h', 'other'],
    'absence_notice' => ['asap', '24h', '48h'],
    'missing_player' => ['skip_session', 'pc_as_npc', 'side_oneshot'],
])

<div class="space-y-6">
    {{-- Venue mode --}}
    <div>
        <p class="text-sm font-medium text-on-surface mb-1">{{ __('charter.field_logistics_venue_mode_label') }}</p>
        <p class="text-xs text-on-surface-variant mb-3">{{ __('charter.field_logistics_venue_mode_help') }}</p>
        <div class="grid sm:grid-cols-2 gap-2">
            @foreach(['location', 'rotate'] as $mode)
                <label class="flex items-center gap-2 px-3 py-2.5 rounded-lg border transition-colors cursor-pointer {{ ($section['venue_mode'] ?? '') === $mode ? 'border-primary/40 bg-primary/5' : 'border-outline/20 bg-surface-container-high hover:border-outline/40' }}">
                    <input
                        type="radio"
                        name="charter-logistics-venue-mode"
                        value="{{ $mode }}"
                        wire:model="content.logistics.venue_mode"
                        @checked(($section['venue_mode'] ?? '') === $mode)
                        class="rounded-full border-outline/40 text-primary focus:ring-primary/20"
                    />
                    <span class="text-sm text-on-surface">{{ __('charter.label_venue_mode_'.$mode) }}</span>
                </label>
            @endforeach
        </div>
        @error('content.logistics.venue_mode')
            <p class="mt-1 text-xs text-error">{{ $message }}</p>
        @enderror
    </div>

    {{-- Play location (fixed venue) --}}
    @if(($section['venue_mode'] ?? '') === 'location')
        <div>
            <label for="charter-logistics-location" class="block text-sm font-medium text-on-surface mb-1">
                {{ __('charter.field_logistics_location_label') }}
            </label>
            <select
                id="charter-logistics-location"
                wire:model="content.logistics.location_id"
                class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors px-4 py-2.5"
            >
                <option value="">{{ __('charter.placeholder_choose_option') }}</option>
                @foreach($locations as $location)
                    <option value="{{ $location->id }}" @selected(($section['location_id'] ?? null) !== null && (string) $section['location_id'] === (string) $location->id)>
                        {{ $location->name }}@if($location->city) — {{ $location->city }}@endif
                    </option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-on-surface-variant">{{ __('charter.field_logistics_location_help') }}</p>
            @error('content.logistics.location_id')
                <p class="mt-1 text-xs text-error">{{ $message }}</p>
            @enderror
        </div>
    @endif

    {{-- Venue accessibility notes --}}
    <div>
        <label for="charter-logistics-access-notes" class="block text-sm font-medium text-on-surface mb-1">
            {{ __('charter.field_logistics_venue_access_notes_label') }}
        </label>
        <textarea
            id="charter-logistics-access-notes"
            wire:model="content.logistics.venue_access_notes"
            rows="2"
            placeholder="{{ __('charter.field_logistics_venue_access_notes_placeholder') }}"
            class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm placeholder:text-on-surface-variant focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors resize-y px-4 py-2.5"
        >{{ $section['venue_access_notes'] ?? '' }}</textarea>
        <p class="mt-1 text-xs text-on-surface-variant">{{ __('charter.field_logistics_venue_access_notes_help') }}</p>
        @error('content.logistics.venue_access_notes')
            <p class="mt-1 text-xs text-error">{{ $message }}</p>
        @enderror
    </div>

    {{-- Enumerated logistics selects --}}
    <div class="grid sm:grid-cols-2 gap-5">
        @foreach($choiceFields as $field => $choices)
            <div>
                <label for="charter-logistics-{{ $field }}" class="block text-sm font-medium text-on-surface mb-1">
                    {{ __('charter.field_logistics_'.$field.'_label') }}
                </label>
                <select
                    id="charter-logistics-{{ $field }}"
                    wire:model="content.logistics.{{ $field }}"
                    class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors px-4 py-2.5"
                >
                    <option value="">{{ __('charter.placeholder_choose_option') }}</option>
                    @foreach($choices as $choice)
                        <option value="{{ $choice }}" @selected(($section[$field] ?? '') === $choice)>
                            {{ __('charter.label_'.$field.'_'.$choice) }}
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-on-surface-variant">{{ __('charter.field_logistics_'.$field.'_help') }}</p>
                @error('content.logistics.'.$field)
                    <p class="mt-1 text-xs text-error">{{ $message }}</p>
                @enderror
            </div>
        @endforeach
    </div>

    {{-- Other session length --}}
    @if(($section['session_length'] ?? '') === 'other')
        <div>
            <label for="charter-logistics-session-length-other" class="block text-sm font-medium text-on-surface mb-1">
                {{ __('charter.field_logistics_session_length_other_label') }}
            </label>
            <input
                id="charter-logistics-session-length-other"
                type="text"
                wire:model="content.logistics.session_length_other"
                placeholder="{{ __('charter.field_logistics_session_length_other_placeholder') }}"
                class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm placeholder:text-on-surface-variant focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors px-4 py-2.5"
                value="{{ $section['session_length_other'] ?? '' }}"
            />
            <p class="mt-1 text-xs text-on-surface-variant">{{ __('charter.field_logistics_session_length_other_help') }}</p>
            @error('content.logistics.session_length_other')
                <p class="mt-1 text-xs text-error">{{ $message }}</p>
            @enderror
        </div>
    @endif

    {{-- Travel notes --}}
    <div>
        <label for="charter-logistics-travel-notes" class="block text-sm font-medium text-on-surface mb-1">
            {{ __('charter.field_logistics_travel_notes_label') }}
        </label>
        <textarea
            id="charter-logistics-travel-notes"
            wire:model="content.logistics.travel_notes"
            rows="2"
            placeholder="{{ __('charter.field_logistics_travel_notes_placeholder') }}"
            class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm placeholder:text-on-surface-variant focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors resize-y px-4 py-2.5"
        >{{ $section['travel_notes'] ?? '' }}</textarea>
        <p class="mt-1 text-xs text-on-surface-variant">{{ __('charter.field_logistics_travel_notes_help') }}</p>
        @error('content.logistics.travel_notes')
            <p class="mt-1 text-xs text-error">{{ $message }}</p>
        @enderror
    </div>

    {{-- Attendance expectation + missed-session limit --}}
    <div>
        <label for="charter-logistics-attendance-note" class="block text-sm font-medium text-on-surface mb-1">
            {{ __('charter.field_logistics_attendance_note_label') }}
        </label>
        <textarea
            id="charter-logistics-attendance-note"
            wire:model="content.logistics.attendance_note"
            rows="2"
            placeholder="{{ __('charter.field_logistics_attendance_note_placeholder') }}"
            class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm placeholder:text-on-surface-variant focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors resize-y px-4 py-2.5"
        >{{ $section['attendance_note'] ?? '' }}</textarea>
        <p class="mt-1 text-xs text-on-surface-variant">{{ __('charter.field_logistics_attendance_note_help') }}</p>
        @error('content.logistics.attendance_note')
            <p class="mt-1 text-xs text-error">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="charter-logistics-max-missed" class="block text-sm font-medium text-on-surface mb-1">
            {{ __('charter.field_logistics_max_missed_sessions_label') }}
        </label>
        <input
            id="charter-logistics-max-missed"
            type="number"
            min="1"
            max="52"
            wire:model="content.logistics.max_missed_sessions"
            class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors px-4 py-2.5"
            value="{{ $section['max_missed_sessions'] ?? '' }}"
        />
        <p class="mt-1 text-xs text-on-surface-variant">{{ __('charter.field_logistics_max_missed_sessions_help') }}</p>
        @error('content.logistics.max_missed_sessions')
            <p class="mt-1 text-xs text-error">{{ $message }}</p>
        @enderror
    </div>

    {{-- Comms: the one deliberate digital field (R086) --}}
    <div>
        <p class="text-sm font-medium text-on-surface mb-1">{{ __('charter.field_logistics_comms_label') }}</p>
        <p class="text-xs text-on-surface-variant mb-3">{{ __('charter.field_logistics_comms_help') }}</p>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
            @foreach(\App\Dto\CharterContent::COMMS as $channel)
                <label class="flex items-center gap-2 px-3 py-2 rounded-lg border transition-colors cursor-pointer {{ in_array($channel, $section['comms'] ?? []) ? 'border-primary/40 bg-primary/5' : 'border-outline/20 bg-surface-container-high hover:border-outline/40' }}">
                    <input
                        type="checkbox"
                        value="{{ $channel }}"
                        wire:model="content.logistics.comms"
                        @checked(in_array($channel, $section['comms'] ?? []))
                        class="rounded-sm border-outline/40 text-primary focus:ring-primary/20"
                    />
                    <span class="text-sm text-on-surface">{{ __('charter.label_comms_'.$channel) }}</span>
                </label>
            @endforeach
        </div>
        @error('content.logistics.comms')
            <p class="mt-1 text-xs text-error">{{ $message }}</p>
        @enderror

        @if(in_array('other', $section['comms'] ?? []))
            <div class="mt-3">
                <label for="charter-logistics-comms-other" class="block text-sm font-medium text-on-surface mb-1">
                    {{ __('charter.field_logistics_comms_other_label') }}
                </label>
                <input
                    id="charter-logistics-comms-other"
                    type="text"
                    wire:model="content.logistics.comms_other"
                    placeholder="{{ __('charter.field_logistics_comms_other_placeholder') }}"
                    class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm placeholder:text-on-surface-variant focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors px-4 py-2.5"
                    value="{{ $section['comms_other'] ?? '' }}"
                />
                <p class="mt-1 text-xs text-on-surface-variant">{{ __('charter.field_logistics_comms_other_help') }}</p>
                @error('content.logistics.comms_other')
                    <p class="mt-1 text-xs text-error">{{ $message }}</p>
                @enderror
            </div>
        @endif
    </div>
</div>
