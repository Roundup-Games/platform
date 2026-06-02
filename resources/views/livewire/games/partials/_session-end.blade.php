{{-- Session end: host recap, write recap form, attendance reporting, debriefing sections --}}

{{-- Host Recap (completed game with recap) --}}
@if($game->status === \App\Enums\GameStatus::Completed && $game->recap)
    <section class="bg-tertiary/5 border-l-4 border-tertiary rounded-xl shadow-ambient p-6">
        <div class="flex items-center gap-2 mb-4">
            <span class="material-symbols-outlined text-xl text-tertiary" aria-hidden="true">auto_stories</span>
            <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface">{{ __('games.title_host_recap') }}</h2>
        </div>
        <div class="prose prose-sm max-w-none text-on-surface">
            {!! nl2br(e($game->recap)) !!}
        </div>
        @if($game->owner)
            <div class="mt-4 flex items-center gap-2 text-sm text-on-surface-variant">
                <span class="material-symbols-outlined text-base" aria-hidden="true">person</span>
                {{ __('games.content_recap_by', ['host' => $game->owner->name]) }}
            </div>
        @endif
    </section>
@endif

{{-- Write Recap (owner only, completed game, no recap yet) --}}
@auth
    @if($isOwner && $game->status === \App\Enums\GameStatus::Completed && empty($game->recap))
        <section class="bg-tertiary/5 border border-tertiary/20 rounded-xl shadow-ambient p-6">
            <div class="flex items-center gap-2 mb-2">
                <span class="material-symbols-outlined text-xl text-tertiary" aria-hidden="true">edit_note</span>
                <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface">{{ __('games.title_write_recap') }}</h2>
            </div>
            <p class="text-sm text-on-surface-variant mb-4">{{ __('games.content_write_recap_description') }}</p>
            <form wire:submit="writeRecap">
                <div class="mb-3">
                    <textarea
                        id="recap-content"
                        wire:model="recapContent"
                        rows="5"
                        maxlength="2000"
                        class="w-full rounded-lg border border-outline-variant bg-surface-container-low text-on-surface text-sm px-3 py-2 focus:ring-2 focus:ring-primary focus:border-primary"
                        placeholder="{{ __('games.label_recap_placeholder') }}"
                    ></textarea>
                    @error('recapContent')
                        <p class="mt-1 text-sm text-error">{{ $message }}</p>
                    @enderror
                    <div class="flex justify-end mt-1">
                        <span class="text-xs text-on-surface-variant"
                              x-text="'{{ strlen($recapContent ?? '') }}' + '/2000'">
                            {{ strlen($recapContent ?? '') }}/2000
                        </span>
                    </div>
                </div>
                <button type="submit"
                    class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-on-primary text-sm font-medium rounded-lg shadow-ambient hover:opacity-90 transition-opacity">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">publish</span>
                    {{ __('games.action_recap_submit') }}
                </button>
            </form>
        </section>
    @endif
@endauth

