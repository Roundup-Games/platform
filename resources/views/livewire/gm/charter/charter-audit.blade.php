<div>
    @section('title', __('charter.title_audit_export'))

    {{-- Print chrome (S04-T04): this page exists to become paper/PDF, so
         @media print strips the app shell (mobile header wrapper, desktop
         sidebar + top bar, any footer) and the in-view action chrome, and
         flattens the main padding so tables run edge to edge. --}}
    <style>
        @media print {
            body > div > div.lg\:hidden,
            aside,
            header,
            footer,
            .audit-action-chrome {
                display: none !important;
            }

            main {
                padding: 0 !important;
            }

            .audit-avoid-break {
                break-inside: avoid;
            }
        }
    </style>

    @if(! $memberConveniences)
        {{-- ── Free tier: polite member-feature panel (R085) ─────
             Same shape as the limit upsell — link, never block. The
             audit history itself never renders for a free owner. --}}
        <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-8">
            <div class="flex items-center gap-3 mb-3">
                <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">workspace_premium</span>
                <h2 class="font-heading text-lg font-bold text-on-surface">{{ __('charter.title_audit_export') }}</h2>
            </div>
            <p class="text-sm text-on-surface-variant max-w-2xl mb-6">
                {{ __('charter.content_upsell_audit') }}
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
        {{-- ── Header: campaign, charter title, status chip, version ── --}}
        <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-8 audit-avoid-break">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <p class="text-on-surface-variant text-sm">{{ $campaign->name }}</p>
                    <h2 class="mt-1 font-heading text-2xl font-bold text-on-surface tracking-tight break-words">
                        {{ $charter->title }}
                    </h2>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium {{ $charter->isArchived() ? 'bg-surface-container-high text-on-surface-variant' : 'bg-secondary-container text-on-secondary-container' }}">
                            {{ $charter->isDraft() ? __('charter.status_draft') : ($charter->isArchived() ? __('charter.status_archived') : __('charter.status_active')) }}
                        </span>
                        @if($charter->current_version !== null)
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-primary/10 text-primary">
                                {{ __('charter.content_version', ['version' => (int) $charter->current_version]) }}
                            </span>
                        @endif
                    </div>
                </div>
                @if($versions->isNotEmpty())
                    <button
                        type="button"
                        onclick="window.print()"
                        class="audit-action-chrome shrink-0 inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-primary text-on-primary text-sm font-medium hover:bg-primary/90 transition-colors whitespace-nowrap"
                    >
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">print</span>
                        {{ __('charter.action_print_export') }}
                    </button>
                @endif
            </div>
        </div>

        @if($versions->isEmpty())
            {{-- Draft: nothing published yet — a note instead of empty tables --}}
            <div class="mt-6 bg-surface-container-lowest rounded-xl shadow-ambient p-8 flex items-start gap-3">
                <span class="material-symbols-outlined text-on-surface-variant shrink-0" aria-hidden="true">schedule</span>
                <p class="text-sm text-on-surface-variant">{{ __('charter.label_audit_not_published') }}</p>
            </div>
        @else
            {{-- ── Version history: the immutable audit trail (§3) ──── --}}
            <section class="mt-6 bg-surface-container-lowest rounded-xl shadow-ambient p-6 sm:p-8">
                <div class="flex items-center gap-2 mb-4">
                    <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">history</span>
                    <h3 class="font-heading text-lg font-semibold text-on-surface">{{ __('charter.heading_version_history') }}</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm border-collapse">
                        <thead>
                            <tr class="border-b-2 border-outline/20">
                                <th scope="col" class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-on-surface-variant whitespace-nowrap">{{ __('charter.label_audit_col_version') }}</th>
                                <th scope="col" class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-on-surface-variant whitespace-nowrap">{{ __('charter.label_audit_col_published') }}</th>
                                <th scope="col" class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-on-surface-variant">{{ __('charter.label_audit_col_change') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($versions as $version)
                                <tr class="border-b border-outline/10 align-top">
                                    <td class="px-3 py-3 font-medium text-on-surface whitespace-nowrap">v{{ (int) $version->version_number }}</td>
                                    <td class="px-3 py-3 text-on-surface whitespace-nowrap">{{ format_date($version->published_at) }}</td>
                                    <td class="px-3 py-3 text-on-surface-variant">
                                        @if((int) $version->version_number > 1)
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $version->is_material ? 'bg-error/10 text-error' : 'bg-secondary-container text-on-secondary-container' }}">
                                                {{ $version->is_material ? __('charter.label_amendment_material_badge') : __('charter.label_amendment_minor_badge') }}
                                            </span>
                                        @endif
                                        <span class="{{ (int) $version->version_number > 1 ? 'block mt-1.5' : '' }}">{{ $version->changelog !== null && $version->changelog !== '' ? $version->changelog : '—' }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- ── Signature history: confirmations grouped by version ── --}}
            <section class="mt-6 bg-surface-container-lowest rounded-xl shadow-ambient p-6 sm:p-8">
                <div class="flex items-center gap-2 mb-4">
                    <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">ink_pen</span>
                    <h3 class="font-heading text-lg font-semibold text-on-surface">{{ __('charter.heading_signature_history') }}</h3>
                </div>
                <div class="space-y-6">
                    @foreach($versions as $version)
                        <div class="audit-avoid-break">
                            <h4 class="text-xs font-semibold uppercase tracking-wide text-on-surface-variant mb-2">v{{ (int) $version->version_number }}</h4>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-sm border-collapse">
                                    <thead>
                                        <tr class="border-b-2 border-outline/20">
                                            <th scope="col" class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-on-surface-variant">{{ __('charter.label_audit_col_participant') }}</th>
                                            <th scope="col" class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-on-surface-variant">{{ __('charter.label_audit_col_role') }}</th>
                                            <th scope="col" class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-on-surface-variant whitespace-nowrap">{{ __('charter.label_audit_col_signed_at') }}</th>
                                            <th scope="col" class="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-on-surface-variant">{{ __('charter.label_audit_col_status') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($version->confirmations as $confirmation)
                                            @php($signer = $confirmation->user)
                                            @php($signerName = $signer !== null && trim((string) $signer->name) !== '' ? (string) $signer->name : (string) ($signer?->email ?? __('common.content_unknown')))
                                            <tr class="border-b border-outline/10">
                                                <td class="px-3 py-2.5 font-medium text-on-surface break-words">{{ $signerName }}</td>
                                                <td class="px-3 py-2.5 text-on-surface-variant whitespace-nowrap">
                                                    {{ $confirmation->role === \App\Models\SessionZeroConfirmation::ROLE_GM ? __('charter.label_audit_role_gm') : __('charter.label_audit_role_participant') }}
                                                </td>
                                                <td class="px-3 py-2.5 text-on-surface whitespace-nowrap">{{ format_date($confirmation->confirmed_at, 'datetime') }}</td>
                                                <td class="px-3 py-2.5 whitespace-nowrap">
                                                    @if($confirmation->status === \App\Models\SessionZeroConfirmation::STATUS_PENDING_REAGREEMENT)
                                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-surface-container-high text-on-surface-variant">
                                                            {{ __('charter.label_roster_reagreement_pending') }}
                                                        </span>
                                                    @else
                                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                                                            {{ __('charter.heading_signed_card') }}
                                                        </span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @empty
                                            <tr class="border-b border-outline/10">
                                                <td colspan="4" class="px-3 py-2.5 text-on-surface-variant">—</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Privacy contract (concept §2): §2 private boundary answers
                     are never part of this export — stated explicitly. --}}
                <p class="mt-6 flex items-start gap-1.5 text-xs text-on-surface-variant border-t border-outline/20 pt-4">
                    <span class="material-symbols-outlined text-sm mt-px shrink-0" aria-hidden="true">visibility_lock</span>
                    {{ __('charter.label_audit_privacy_note') }}
                </p>
            </section>
        @endif
    @endif
</div>
