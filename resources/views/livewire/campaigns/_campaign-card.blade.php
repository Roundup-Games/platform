{{--
    Reusable campaign card for the My Campaigns board.

    @var \App\Models\Campaign $campaign
    @var bool $asOrganizer  Render organizer actions (edit/complete/cancel) vs player (leave).
--}}
@php
    $isActive = $campaign->status->value === 'active';
    $statusClass = $campaign->status->value === 'active'
        ? 'bg-primary-container text-on-primary-container'
        : ($campaign->status->value === 'completed'
            ? 'bg-secondary-container text-on-secondary-container'
            : 'bg-error-container text-on-error-container');
@endphp

<div class="bg-surface-container-low rounded-xl shadow-ambient overflow-hidden">
    <a href="{{ route('campaigns.show', $campaign) }}" wire:navigate
       class="group block p-4 sm:p-5 hover:bg-surface-container/50 transition-colors @if(!$asOrganizer) @endif">
        <div class="flex flex-wrap items-center gap-2 mb-2">
            <h3 class="text-base font-medium text-on-surface @if(!$asOrganizer) group-hover:text-secondary transition-colors @endif">
                {{ $campaign->name }}
            </h3>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $statusClass }}">
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
            @if(!$asOrganizer && $campaign->owner)
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

    @if($asOrganizer && $isActive)
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
            <x-confirm-action
                action="cancelCampaign('{{ $campaign->id }}')"
                id="cancel-campaign-{{ $campaign->id }}"
                :icon="'cancel'"
                :trigger-label="__('campaigns.action_cancel_campaign')"
                trigger-class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium text-error hover:bg-error/10 transition-colors"
                :confirm-label="__('campaigns.action_cancel_campaign')"
                :cancel-label="__('common.action_keep')"
                :message="__('campaigns.confirm_cancel_campaign')"
                variant="inline"
                severity="destructive"
                confirm-icon="cancel"
            />
        </div>
    @elseif(!$asOrganizer && $isActive)
        <div class="border-t border-outline-variant/30 px-4 py-2 sm:px-5">
            <x-confirm-action
                action="leaveCampaign('{{ $campaign->id }}')"
                id="leave-campaign-{{ $campaign->id }}"
                :icon="'logout'"
                :trigger-label="__('campaigns.action_leave_campaign')"
                trigger-class="inline-flex items-center gap-1.5 text-xs font-medium text-on-surface-variant hover:text-error transition-colors"
                :confirm-label="__('campaigns.action_leave_campaign')"
                :cancel-label="__('common.action_keep')"
                :message="__('campaigns.confirm_leave_campaign')"
                variant="inline"
                severity="destructive"
                confirm-icon="logout"
            />
        </div>
    @endif
</div>