{{-- Attendance Reporting (completed games) --}}
@auth
    @if($game->status === \App\Enums\GameStatus::Completed)
        @php
            $viewerId = \Illuminate\Support\Facades\Auth::id();
            $allApproved = $game->participants->where('status', \App\Enums\ParticipantStatus::Approved);
            $hostParticipant = $allApproved->first(fn ($p) => $p->user_id === $game->owner_id);
            $ownParticipant = $allApproved->first(fn ($p) => $p->user_id === $viewerId);

            // For host: all non-host approved participants
            $hostReportable = $isOwner
                ? $allApproved->filter(fn ($p) => $p->user_id !== $game->owner_id)
                : collect();
            $hostHasUnreported = $hostReportable->whereNull('attendance_status')->count() > 0;

            // For participants: everyone except themselves (including the host)
            $participantReportable = !$isOwner && $ownParticipant
                ? $allApproved->filter(fn ($p) => $p->user_id !== $viewerId)
                : collect();
            $participantHasUnreported = $participantReportable->whereNull('attendance_status')->count() > 0;
        @endphp

        {{-- Host attendance reporting: full roster of players --}}
        @if($isOwner && $hostReportable->count() > 0)
            <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                <div class="flex items-center gap-2 mb-4">
                    <span class="material-symbols-outlined text-xl text-on-surface-variant" aria-hidden="true">how_to_reg</span>
                    <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface">{{ __('games.title_attendance_report') }}</h2>
                </div>
                @if($hostHasUnreported)
                    <p class="text-sm text-on-surface-variant mb-4">{{ __('games.content_attendance_report_description') }}</p>
                @else
                    <div class="flex items-center gap-3 mb-4">
                        <span class="material-symbols-outlined text-lg text-on-secondary-container" aria-hidden="true">task_alt</span>
                        <p class="text-sm text-on-surface">{{ __('games.content_attendance_all_reported') }}</p>
                    </div>
                @endif
                <div class="space-y-3">
                    @foreach($hostReportable as $participant)
                        <div class="flex items-center justify-between gap-3 bg-surface rounded-lg p-3">
                            <div class="flex items-center gap-2 min-w-0">
                                <x-user-link :user="$participant->user" avatar-size="w-7 h-7" :truncate="true" />
                                @if($participant->attendance_status)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $participant->attendance_status === \App\Enums\AttendanceStatus::Attended ? 'bg-secondary-container text-on-secondary-container' : 'bg-error/10 text-error' }}">
                                        {{ $participant->attendance_status->label() }}
                                    </span>
                                @endif
                            </div>
                            @if(! $participant->attendance_status)
                                <div class="flex items-center gap-1.5 shrink-0">
                                    <button
                                        wire:click="reportParticipantAttendance('{{ $participant->id }}', 'attended')"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium rounded-lg bg-secondary-container text-on-secondary-container hover:opacity-90 transition-opacity"
                                    >
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">check_circle</span>
                                        {{ __('attendance.status_attended') }}
                                    </button>
                                    <button
                                        wire:click="reportParticipantAttendance('{{ $participant->id }}', 'no_show')"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium rounded-lg bg-error/10 text-error hover:opacity-90 transition-opacity"
                                    >
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">cancel</span>
                                        {{ __('attendance.status_no_show') }}
                                    </button>
                                    <button
                                        wire:click="reportParticipantAttendance('{{ $participant->id }}', 'excused')"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium rounded-lg bg-surface-container-high text-on-surface-variant hover:opacity-90 transition-opacity"
                                    >
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">event_busy</span>
                                        {{ __('attendance.status_excused') }}
                                    </button>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Participant attendance reporting: report others (including host) --}}
        @if(!$isOwner && $ownParticipant && $participantReportable->count() > 0)
            <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                <div class="flex items-center gap-2 mb-4">
                    <span class="material-symbols-outlined text-xl text-on-surface-variant" aria-hidden="true">how_to_reg</span>
                    <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface">{{ __('games.title_attendance_report') }}</h2>
                </div>
                @if($participantHasUnreported)
                    <p class="text-sm text-on-surface-variant mb-4">{{ __('games.content_report_others_description') }}</p>
                @else
                    <div class="flex items-center gap-3 mb-4">
                        <span class="material-symbols-outlined text-lg text-on-secondary-container" aria-hidden="true">task_alt</span>
                        <p class="text-sm text-on-surface">{{ __('games.content_attendance_all_reported') }}</p>
                    </div>
                @endif
                    <div class="space-y-3">
                        @foreach($participantReportable as $participant)
                            <div class="flex items-center justify-between gap-3 bg-surface rounded-lg p-3">
                                <div class="flex items-center gap-2 min-w-0">
                                    @if($participant->user_id === $game->owner_id)
                                        <span class="material-symbols-outlined text-sm text-primary" aria-hidden="true">shield</span>
                                    @endif
                                    <x-user-link :user="$participant->user" avatar-size="w-7 h-7" :truncate="true" />
                                    @if($participant->user_id === $game->owner_id)
                                        <span class="text-xs text-on-surface-variant">({{ __('games.label_host') }})</span>
                                    @endif
                                    @if($participant->attendance_status)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $participant->attendance_status === \App\Enums\AttendanceStatus::Attended ? 'bg-secondary-container text-on-secondary-container' : 'bg-error/10 text-error' }}">
                                            {{ $participant->attendance_status->label() }}
                                        </span>
                                    @endif
                                </div>
                                @if(! $participant->attendance_status)
                                    <div class="flex items-center gap-1.5 shrink-0">
                                        <button
                                            wire:click="reportParticipantAttendance('{{ $participant->id }}', 'attended')"
                                            class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium rounded-lg bg-secondary-container text-on-secondary-container hover:opacity-90 transition-opacity"
                                        >
                                            <span class="material-symbols-outlined text-sm" aria-hidden="true">check_circle</span>
                                            {{ __('attendance.status_attended') }}
                                        </button>
                                        <button
                                            wire:click="reportParticipantAttendance('{{ $participant->id }}', 'no_show')"
                                            class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium rounded-lg bg-error/10 text-error hover:opacity-90 transition-opacity"
                                        >
                                            <span class="material-symbols-outlined text-sm" aria-hidden="true">cancel</span>
                                            {{ __('attendance.status_no_show') }}
                                        </button>
                                        <button
                                            wire:click="reportParticipantAttendance('{{ $participant->id }}', 'excused')"
                                            class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium rounded-lg bg-surface-container-high text-on-surface-variant hover:opacity-90 transition-opacity"
                                        >
                                            <span class="material-symbols-outlined text-sm" aria-hidden="true">event_busy</span>
                                            {{ __('attendance.status_excused') }}
                                        </button>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
            </section>
        @endif
    @endif
@endauth

