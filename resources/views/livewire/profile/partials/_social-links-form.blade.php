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
                            class="flex-1 rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm text-on-surface placeholder:text-on-surface-variant/50 focus:border-primary focus:ring-1 focus:ring-primary outline-hidden transition-colors"
                            autocomplete="off"
                        />
                    </div>

                    {{-- Discord auto-fill + 'Use my Discord' re-apply (M056 Q1 + S03/T11).
                        On mount, the Discord handle is auto-populated from the GM's
                        linked Discord LinkedAccount when no existing GmSocialLink
                        handle exists. GMs with a linked account see a one-click
                        button to re-apply the linked ID after manual edits; GMs
                        without one see help text directing them to link first. --}}
                    @if($key === 'discord')
                        @if($discordLinkedUserId)
                            @if($discordAutofilled)
                                <p class="mt-2 text-xs text-on-surface-variant flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm" aria-hidden="true">info</span>
                                    {{ __('profile.gm_social_discord_autofilled') }}
                                </p>
                            @endif
                            <div class="mt-2">
                                <button type="button" wire:click="useMyDiscord" class="btn-discord-prefill">
                                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" aria-hidden="true" fill="currentColor">
                                        <path d="M20.317 4.369A19.79 19.79 0 0 0 16.558 3.2a.074.074 0 0 0-.079.037c-.34.6-.717 1.385-.98 2.003a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.996-2.003.077.077 0 0 0-.079-.037A19.74 19.74 0 0 0 5.18 4.369a.07.07 0 0 0-.032.027C1.967 9.063 1.062 13.635 1.508 18.15a.082.082 0 0 0 .031.056 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028c.462-.63.874-1.295 1.226-1.994a.076.076 0 0 0-.041-.106 13.1 13.1 0 0 1-1.872-.892.077.077 0 0 1-.008-.128c.126-.094.252-.192.372-.291a.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.061 0a.074.074 0 0 1 .078.009c.12.099.246.198.373.292a.077.077 0 0 1-.006.127 12.3 12.3 0 0 1-1.873.891.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.84 19.84 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.028ZM8.02 15.331c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418Zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418Z"/>
                                    </svg>
                                    {{ __('profile.gm_social_use_my_discord') }}
                                </button>
                            </div>
                        @else
                            <p class="mt-2 text-xs text-on-surface-variant">
                                {{ __('profile.gm_social_link_discord_first') }}
                            </p>
                        @endif
                    @endif

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
                                class="w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm text-on-surface placeholder:text-on-surface-variant/50 focus:border-primary focus:ring-1 focus:ring-primary outline-hidden transition-colors"
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
