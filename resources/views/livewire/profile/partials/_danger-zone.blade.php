{{-- Danger Zone: Account Deletion --}}
<section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 border-l-4 border-error">
    <h2 class="text-lg font-heading font-semibold text-error mb-2 tracking-tight flex items-center gap-2">
        <span class="material-symbols-outlined text-lg" aria-hidden="true">warning</span>
        {{ __('profile.action_delete_account') }}
    </h2>
    <p class="text-sm text-on-surface-variant mb-4">
        {{ __('profile.error_once_you_delete_your_account') }}
    </p>

    @if(!$showDeleteForm)
        <button wire:click="$set('showDeleteForm', true)"
                class="inline-flex items-center gap-1.5 px-4 py-2 bg-error-container text-on-error-container rounded-lg text-sm font-medium hover:brightness-110 transition-all">
            <span class="material-symbols-outlined text-base" aria-hidden="true">delete_forever</span>
            {{ __('profile.action_delete_account') }}
        </button>
    @else
        <div class="space-y-4 mt-4 pt-4 border-t border-error/20">
            @if($userHasPassword)
                <div>
                    <label for="delete-password" class="block text-sm font-medium text-on-surface mb-1">{{ __('auth.field_confirm_your_password') }}</label>
                    <input type="password" id="delete-password" wire:model="delete_password" autocomplete="current-password"
                           class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 shadow-sm focus:border-error/30 focus:ring-1 focus:ring-error/30 text-on-surface" />
                    @error('delete_password') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
            @else
                <div>
                    <label for="delete-confirm" class="block text-sm font-medium text-on-surface mb-1">
                        {!! __('discovery.content_type_word_to_confirm', ['word' => '<strong class="text-error">DELETE</strong>']) !!}
                    </label>
                    <input type="text" id="delete-confirm" wire:model="delete_confirmation" autocomplete="off"
                           class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 shadow-sm focus:border-error/30 focus:ring-1 focus:ring-error/30 text-on-surface" />
                    @error('delete_confirmation') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
            @endif

            <div class="flex items-center gap-3">
                <button wire:click="deleteAccount" wire:loading.attr="disabled"
                        class="inline-flex items-center gap-1.5 px-4 py-2 bg-error-container text-on-error-container rounded-lg text-sm font-medium hover:brightness-110 transition-all">
                    <span wire:loading.remove>{{ __('profile.content_permanently_delete_account') }}</span>
                    <span wire:loading>{{ __('common.content_deleting') }}</span>
                </button>
                <button type="button" wire:click="$set('showDeleteForm', false)"
                        class="px-4 py-2.5 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
                    {{ __('common.action_cancel') }}
                </button>
            </div>
        </div>
    @endif
</section>
