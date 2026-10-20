<div>
    @if($canViewJournal)
        {{-- Previously On panel (S04-T03): GM-only nested component,
             self-guarded (owner + member gate) — players and empty
             journals render nothing. Sits above the kind-filter pills. --}}
        <livewire:campaigns.campaign-previously-on :campaign="$campaign" />

        {{-- Authoring feedback. The parent page's banner only re-renders
             on parent updates, so the journal surfaces its own flashes —
             same markup as the campaign-detail banners. --}}
        @if(session()->has('success'))
            <div class="mb-4 rounded-xl bg-secondary-container p-4 flex items-center gap-3" role="status" aria-live="polite">
                <span class="material-symbols-outlined text-on-secondary-container" aria-hidden="true">check_circle</span>
                <p class="text-sm text-on-secondary-container">{{ session('success') }}</p>
            </div>
        @endif

        @if(session()->has('error'))
            <div class="mb-4 rounded-xl bg-error-container p-4 flex items-center gap-3" role="alert" aria-live="polite">
                <span class="material-symbols-outlined text-on-error-container" aria-hidden="true">error</span>
                <p class="text-sm text-on-error-container">{{ session('error') }}</p>
            </div>
        @endif

        {{-- Kind filter pills. The GM Notes pill only renders for the GM —
             players must not even learn the prep_note filter exists. --}}
        <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('journal.label_filter_group') }}">
            @foreach(($isGm ? ['all', 'recap', 'in_character', 'prep_note'] : ['all', 'recap', 'in_character']) as $kind)
                <button type="button" wire:click="$set('kindFilter', '{{ $kind }}')"
                        aria-pressed="{{ $kindFilter === $kind ? 'true' : 'false' }}"
                        class="px-3 py-1.5 rounded-full text-xs font-medium border transition-colors {{ $kindFilter === $kind ? 'bg-primary text-on-primary border-primary' : 'bg-surface-container-high text-on-surface-variant border-transparent hover:text-primary' }}">
                    {{ __('journal.filter_'.$kind) }}
                </button>
            @endforeach
        </div>

        {{-- Timeline (reverse chronological, newest first) --}}
        @if($timeline->count())
            <div class="space-y-4" role="list">
                @foreach($timeline as $entry)
                    <article class="bg-surface-container-low rounded-xl shadow-ambient p-5" role="listitem" wire:key="journal-{{ $entry->id }}">
                        <div class="flex flex-wrap items-center gap-2 mb-3">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-container-highest text-on-surface-variant">
                                {{ $entry->kind->label() }}
                            </span>
                            @if($entry->game)
                                <a href="{{ route('games.show', $entry->game) }}" wire:navigate
                                   class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline transition-colors">
                                    <span class="material-symbols-outlined text-sm" aria-hidden="true">event</span>
                                    {{ $entry->game->name }}
                                </a>
                                <span class="text-xs text-on-surface-variant">{{ format_date($entry->game->date_time, 'short_date') }}</span>
                            @endif
                        </div>

                        <div class="text-sm text-on-surface leading-relaxed whitespace-pre-line">{{ $entry->body }}</div>

                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-3 text-xs text-on-surface-variant">
                            <span class="font-medium">{{ $entry->authorUser?->name ?? __('journal.label_unknown_author') }}</span>
                            @if($entry->in_game_date)
                                <span class="inline-flex items-center gap-1">
                                    <span class="material-symbols-outlined text-xs" aria-hidden="true">calendar_month</span>
                                    {{ $entry->in_game_date }}
                                </span>
                            @endif
                            <span>&middot;</span>
                            <span>{{ format_date($entry->created_at, 'datetime') }}</span>
                        </div>
                    </article>
                @endforeach
            </div>

            {{-- Keyset pagination: cursor buttons mirror Livewire's own
                 simple-cursor view (setPage(cursor, cursorName)). --}}
            @if(! $timeline->onFirstPage() || $timeline->hasMorePages())
                <div class="flex items-center justify-between pt-2">
                    @if(! $timeline->onFirstPage())
                        @php($previousCursor = $timeline->previousCursor() ?? $timeline->cursor())
                        <button type="button" wire:click="setPage('{{ $previousCursor?->encode() }}', 'cursor')"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium bg-surface-container-high text-on-surface-variant hover:bg-surface-container-highest transition-colors">
                            <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_upward</span>
                            {{ __('journal.action_newer_entries') }}
                        </button>
                    @else
                        <span></span>
                    @endif
                    @if($timeline->hasMorePages())
                        @php($nextCursor = $timeline->nextCursor() ?? $timeline->cursor())
                        <button type="button" wire:click="setPage('{{ $nextCursor?->encode() }}', 'cursor')"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium bg-surface-container-high text-on-surface-variant hover:bg-surface-container-highest transition-colors">
                            {{ __('journal.action_older_entries') }}
                            <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_downward</span>
                        </button>
                    @endif
                </div>
            @endif
        @else
            <p class="text-sm text-on-surface-variant italic py-8 text-center">{{ __('journal.empty_journal') }}</p>
        @endif

        {{-- ═══ In-character form: any Approved|Benched participant ═══ --}}
        @if($canPostInCharacter)
            <section class="mt-8" aria-labelledby="journal-in-character-heading">
                <h2 id="journal-in-character-heading" class="text-lg font-heading font-bold text-on-surface mb-3">{{ __('journal.action_post_in_character') }}</h2>
                <form wire:submit="postInCharacter" class="bg-surface-container-low rounded-xl shadow-ambient p-5">
                    <div class="mb-3">
                        <label for="in-character-body" class="block text-xs font-medium text-on-surface-variant mb-1">
                            {{ __('journal.action_post_in_character') }}
                        </label>
                        <textarea
                            id="in-character-body"
                            wire:model="inCharacterBody"
                            rows="4"
                            maxlength="10000"
                            class="w-full rounded-lg border border-outline-variant bg-surface-container-low text-on-surface text-sm px-3 py-2 focus:ring-2 focus:ring-primary focus:border-primary"
                        ></textarea>
                        @error('inCharacterBody')
                            <p class="mt-1 text-sm text-error">{{ $message }}</p>
                        @enderror
                        <div class="flex justify-end mt-1">
                            <span class="text-xs text-on-surface-variant">{{ mb_strlen($inCharacterBody) }}/10000</span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="in-character-date" class="block text-xs font-medium text-on-surface-variant mb-1">
                            {{ __('journal.label_in_game_date') }}
                        </label>
                        <input id="in-character-date" type="text" wire:model="inGameDate" maxlength="100"
                               class="w-full px-3 py-2 rounded-lg border border-outline-variant bg-surface text-on-surface focus:border-primary focus:ring-1 focus:ring-primary outline-none" />
                    </div>
                    <button type="submit"
                            class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-on-primary text-sm font-medium rounded-lg shadow-ambient hover:opacity-90 transition-opacity">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">publish</span>
                        {{ __('journal.action_post_in_character') }}
                    </button>
                </form>
            </section>
        @endif

        {{-- ═══ Recruitment composer entry (S05-T03): GM-only block.
             The nested component is fail-closed on the
             manageRecruitmentSummary ability; emphasized while the
             campaign is publicly recruiting (MEM387 proxy: public +
             seats open), with the staleness nudge once 3 completed
             sessions accrue since the last summary stamp. ═══ --}}
        @if($isGm)
            <section class="mt-8" aria-labelledby="journal-recruitment-heading">
                <div class="bg-surface-container-low rounded-xl shadow-ambient p-5 {{ $campaign->visibility === \App\Enums\Visibility::Public && ! $campaign->isAtCapacity() ? 'ring-2 ring-primary' : '' }}">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 id="journal-recruitment-heading" class="text-lg font-heading font-bold text-on-surface flex items-center gap-3">
                                <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1" aria-hidden="true">campaign</span>
                                {{ __('journal.heading_recruitment_composer') }}
                            </h2>
                            @if($campaign->visibility === \App\Enums\Visibility::Public && ! $campaign->isAtCapacity())
                                <p class="text-xs font-medium text-primary mt-1">{{ __('journal.hint_recruitment_open_seats') }}</p>
                            @endif
                        </div>
                        <button type="button" wire:click="$dispatch('open-recruitment-composer')"
                                class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-on-primary text-sm font-medium rounded-lg shadow-ambient hover:opacity-90 transition-opacity">
                            <span class="material-symbols-outlined text-base" aria-hidden="true">group_add</span>
                            {{ __('journal.action_recruit_with_campaign') }}
                        </button>
                    </div>

                    @if($campaign->recruitmentSummaryStaleSessions() >= 3)
                        <div class="mt-4 rounded-lg bg-tertiary-container p-3 flex flex-wrap items-center justify-between gap-2" role="status">
                            <p class="text-sm text-on-tertiary-container">{{ __('journal.hint_recruitment_stale', ['count' => $campaign->recruitmentSummaryStaleSessions()]) }}</p>
                            <button type="button" wire:click="$dispatch('open-recruitment-composer')"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-surface-container-high text-on-surface-variant hover:bg-surface-container-highest transition-colors">
                                <span class="material-symbols-outlined text-sm" aria-hidden="true">refresh</span>
                                {{ __('journal.action_recruit_with_campaign') }}
                            </button>
                        </div>
                    @endif
                </div>

                {{-- Nested composer: self-guarded (owner-only policy),
                     own flash banner (MEM1379). Renders only its flash
                     banner until the open event flips $open. --}}
                <livewire:campaigns.campaign-recruitment-composer :campaign="$campaign" />
            </section>
        @endif

        {{-- ═══ GM prep-note form: never rendered for players ═══ --}}
        @if($isGm)
            <section class="mt-8" aria-labelledby="journal-prep-heading">
                <h2 id="journal-prep-heading" class="text-lg font-heading font-bold text-on-surface mb-3">{{ __('journal.action_post_prep_note') }}</h2>
                <p class="text-xs text-on-surface-variant mb-3">{{ __('journal.hint_prep_note') }}</p>
                <form wire:submit="postPrepNote" class="bg-surface-container-low rounded-xl shadow-ambient p-5">
                    <div class="mb-3">
                        <label for="prep-note-body" class="block text-xs font-medium text-on-surface-variant mb-1">
                            {{ __('journal.action_post_prep_note') }}
                        </label>
                        <textarea
                            id="prep-note-body"
                            wire:model="prepNoteBody"
                            rows="3"
                            maxlength="10000"
                            class="w-full rounded-lg border border-outline-variant bg-surface-container-low text-on-surface text-sm px-3 py-2 focus:ring-2 focus:ring-primary focus:border-primary"
                        ></textarea>
                        @error('prepNoteBody')
                            <p class="mt-1 text-sm text-error">{{ $message }}</p>
                        @enderror
                        <div class="flex justify-end mt-1">
                            <span class="text-xs text-on-surface-variant">{{ mb_strlen($prepNoteBody) }}/10000</span>
                        </div>
                    </div>
                    <button type="submit"
                            class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-on-primary text-sm font-medium rounded-lg shadow-ambient hover:opacity-90 transition-opacity">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">lock</span>
                        {{ __('journal.action_post_prep_note') }}
                    </button>
                </form>
            </section>
        @endif

        {{-- ═══ Goal board: status-grouped, fixed order, sort within groups ═══ --}}
        <section class="mt-8" aria-labelledby="journal-goals-heading">
            <h2 id="journal-goals-heading" class="text-lg font-heading font-bold text-on-surface mb-3">{{ __('journal.heading_goals') }}</h2>

            @if(count($goalGroups))
                <div class="space-y-5">
                    @foreach($goalGroups as $group)
                        <div wire:key="goal-group-{{ $group['status']->value }}">
                            <h3 class="text-sm font-semibold text-on-surface-variant uppercase tracking-wide mb-2">
                                {{ $group['status']->label() }}
                                <span class="ml-1 text-on-surface-variant/70">({{ $group['goals']->count() }})</span>
                            </h3>
                            <div class="space-y-2">
                                @foreach($group['goals'] as $goal)
                                    <article class="bg-surface-container-low rounded-xl shadow-ambient p-4" wire:key="goal-{{ $goal->id }}">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <h4 class="text-sm font-medium text-on-surface">{{ $goal->title }}</h4>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-container-highest text-on-surface-variant">
                                                {{ $goal->status->label() }}
                                            </span>
                                        </div>
                                        @if($goal->description)
                                            <p class="text-sm text-on-surface-variant mt-1">{{ $goal->description }}</p>
                                        @endif
                                        @if($isGm)
                                            <form wire:submit.prevent="changeGoalStatus('{{ $goal->id }}')" class="flex flex-wrap items-center gap-2 mt-3">
                                                <label for="goal-status-{{ $goal->id }}" class="sr-only">
                                                    {{ __('journal.action_change_status') }} — {{ $goal->title }}
                                                </label>
                                                <select id="goal-status-{{ $goal->id }}" wire:model="goalStatuses.{{ $goal->id }}"
                                                        class="px-3 py-1.5 rounded-lg border border-outline-variant bg-surface text-on-surface text-sm focus:border-primary focus:ring-1 focus:ring-primary outline-none">
                                                    @foreach(\App\Enums\GoalStatus::cases() as $status)
                                                        <option value="{{ $status->value }}"
                                                                @selected(($goalStatuses[$goal->id] ?? $goal->status->value) === $status->value)>
                                                            {{ $status->label() }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                                <button type="submit"
                                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium bg-surface-container-high text-on-surface-variant hover:bg-surface-container-highest transition-colors">
                                                    {{ __('journal.action_change_status') }}
                                                </button>
                                            </form>
                                        @endif
                                    </article>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-on-surface-variant italic py-4">{{ __('journal.empty_goals') }}</p>
            @endif

            @if($isGm)
                <form wire:submit="createGoal" class="mt-4 bg-surface-container-low rounded-xl shadow-ambient p-5 space-y-3">
                    <div>
                        <label for="goal-title" class="block text-xs font-medium text-on-surface-variant mb-1">
                            {{ __('journal.label_goal_title') }}
                        </label>
                        <input id="goal-title" type="text" wire:model="goalTitle" maxlength="200"
                               class="w-full px-3 py-2 rounded-lg border border-outline-variant bg-surface text-on-surface focus:border-primary focus:ring-1 focus:ring-primary outline-none" />
                        @error('goalTitle')
                            <p class="mt-1 text-xs text-error">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="goal-description" class="block text-xs font-medium text-on-surface-variant mb-1">
                            {{ __('journal.label_goal_description') }}
                        </label>
                        <input id="goal-description" type="text" wire:model="goalDescription" maxlength="1000"
                               class="w-full px-3 py-2 rounded-lg border border-outline-variant bg-surface text-on-surface focus:border-primary focus:ring-1 focus:ring-primary outline-none" />
                        @error('goalDescription')
                            <p class="mt-1 text-xs text-error">{{ $message }}</p>
                        @enderror
                    </div>
                    <button type="submit"
                            class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-on-primary text-sm font-medium rounded-lg shadow-ambient hover:opacity-90 transition-opacity">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">flag</span>
                        {{ __('journal.action_add_goal') }}
                    </button>
                </form>
            @endif
        </section>

        {{-- ═══ Per-character state timelines: newest 3 preview, <details>
             expands to the full timeline with zero extra requests ═══ --}}
        <section class="mt-8" aria-labelledby="journal-states-heading">
            <h2 id="journal-states-heading" class="text-lg font-heading font-bold text-on-surface mb-3">{{ __('journal.heading_state_changes') }}</h2>

            @if($participantTimelines->isNotEmpty())
                <div class="space-y-3">
                    @foreach($participantTimelines as $participant)
                        <div class="bg-surface-container-low rounded-xl shadow-ambient p-5" wire:key="states-{{ $participant->id }}">
                            <h3 class="text-sm font-medium text-on-surface">{{ $participant->user?->name ?? __('journal.label_unknown_author') }}</h3>
                            @if($participant->stateChanges->isNotEmpty())
                                <ul class="mt-2 space-y-1.5">
                                    @foreach($participant->stateChanges->take(3) as $change)
                                        <li class="flex flex-wrap items-center gap-2 text-sm" wire:key="states-{{ $participant->id }}-{{ $change->id }}">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-container-highest text-on-surface-variant">
                                                {{ $change->change_type->label() }}
                                            </span>
                                            <span class="text-on-surface">{{ $change->delta }}</span>
                                            <span class="text-xs text-on-surface-variant">{{ format_date($change->created_at, 'datetime') }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                                @if($participant->stateChanges->count() > 3)
                                    <details class="mt-2">
                                        <summary class="text-xs font-medium text-primary cursor-pointer">{{ __('journal.action_show_all') }}</summary>
                                        <ul class="mt-2 space-y-1.5">
                                            @foreach($participant->stateChanges->skip(3) as $change)
                                                <li class="flex flex-wrap items-center gap-2 text-sm" wire:key="states-older-{{ $participant->id }}-{{ $change->id }}">
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-container-highest text-on-surface-variant">
                                                        {{ $change->change_type->label() }}
                                                    </span>
                                                    <span class="text-on-surface">{{ $change->delta }}</span>
                                                    <span class="text-xs text-on-surface-variant">{{ format_date($change->created_at, 'datetime') }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </details>
                                @endif
                            @else
                                <p class="text-xs text-on-surface-variant italic mt-1">{{ __('journal.empty_state_changes') }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-on-surface-variant italic py-4">{{ __('journal.empty_state_changes') }}</p>
            @endif

            @if($isGm)
                <form wire:submit="logStateChange" class="mt-4 bg-surface-container-low rounded-xl shadow-ambient p-5 space-y-3">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="state-participant" class="block text-xs font-medium text-on-surface-variant mb-1">
                                {{ __('journal.label_state_participant') }}
                            </label>
                            <select id="state-participant" wire:model="stateParticipantId"
                                    class="w-full px-3 py-2 rounded-lg border border-outline-variant bg-surface text-on-surface text-sm focus:border-primary focus:ring-1 focus:ring-primary outline-none">
                                <option value="">{{ __('journal.label_state_choose_participant') }}</option>
                                @foreach($participantTimelines as $participant)
                                    <option value="{{ $participant->id }}" @selected($stateParticipantId === $participant->id)>
                                        {{ $participant->user?->name ?? __('journal.label_unknown_author') }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="state-type" class="block text-xs font-medium text-on-surface-variant mb-1">
                                {{ __('journal.label_state_change_type') }}
                            </label>
                            <select id="state-type" wire:model="stateChangeType"
                                    class="w-full px-3 py-2 rounded-lg border border-outline-variant bg-surface text-on-surface text-sm focus:border-primary focus:ring-1 focus:ring-primary outline-none">
                                <option value="">{{ __('journal.label_state_choose_type') }}</option>
                                @foreach(\App\Enums\CharacterStateChangeType::cases() as $type)
                                    <option value="{{ $type->value }}" @selected($stateChangeType === $type->value)>{{ $type->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label for="state-delta" class="block text-xs font-medium text-on-surface-variant mb-1">
                            {{ __('journal.label_state_delta') }}
                        </label>
                        <input id="state-delta" type="text" wire:model="stateDelta" maxlength="2000"
                               class="w-full px-3 py-2 rounded-lg border border-outline-variant bg-surface text-on-surface focus:border-primary focus:ring-1 focus:ring-primary outline-none" />
                        @error('stateDelta')
                            <p class="mt-1 text-xs text-error">{{ $message }}</p>
                        @enderror
                    </div>
                    <button type="submit"
                            class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-on-primary text-sm font-medium rounded-lg shadow-ambient hover:opacity-90 transition-opacity">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">history</span>
                        {{ __('journal.action_log_state') }}
                    </button>
                </form>
            @endif
        </section>
    @endif
</div>
