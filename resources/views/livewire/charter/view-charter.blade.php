<div>
    @section('title', $charter->title)

    {{-- Draft banner — owner-only preview (guests get a 404 in mount) --}}
    @if($charter->isDraft())
        <div class="mb-6 flex items-start gap-3 rounded-xl border border-primary/30 bg-primary/5 px-5 py-4">
            <span class="material-symbols-outlined text-primary shrink-0" aria-hidden="true">visibility</span>
            <p class="text-sm text-on-surface">{{ __('charter.view_draft_banner') }}</p>
        </div>
    @endif

    {{-- Archived note — read-only campaign history --}}
    @if($charter->isArchived())
        <div class="mb-6 flex items-start gap-3 rounded-xl border border-outline/30 bg-surface-container-high px-5 py-4">
            <span class="material-symbols-outlined text-on-surface-variant shrink-0" aria-hidden="true">archive</span>
            <p class="text-sm text-on-surface-variant">{{ __('charter.content_archived_note') }}</p>
        </div>
    @endif

    {{-- ── Amended banner (M070/S03-T04) — viewer-scoped: only an
         approved participant whose latest earlier-version participant
         confirmation sits in pending re-agreement after a material
         amendment sees it. Amended-on date, the GM's changelog, and a
         collapsible field-level diff against the version they signed;
         re-signing through the unchanged panel below (or a minor
         amendment carrying the signature) removes it next render. --}}
    @if($amendedBanner !== null)
        <div class="mb-6 rounded-xl border border-error/30 bg-error/5 px-5 py-4">
            <div class="flex items-start gap-3">
                <span class="material-symbols-outlined text-error shrink-0" aria-hidden="true">published_with_changes</span>
                <div class="min-w-0 flex-1">
                    <p class="font-heading font-semibold text-on-surface">{{ __('charter.content_amended_banner_title') }}</p>
                    <p class="mt-1 text-sm text-on-surface">{{ __('charter.content_amended_banner_body') }}</p>
                    <p class="mt-2 text-sm text-on-surface-variant">
                        {{ __('charter.content_amended_banner_on', ['date' => format_date($amendedBanner['amendedOn'])]) }}
                    </p>

                    <div class="mt-3 rounded-lg border border-outline/15 bg-surface-container-lowest px-4 py-3">
                        <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.label_amendment_changelog_shown') }}</p>
                        <p class="mt-1.5 text-sm text-on-surface">{{ $amendedBanner['changelog'] }}</p>
                    </div>

                    <details class="group mt-3">
                        <summary class="inline-flex cursor-pointer items-center gap-1.5 text-sm font-medium text-primary hover:underline">
                            {{ __('charter.action_show_changes') }}
                            <span class="material-symbols-outlined text-base text-primary group-open:rotate-180 transition-transform shrink-0" aria-hidden="true">expand_more</span>
                        </summary>
                        <div class="mt-3">
                            @include('livewire.charter.sections.amendment-diff', ['changes' => $amendedBanner['changes']])
                        </div>
                    </details>
                </div>
            </div>
        </div>
    @endif

    {{-- ── Header ──────────────────────────────────────────────── --}}
    <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-8">
        <div class="flex items-center gap-4">
            <div class="w-14 h-14 bg-primary/10 rounded-2xl flex items-center justify-center shrink-0">
                <span class="material-symbols-outlined text-primary text-2xl" style="font-variation-settings: 'FILL' 1">contract</span>
            </div>
            <div>
                <h2 class="font-heading text-2xl font-bold text-on-surface tracking-tight">
                    {{ $charter->title }}
                </h2>
                <p class="mt-1 text-on-surface-variant text-sm">
                    {{ __('charter.view_description_by_gm') }}
                </p>
                @if($version)
                    <p class="mt-2 text-xs text-on-surface-variant">
                        {{ __('charter.content_version', ['version' => (int) $charter->current_version]) }} · {{ __('charter.content_published_on', ['date' => format_date($version->published_at)]) }}
                    </p>
                @endif
            </div>
        </div>
    </div>

    {{-- ── GM strip (campaign owner only): version + date + copy link ── --}}
    @if($isOwner && $version)
        <div class="mt-4 bg-surface-container-lowest rounded-xl shadow-ambient px-6 py-4 flex flex-col sm:flex-row sm:items-center gap-4">
            <div class="flex-1 min-w-0 space-y-1">
                <p class="flex items-center gap-2 text-sm text-on-surface-variant">
                    <span class="material-symbols-outlined text-primary text-sm shrink-0" aria-hidden="true">workspace_premium</span>
                    <span class="truncate">
                        {{ __('charter.content_gm_strip_published', ['version' => (int) $charter->current_version, 'date' => format_date($version->published_at)]) }}
                    </span>
                </p>
                {{-- §8 countersignature state (S02-T04): shown to the owner
                     in the GM strip once the version is countersigned. --}}
                @if($gmCountersignature !== null)
                    <p class="flex items-center gap-2 text-sm font-medium text-primary">
                        <span class="material-symbols-outlined text-primary text-sm shrink-0" aria-hidden="true">verified</span>
                        <span class="truncate">
                            {{ __('charter.content_gm_strip_countersigned', ['version' => (int) $charter->current_version, 'date' => format_date($gmCountersignature->confirmed_at, 'datetime')]) }}
                        </span>
                    </p>
                @endif
            </div>
            <div class="flex items-center gap-2" x-data>
                <input
                    type="text"
                    value="{{ route('charter.view', $charter->uuid) }}"
                    readonly
                    aria-label="{{ __('charter.label_share_link') }}"
                    class="w-full sm:w-72 rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-xs px-3 py-2 font-mono"
                    x-ref="shareableLinkInput"
                />
                <button
                    type="button"
                    x-on:click="
                        navigator.clipboard.writeText($refs.shareableLinkInput.value);
                        $el.textContent = '{{ __('common.status_copied') }}';
                        setTimeout(() => $el.textContent = '{{ __('common.action_copy_link') }}', 2000)
                    "
                    class="shrink-0 inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-primary text-on-primary text-xs font-medium hover:bg-primary/90 transition-colors"
                >
                    <span class="material-symbols-outlined text-xs" aria-hidden="true">content_copy</span>
                    {{ __('common.action_copy_link') }}
                </button>
            </div>
        </div>

        {{-- ── GM roster + countersignature (S02-T04): owner-only, active
             charters only — where the share link lives (concept §4). --}}
        @if($charter->isActive())
            @include('livewire.charter.sections.gm-roster')
        @endif
    @endif

    {{-- ── Sections: clean reading view, no editing chrome ─────── --}}
    <div class="mt-6 space-y-6">
        @include('livewire.charter.sections.overview')
        @include('livewire.charter.sections.boundaries')
        @include('livewire.charter.sections.safety')
        @include('livewire.charter.sections.policies')
        @include('livewire.charter.sections.ai-tools')
        @include('livewire.charter.sections.logistics')
        @include('livewire.charter.sections.character')
        @include('livewire.charter.sections.agreement')
        @include('livewire.charter.sections.signature-panel')
    </div>
</div>
