<section id="charter-section-agreement" class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 sm:p-8 scroll-mt-24">
    <div class="flex items-center gap-2 mb-4">
        <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">handshake</span>
        <h3 class="font-heading text-lg font-semibold text-on-surface">{{ __('charter.section_agreement_title') }}</h3>
    </div>

    <p class="text-sm text-on-surface-variant">{{ __('charter.view_agreement_intro') }}</p>

    {{-- Informational: what every participant confirms when they sign (S02 owns the signing UI) --}}
    <ul class="mt-4 space-y-3">
        @foreach(['confirm_read_attestation', 'confirm_safety', 'confirm_policies', 'confirm_ai_tools', 'confirm_logistics'] as $confirmKey)
            <li class="flex items-start gap-3">
                <span class="material-symbols-outlined text-primary mt-0.5" aria-hidden="true">task_alt</span>
                <p class="text-sm text-on-surface leading-relaxed">{{ __('charter.'.$confirmKey) }}</p>
            </li>
        @endforeach
    </ul>

    {{-- §8 countersignature line (M070/S02-T04): once the campaign owner
         countersigns the current version, EVERYONE sees it under the
         agreement statements — a charter is fully agreed only when the GM
         has countersigned. Absent otherwise. --}}
    @if($gmCountersignature !== null)
        <div class="mt-6 flex items-start gap-3 rounded-xl border border-primary/30 bg-primary/5 px-5 py-4">
            <span class="material-symbols-outlined text-primary shrink-0" aria-hidden="true">verified</span>
            <p class="text-sm font-medium text-on-surface">
                {{ __('charter.content_countersigned_line', [
                    'gm' => $gmCountersignature->user?->name ?? __('common.content_unknown'),
                    'date' => format_date($gmCountersignature->confirmed_at, 'datetime'),
                ]) }}
            </p>
        </div>
    @endif
</section>
