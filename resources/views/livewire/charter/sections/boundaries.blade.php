@php($section = $content['boundaries'] ?? [])
@php($linesCategories = $section['lines_categories'] ?? [])
@php($linesText = (string) ($section['lines_text'] ?? ''))
@php($veilsCategories = $section['veils_categories'] ?? [])
@php($veilsText = (string) ($section['veils_text'] ?? ''))
@php($introNote = (string) ($section['intro_note'] ?? ''))

@if($canSign || $introNote !== '' || $linesCategories !== [] || $linesText !== '' || $veilsCategories !== [] || $veilsText !== '')
<section id="charter-section-boundaries" class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 sm:p-8 scroll-mt-24">
    <div class="flex items-center gap-2 mb-6">
        <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">front_hand</span>
        <h3 class="font-heading text-lg font-semibold text-on-surface">{{ __('charter.section_boundaries_title') }}</h3>
    </div>

    <div class="space-y-6">
        @if($introNote !== '')
            @include('livewire.charter.sections._prose', ['value' => $introNote])
        @endif

        {{-- Table lines (hard no) --}}
        @if($linesCategories !== [] || $linesText !== '')
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_boundaries_lines_label') }}</p>
                @if($linesCategories !== [])
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach($linesCategories as $category)
                            <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-error/10 text-error text-xs font-medium">
                                <span class="material-symbols-outlined text-xs" aria-hidden="true">block</span>
                                {{ __('charter.label_boundary_'.$category) }}
                            </span>
                        @endforeach
                    </div>
                @endif
                @if($linesText !== '')
                    <div class="mt-3">
                        <p class="text-xs font-medium text-on-surface-variant">{{ __('charter.field_boundaries_lines_text_label') }}</p>
                        @include('livewire.charter.sections._prose', ['value' => $linesText])
                    </div>
                @endif
            </div>
        @endif

        {{-- Table veils (fade to black) --}}
        @if($veilsCategories !== [] || $veilsText !== '')
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_boundaries_veils_label') }}</p>
                @if($veilsCategories !== [])
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach($veilsCategories as $category)
                            <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-secondary/10 text-secondary text-xs font-medium">
                                <span class="material-symbols-outlined text-xs" aria-hidden="true">curtains</span>
                                {{ __('charter.label_boundary_'.$category) }}
                            </span>
                        @endforeach
                    </div>
                @endif
                @if($veilsText !== '')
                    <div class="mt-3">
                        <p class="text-xs font-medium text-on-surface-variant">{{ __('charter.field_boundaries_veils_text_label') }}</p>
                        @include('livewire.charter.sections._prose', ['value' => $veilsText])
                    </div>
                @endif
            </div>
        @endif

        {{-- §2 two-way: the signer's private answers, inline at the boundaries
             section (concept §2/§4). Rendered only for an eligible signer —
             guests, non-participants, pending participants, and anyone who
             already signed keep the byte-identical read-only markup. --}}
        @if($canSign)
            <div class="pt-6 border-t border-outline/20">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary text-lg" aria-hidden="true">lock</span>
                    <h4 class="font-heading text-base font-semibold text-on-surface">{{ __('charter.heading_private_answers') }}</h4>
                </div>
                <p class="mt-1 text-xs text-on-surface-variant">{{ __('charter.description_private_answers_intro') }}</p>

                {{-- My lines --}}
                <div class="mt-5">
                    <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_my_lines_label') }}</p>
                    <p class="mt-1 text-xs text-on-surface-variant">{{ __('charter.field_my_lines_help') }}</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach(\App\Dto\CharterContent::BOUNDARY_CATEGORIES as $category)
                            <label class="cursor-pointer">
                                <input type="checkbox" value="{{ $category }}" wire:model="myLinesCategories" class="sr-only peer">
                                <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full border border-outline/30 text-xs font-medium text-on-surface-variant peer-checked:bg-error/10 peer-checked:text-error peer-checked:border-error/40 transition-colors">
                                    {{ __('charter.label_boundary_'.$category) }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <label for="charter-my-lines-text" class="mt-4 block text-xs font-medium text-on-surface-variant">{{ __('charter.field_my_lines_text_label') }}</label>
                    <input
                        id="charter-my-lines-text"
                        type="text"
                        wire:model="myLinesText"
                        maxlength="2000"
                        placeholder="{{ __('charter.field_boundaries_lines_placeholder') }}"
                        class="mt-1 w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm px-3 py-2 focus:border-primary/50 focus:ring-2 focus:ring-primary/20 outline-none"
                    >
                    @error('private_answers.my_lines_text')
                        <p class="mt-1 text-xs text-error">{{ $message }}</p>
                    @enderror
                </div>

                {{-- My veils --}}
                <div class="mt-5">
                    <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_my_veils_label') }}</p>
                    <p class="mt-1 text-xs text-on-surface-variant">{{ __('charter.field_my_veils_help') }}</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach(\App\Dto\CharterContent::BOUNDARY_CATEGORIES as $category)
                            <label class="cursor-pointer">
                                <input type="checkbox" value="{{ $category }}" wire:model="myVeilsCategories" class="sr-only peer">
                                <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full border border-outline/30 text-xs font-medium text-on-surface-variant peer-checked:bg-secondary/10 peer-checked:text-secondary peer-checked:border-secondary/40 transition-colors">
                                    {{ __('charter.label_boundary_'.$category) }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <label for="charter-my-veils-text" class="mt-4 block text-xs font-medium text-on-surface-variant">{{ __('charter.field_my_veils_text_label') }}</label>
                    <input
                        id="charter-my-veils-text"
                        type="text"
                        wire:model="myVeilsText"
                        maxlength="2000"
                        placeholder="{{ __('charter.field_boundaries_veils_placeholder') }}"
                        class="mt-1 w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm px-3 py-2 focus:border-primary/50 focus:ring-2 focus:ring-primary/20 outline-none"
                    >
                    @error('private_answers.my_veils_text')
                        <p class="mt-1 text-xs text-error">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Note to the GM --}}
                <div class="mt-5">
                    <label for="charter-note-to-gm" class="block text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_note_to_gm_label') }}</label>
                    <p class="mt-1 text-xs text-on-surface-variant">{{ __('charter.field_note_to_gm_help') }}</p>
                    <textarea
                        id="charter-note-to-gm"
                        wire:model="noteToGm"
                        rows="3"
                        maxlength="2000"
                        class="mt-1 w-full rounded-lg bg-surface-container-high border border-outline/20 text-on-surface text-sm px-3 py-2 focus:border-primary/50 focus:ring-2 focus:ring-primary/20 outline-none"
                    ></textarea>
                    @error('private_answers.note_to_gm')
                        <p class="mt-1 text-xs text-error">{{ $message }}</p>
                    @enderror
                </div>

                <p class="mt-4 flex items-start gap-1.5 text-xs text-on-surface-variant">
                    <span class="material-symbols-outlined text-sm mt-px shrink-0" aria-hidden="true">visibility_lock</span>
                    {{ __('charter.description_privacy_note') }}
                </p>
            </div>
        @endif
    </div>
</section>
@endif