{{-- Debriefing Section (completed games with debriefing tools) --}}
@if($game->status === \App\Enums\GameStatus::Completed && $hasDebriefingTools)
    {{-- Host: aggregated debriefing dashboard --}}
    @if($isOwner && $hostDebriefings->count() > 0)
        <section class="bg-secondary-container/30 border-l-4 border-secondary rounded-xl shadow-ambient p-6">
            <div class="flex items-center gap-2 mb-4">
                <span class="material-symbols-outlined text-xl text-on-secondary-container" aria-hidden="true">psychology</span>
                <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface">{{ __('games.title_debriefing_responses') }}</h2>
                <span class="ml-auto inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                    {{ $hostDebriefings->count() }}
                </span>
            </div>
            @foreach($hostDebriefings as $debriefing)
                <div class="mb-4 last:mb-0 bg-surface-container-low rounded-lg p-4">
                    <div class="flex items-center gap-2 mb-3">
                        <x-user-link :user="$debriefing->user" avatar-size="w-7 h-7" :truncate="true" />
                        <span class="text-xs text-on-surface-variant">{{ $debriefing->submitted_at?->isoFormat('LLL') }}</span>
                    </div>
                    @foreach($debriefing->responses as $key => $response)
                        @php($promptLabel = $debriefingPrompts[$key]['prompt'] ?? $key)
                        <div class="mb-2 last:mb-0">
                            <p class="text-xs font-medium text-on-surface-variant">{{ $promptLabel }}</p>
                            <p class="text-sm text-on-surface">{{ $response }}</p>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </section>
    @elseif($isOwner)
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6 text-center">
            <span class="material-symbols-outlined text-3xl text-on-surface-variant mb-2" aria-hidden="true">psychology</span>
            <p class="text-on-surface font-medium">{{ __('games.title_debriefing_responses') }}</p>
            <p class="text-sm text-on-surface-variant mt-1">{{ __('games.content_debriefing_waiting_for_responses') }}</p>
        </section>
    @endif

    {{-- Participant: submit debriefing form --}}
    @auth
        @if(!$isOwner && $isParticipant && !$userDebriefing)
            <section class="bg-primary/5 border border-primary/20 rounded-xl shadow-ambient p-6">
                <div class="flex items-center gap-2 mb-4">
                    <span class="material-symbols-outlined text-xl text-primary" aria-hidden="true">psychology</span>
                    <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface">{{ __('games.title_debriefing_submit') }}</h2>
                </div>
                <p class="text-sm text-on-surface-variant mb-4">{{ __('games.content_debriefing_description') }}</p>
                <form wire:submit="submitDebriefing">
                    @foreach($debriefingPrompts as $key => $promptData)
                        <div class="mb-4">
                            <label for="debriefing_{{ $key }}" class="block text-sm font-medium text-on-surface mb-1">
                                {{ $promptData['prompt'] }}
                                @if(!empty($promptData['confidential']))
                                    <span class="text-xs text-on-surface-variant ml-1">({{ __('games.content_confidential') }})</span>
                                @endif
                            </label>
                            <textarea
                                id="debriefing_{{ $key }}"
                                wire:model="debriefingResponses.{{ $key }}"
                                rows="3"
                                class="w-full rounded-lg border border-outline-variant bg-surface-container-low text-on-surface text-sm px-3 py-2 focus:ring-2 focus:ring-primary focus:border-primary"
                                placeholder="{{ $promptData['prompt'] }}"
                            ></textarea>
                        </div>
                    @endforeach
                    <button type="submit"
                        class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-on-primary text-sm font-medium rounded-lg shadow-ambient hover:opacity-90 transition-opacity">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">send</span>
                        {{ __('games.action_submit_debriefing') }}
                    </button>
                </form>
            </section>
        @elseif(!$isOwner && $isParticipant && $userDebriefing)
            {{-- Already submitted --}}
            <section class="bg-secondary-container/30 border-l-4 border-secondary rounded-xl shadow-ambient p-6">
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined text-xl text-on-secondary-container" aria-hidden="true">check_circle</span>
                    <div>
                        <p class="font-medium text-on-surface">{{ __('games.content_debriefing_submitted') }}</p>
                        @if($userDebriefing->submitted_at)
                            <p class="text-xs text-on-surface-variant">{{ $userDebriefing->submitted_at->isoFormat('LLL') }}</p>
                        @endif
                    </div>
                </div>
            </section>

            {{-- Anonymized summary (available after submitting) --}}
            @if($debriefingSummary && $debriefingSummary['total_submissions'] > 0)
                <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
                    <div class="flex items-center gap-2 mb-4">
                        <span class="material-symbols-outlined text-xl text-on-surface-variant" aria-hidden="true">groups</span>
                        <h2 class="text-lg font-heading font-bold tracking-tight text-on-surface">{{ __('games.title_debriefing_summary') }}</h2>
                        <span class="ml-auto text-xs text-on-surface-variant">
                            {{ trans_choice('games.content_debriefing_response_count', $debriefingSummary['total_submissions']) }}
                        </span>
                    </div>
                    @foreach($debriefingSummary['prompts'] as $key => $responses)
                        @php($promptLabel = $debriefingPrompts[$key]['prompt'] ?? $key)
                        <div class="mb-4 last:mb-0">
                            <p class="text-xs font-medium text-on-surface-variant mb-2">{{ $promptLabel }}</p>
                            <div class="space-y-1">
                                @foreach($responses as $response)
                                    <p class="text-sm text-on-surface bg-surface-container-high rounded px-3 py-2">{{ $response }}</p>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </section>
            @endif
        @endif
    @endauth
@endif
