<div>
    {{-- Action feedback. The parent Journal tab's banners only re-render
         on parent updates, so the composer surfaces its own flashes —
         same markup as the campaign-journal banners (MEM1379). Rendered
         OUTSIDE the authorization gate so a rejected action's error
         still surfaces for the (unauthorized) caller. --}}
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

    @if($authorized && $open)
        {{-- Spec §4.1/§4.2 two-column composer: left rail = spoiler
             checklist + goal/excerpt pickers; right column = the
             editable draft + preview + publish actions. x-data scopes
             the Alpine helpers ($refs.draft, $wire) to this panel. --}}
        <section class="mb-8 bg-surface-container-low rounded-xl shadow-ambient p-5" aria-labelledby="recruitment-composer-heading" x-data>
            <div class="flex items-center gap-3 mb-1">
                <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1" aria-hidden="true">campaign</span>
                <h3 id="recruitment-composer-heading" class="font-heading text-lg font-bold text-on-surface">{{ __('journal.heading_recruitment_composer') }}</h3>
            </div>
            <p class="text-sm text-on-surface-variant mb-5">{{ __('journal.hint_recruitment_public') }}</p>

            <div class="grid gap-6 lg:grid-cols-2">
                {{-- ═══ Left rail: checklist + pickers ═══ --}}
                <div class="space-y-6">
                    {{-- The spoiler checklist: ALWAYS visible, all three
                         confirmed before Apply (server authoritative —
                         the Alpine :disabled on Apply is convenience). --}}
                    <fieldset class="border border-outline-variant rounded-xl p-4">
                        <legend class="px-2 text-sm font-semibold text-on-surface">{{ __('journal.section_recruitment_checklist') }}</legend>
                        <div class="mt-2 space-y-3">
                            <label class="flex items-start gap-2.5 text-sm text-on-surface cursor-pointer">
                                <input type="checkbox" wire:model="checklist.review_excerpts" class="mt-0.5 accent-primary" />
                                <span>{{ __('journal.confirmation_recruitment_review_excerpts') }}</span>
                            </label>
                            <label class="flex items-start gap-2.5 text-sm text-on-surface cursor-pointer">
                                <input type="checkbox" wire:model="checklist.names_removed" class="mt-0.5 accent-primary" />
                                <span>{{ __('journal.confirmation_recruitment_names_removed') }}</span>
                            </label>
                            <label class="flex items-start gap-2.5 text-sm text-on-surface cursor-pointer">
                                <input type="checkbox" wire:model="checklist.visitor_preview" class="mt-0.5 accent-primary" />
                                <span>{{ __('journal.confirmation_recruitment_visitor_preview') }}</span>
                            </label>
                        </div>
                    </fieldset>

                    {{-- Goals the GM may mark safe. Checking a box
                         appends the "- title" line to the draft;
                         unchecking removes it (first match). --}}
                    <div>
                        <h4 class="text-sm font-semibold text-on-surface mb-2">{{ __('journal.section_recruitment_goals') }}</h4>
                        @if($safeGoals->isNotEmpty())
                            <div class="space-y-2">
                                @foreach($safeGoals as $goal)
                                    <label class="flex items-start gap-2.5 text-sm text-on-surface cursor-pointer" wire:key="recruitment-goal-{{ $goal->id }}">
                                        <input type="checkbox" wire:model="goalChecked.{{ $goal->id }}" wire:change="toggleGoal('{{ $goal->id }}')" class="mt-0.5 accent-primary" />
                                        <span>{{ $goal->title }}</span>
                                    </label>
                                @endforeach
                            </div>
                        @else
                            <p class="text-sm text-on-surface-variant italic">{{ __('journal.empty_recruitment_no_goals') }}</p>
                        @endif
                    </div>

                    {{-- Recap-excerpt picker: player-visible recaps
                         only (prep notes / GM-visibility entries are
                         never offered). Nothing is auto-included. --}}
                    <div>
                        <h4 class="text-sm font-semibold text-on-surface mb-2">{{ __('journal.section_recruitment_excerpts') }}</h4>
                        @if($recapEntries->isNotEmpty())
                            <div class="space-y-2">
                                @foreach($recapEntries as $entry)
                                    <div class="border border-outline-variant rounded-lg p-3 bg-surface-container-lowest" wire:key="recruitment-excerpt-{{ $entry->id }}">
                                        <p class="text-xs text-on-surface-variant mb-1">
                                            {{ format_date($entry->game?->date_time, 'short_date') }}
                                        </p>
                                        <p class="text-sm text-on-surface leading-relaxed">{{ \Illuminate\Support\Str::limit($entry->body, 120) }}</p>
                                        <button type="button" wire:click="appendExcerpt('{{ $entry->id }}')"
                                                class="mt-2 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-surface-container-high text-on-surface-variant hover:bg-surface-container-highest transition-colors">
                                            <span class="material-symbols-outlined text-sm" aria-hidden="true">add</span>
                                            {{ __('journal.action_append_excerpt') }}
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="text-sm text-on-surface-variant italic">{{ __('journal.empty_recruitment_no_recaps') }}</p>
                        @endif
                    </div>
                </div>

                {{-- ═══ Right column: draft + actions ═══ --}}
                <div>
                    <label for="recruitment-draft" class="block text-xs font-medium text-on-surface-variant mb-1">
                        {{ __('journal.label_recruitment_draft') }}
                    </label>
                    <textarea
                        id="recruitment-draft"
                        x-ref="draft"
                        wire:model="draft"
                        rows="12"
                        maxlength="10000"
                        class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest text-on-surface text-sm px-3 py-2 leading-relaxed focus:ring-2 focus:ring-primary focus:border-primary"
                    ></textarea>
                    <div class="flex justify-end mt-1">
                        <span class="text-xs text-on-surface-variant">{{ mb_strlen($draft) }}/10000</span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 mt-3">
                        {{-- Redact selection: replaces the textarea's
                             current selection with block characters —
                             destructive to the draft text ONLY, never
                             the journal (the journal rows are never
                             written by this component). --}}
                        <button type="button"
                                x-on:click="
                                    const field = $refs.draft;
                                    const start = field.selectionStart;
                                    const end = field.selectionEnd;
                                    if (start !== null && end !== null && start !== end) {
                                        $wire.set('draft', field.value.slice(0, start) + '████' + field.value.slice(end));
                                    }
                                "
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-surface-container-high text-on-surface-variant hover:bg-surface-container-highest transition-colors">
                            <span class="material-symbols-outlined text-sm" aria-hidden="true">ink_eraser</span>
                            {{ __('journal.action_redact_selection') }}
                        </button>
                        <button type="button"
                                x-on:click="navigator.clipboard.writeText($wire.draft)"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-surface-container-high text-on-surface-variant hover:bg-surface-container-highest transition-colors">
                            <span class="material-symbols-outlined text-sm" aria-hidden="true">content_copy</span>
                            {{ __('journal.action_copy_recruitment_summary') }}
                        </button>
                        <button type="button" wire:click="$toggle('previewing')"
                                aria-pressed="{{ $previewing ? 'true' : 'false' }}"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium {{ $previewing ? 'bg-primary text-on-primary' : 'bg-surface-container-high text-on-surface-variant hover:bg-surface-container-highest' }} transition-colors">
                            <span class="material-symbols-outlined text-sm" aria-hidden="true">visibility</span>
                            {{ __('journal.action_preview_as_visitor') }}
                        </button>
                        {{-- Apply: Alpine keeps the button inert until
                             all three checklist boxes are true — client
                             convenience; the service re-enforces. --}}
                        <button type="button" wire:click="apply" wire:loading.attr="disabled" wire:target="apply"
                                x-bind:disabled="! ($wire.checklist.review_excerpts && $wire.checklist.names_removed && $wire.checklist.visitor_preview)"
                                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-primary text-on-primary text-sm font-medium shadow-ambient hover:opacity-90 transition-opacity disabled:opacity-40">
                            <span class="material-symbols-outlined text-base" aria-hidden="true">publish</span>
                            {{ __('journal.action_apply_recruitment_summary') }}
                        </button>
                    </div>

                    {{-- Applied state: the live block's stamp, the
                         public link, and one-click reversibility. --}}
                    @if($appliedSummary !== null)
                        <div class="mt-4 rounded-xl bg-secondary-container p-4" role="status">
                            <p class="text-sm text-on-secondary-container mb-3 flex items-center gap-2">
                                <span class="material-symbols-outlined text-base" aria-hidden="true">task_alt</span>
                                {{ __('campaigns.content_last_refreshed_after_session', ['count' => $refreshedAfterCount]) }}
                            </p>
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button"
                                        x-on:click="navigator.clipboard.writeText('{{ $publicUrl }}')"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-surface-container-high text-on-surface-variant hover:bg-surface-container-highest transition-colors">
                                    <span class="material-symbols-outlined text-sm" aria-hidden="true">link</span>
                                    {{ __('journal.action_copy_public_link') }}
                                </button>
                                <a href="{{ $publicUrl }}" target="_blank" rel="noopener"
                                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-surface-container-high text-on-surface-variant hover:bg-surface-container-highest transition-colors">
                                    <span class="material-symbols-outlined text-sm" aria-hidden="true">open_in_new</span>
                                    {{ $publicUrl }}
                                </a>
                                <button type="button" wire:click="remove" wire:loading.attr="disabled" wire:target="remove"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-error-container text-on-error-container hover:opacity-90 transition-opacity">
                                    <span class="material-symbols-outlined text-sm" aria-hidden="true">delete</span>
                                    {{ __('journal.action_remove_recruitment_summary') }}
                                </button>
                            </div>
                        </div>
                    @endif

                    {{-- Visitor-preview replica: EXACTLY what the public
                         campaign page will render (same heading, same
                         nl2br(e()) body, same refreshed line) — a
                         logged-in GM can never open the real public
                         page (PublicCampaignDetail redirects), so the
                         replica is the honesty surface. --}}
                    @if($previewing)
                        <div class="mt-4 border-2 border-outline-variant rounded-xl p-4" aria-label="{{ __('journal.action_preview_as_visitor') }}">
                            <p class="text-xs font-semibold uppercase tracking-wide text-on-surface-variant mb-2">
                                {{ __('journal.action_preview_as_visitor') }}
                            </p>
                            <div class="bg-surface-container-lowest rounded-lg p-4">
                                <h4 class="font-heading text-lg font-bold text-on-surface mb-2">{{ __('campaigns.heading_campaign_so_far') }}</h4>
                                <div class="text-sm text-on-surface leading-relaxed">{!! nl2br(e($draft)) !!}</div>
                                @if($refreshedAfterCount !== null)
                                    <p class="text-xs text-on-surface-variant mt-3">{{ __('campaigns.content_last_refreshed_after_session', ['count' => $refreshedAfterCount]) }}</p>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </section>
    @endif
</div>
