<div>
    {{-- Report button --}}
    @if($successMessage)
        <span class="text-xs text-primary">{{ $successMessage }}</span>
    @else
        <button wire:click="openModal"
                class="inline-flex items-center gap-1 text-xs text-on-surface-variant hover:text-error transition-colors"
                title="{{ __('reports.action_report') }}">
            <span class="material-symbols-outlined text-sm">flag</span>
            {{ __('reports.action_report') }}
        </button>
    @endif

    {{-- Report modal --}}
    @if($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
             role="dialog"
             aria-modal="true"
             aria-labelledby="report-content-title"
             wire:click.self="closeModal">
            <div class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 w-full max-w-md mx-4">
                <h3 id="report-content-title" class="text-lg font-heading font-semibold text-on-surface mb-4">
                    {{ __('reports.title_report_content', ['type' => $this->getEntityTypeLabel()]) }}
                </h3>

                <p class="text-sm text-on-surface-variant mb-4">
                    {{ __('reports.content_report_explanation') }}
                </p>

                {{-- Reason selection --}}
                <div class="space-y-2 mb-4">
                    @foreach($this->getReasons() as $value => $label)
                        <label class="flex items-center gap-3 p-3 rounded-lg cursor-pointer transition-colors
                            {{ $reason === $value ? 'bg-primary/10 ring-1 ring-primary' : 'bg-surface-container-high hover:bg-surface-container' }}">
                            <input type="radio"
                                   wire:model="reason"
                                   value="{{ $value }}"
                                   class="text-primary focus:ring-primary" />
                            <span class="text-sm text-on-surface">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>

                @error('reason')
                    <p class="text-sm text-error mb-3">{{ $message }}</p>
                @enderror

                {{-- Optional description --}}
                <div class="mb-4">
                    <label class="block text-sm font-medium text-on-surface mb-1">
                        {{ __('reports.label_description') }}
                        <span class="text-on-surface-variant font-normal">({{ __('reports.label_optional') }})</span>
                    </label>
                    <textarea wire:model="description"
                              rows="3"
                              maxlength="1000"
                              class="w-full rounded-lg border border-outline bg-surface-container-high text-on-surface text-sm p-3 focus:ring-primary focus:border-primary resize-none"
                              placeholder="{{ __('reports.placeholder_description') }}"></textarea>
                    @error('description')
                        <p class="text-sm text-error mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Actions --}}
                <div class="flex items-center justify-end gap-3">
                    <button wire:click="closeModal"
                            class="px-4 py-2 rounded-lg text-sm font-medium text-on-surface-variant hover:bg-surface-container-high transition-colors">
                        {{ __('common.action_cancel') }}
                    </button>
                    <button wire:click="submitReport"
                            wire:loading.attr="disabled"
                            class="px-4 py-2 rounded-lg text-sm font-medium bg-error text-on-error hover:brightness-110 transition-colors disabled:opacity-50">
                        <span wire:loading.remove>{{ __('reports.action_submit_report') }}</span>
                        <span wire:loading>{{ __('reports.content_submitting') }}</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
