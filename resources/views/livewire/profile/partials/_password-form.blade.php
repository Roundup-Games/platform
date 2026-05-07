{{-- Password Change Form --}}
<section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface flex items-center gap-2">
            <span class="material-symbols-outlined text-lg text-on-surface-variant" aria-hidden="true">lock</span>
            {{ __('auth.field_password') }}
        </h2>
        @if(!$showPasswordForm)
            <button wire:click="$set('showPasswordForm', true)"
                    class="text-sm text-primary hover:brightness-110 transition-colors font-medium">
                {{ $userHasPassword ? __('auth.field_change_password') : __('auth.field_set_password') }}
            </button>
        @endif
    </div>

    @if($showPasswordForm)
        <form wire:submit="changePassword" class="space-y-4">
            @if($userHasPassword)
                <div>
                    <label for="profile-current-password" class="block text-sm font-medium text-on-surface mb-1">{{ __('auth.field_current_password') }}</label>
                    <input type="password" id="profile-current-password" wire:model="current_password" autocomplete="current-password"
                           class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface" />
                    @error('current_password') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                </div>
            @else
                <div class="rounded-lg bg-primary/5 border border-primary/10 p-3">
                    <p class="text-sm text-on-surface-variant flex items-start gap-2">
                        <span class="material-symbols-outlined text-base text-primary mt-0.5 shrink-0" style="font-variation-settings: 'FILL' 1">info</span>
                        {{ __('emails.content_your_account_was_created_via', ['provider' => $linkedAccounts->count() > 0 ? $linkedAccounts->first()->provider : __('common.content_a_third_party_provider')]) }}
                    </p>
                </div>
            @endif

            <div>
                <label for="profile-new-password" class="block text-sm font-medium text-on-surface mb-1">{{ __('auth.field_new_password') }}</label>
                <input type="password" id="profile-new-password" wire:model="password" autocomplete="new-password"
                       class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface" />
                @error('password') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="profile-confirm-password" class="block text-sm font-medium text-on-surface mb-1">{{ __('auth.field_confirm_password') }}</label>
                <input type="password" id="profile-confirm-password" wire:model="password_confirmation" autocomplete="new-password"
                       class="w-full rounded-lg bg-surface-container-high border border-transparent px-4 py-2.5 shadow-sm focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 text-on-surface" />
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" wire:loading.attr="disabled"
                        class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-[0.96] transition-all text-sm font-medium">
                    <span wire:loading.remove>{{ $userHasPassword ? __('auth.field_update_password') : __('auth.field_set_password') }}</span>
                    <span wire:loading>{{ $userHasPassword ? __('common.content_updating') : __('profile.content_setting') }}</span>
                </button>
                <button type="button" wire:click="$set('showPasswordForm', false)"
                        class="px-4 py-2.5 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
                    {{ __('common.action_cancel') }}
                </button>
            </div>
        </form>
    @else
        @if($userHasPassword)
            <p class="text-sm text-on-surface-variant">{{ __('auth.content_your_password_is_set_click') }}</p>
        @else
            <p class="text-sm text-on-surface-variant flex items-center gap-2">
                <span class="material-symbols-outlined text-base text-amber-500" aria-hidden="true">warning</span>
                {{ __('auth.content_no_password_set_you_currently') }}
            </p>
        @endif
    @endif
</section>

@if(session('password_updated'))
    <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
         class="rounded-lg bg-secondary-container p-4" role="status" aria-live="polite">
        <p class="text-sm text-on-secondary-container flex items-center gap-2">
            <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">check_circle</span>
            {{ session('password_updated') }}
        </p>
    </div>
@endif
