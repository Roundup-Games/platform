<div>
    @section('title', __('charter.title_charter'))

    {{-- ── Header ──────────────────────────────────────────────── --}}
    <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-8">
        <div class="flex items-center gap-4">
            <div class="w-14 h-14 bg-primary/10 rounded-2xl flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-primary text-2xl" style="font-variation-settings: 'FILL' 1">history_edu</span>
            </div>
            <div>
                <h2 class="font-heading text-2xl font-bold text-on-surface tracking-tight">
                    {{ __('charter.title_charter') }}
                </h2>
                <p class="mt-1 text-on-surface-variant text-sm">{{ $campaign->name }}</p>
            </div>
        </div>
    </div>

    @if($state === 'start')
        {{-- ── Start Screen: quick start vs blank ─────────────── --}}
        <div class="mt-6 bg-surface-container-lowest rounded-xl shadow-ambient p-8">
            <h3 class="font-heading text-xl font-bold text-on-surface mb-2">
                {{ __('charter.heading_start') }}
            </h3>
            <p class="text-sm text-on-surface-variant max-w-2xl">
                {{ __('charter.description_start_intro') }}
            </p>
        </div>

        @if($this->atCharterLimit)
            {{-- Polite membership upsell (S04): free tier caps at 2 concurrent
                 active charters (R084); archiving a finished charter frees a
                 slot. Members are unlimited — link, never block. --}}
            <div class="mt-6 bg-surface-container-lowest rounded-xl shadow-ambient p-8">
                <div class="flex items-center gap-3 mb-3">
                    <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">workspace_premium</span>
                    <h4 class="font-heading text-lg font-bold text-on-surface">{{ __('charter.content_upsell_limit_title') }}</h4>
                </div>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container mb-4">
                    {{ __('charter.label_active_charter_limit', ['limit' => $this->activeCharterLimit]) }}
                </span>
                <p class="text-sm text-on-surface-variant max-w-2xl mb-6">
                    {{ __('charter.content_upsell_limit_body', ['limit' => $this->activeCharterLimit]) }}
                </p>
                <div class="flex flex-wrap items-center gap-3">
                    <a
                        href="{{ route('pricing') }}"
                        wire:navigate
                        class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-lg bg-primary text-on-primary text-sm font-medium hover:bg-primary/90 transition-colors whitespace-nowrap"
                    >
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">sell</span>
                        {{ __('charter.content_upsell_limit_view_pricing') }}
                    </a>
                    <a
                        href="{{ route('membership') }}"
                        wire:navigate
                        class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-lg bg-surface-container-high text-on-surface text-sm font-medium hover:bg-surface-container-highest transition-colors whitespace-nowrap"
                    >
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">card_membership</span>
                        {{ __('charter.content_upsell_limit_membership') }}
                    </a>
                </div>
            </div>
        @else
        <div class="mt-6 grid sm:grid-cols-2 gap-4">
            <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 flex flex-col">
                <div class="flex items-center gap-2 mb-3">
                    <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">bolt</span>
                    <h4 class="font-heading font-semibold text-on-surface">{{ __('charter.title_quickstart') }}</h4>
                </div>
                <p class="text-sm text-on-surface-variant mb-6 flex-1">{{ __('charter.description_quickstart') }}</p>
                <button
                    type="button"
                    wire:click="startCharter('quickstart')"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-lg bg-primary text-on-primary text-sm font-medium hover:bg-primary/90 transition-colors whitespace-nowrap"
                >
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">arrow_forward</span>
                    {{ __('charter.action_start_quickstart') }}
                </button>
            </div>

            <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 flex flex-col">
                <div class="flex items-center gap-2 mb-3">
                    <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">edit_note</span>
                    <h4 class="font-heading font-semibold text-on-surface">{{ __('charter.title_blank') }}</h4>
                </div>
                <p class="text-sm text-on-surface-variant mb-6 flex-1">{{ __('charter.description_blank') }}</p>
                <button
                    type="button"
                    wire:click="startCharter('blank')"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-lg bg-surface-container-high text-on-surface text-sm font-medium hover:bg-surface-container-highest transition-colors whitespace-nowrap"
                >
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">arrow_forward</span>
                    {{ __('charter.action_start_blank') }}
                </button>
            </div>
        </div>

        @if($this->memberConveniences)
            {{-- Member conveniences (S04, R085): seeded starts from a saved
                 template or one of the owner's published charters. Additive
                 member value — hidden entirely for free owners, no tease. --}}
            <div class="mt-6 bg-surface-container-lowest rounded-xl shadow-ambient p-8">
                <div class="flex items-center gap-2 mb-4">
                    <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">workspace_premium</span>
                    <h4 class="font-heading text-lg font-bold text-on-surface">{{ __('charter.heading_start_from_template') }}</h4>
                </div>
                <div class="grid sm:grid-cols-2 gap-4">
                    <div class="bg-surface-container-high/40 rounded-xl p-6 flex flex-col">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="material-symbols-outlined text-primary" aria-hidden="true">content_copy</span>
                            <h5 class="font-heading font-semibold text-on-surface">{{ __('charter.title_template_card') }}</h5>
                        </div>
                        @if($this->templates->isNotEmpty())
                            <select
                                wire:model.live="templateId"
                                class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm px-4 py-2.5 mb-4"
                                aria-label="{{ __('charter.title_template_card') }}"
                            >
                                {{-- The placeholder makes the first real
                                     option REQUIRE a pick: without it the
                                     browser displays option #1 while the
                                     model stays '' and re-selecting the
                                     displayed option fires no change —
                                     startCharter would silently no-op. --}}
                                <option value="" disabled selected>{{ __('charter.placeholder_choose_option') }}</option>
                                @foreach($this->templates as $template)
                                    <option value="{{ $template->id }}">{{ $template->name }}</option>
                                @endforeach
                            </select>
                            <button
                                type="button"
                                wire:click="startCharter('template')"
                                wire:loading.attr="disabled"
                                @disabled($templateId === '')
                                class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-lg bg-primary text-on-primary text-sm font-medium hover:bg-primary/90 transition-colors whitespace-nowrap"
                            >
                                <span class="material-symbols-outlined text-sm" aria-hidden="true">arrow_forward</span>
                                {{ __('charter.action_start_from_template') }}
                            </button>
                        @else
                            <p class="text-sm text-on-surface-variant flex-1">{{ __('charter.label_template_empty') }}</p>
                        @endif
                    </div>

                    <div class="bg-surface-container-high/40 rounded-xl p-6 flex flex-col">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="material-symbols-outlined text-primary" aria-hidden="true">history</span>
                            <h5 class="font-heading font-semibold text-on-surface">{{ __('charter.title_previous_card') }}</h5>
                        </div>
                        @if($this->previousCharters->isNotEmpty())
                            <select
                                wire:model.live="previousCharterId"
                                class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm px-4 py-2.5 mb-4"
                                aria-label="{{ __('charter.title_previous_card') }}"
                            >
                                <option value="" disabled selected>{{ __('charter.placeholder_choose_option') }}</option>
                                @foreach($this->previousCharters as $previous)
                                    <option value="{{ $previous->id }}">{{ $previous->title }}</option>
                                @endforeach
                            </select>
                            <button
                                type="button"
                                wire:click="startCharter('previous')"
                                wire:loading.attr="disabled"
                                @disabled($previousCharterId === '')
                                class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-lg bg-primary text-on-primary text-sm font-medium hover:bg-primary/90 transition-colors whitespace-nowrap"
                            >
                                <span class="material-symbols-outlined text-sm" aria-hidden="true">arrow_forward</span>
                                {{ __('charter.action_start_from_previous') }}
                            </button>
                        @else
                            <p class="text-sm text-on-surface-variant flex-1">{{ __('charter.label_template_empty') }}</p>
                        @endif
                    </div>
                </div>
            </div>
        @endif
        @endif
    @elseif($state === 'published' && ! $amending)
        {{-- ── Published State: Shareable Link ─────────────────── --}}
        <div class="mt-6 bg-surface-container-lowest rounded-xl shadow-ambient p-8">
            <div class="text-center">
                <div class="w-16 h-16 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-4">
                    <span class="material-symbols-outlined text-primary text-3xl" style="font-variation-settings: 'FILL' 1">check_circle</span>
                </div>
                <h3 class="font-heading text-xl font-bold text-on-surface mb-2">
                    {{ __('charter.flash_publish_success') }}
                </h3>
                <p class="text-on-surface-variant text-sm mb-4">
                    {{ __('charter.description_publish_success') }}
                </p>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container mb-6">
                    {{ $charter->isArchived() ? __('charter.status_archived') : __('charter.flash_version_published', ['version' => $charter->current_version]) }}
                </span>

                {{-- Amendment just published this session (S03) — transient flash. --}}
                @if(session('charter_amended'))
                    <p class="text-sm font-medium text-primary -mt-4 mb-6">
                        {{ __('charter.flash_amendment_published', ['version' => (int) session('charter_amended')]) }}
                    </p>
                @endif

                {{-- Charter just archived this session (S04) — transient flash. --}}
                @if(session('charter_archived'))
                    <p class="text-sm font-medium text-primary -mt-4 mb-6">
                        {{ __('charter.flash_charter_archived') }}
                    </p>
                @endif

                {{-- Template just saved this session (S04) — transient flash. --}}
                @if(session('charter_template_saved'))
                    <p class="text-sm font-medium text-primary -mt-4 mb-6">
                        {{ __('charter.flash_template_saved') }}
                    </p>
                @endif

                <div class="max-w-lg mx-auto">
                    <label for="charter-share-link" class="block text-sm font-medium text-on-surface mb-2">
                        {{ __('charter.label_share_link') }}
                    </label>
                    <div class="flex items-center gap-2">
                        <input
                            id="charter-share-link"
                            type="text"
                            value="{{ $shareableLink }}"
                            readonly
                            class="flex-1 rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm px-4 py-2.5 font-mono"
                            x-ref="shareableLinkInput"
                        />
                        <button
                            type="button"
                            x-data
                            x-on:click="
                                navigator.clipboard.writeText($refs.shareableLinkInput.value);
                                $el.textContent = '{{ __('common.status_copied') }}';
                                setTimeout(() => $el.textContent = '{{ __('common.action_copy_link') }}', 2000)
                            "
                            class="shrink-0 inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-primary text-on-primary text-sm font-medium hover:bg-primary/90 transition-colors"
                        >
                            <span class="material-symbols-outlined text-sm" aria-hidden="true">content_copy</span>
                            {{ __('common.action_copy_link') }}
                        </button>
                    </div>
                </div>

                @if($this->memberConveniences)
                    {{-- Save as template (S04 member convenience): snapshot this
                         charter's content under a name for future seeded starts.
                         Hidden entirely for free owners — additive member value. --}}
                    <div class="mt-6 max-w-lg mx-auto text-left">
                        <label for="charter-template-name" class="block text-sm font-medium text-on-surface mb-2">
                            {{ __('charter.action_save_as_template') }}
                        </label>
                        <div class="flex items-center gap-2">
                            <input
                                id="charter-template-name"
                                type="text"
                                wire:model="templateName"
                                placeholder="{{ __('charter.field_template_name') }}"
                                class="flex-1 rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm placeholder:text-on-surface-variant focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors px-4 py-2.5"
                            />
                            <button
                                type="button"
                                wire:click="saveAsTemplate"
                                wire:loading.attr="disabled"
                                class="shrink-0 inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-surface-container-high text-on-surface text-sm font-medium hover:bg-surface-container-highest transition-colors whitespace-nowrap"
                            >
                                <span class="material-symbols-outlined text-sm" aria-hidden="true">content_copy</span>
                                {{ __('charter.action_save_as_template') }}
                            </button>
                        </div>
                        @error('templateName')
                            <p class="mt-1 text-xs text-error">{{ $message }}</p>
                        @enderror
                    </div>
                    @error('charter')
                        {{-- Service-level refusals (e.g. the member-only
                             template gate) — the field error alone left a
                             crafted refusal silent on this panel. --}}
                        <p class="mt-1 text-xs text-error">{{ $message }}</p>
                    @enderror
                @endif

                <div class="mt-6 flex items-center justify-center gap-3">
                    <a
                        href="{{ route('campaigns.show', $campaign) }}"
                        wire:navigate
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-surface-container-high text-on-surface text-sm font-medium hover:bg-surface-container-highest transition-colors"
                    >
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">arrow_back</span>
                        {{ $campaign->name }}
                    </a>
                    <a
                        href="{{ $shareableLink }}"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-secondary/10 text-secondary text-sm font-medium hover:bg-secondary/20 transition-colors"
                    >
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">open_in_new</span>
                        {{ __('charter.action_view_charter') }}
                    </a>
                    @if($this->memberConveniences)
                        {{-- Audit export (S04 member convenience, R085): the
                             immutable version + signature history rendered
                             print-ready. Member-only like the tier UX in
                             T01/T02; archived charters keep the link — the
                             frozen history is the point. --}}
                        <a
                            href="{{ route('campaigns.charter.audit', $campaign) }}"
                            wire:navigate
                            class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-surface-container-high text-on-surface text-sm font-medium hover:bg-surface-container-highest transition-colors whitespace-nowrap"
                        >
                            <span class="material-symbols-outlined text-sm" aria-hidden="true">print</span>
                            {{ __('charter.action_export_audit') }}
                        </a>
                    @endif
                    @if($charter->isActive())
                        <button
                            type="button"
                            wire:click="startAmendment"
                            wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-primary text-on-primary text-sm font-medium hover:bg-primary/90 transition-colors whitespace-nowrap"
                        >
                            <span class="material-symbols-outlined text-sm" aria-hidden="true">edit_document</span>
                            {{ __('charter.action_amend_charter') }}
                        </button>
                        <button
                            type="button"
                            wire:click="archive"
                            wire:confirm="{{ __('charter.confirm_archive_charter') }}"
                            wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-surface-container-high text-on-surface text-sm font-medium hover:bg-surface-container-highest transition-colors whitespace-nowrap"
                        >
                            <span class="material-symbols-outlined text-sm" aria-hidden="true">inventory_2</span>
                            {{ __('charter.action_archive_charter') }}
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @else
        {{-- ── Draft / Amendment Composer ───────────────────────── --}}
        @if($amending)
            {{-- Amendment mode banner: this is not a draft — nothing is
                 persisted until the amendment publishes as vN+1. --}}
            <div class="mt-6 bg-surface-container-low rounded-xl shadow-ambient border border-primary/20 px-6 py-4 flex items-center gap-3">
                <span class="material-symbols-outlined text-primary shrink-0" aria-hidden="true">published_with_changes</span>
                <p class="text-sm text-on-surface-variant flex-1">{{ __('charter.label_amendment_in_progress') }}</p>
                {{-- Autosave/publish errors land on the 'charter' key —
                     surface them here (always visible) instead of only in
                     the step-8 agreement block, or a GM on step 2 gets a
                     silent no-op when the draft was published/archived in
                     another tab. --}}
                @error('charter')
                    <p class="text-xs text-error shrink-0">{{ $message }}</p>
                @enderror
                <button
                    type="button"
                    wire:click="cancelAmendment"
                    wire:loading.attr="disabled"
                    class="shrink-0 inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-surface-container-high text-on-surface text-sm font-medium hover:bg-surface-container-highest transition-colors whitespace-nowrap"
                >
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">close</span>
                    {{ __('charter.action_cancel_amendment') }}
                </button>
            </div>
        @else
            <div class="mt-6 bg-surface-container-low rounded-xl shadow-ambient px-6 py-4 flex items-center gap-3">
                <span class="material-symbols-outlined text-on-surface-variant" aria-hidden="true">visibility_off</span>
                <p class="text-sm text-on-surface-variant flex-1">
                    {{ __('charter.content_draft_banner') }}
                    {{-- Same always-visible error surface as the amendment
                         banner — autosave failures must never be silent. --}}
                    @error('charter')
                        <span class="block mt-1 text-xs text-error">{{ $message }}</span>
                    @enderror
                </p>
                @if($draftSaved)
                    <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">check</span>
                        {{ __('charter.flash_draft_saved') }}
                    </span>
                @endif
            </div>
        @endif

        {{-- Title --}}
        <div class="mt-6 bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <label for="charter-title" class="block text-sm font-medium text-on-surface mb-1">
                {{ __('common.field_title') }}
            </label>
            <input
                id="charter-title"
                type="text"
                wire:model="title"
                class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm placeholder:text-on-surface-variant focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors px-4 py-2.5"
                maxlength="255"/>
            @error('title')
                <p class="mt-1 text-xs text-error">{{ $message }}</p>
            @enderror
        </div>

        {{-- Stepper rail with completion dots --}}
        <div class="mt-6 bg-surface-container-lowest rounded-xl shadow-ambient p-4">
            <div class="flex items-center justify-between gap-3 mb-3">
                <p class="text-xs text-on-surface-variant">
                    {{ __('charter.label_step_of', ['step' => $step, 'total' => count($railSections)]) }}
                </p>
                <span class="inline-flex items-center gap-1 text-xs text-on-surface-variant">
                    <span class="w-2 h-2 rounded-full bg-primary inline-block" aria-hidden="true"></span>
                    {{ __('charter.status_completed') }}
                </span>
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach($railSections as $rail)
                    <button
                        type="button"
                        wire:click="goToStep({{ $rail['step'] }})"
                        title="{{ $rail['title'] }}"
                        class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-sm font-medium transition-colors {{ $step === $rail['step'] ? 'bg-primary text-on-primary' : 'bg-surface-container-high text-on-surface-variant hover:bg-surface-container-highest' }}"
                    >
                        @if($rail['complete'] !== null)
                            <span
                                class="w-2 h-2 rounded-full {{ $rail['complete'] ? 'bg-primary' : 'bg-outline/50' }} {{ $step === $rail['step'] ? 'ring-1 ring-on-primary/40' : '' }}"
                                title="{{ $rail['complete'] ? __('charter.status_completed') : __('charter.status_incomplete') }}"
                                aria-hidden="true"
                            ></span>
                        @else
                            <span class="material-symbols-outlined text-sm" aria-hidden="true">{{ $rail['icon'] }}</span>
                        @endif
                        <span>{{ $rail['step'] }}. {{ $rail['short'] }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Current section form --}}
        @php($current = $railSections[$step - 1])
        <div class="mt-6 bg-surface-container-lowest rounded-xl shadow-ambient p-6">
            <div class="flex items-center gap-2 mb-2">
                <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">{{ $current['icon'] }}</span>
                <h3 class="font-heading text-xl font-bold text-on-surface">{{ $current['title'] }}</h3>
            </div>
            <p class="text-sm text-on-surface-variant mb-6">{{ __('charter.section_'.$current['id'].'_description') }}</p>

            @if($current['id'] === 'agreement')
                @if($amending)
                    {{-- Detected materiality (S03): material edits reset every
                         signature; minor edits carry them. --}}
                    @php($amendmentIsMaterial = $this->amendmentIsMaterial)
                    <div class="rounded-xl border p-4 mb-4 {{ $amendmentIsMaterial ? 'border-error/40 bg-error/5' : 'border-secondary/40 bg-secondary/5' }}">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="material-symbols-outlined {{ $amendmentIsMaterial ? 'text-error' : 'text-secondary' }}" aria-hidden="true">{{ $amendmentIsMaterial ? 'report' : 'verified' }}</span>
                            @if($amendmentIsMaterial)
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-error/10 text-error">
                                    {{ __('charter.label_amendment_material_badge') }}
                                </span>
                            @else
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-secondary-container text-on-secondary-container">
                                    {{ __('charter.label_amendment_minor_badge') }}
                                </span>
                            @endif
                        </div>
                        <p class="mt-2 text-sm {{ $amendmentIsMaterial ? 'text-error' : 'text-secondary' }}">
                            {{ $amendmentIsMaterial ? __('charter.description_amendment_material') : __('charter.description_amendment_minor') }}
                        </p>
                    </div>

                    {{-- Field-level diff preview against the published version
                         (shared partial — same renderer as the participant view). --}}
                    @include('livewire.charter.sections.amendment-diff', ['changes' => $this->amendmentChanges])

                    {{-- Required changelog — stored with the new version. --}}
                    <div class="mt-4 mb-2">
                        <label for="charter-amendment-changelog" class="block text-sm font-medium text-on-surface mb-1">
                            {{ __('charter.field_amendment_changelog_label') }} <span class="text-error" aria-hidden="true">*</span>
                        </label>
                        <textarea
                            id="charter-amendment-changelog"
                            wire:model="amendmentChangelog"
                            rows="3"
                            placeholder="{{ __('charter.field_amendment_changelog_placeholder') }}"
                            class="w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm placeholder:text-on-surface-variant focus:border-primary/40 focus:ring-1 focus:ring-primary/20 transition-colors resize-y px-4 py-2.5"
                        >{{ old('amendmentChangelog', $amendmentChangelog) }}</textarea>
                        <p class="mt-1 text-xs text-on-surface-variant">{{ __('charter.description_amendment_changelog') }}</p>
                        @error('amendmentChangelog')
                            <p class="mt-1 text-xs text-error">{{ $message }}</p>
                        @enderror
                    </div>
                @endif

                {{-- §8 review + publish: signing-time copy shown informationally --}}
                <div class="rounded-xl bg-surface-container-high/60 border border-outline/10 p-4 mb-2">
                    <p class="text-sm font-medium text-on-surface mb-3">{{ __('charter.view_agreement_intro') }}</p>
                    <ul class="space-y-3">
                        @foreach(['confirm_read_attestation', 'confirm_safety', 'confirm_policies', 'confirm_ai_tools', 'confirm_logistics'] as $confirmKey)
                            <li class="flex items-start gap-2">
                                <span class="material-symbols-outlined text-secondary text-sm mt-0.5" aria-hidden="true">check_circle</span>
                                <span class="text-sm text-on-surface-variant">{{ __('charter.'.$confirmKey) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                @error('charter')
                    <p class="mt-2 text-xs text-error">{{ $message }}</p>
                @enderror
            @else
                @include('livewire.gm.charter.sections.'.$current['id'])
            @endif
        </div>

        {{-- Navigation --}}
        <div class="mt-6 flex items-center justify-between gap-3">
            <div>
                @if($step > 1)
                    <button
                        type="button"
                        wire:click="goToStep({{ $step - 1 }})"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-surface-container-high text-on-surface text-sm font-medium hover:bg-surface-container-highest transition-colors"
                    >
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">arrow_back</span>
                        {{ __('charter.action_back') }}
                    </button>
                @endif
            </div>
            <div class="flex items-center gap-3">
                @if(! $amending)
                    <button
                        type="button"
                        wire:click="saveDraft"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-surface-container-high text-on-surface text-sm font-medium hover:bg-surface-container-highest transition-colors"
                    >
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">save</span>
                        {{ __('charter.action_save_draft') }}
                    </button>
                @endif
                @if($step < count($railSections))
                    <button
                        type="button"
                        wire:click="goToStep({{ $step + 1 }})"
                        class="inline-flex items-center gap-2 px-6 py-2.5 rounded-lg bg-primary text-on-primary text-sm font-medium hover:bg-primary/90 transition-colors whitespace-nowrap"
                    >
                        {{ __('charter.action_next') }}
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">arrow_forward</span>
                    </button>
                @else
                    <button
                        type="button"
                        wire:click="publish"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center gap-2 px-6 py-2.5 rounded-lg bg-primary text-on-primary text-sm font-medium hover:bg-primary/90 transition-colors whitespace-nowrap"
                    >
                        <span class="material-symbols-outlined text-sm" wire:loading.remove wire:target="publish" aria-hidden="true">rocket_launch</span>
                        <span class="material-symbols-outlined text-sm animate-spin" wire:loading wire:target="publish" aria-hidden="true" role="status" aria-label="{{ __('charter.action_publish') }}">progress_activity</span>
                        {{ $amending ? __('charter.action_publish_amendment') : __('charter.action_publish') }}
                    </button>
                @endif
            </div>
        </div>
    @endif
</div>
