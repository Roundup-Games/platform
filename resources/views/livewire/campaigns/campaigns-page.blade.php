<div class="py-8">
    <div class="max-w-5xl mx-auto px-4 sm:px-6 space-y-10">

        {{-- Page Header --}}
        <div>
            <div class="flex items-center gap-3 mb-1">
                <a href="{{ route('dashboard') }}" wire:navigate class="text-on-surface-variant hover:text-on-surface transition-colors">
                    <span class="material-symbols-outlined text-xl" aria-hidden="true">arrow_back</span>
                </a>
                <h1 class="text-2xl font-heading font-bold tracking-tight text-on-surface">{{ __('campaigns.content_campaigns') }}</h1>
            </div>
        </div>

        {{-- Flash Messages --}}
        @if(session('success'))
            <div class="rounded-lg bg-secondary-container text-on-secondary-container px-4 py-3 text-sm flex items-center gap-2">
                <span class="material-symbols-outlined text-base" aria-hidden="true">check_circle</span>
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="rounded-lg bg-error-container text-on-error-container px-4 py-3 text-sm flex items-center gap-2">
                <span class="material-symbols-outlined text-base" aria-hidden="true">error</span>
                {{ session('error') }}
            </div>
        @endif

        {{-- My Campaigns Section --}}
        <section>
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-heading font-semibold text-on-surface">{{ __('campaigns.heading_my_campaigns') }}</h2>
                <a href="{{ route('campaigns.create') }}" wire:navigate
                   class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-primary text-on-primary text-sm font-semibold shadow-sm hover:opacity-90 active:scale-[0.98] transition ease-in-out duration-150 whitespace-nowrap">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">add</span>
                    {{ __('common.action_create') }}
                </a>
            </div>

            @if($ownedCampaigns->isEmpty())
                <div class="bg-surface-container-low rounded-xl p-8 text-center">
                    <span class="material-symbols-outlined text-4xl text-on-surface-variant mb-2 block" aria-hidden="true">campaign</span>
                    <p class="text-on-surface-variant text-sm">{{ __('campaigns.content_no_owned_campaigns') }}</p>
                    <a href="{{ route('campaigns.create') }}" wire:navigate
                       class="mt-3 inline-flex items-center gap-1.5 px-4 py-2 rounded-xl text-sm font-semibold bg-primary text-on-primary shadow-sm hover:opacity-90 active:scale-[0.98] transition ease-in-out duration-150 whitespace-nowrap">
                        <span class="material-symbols-outlined text-base" aria-hidden="true">add</span>
                        {{ __('common.action_create') }}
                    </a>
                </div>
            @else
                <div class="space-y-3">
                    @foreach($ownedCampaigns as $campaign)
                        <div class="bg-surface-container-low rounded-xl shadow-ambient overflow-hidden">
                            {{-- Info area: clickable to detail --}}
                            <a href="{{ route('campaigns.detail', $campaign->id) }}" wire:navigate class="block p-4 sm:p-5 hover:bg-surface-container/50 transition-colors">
                                <div class="flex flex-wrap items-center gap-2 mb-2">
                                    <h3 class="text-base font-medium text-on-surface">
                                        {{ $campaign->name }}
                                    </h3>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                        {{ $campaign->status->value === 'active' ? 'bg-primary-container text-on-primary-container' : ($campaign->status->value === 'completed' ? 'bg-secondary-container text-on-secondary-container' : 'bg-error-container text-on-error-container') }}">
                                        {{ __('campaigns.status_' . $campaign->status->value) }}
                                    </span>
                                    @if($campaign->gameSystem)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container-high text-on-surface-variant">
                                            {{ $campaign->gameSystem->name }}
                                        </span>
                                    @endif
                                </div>
                                <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-on-surface-variant">
                                    @if($campaign->recurrence)
                                        <span class="flex items-center gap-1">
                                            <span class="material-symbols-outlined text-sm" aria-hidden="true">repeat</span>
                                            {{ __('campaigns.content_' . $campaign->recurrence) }}
                                        </span>
                                    @endif
                                    <span class="flex items-center gap-1">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">group</span>
                                        {{ $campaign->participants->count() }}/{{ $campaign->max_players ?? '∞' }}
                                    </span>
                                </div>
                            </a>

                            {{-- Actions footer --}}
                            @if($campaign->status->value === 'active')
                                <div class="border-t border-outline-variant/20 px-4 sm:px-5 py-2.5 flex flex-wrap gap-1">
                                    <button wire:click="editCampaign('{{ $campaign->id }}')"
                                            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium text-on-surface-variant hover:bg-surface-container-high transition-colors"
                                            aria-label="{{ __('campaigns.action_edit_campaign') }}">
                                        <span class="material-symbols-outlined text-base" aria-hidden="true">edit</span>
                                        <span class="hidden sm:inline">{{ __('campaigns.action_edit_campaign') }}</span>
                                    </button>
                                    <button wire:click="completeCampaign('{{ $campaign->id }}')"
                                            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium text-secondary hover:bg-secondary/10 transition-colors"
                                            aria-label="{{ __('campaigns.action_complete_campaign') }}">
                                        <span class="material-symbols-outlined text-base" aria-hidden="true">check_circle</span>
                                        <span class="hidden sm:inline">{{ __('campaigns.action_complete_campaign') }}</span>
                                    </button>
                                    <button wire:click="cancelCampaign('{{ $campaign->id }}')"
                                            wire:confirm="{{ __('campaigns.confirm_cancel_campaign') }}"
                                            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium text-error hover:bg-error/10 transition-colors"
                                            aria-label="{{ __('campaigns.action_cancel_campaign') }}">
                                        <span class="material-symbols-outlined text-base" aria-hidden="true">cancel</span>
                                        <span class="hidden sm:inline">{{ __('campaigns.action_cancel_campaign') }}</span>
                                    </button>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Campaigns I'm In Section --}}
        <section>
            <h2 class="text-xl font-heading font-semibold text-on-surface mb-4">{{ __('campaigns.heading_campaigns_im_in') }}</h2>

            @if($participatingCampaigns->isEmpty())
                <div class="bg-surface-container-low rounded-xl p-8 text-center">
                    <span class="material-symbols-outlined text-4xl text-on-surface-variant mb-2 block" aria-hidden="true">group</span>
                    <p class="text-on-surface-variant text-sm">{{ __('campaigns.content_no_campaigns_joined') }}</p>
                </div>
            @else
                <div class="space-y-3">
                    @foreach($participatingCampaigns as $campaign)
                        <a href="{{ route('campaigns.detail', $campaign->id) }}" wire:navigate class="block bg-surface-container-low rounded-xl shadow-ambient p-4 sm:p-5 hover:bg-surface-container/50 transition-colors">
                            <div class="flex flex-wrap items-center gap-2 mb-2">
                                <h3 class="text-base font-medium text-on-surface">
                                    {{ $campaign->name }}
                                </h3>
                                @if($campaign->gameSystem)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container-high text-on-surface-variant">
                                        {{ $campaign->gameSystem->name }}
                                    </span>
                                @endif
                            </div>
                            <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-on-surface-variant">
                                @if($campaign->recurrence)
                                    <span class="flex items-center gap-1">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">repeat</span>
                                        {{ __('campaigns.content_' . $campaign->recurrence) }}
                                    </span>
                                @endif
                                @if($campaign->owner)
                                    <span class="flex items-center gap-1">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">person</span>
                                        {{ $campaign->owner->name }}
                                    </span>
                                @endif
                                <span class="flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm" aria-hidden="true">group</span>
                                    {{ $campaign->participants->count() }}/{{ $campaign->max_players ?? '∞' }}
                                </span>
                            </div>
                        </a>
                    @endforeach>
                </div>
            @endif
        </section>

        {{-- Open Invitations Section --}}
        @if($pendingInvitations->isNotEmpty())
        <section>
            <h2 class="text-xl font-heading font-semibold text-on-surface mb-4">{{ __('campaigns.heading_open_invitations') }}</h2>

            <div class="space-y-3">
                @foreach($pendingInvitations as $invitation)
                    @php $campaign = $invitation->campaign; @endphp
                    <div class="bg-surface-container-low rounded-xl shadow-ambient overflow-hidden border-l-4 border-primary">
                        {{-- Info area --}}
                        <div class="p-4 sm:p-5">
                            <div class="flex flex-wrap items-center gap-2 mb-2">
                                <h3 class="text-base font-medium text-on-surface">
                                    {{ $campaign->name }}
                                </h3>
                                @if($campaign->gameSystem)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container-high text-on-surface-variant">
                                        {{ $campaign->gameSystem->name }}
                                    </span>
                                @endif
                            </div>
                            <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-on-surface-variant">
                                @if($campaign->recurrence)
                                    <span class="flex items-center gap-1">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">repeat</span>
                                        {{ __('campaigns.content_' . $campaign->recurrence) }}
                                    </span>
                                @endif
                                @if($campaign->owner)
                                    <span class="flex items-center gap-1">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">person</span>
                                        {{ $campaign->owner->name }}
                                    </span>
                                @endif
                            </div>
                        </div>

                        {{-- Actions footer --}}
                        <div class="border-t border-outline-variant/20 px-4 sm:px-5 py-2.5 flex flex-wrap gap-1">
                            <button wire:click="acceptInvitation('{{ $invitation->id }}')"
                                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium text-secondary hover:bg-secondary/10 transition-colors"
                                    aria-label="{{ __('campaigns.action_accept_invitation') }}">
                                <span class="material-symbols-outlined text-base" aria-hidden="true">check</span>
                                <span class="hidden sm:inline">{{ __('campaigns.action_accept_invitation') }}</span>
                            </button>
                            <button wire:click="declineInvitation('{{ $invitation->id }}')"
                                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium text-error hover:bg-error/10 transition-colors"
                                    aria-label="{{ __('campaigns.action_decline_invitation') }}">
                                <span class="material-symbols-outlined text-base" aria-hidden="true">close</span>
                                <span class="hidden sm:inline">{{ __('campaigns.action_decline_invitation') }}</span>
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
        @endif


        {{-- Community Activity Feed --}}
        @include('livewire.partials.activity-feed', ['activityFeed' => $activityFeed, 'entityType' => 'campaign'])

        {{-- Edit Campaign Modal --}}
        @if($editingCampaignId)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" wire:click="cancelEdit">
                <div class="bg-surface-container-low rounded-2xl shadow-xl w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto" wire:click.stop>
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-lg font-heading font-semibold text-on-surface">{{ __('campaigns.heading_edit_campaign') }}</h2>
                            <button wire:click="cancelEdit" class="text-on-surface-variant hover:text-on-surface transition-colors">
                                <span class="material-symbols-outlined" aria-hidden="true">close</span>
                            </button>
                        </div>

                        <form wire:submit="saveCampaignEdit" class="space-y-4">
                            <div>
                                <label for="edit-campaign-name" class="block text-sm font-medium text-on-surface mb-1">{{ __('campaigns.field_campaign_name') }}</label>
                                <input type="text" id="edit-campaign-name" wire:model="edit_name"
                                       class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                                @error('edit_name') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="edit-campaign-description" class="block text-sm font-medium text-on-surface mb-1">{{ __('games.field_description') }}</label>
                                <textarea id="edit-campaign-description" wire:model="edit_description" rows="3"
                                          class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors"></textarea>
                                @error('edit_description') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label for="edit-campaign-duration" class="block text-sm font-medium text-on-surface mb-1">{{ __('campaigns.field_duration') }}</label>
                                    <input type="number" id="edit-campaign-duration" wire:model="edit_session_duration" step="0.5" min="0.5" max="24"
                                           class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors" />
                                    @error('edit_session_duration') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="edit-campaign-visibility" class="block text-sm font-medium text-on-surface mb-1">{{ __('campaigns.field_visibility') }}</label>
                                    <select id="edit-campaign-visibility" wire:model="edit_visibility"
                                            class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors">
                                        <option value="public">{{ __('common.content_public') }}</option>
                                        <option value="protected">{{ __('common.content_protected') }}</option>
                                        <option value="private">{{ __('common.content_private') }}</option>
                                    </select>
                                    @error('edit_visibility') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            <div class="flex justify-end gap-3 pt-2">
                                <button type="button" wire:click="cancelEdit"
                                        class="px-4 py-2 rounded-lg text-sm font-medium text-on-surface-variant hover:bg-surface-container-high transition-colors">
                                    {{ __('common.action_cancel') }}
                                </button>
                                <button type="submit"
                                        class="px-4 py-2 rounded-lg text-sm font-medium bg-secondary text-on-secondary hover:bg-secondary/90 transition-colors">
                                    {{ __('common.action_save_changes') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif

    </div>
</div>
