@php($section = $content['logistics'] ?? [])
@php($venueAccessNotes = (string) ($section['venue_access_notes'] ?? ''))
@php($travelNotes = (string) ($section['travel_notes'] ?? ''))
@php($attendanceNote = (string) ($section['attendance_note'] ?? ''))
@php($sessionLengthOther = (string) ($section['session_length_other'] ?? ''))
@php($commsOther = (string) ($section['comms_other'] ?? ''))

<section id="charter-section-logistics" class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 sm:p-8 scroll-mt-24">
    <div class="flex items-center gap-2 mb-6">
        <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">map</span>
        <h3 class="font-heading text-lg font-semibold text-on-surface">{{ __('charter.section_logistics_title') }}</h3>
    </div>

    <div class="space-y-6">
        {{-- Venue & location --}}
        @if(($section['venue_mode'] ?? '') !== '')
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_logistics_venue_mode_label') }}</p>
                <p class="mt-1 text-sm text-on-surface">{{ __('charter.label_venue_mode_'.$section['venue_mode']) }}</p>
                @if($section['venue_mode'] === 'location' && $location)
                    <p class="mt-1 text-sm text-on-surface-variant">
                        <span class="material-symbols-outlined text-xs align-middle" aria-hidden="true">place</span>
                        {{ $location->name }}@if($location->city) — {{ $location->city }}@endif
                    </p>
                @endif
            </div>
        @endif

        @if($venueAccessNotes !== '')
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_logistics_venue_access_notes_label') }}</p>
                @include('livewire.charter.sections._prose', ['value' => $venueAccessNotes])
            </div>
        @endif

        @if($travelNotes !== '')
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_logistics_travel_notes_label') }}</p>
                @include('livewire.charter.sections._prose', ['value' => $travelNotes])
            </div>
        @endif

        {{-- Rhythm: cadence, length, food --}}
        <div class="grid sm:grid-cols-2 gap-x-8 gap-y-4">
            @if(($section['cadence'] ?? '') !== '')
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_logistics_cadence_label') }}</p>
                    <p class="mt-1 text-sm text-on-surface">{{ __('charter.label_cadence_'.$section['cadence']) }}</p>
                </div>
            @endif
            @if(($section['session_length'] ?? '') !== '')
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_logistics_session_length_label') }}</p>
                    <p class="mt-1 text-sm text-on-surface">{{ __('charter.label_session_length_'.$section['session_length']) }}</p>
                </div>
            @endif
            @if(($section['food_drink'] ?? '') !== '')
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_logistics_food_drink_label') }}</p>
                    <p class="mt-1 text-sm text-on-surface">{{ __('charter.label_food_drink_'.$section['food_drink']) }}</p>
                </div>
            @endif
            @if(isset($section['max_missed_sessions']) && $section['max_missed_sessions'] !== null)
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_logistics_max_missed_sessions_label') }}</p>
                    <p class="mt-1 text-sm text-on-surface">{{ (int) $section['max_missed_sessions'] }}</p>
                </div>
            @endif
        </div>

        @if($sessionLengthOther !== '')
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_logistics_session_length_other_label') }}</p>
                @include('livewire.charter.sections._prose', ['value' => $sessionLengthOther])
            </div>
        @endif

        @if($attendanceNote !== '')
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_logistics_attendance_note_label') }}</p>
                @include('livewire.charter.sections._prose', ['value' => $attendanceNote])
            </div>
        @endif

        {{-- Attendance expectations --}}
        <div class="grid sm:grid-cols-2 gap-x-8 gap-y-4">
            @if(($section['absence_notice'] ?? '') !== '')
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_logistics_absence_notice_label') }}</p>
                    <p class="mt-1 text-sm text-on-surface">{{ __('charter.label_absence_notice_'.$section['absence_notice']) }}</p>
                </div>
            @endif
            @if(($section['missing_player'] ?? '') !== '')
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_logistics_missing_player_label') }}</p>
                    <p class="mt-1 text-sm text-on-surface">{{ __('charter.label_missing_player_'.$section['missing_player']) }}</p>
                </div>
            @endif
        </div>

        {{-- Communication channel (the one deliberate digital layer) --}}
        @if(! empty($section['comms']))
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_logistics_comms_label') }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach($section['comms'] as $channel)
                        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-primary/10 text-primary text-xs font-medium">
                            <span class="material-symbols-outlined text-xs" aria-hidden="true">forum</span>
                            {{ __('charter.label_comms_'.$channel) }}
                        </span>
                    @endforeach
                </div>
                @if($commsOther !== '')
                    <p class="mt-2 text-sm text-on-surface-variant">{{ $commsOther }}</p>
                @endif
            </div>
        @endif
    </div>
</section>
