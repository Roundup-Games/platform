<div class="py-8">
    <div class="max-w-2xl mx-auto space-y-8">
        {{-- Page Header --}}
        <div>
            <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('support.title_account_support') }}</h1>
            <p class="mt-1 text-sm text-on-surface-variant">{{ __('support.description_account_support') }}</p>
        </div>

        {{-- Success Message --}}
        @if($successMessage)
            <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 5000)"
                 class="rounded-lg bg-secondary-container p-4" role="status" aria-live="polite">
                <p class="text-sm text-on-secondary-container flex items-center gap-2">
                    <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">check_circle</span>
                    {{ $successMessage }}
                </p>
            </div>
        @endif

        {{-- Support Form --}}
        <form wire:submit="submitSupport" class="space-y-6">
            {{-- Issue Type --}}
            <div>
                <label for="issueType" class="block text-sm font-medium text-on-surface-variant mb-1">
                    {{ __('support.field_issue_type') }} <span class="text-error">*</span>
                </label>
                <select wire:model="issueType" id="issueType"
                        aria-invalid="@error('issueType') true @else false @enderror"
                        aria-describedby="issueType-error"
                        class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm">
                    @foreach($issueTypes as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('issueType')
                    <p id="issueType-error" class="mt-1 text-sm text-error">{{ $message }}</p>
                @enderror
            </div>

            {{-- Subject --}}
            <div>
                <label for="subject" class="block text-sm font-medium text-on-surface-variant mb-1">
                    {{ __('support.field_subject') }} <span class="text-error">*</span>
                </label>
                <input type="text" wire:model="subject" id="subject"
                       aria-invalid="@error('subject') true @else false @enderror"
                       aria-describedby="subject-error"
                       class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"
                       placeholder="{{ __('support.placeholder_subject') }}" />
                @error('subject')
                    <p id="subject-error" class="mt-1 text-sm text-error">{{ $message }}</p>
                @enderror
            </div>

            {{-- Description --}}
            <div>
                <label for="description" class="block text-sm font-medium text-on-surface-variant mb-1">
                    {{ __('support.field_description') }} <span class="text-error">*</span>
                </label>
                <textarea wire:model="description" id="description" rows="6"
                          aria-invalid="@error('description') true @else false @enderror"
                          aria-describedby="description-error"
                          class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"
                          placeholder="{{ __('support.placeholder_description') }}"></textarea>
                @error('description')
                    <p id="description-error" class="mt-1 text-sm text-error">{{ $message }}</p>
                @enderror
            </div>

            {{-- Submit --}}
            <div class="flex justify-end">
                <button type="submit" wire:loading.attr="disabled"
                        class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-[0.96] transition-all text-sm font-medium">
                    <span class="material-symbols-outlined text-base" wire:loading.remove aria-hidden="true">contact_support</span>
                    <span wire:loading.remove>{{ __('support.action_submit_ticket') }}</span>
                    <span wire:loading class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-base animate-spin" aria-hidden="true">progress_activity</span>
                        {{ __('common.content_saving') }}
                    </span>
                </button>
            </div>
        </form>

        {{-- Help Info --}}
        <section class="bg-surface-container-low rounded-xl p-6">
            <h3 class="font-heading font-semibold text-sm text-on-surface tracking-tight mb-3">{{ __('support.content_need_help_fast') }}</h3>
            <div class="space-y-2 text-sm text-on-surface-variant">
                <p>{{ __('support.content_account_support_team_responds') }}</p>
                <p>{{ __('support.content_for_billing_issues_visit_billing') }}</p>
            </div>
        </section>
    </div>
</div>
