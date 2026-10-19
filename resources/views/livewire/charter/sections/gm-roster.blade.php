{{-- §4/§8 GM roster + countersignature (M070/S02-T04). Owner-only, included
     from view-charter.blade.php inside the isOwner block. Roster rows come
     from CharterSigningService::roster(); the collapsible §2 private
     answers come from CharterSigningService::privateAnswersFor — the
     owner-gated choke point (the view never queries confirmations
     directly). Answers are read-only by contract: no edit or delete
     affordance exists here or anywhere else (concept §2 — the GM can never
     edit a participant's private answers).

     Re-agreement (M070/S03-T05): participants whose signature a material
     amendment reset show the distinct re-agreement-pending chip instead of
     the plain pending chip, and their carried §2 answers (their latest
     submitted answers on an earlier version) keep rendering through the
     same choke point — only never-signed participants see the not-signed
     copy. --}}
<section id="charter-gm-roster" class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 sm:p-8 scroll-mt-24">
    @if(session('charter_countersigned'))
        <div class="mb-6 flex items-start gap-3 rounded-xl border border-primary/30 bg-primary/5 px-5 py-4">
            <span class="material-symbols-outlined text-primary shrink-0" aria-hidden="true">check_circle</span>
            <p class="text-sm text-on-surface">{{ __('charter.flash_countersigned_success') }}</p>
        </div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">fact_check</span>
            <h3 class="font-heading text-lg font-semibold text-on-surface">{{ __('charter.heading_gm_roster') }}</h3>
        </div>
        <p class="text-xs font-medium text-on-surface-variant">
            {{ __('charter.label_roster_signed_summary', ['signed' => $rosterSigned, 'total' => $rosterTotal]) }}
        </p>
    </div>

    @error('charter')
        <p class="mt-4 rounded-lg bg-error/10 text-error text-sm px-4 py-3">{{ $message }}</p>
    @enderror

    <ul class="mt-5 space-y-3">
        @foreach($roster as $row)
            @php($rowUser = $row['user'])
            @php($rowConfirmation = $row['confirmation'])
            @php($rowReagreementPending = $row['reagreementPending'])
            @php($rowAnswersRaw = $rowUser !== null ? ($rosterAnswers->get($rowUser->id)?->private_answers ?? null) : null)
            @php($rowAnswers = is_array($rowAnswersRaw) ? $rowAnswersRaw : [])
            @php($rowLinesCategories = is_array($rowAnswers['my_lines_categories'] ?? null) ? $rowAnswers['my_lines_categories'] : [])
            @php($rowVeilsCategories = is_array($rowAnswers['my_veils_categories'] ?? null) ? $rowAnswers['my_veils_categories'] : [])
            @php($rowLinesText = (string) ($rowAnswers['my_lines_text'] ?? ''))
            @php($rowVeilsText = (string) ($rowAnswers['my_veils_text'] ?? ''))
            @php($rowNote = (string) ($rowAnswers['note_to_gm'] ?? ''))
            @php($rowHasAnswers = $rowLinesCategories !== [] || $rowVeilsCategories !== [] || $rowLinesText !== '' || $rowVeilsText !== '' || $rowNote !== '')

            <li class="rounded-xl border border-outline/20 bg-surface-container-high/40">
                <div class="flex flex-wrap items-center gap-3 px-4 py-3">
                    <div class="flex-1 min-w-0 flex items-center gap-3">
                        <x-user-avatar :user="$rowUser" />
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-on-surface truncate">{{ $rowUser?->name ?? __('common.content_unknown') }}</p>
                            @if($rowConfirmation !== null)
                                <p class="mt-0.5 inline-flex items-center gap-1 text-xs font-medium text-primary">
                                    <span class="material-symbols-outlined text-xs" aria-hidden="true">ink_pen</span>
                                    {{ __('charter.label_roster_signed_chip', [
                                        'version' => (int) ($version?->version_number ?? 0),
                                        'date' => format_date($rowConfirmation->confirmed_at, 'datetime'),
                                    ]) }}
                                </p>
                            @elseif($rowReagreementPending)
                                {{-- M070/S03-T05: signed an earlier version, reset by a
                                     material amendment — awaiting their re-signature. --}}
                                <p class="mt-0.5 inline-flex items-center gap-1 text-xs font-medium text-on-surface-variant">
                                    <span class="material-symbols-outlined text-xs" aria-hidden="true">sync</span>
                                    {{ __('charter.label_roster_reagreement_pending') }}
                                </p>
                            @else
                                <p class="mt-0.5 inline-flex items-center gap-1 text-xs font-medium text-on-surface-variant">
                                    <span class="material-symbols-outlined text-xs" aria-hidden="true">schedule</span>
                                    {{ __('charter.label_roster_pending_chip') }}
                                </p>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Collapsible §2 detail: this participant's private
                     answers, read-only, GM-only (concept §2). --}}
                <details class="group border-t border-outline/15">
                    <summary class="px-4 py-2.5 cursor-pointer text-xs font-medium text-on-surface-variant flex items-center justify-between gap-3 hover:bg-surface-container-high/60 transition-colors">
                        {{ __('charter.action_roster_answers') }}
                        <span class="material-symbols-outlined text-base text-on-surface-variant group-open:rotate-180 transition-transform shrink-0" aria-hidden="true">expand_more</span>
                    </summary>
                    <div class="px-4 pb-4 pt-1">
                        @if($rowConfirmation === null && ! $rowReagreementPending)
                            {{-- Only never-signed participants: re-agreement-pending
                                 participants keep their carried answers visible below. --}}
                            <p class="text-sm text-on-surface-variant">{{ __('charter.label_roster_not_signed') }}</p>
                        @elseif($rowHasAnswers)
                            @if($rowLinesCategories !== [] || $rowLinesText !== '')
                                <div>
                                    <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.label_roster_answer_lines') }}</p>
                                    @if($rowLinesCategories !== [])
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            @foreach($rowLinesCategories as $category)
                                                <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-error/10 text-error text-xs font-medium">
                                                    <span class="material-symbols-outlined text-xs" aria-hidden="true">block</span>
                                                    {{ __('charter.label_boundary_'.$category) }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                    @if($rowLinesText !== '')
                                        <p class="mt-2 text-sm text-on-surface">{{ $rowLinesText }}</p>
                                    @endif
                                </div>
                            @endif

                            @if($rowVeilsCategories !== [] || $rowVeilsText !== '')
                                <div class="mt-3">
                                    <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.label_roster_answer_veils') }}</p>
                                    @if($rowVeilsCategories !== [])
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            @foreach($rowVeilsCategories as $category)
                                                <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-secondary/10 text-secondary text-xs font-medium">
                                                    <span class="material-symbols-outlined text-xs" aria-hidden="true">curtains</span>
                                                    {{ __('charter.label_boundary_'.$category) }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                    @if($rowVeilsText !== '')
                                        <p class="mt-2 text-sm text-on-surface">{{ $rowVeilsText }}</p>
                                    @endif
                                </div>
                            @endif

                            @if($rowNote !== '')
                                <div class="mt-3">
                                    <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.label_roster_answer_note') }}</p>
                                    <p class="mt-2 text-sm text-on-surface">{{ $rowNote }}</p>
                                </div>
                            @endif
                        @else
                            <p class="text-sm text-on-surface-variant">{{ __('charter.label_roster_no_answers') }}</p>
                        @endif
                    </div>
                </details>
            </li>
        @endforeach
    </ul>

    {{-- §8 countersignature: unlocked only once every approved participant
         has signed the current version; CharterSigningService re-enforces
         the gate on the server for every attempt. --}}
    <div class="mt-6 pt-5 border-t border-outline/20">
        @if($gmCountersignature !== null)
            <p class="flex items-center gap-2 text-sm font-medium text-primary">
                <span class="material-symbols-outlined" aria-hidden="true">verified</span>
                {{ __('charter.content_gm_strip_countersigned', [
                    'version' => (int) ($version?->version_number ?? 0),
                    'date' => format_date($gmCountersignature->confirmed_at, 'datetime'),
                ]) }}
            </p>
        @else
            <div class="flex flex-col sm:flex-row sm:items-center gap-4">
                <button
                    type="button"
                    wire:click="countersign"
                    @disabled($rosterSigned < $rosterTotal)
                    class="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-primary text-on-primary text-sm font-semibold hover:bg-primary/90 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    <span class="material-symbols-outlined text-base" aria-hidden="true">verified</span>
                    {{ __('charter.action_countersign_charter') }}
                </button>
                @if($rosterSigned < $rosterTotal)
                    <p class="flex items-start gap-1.5 text-xs text-on-surface-variant">
                        <span class="material-symbols-outlined text-sm mt-px shrink-0" aria-hidden="true">hourglass_top</span>
                        {{ __('charter.description_countersign_pending', ['count' => $rosterTotal - $rosterSigned]) }}
                    </p>
                @endif
            </div>
        @endif

        <p class="mt-4 flex items-start gap-1.5 text-xs text-on-surface-variant">
            <span class="material-symbols-outlined text-sm mt-px shrink-0" aria-hidden="true">visibility_lock</span>
            {{ __('charter.description_roster_privacy') }}
        </p>
    </div>
</section>
