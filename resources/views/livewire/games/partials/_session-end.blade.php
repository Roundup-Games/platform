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

{{-- ═══════════════════════════════════════════════════════════════════════════════
     Attendance Reporting — Three-state UI
     State 1: Form (window open, not yet submitted)
     State 2: Tallies (submitted, window open, not resolved)
     State 3: Resolved (window closed / resolution complete)
     ═══════════════════════════════════════════════════════════════════════════════ --}}
@auth
    @if($game->status === \App\Enums\GameStatus::Completed)
        @php
            $viewerId = \Illuminate\Support\Facades\Auth::id();
            $isHost = $isOwner;
            $allApproved = $game->participants->where('status', \App\Enums\ParticipantStatus::Approved);
            $ownParticipant = $allApproved->first(fn ($p) => $p->user_id === $viewerId);

            // Build list of other participants (everyone except self, including host)
            $reportableParticipants = $allApproved->filter(fn ($p) => $p->user_id !== $viewerId);

            // Pre-game statuses that are already set (LateCancel, CancelledEarly)
            $preGameStatuses = [
                \App\Enums\AttendanceStatus::LateCancel,
                \App\Enums\AttendanceStatus::CancelledEarly,
            ];

            // Determine the three states
            $windowOpen = $isAttendanceWindowOpen;
            $hasSubmitted = $hasSubmittedAttendance;
            $isResolved = !$windowOpen && $game->attendance_resolved_at !== null;
            $showAttendanceUI = $windowOpen || $isResolved;
        @endphp

        @if($showAttendanceUI && ($isHost || $ownParticipant))
            <section class="bg-surface-container-low rounded-xl shadow-ambient p-6"
                     x-data="{ disputeOpen: null }">

                {{-- ── Header ────────────────────────────────────────────────────── --}}

                {{-- State 1: Form header --}}
                @if($windowOpen && !$hasSubmitted)
                    <div class="flex items-center gap-2 mb-4">
                        <span class="material-symbols-outlined text-xl text-on-surface-variant" aria-hidden="true">how_to_reg</span>
                        <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface">
                            {{ __('games.title_submit_attendance') }}
                        </h2>
                    </div>
                    @if($attendanceTimeRemaining)
                        <div class="flex items-center gap-1.5 mb-4 text-sm text-on-surface-variant">
                            <span class="material-symbols-outlined text-base" aria-hidden="true">schedule</span>
                            {{ __('games.label_time_remaining', ['time' => $attendanceTimeRemaining]) }}
                        </div>
                    @endif
                    <p class="text-sm text-on-surface-variant mb-4">
                        @if($isHost)
                            {{ __('games.content_attendance_report_description') }}
                        @else
                            {{ __('games.content_report_others_description') }}
                        @endif
                    </p>

                {{-- State 2: Tallies header --}}
                @elseif($windowOpen && $hasSubmitted)
                    <div class="flex items-center gap-2 mb-2">
                        <span class="material-symbols-outlined text-xl text-on-secondary-container" aria-hidden="true">task_alt</span>
                        <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface">
                            {{ __('games.title_attendance_submitted') }}
                        </h2>
                    </div>
                    @if($currentUserAttendanceStatus)
                        <div class="flex items-center gap-1.5 mb-2 text-sm text-on-surface-variant">
                            <span class="material-symbols-outlined text-base" aria-hidden="true">person</span>
                            {{ __('games.label_your_status', ['status' => $currentUserAttendanceStatus->label()]) }}
                        </div>
                    @endif
                    @if($attendanceTimeRemaining)
                        <div class="flex items-center gap-1.5 mb-4 text-sm text-on-surface-variant">
                            <span class="material-symbols-outlined text-base" aria-hidden="true">schedule</span>
                            {{ __('games.label_time_remaining', ['time' => $attendanceTimeRemaining]) }}
                        </div>
                    @else
                        <div class="flex items-center gap-1.5 mb-4 text-sm text-on-surface-variant">
                            <span class="material-symbols-outlined text-base" aria-hidden="true">check_circle</span>
                            {{ __('games.label_all_reports_submitted') }}
                        </div>
                    @endif

                {{-- State 3: Resolved header --}}
                @elseif($isResolved)
                    <div class="flex items-center gap-2 mb-2">
                        <span class="material-symbols-outlined text-xl text-on-secondary-container" aria-hidden="true">verified</span>
                        <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface">
                            {{ __('games.title_attendance_resolved') }}
                        </h2>
                    </div>
                    @if($currentUserAttendanceStatus)
                        <div class="flex items-center gap-1.5 mb-2 text-sm text-on-surface-variant">
                            <span class="material-symbols-outlined text-base" aria-hidden="true">person</span>
                            {{ __('games.label_your_status', ['status' => $currentUserAttendanceStatus->label()]) }}
                        </div>
                    @endif
                    {{-- Resolution method label --}}
                    <div class="flex items-center gap-1.5 mb-4 text-xs text-on-surface-variant">
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">info</span>
                        @php
                            $resolutionMethod = match($game->attendance_resolution_method) {
                                'early_consensus' => __('games.label_resolution_consensus'),
                                'timeout' => __('games.label_resolution_timeout'),
                                'manual' => __('games.label_resolution_host_override'),
                                default => $game->attendance_resolution_method ?? '—',
                            };
                        @endphp
                        {{ __('games.label_resolution_method', ['method' => $resolutionMethod]) }}
                    </div>
                @endif

                {{-- ── Participant list (shared across all states) ─────────────────── --}}

                <div class="space-y-3">
                    @foreach($reportableParticipants as $participant)
                        @php
                            $isHostParticipant = $participant->user_id === $game->owner_id;
                            $preGameStatus = in_array($participant->attendance_status, $preGameStatuses)
                                ? $participant->attendance_status : null;
                            $participantTallies = $attendanceTallies[$participant->user_id] ?? [];
                            $leadingStatus = null;
                            $leadingCount = 0;
                            foreach ($participantTallies as $status => $count) {
                                if ($count > $leadingCount) {
                                    $leadingCount = $count;
                                    $leadingStatus = $status;
                                }
                            }
                        @endphp

                        <div class="flex flex-col bg-surface rounded-lg p-3 @if($preGameStatus) opacity-60 @endif">
                            {{-- Name row --}}
                            <div class="flex items-center justify-between gap-3">
                                <div class="flex items-center gap-2 min-w-0">
                                    @if($isHostParticipant)
                                        <span class="material-symbols-outlined text-sm text-primary" aria-hidden="true">shield</span>
                                    @endif
                                    <x-user-link :user="$participant->user" avatar-size="w-7 h-7" :truncate="true" />
                                    @if($isHostParticipant)
                                        <span class="text-xs text-on-surface-variant">({{ __('games.label_host') }})</span>
                                    @endif
                                </div>

                                {{-- Pre-game status badge --}}
                                @if($preGameStatus)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container-high text-on-surface-variant">
                                        {{ $preGameStatus->label() }}
                                    </span>
                                @endif
                            </div>

                            {{-- ── State 1: Interactive pills ─────────────────────────── --}}

                            @if($windowOpen && !$hasSubmitted && !$preGameStatus)
                                <div class="flex flex-wrap items-center gap-1.5 mt-2">
                                    {{-- Attended pill --}}
                                    <button
                                        type="button"
                                        @click="
                                            $wire.set('attendanceReports.{{ $participant->id }}.status', 'attended');
                                            $wire.set('attendanceReports.{{ $participant->id }}.reason', null);
                                        "
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium rounded-lg transition-all
                                            {{ ($attendanceReports[$participant->id]['status'] ?? 'attended') === 'attended'
                                                ? 'bg-secondary-container text-on-secondary-container ring-2 ring-secondary/30'
                                                : 'bg-surface-container-high text-on-surface-variant hover:bg-secondary-container/50' }}"
                                    >
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">check_circle</span>
                                        {{ __('attendance.status_attended') }}
                                    </button>

                                    {{-- No Show pill --}}
                                    <button
                                        type="button"
                                        @click="
                                            $wire.set('attendanceReports.{{ $participant->id }}.status', 'no_show');
                                            $wire.set('attendanceReports.{{ $participant->id }}.reason', null);
                                        "
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium rounded-lg transition-all
                                            {{ ($attendanceReports[$participant->id]['status'] ?? '') === 'no_show'
                                                ? 'bg-error/15 text-error ring-2 ring-error/30'
                                                : 'bg-surface-container-high text-on-surface-variant hover:bg-error/10' }}"
                                    >
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">cancel</span>
                                        {{ __('attendance.status_no_show') }}
                                    </button>

                                    {{-- Excused pill (host only) --}}
                                    @if($isHost)
                                        <button
                                            type="button"
                                            @click="$wire.set('attendanceReports.{{ $participant->id }}.status', 'excused')"
                                            class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium rounded-lg transition-all
                                                {{ ($attendanceReports[$participant->id]['status'] ?? '') === 'excused'
                                                    ? 'bg-surface-container-highest text-on-surface ring-2 ring-outline-variant'
                                                    : 'bg-surface-container-high text-on-surface-variant hover:bg-surface-container-highest/50' }}"
                                        >
                                            <span class="material-symbols-outlined text-sm" aria-hidden="true">event_busy</span>
                                            {{ __('attendance.status_excused') }}
                                        </button>
                                    @endif
                                </div>

                                {{-- Excused reason textarea (conditional) --}}
                                @if($isHost && ($attendanceReports[$participant->id]['status'] ?? '') === 'excused')
                                    <div class="mt-2">
                                        <textarea
                                            wire:model="attendanceReports.{{ $participant->id }}.reason"
                                            rows="2"
                                            maxlength="500"
                                            class="w-full rounded-lg border border-outline-variant bg-surface-container-low text-on-surface text-sm px-3 py-2 focus:ring-2 focus:ring-primary focus:border-primary"
                                            placeholder="{{ __('games.placeholder_excused_reason') }}"
                                        ></textarea>
                                    </div>
                                @endif
                            @endif

                            {{-- ── State 2 & 3: Vote tallies (read-only) ─────────────── --}}

                            @if(($windowOpen && $hasSubmitted) || $isResolved)
                                @if(!$preGameStatus && !empty($participantTallies))
                                    <div class="flex flex-wrap items-center gap-2 mt-2">
                                        @foreach([\App\Enums\AttendanceStatus::Attended, \App\Enums\AttendanceStatus::NoShow, \App\Enums\AttendanceStatus::Excused] as $status)
                                            @php($count = $participantTallies[$status->value] ?? 0)
                                            @if($count > 0)
                                                @php($isLeading = $status->value === $leadingStatus && ($isResolved || $leadingCount > 1))
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium
                                                    {{ $status === \App\Enums\AttendanceStatus::Attended
                                                        ? ($isLeading ? 'bg-secondary-container text-on-secondary-container' : 'bg-secondary-container/40 text-on-secondary-container')
                                                        : ($status === \App\Enums\AttendanceStatus::NoShow
                                                            ? ($isLeading ? 'bg-error/15 text-error' : 'bg-error/5 text-error/70')
                                                            : ($isLeading ? 'bg-surface-container-highest text-on-surface' : 'bg-surface-container-high text-on-surface-variant')) }}">
                                                    @if($status === \App\Enums\AttendanceStatus::Attended)
                                                        <span class="material-symbols-outlined text-xs" aria-hidden="true">check_circle</span>
                                                    @elseif($status === \App\Enums\AttendanceStatus::NoShow)
                                                        <span class="material-symbols-outlined text-xs" aria-hidden="true">cancel</span>
                                                    @else
                                                        <span class="material-symbols-outlined text-xs" aria-hidden="true">event_busy</span>
                                                    @endif
                                                    {{ $status->label() }} ({{ $count }})
                                                </span>
                                            @endif
                                        @endforeach
                                    </div>
                                @elseif(!$preGameStatus)
                                    <div class="mt-2 text-xs text-on-surface-variant italic">
                                        {{ __('attendance.status_not_reported') }}
                                    </div>
                                @endif
                            @endif
                        </div>
                    @endforeach
                </div>

                {{-- ── State 3: Viewer's own dispute button (outside participant loop) ── --}}
                @if($isResolved && $ownParticipant
                    && $ownParticipant->attendance_status === \App\Enums\AttendanceStatus::NoShow
                    && !$ownParticipant->attendance_disputed_at)
                    <div class="mt-4 p-3 bg-error/5 rounded-lg border border-error/20"
                         x-data="{ ownDisputeOpen: false }">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="material-symbols-outlined text-base text-error" aria-hidden="true">warning</span>
                            <span class="text-sm font-medium text-error">{{ __('games.label_your_status', ['status' => \App\Enums\AttendanceStatus::NoShow->label()]) }}</span>
                        </div>
                        <button
                            type="button"
                            @click="ownDisputeOpen = true"
                            class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg bg-error/10 text-error hover:bg-error/20 transition-colors"
                        >
                            <span class="material-symbols-outlined text-sm" aria-hidden="true">gavel</span>
                            {{ __('games.action_dispute_attendance') }}
                        </button>

                        {{-- Dispute confirmation --}}
                        <div x-show="ownDisputeOpen" x-transition class="mt-2 space-y-2">
                            <p class="text-xs text-on-surface-variant">
                                {{ __('games.placeholder_dispute_reason') }}
                            </p>
                            <button
                                wire:click="disputeAttendance('{{ $ownParticipant->id }}')"
                                wire:loading.attr.disabled
                                class="inline-flex items-center gap-1 px-4 py-2 bg-error text-on-error text-sm font-medium rounded-lg hover:opacity-90 transition-opacity"
                            >
                                <span class="material-symbols-outlined text-base" aria-hidden="true">send</span>
                                {{ __('games.action_submit_dispute') }}
                            </button>
                        </div>
                    </div>
                @endif

                {{-- ── State 1: Submit button ────────────────────────────────────────── --}}

                @if($windowOpen && !$hasSubmitted)
                    <div class="mt-4">
                        <button
                            wire:click="submitAttendanceReport()"
                            wire:loading.attr.disabled
                            class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-on-primary text-sm font-medium rounded-lg shadow-ambient hover:opacity-90 transition-opacity"
                        >
                            <span class="material-symbols-outlined text-base" aria-hidden="true">send</span>
                            {{ __('games.action_submit_attendance_report') }}
                            <span wire:loading class="inline-flex items-center">
                                <span class="material-symbols-outlined text-base animate-spin" aria-hidden="true">progress_activity</span>
                            </span>
                        </button>
                    </div>
                @endif
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
