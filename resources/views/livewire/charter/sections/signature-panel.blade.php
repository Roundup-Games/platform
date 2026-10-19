{{-- §4/§8 participant signature panel (M070/S02-T03). Renders only for an
     eligible signer or a viewer who already signed the current version —
     everyone else (guests, non-participants, pending participants, the
     owner countersigning later via S02-T04) gets no extra markup. The five
     checkbox labels are the S01 charter.confirm_* copy rendered VERBATIM
     (locked convention, App\Dto\CharterContent::AGREEMENT_ITEMS). --}}
@if($canSign || $mySignature !== null)
<section id="charter-signature-panel" class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 sm:p-8 scroll-mt-24">
    @if(session('charter_signed'))
        <div class="mb-6 flex items-start gap-3 rounded-xl border border-primary/30 bg-primary/5 px-5 py-4">
            <span class="material-symbols-outlined text-primary shrink-0" aria-hidden="true">check_circle</span>
            <p class="text-sm text-on-surface">{{ __('charter.flash_signed_success') }}</p>
        </div>
    @endif

    @if($mySignature !== null)
        {{-- Signed-state card: version pin + timestamp + the signer's own answers read back --}}
        <div class="flex items-center gap-2 mb-4">
            <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">ink_pen</span>
            <h3 class="font-heading text-lg font-semibold text-on-surface">{{ __('charter.heading_signed_card') }}</h3>
        </div>

        <p class="text-sm text-on-surface">
            {{ __('charter.content_signed_card', [
                'version' => (int) ($version?->version_number ?? $charter->current_version ?? 0),
                'date' => format_date($mySignature->confirmed_at, 'datetime'),
            ]) }}
        </p>

        @php($answers = is_array($mySignature->private_answers) ? $mySignature->private_answers : [])
        @php($answerLinesCategories = is_array($answers['my_lines_categories'] ?? null) ? $answers['my_lines_categories'] : [])
        @php($answerVeilsCategories = is_array($answers['my_veils_categories'] ?? null) ? $answers['my_veils_categories'] : [])
        @php($answerLinesText = (string) ($answers['my_lines_text'] ?? ''))
        @php($answerVeilsText = (string) ($answers['my_veils_text'] ?? ''))
        @php($answerNote = (string) ($answers['note_to_gm'] ?? ''))

        @if($answerLinesCategories !== [] || $answerLinesText !== '' || $answerVeilsCategories !== [] || $answerVeilsText !== '' || $answerNote !== '')
            <div class="mt-5">
                <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.heading_private_answers') }}</p>

                @if($answerLinesCategories !== [] || $answerLinesText !== '')
                    <div class="mt-3">
                        <p class="text-xs font-medium text-on-surface-variant">{{ __('charter.field_my_lines_label') }}</p>
                        @if($answerLinesCategories !== [])
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach($answerLinesCategories as $category)
                                    <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-error/10 text-error text-xs font-medium">
                                        <span class="material-symbols-outlined text-xs" aria-hidden="true">block</span>
                                        {{ __('charter.label_boundary_'.$category) }}
                                    </span>
                                @endforeach
                            </div>
                        @endif
                        @if($answerLinesText !== '')
                            <p class="mt-2 text-sm text-on-surface">{{ $answerLinesText }}</p>
                        @endif
                    </div>
                @endif

                @if($answerVeilsCategories !== [] || $answerVeilsText !== '')
                    <div class="mt-3">
                        <p class="text-xs font-medium text-on-surface-variant">{{ __('charter.field_my_veils_label') }}</p>
                        @if($answerVeilsCategories !== [])
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach($answerVeilsCategories as $category)
                                    <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-secondary/10 text-secondary text-xs font-medium">
                                        <span class="material-symbols-outlined text-xs" aria-hidden="true">curtains</span>
                                        {{ __('charter.label_boundary_'.$category) }}
                                    </span>
                                @endforeach
                            </div>
                        @endif
                        @if($answerVeilsText !== '')
                            <p class="mt-2 text-sm text-on-surface">{{ $answerVeilsText }}</p>
                        @endif
                    </div>
                @endif

                @if($answerNote !== '')
                    <div class="mt-3">
                        <p class="text-xs font-medium text-on-surface-variant">{{ __('charter.field_note_to_gm_label') }}</p>
                        <p class="mt-2 text-sm text-on-surface">{{ $answerNote }}</p>
                    </div>
                @endif
            </div>
        @else
            <p class="mt-4 text-sm text-on-surface-variant">{{ __('charter.content_signed_no_answers') }}</p>
        @endif

        <p class="mt-4 flex items-start gap-1.5 text-xs text-on-surface-variant">
            <span class="material-symbols-outlined text-sm mt-px shrink-0" aria-hidden="true">visibility_lock</span>
            {{ __('charter.description_privacy_note') }}
        </p>
    @else
        {{-- Signing form: five verbatim §8 attestations + sign action --}}
        <div class="flex items-center gap-2 mb-4">
            <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">draw</span>
            <h3 class="font-heading text-lg font-semibold text-on-surface">{{ __('charter.heading_signature_panel') }}</h3>
        </div>

        <p class="text-sm text-on-surface-variant">
            {{ __('charter.description_signature_panel_intro', ['version' => (int) ($version?->version_number ?? 0)]) }}
        </p>

        {{-- One reactive x-data scope so the sign button can react client-side
             to the five deferred checkboxes without a round-trip per tick: the
             delegated change listener recomputes the reactive `ready` flag
             from all five x-refs (keys single-sourced from
             CharterContent::AGREEMENT_ITEMS via @js; Alpine only re-runs
             effects on reactive state, so the former untracked $refs.checked
             reads evaluated once and never again); the server re-enforces
             every attestation in CharterSigningService. --}}
        <div
            x-data="{ ready: false }"
            x-on:change="ready = @js(\App\Dto\CharterContent::AGREEMENT_ITEMS).every(k => $refs['confirm_' + k].checked)"
            class="mt-5"
        >
            <ul class="space-y-3">
                @foreach(\App\Dto\CharterContent::AGREEMENT_ITEMS as $item)
                    <li>
                        <label for="charter-confirm-{{ $item }}" class="flex items-start gap-3 cursor-pointer">
                            <input
                                id="charter-confirm-{{ $item }}"
                                type="checkbox"
                                x-ref="confirm_{{ $item }}"
                                wire:model="attestations.{{ $item }}"
                                class="mt-1 w-4 h-4 rounded border-outline/40 text-primary focus:ring-primary/30"
                            >
                            <span class="text-sm text-on-surface leading-relaxed">{{ __('charter.confirm_'.$item) }}</span>
                        </label>
                        @error('attestations.'.$item)
                            <p class="ml-7 mt-1 text-xs text-error">{{ $message }}</p>
                        @enderror
                    </li>
                @endforeach
            </ul>

            @error('charter')
                <p class="mt-4 rounded-lg bg-error/10 text-error text-sm px-4 py-3">{{ $message }}</p>
            @enderror

            <div class="mt-6 flex flex-col sm:flex-row sm:items-center gap-4">
                <button
                    type="button"
                    wire:click="sign"
                    x-bind:disabled="!ready"
                    class="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-primary text-on-primary text-sm font-semibold hover:bg-primary/90 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    <span class="material-symbols-outlined text-base" aria-hidden="true">ink_pen</span>
                    {{ __('charter.action_sign_charter') }}
                </button>
                <p class="flex items-start gap-1.5 text-xs text-on-surface-variant">
                    <span class="material-symbols-outlined text-sm mt-px shrink-0" aria-hidden="true">visibility_lock</span>
                    {{ __('charter.description_privacy_note') }}
                </p>
            </div>
        </div>
    @endif
</section>
@endif
