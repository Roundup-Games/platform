{{-- Social Links Form for GM Profile --}}
{{-- Iterates over platform config and renders handle inputs --}}

<form wire:submit="saveSocialLinks" class="space-y-6">
    <section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
        <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-4 flex items-center gap-2">
            <span class="material-symbols-outlined text-lg text-on-surface-variant" aria-hidden="true">link</span>
            {{ __('profile.gm_social_links_title') }}
        </h2>

        <div class="space-y-4">
            @foreach($platforms as $key => $platform)
                <div class="p-3 sm:p-4 bg-surface-container-low rounded-lg">
                    <div class="flex items-center gap-3 mb-2">
                        <span class="material-symbols-outlined text-lg text-on-surface-variant shrink-0" aria-hidden="true">{{ $platform['icon'] }}</span>
                        <label for="social-{{ $key }}" class="text-sm font-medium text-on-surface">{{ $platform['name'] }}</label>
                    </div>

                    <div class="flex items-center gap-2">
                        @if($platform['at_prefixed'])
                            <span class="text-sm text-on-surface-variant font-medium select-none">@</span>
                        @endif
                        <input
                            type="text"
                            id="social-{{ $key }}"
                            wire:model="socialLinks.{{ $key }}.handle"
                            placeholder="{{ $platform['at_prefixed'] ? __('profile.gm_social_handle_placeholder_at') : __('profile.gm_social_handle_placeholder') }}"
                            class="flex-1 rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm text-on-surface placeholder:text-on-surface-variant/50 focus:border-primary focus:ring-1 focus:ring-primary outline-none transition-colors"
                            autocomplete="off"
                        />
                    </div>

                    {{-- Instance field for Mastodon --}}
                    @if(($platform['instance_required'] ?? false))
                        <div class="mt-2">
                            <label for="social-{{ $key }}-instance" class="text-xs font-medium text-on-surface-variant mb-1 block">
                                {{ __('profile.gm_social_instance_label') }}
                            </label>
                            <input
                                type="text"
                                id="social-{{ $key }}-instance"
                                wire:model="socialLinks.{{ $key }}.instance"
                                placeholder="mastodon.social"
                                class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm text-on-surface placeholder:text-on-surface-variant/50 focus:border-primary focus:ring-1 focus:ring-primary outline-none transition-colors"
                                autocomplete="off"
                            />
                        </div>
                    @endif

                    @error("socialLinks.{$key}.handle")
                        <p class="mt-1.5 text-xs text-error">{{ $message }}</p>
                    @enderror
                    @error("socialLinks.{$key}.instance")
                        <p class="mt-1.5 text-xs text-error">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach
        </div>

        @error('socialLinks')
            <p class="mt-4 text-sm text-error">{{ $message }}</p>
        @enderror
    </section>

    {{-- Save --}}
    <div class="flex justify-end">
        <button type="submit" wire:loading.attr="disabled"
                class="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-on-primary rounded-lg shadow-ambient hover:brightness-110 active:scale-[0.96] transition-all text-sm font-medium">
            <span class="material-symbols-outlined text-base" wire:loading.remove aria-hidden="true">save</span>
            <span wire:loading.remove>{{ __('common.action_save_changes') }}</span>
            <span wire:loading class="flex items-center gap-2">
                <span class="material-symbols-outlined text-base animate-spin" aria-hidden="true">progress_activity</span>
                {{ __('common.content_saving') }}
            </span>
        </button>
    </div>
</form>
