{{-- Capacity editor (host affordance, Game-specific) --}}

@php
    $approvedCount = $game->participants->where('status.value', 'approved')->count();
    $canEdit = $isOwner
        && $game->status->value === 'scheduled'
        && $game->attendance_resolved_at === null;
    $preview = $capacityDemotionPreview ?? null;
@endphp

@if($canEdit)
    <section class="bg-surface-container-low rounded-xl shadow-ambient p-6"
             x-data="{ editing: false }">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <h2 class="text-lg font-heading font-bold text-on-surface flex items-center gap-2">
                <span class="material-symbols-outlined text-xl" aria-hidden="true">group_add</span>
                {{ __('games.title_capacity') }}
            </h2>
            <span class="text-sm text-on-surface-variant">
                {{ __('games.label_current_capacity', [
                    'approved' => $approvedCount,
                    'max' => $game->max_players ?: '∞',
                ]) }}
            </span>
        </div>

        {{-- Inline edit form --}}
        <div x-show="!editing" x-cloak class="mt-3">
            <button type="button" @click="editing = true"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-lg bg-primary/10 text-primary hover:bg-primary/20 transition-colors">
                <span class="material-symbols-outlined text-base" aria-hidden="true">edit</span>
                {{ __('games.action_edit_capacity') }}
            </button>
        </div>

        <form x-show="editing" x-cloak wire:submit.prevent="updateCapacity({{ $capacityNewMax ?? 'null' }})"
              class="mt-3 flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[140px]">
                <label for="capacityNewMax" class="block text-xs font-medium text-on-surface-variant mb-1">
                    {{ __('games.label_max_players') }}
                </label>
                <input id="capacityNewMax" type="number" min="2" max="30"
                    wire:model="capacityNewMax"
                    placeholder="{{ __('games.placeholder_capacity_new_max') }}"
                    class="w-full px-3 py-2 rounded-lg border border-outline-variant bg-surface text-on-surface focus:border-primary focus:ring-1 focus:ring-primary outline-none" />
                @error('capacityNewMax')
                    <p class="mt-1 text-xs text-error">{{ $message }}</p>
                @enderror
            </div>
            <div class="flex gap-2">
                <button type="submit"
                    class="inline-flex items-center gap-1.5 px-4 py-2 bg-primary text-on-primary text-sm font-medium rounded-lg hover:opacity-90 transition-opacity">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">save</span>
                    {{ __('games.action_save_capacity') }}
                </button>
                <button type="button" @click="editing = false; $wire.set('capacityNewMax', null, false)"
                    class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg text-on-surface-variant hover:bg-surface-container-high transition-colors">
                    {{ __('common.action_cancel') }}
                </button>
            </div>
        </form>

        {{-- Demotion confirm modal (Alpine) --}}
        @if($preview && $preview->actualDemotionCount > 0)
            <div x-data="{ show: true }"
                 x-show="show"
                 x-transition.opacity
                 x-cloak
                 class="mt-4 rounded-xl border border-warning/30 bg-warning/5 p-5"
                 role="dialog"
                 aria-modal="true"
                 aria-labelledby="capacity-confirm-title">
                <h3 id="capacity-confirm-title" class="text-base font-heading font-bold text-on-surface flex items-center gap-2">
                    <span class="material-symbols-outlined text-xl text-warning" aria-hidden="true">warning</span>
                    {{ __('games.title_confirm_capacity_decrease') }}
                </h3>

                <p class="mt-2 text-sm text-on-surface-variant">
                    @if($preview->actualDemotionCount === 1)
                        {{ __('games.content_capacity_displaced_one') }}
                    @else
                        {{ __('games.content_capacity_displaced_many', ['count' => $preview->actualDemotionCount]) }}
                    @endif
                </p>

                {{-- Players who would be demoted --}}
                <ul class="mt-2 space-y-1">
                    @foreach($preview->wouldDemote as $row)
                        <li class="flex items-center gap-2 text-sm text-on-surface">
                            <span class="material-symbols-outlined text-base text-warning" aria-hidden="true">arrow_downward</span>
                            {{ $row['name'] }}
                        </li>
                    @endforeach
                </ul>

                {{-- Exempt players keep their seats --}}
                @if(!empty($preview->exempt))
                    <p class="mt-3 text-xs text-on-surface-variant">{{ __('games.content_capacity_exempt') }}</p>
                    <ul class="mt-1 flex flex-wrap gap-2">
                        @foreach($preview->exempt as $row)
                            <li class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-surface-container-high text-on-surface-variant">
                                {{ $row['name'] }}
                                <span class="text-on-surface-variant/70">
                                    ({{ $row['reason'] === 'owner'
                                        ? __('games.label_capacity_exempt_owner')
                                        : __('games.label_capacity_exempt_manual') }})
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                {{-- Reason textarea + confirm --}}
                <form wire:submit.prevent="updateCapacity({{ $pendingNewMax ?? 'null' }}, $wire.capacityReason)"
                      class="mt-4 space-y-3">
                    <div>
                        <label for="capacityReason" class="block text-xs font-medium text-on-surface-variant mb-1">
                            {{ __('games.content_capacity_reason_required') }}
                        </label>
                        <textarea id="capacityReason" rows="3" wire:model="capacityReason"
                            placeholder="{{ __('games.placeholder_capacity_reason') }}"
                            maxlength="500"
                            class="w-full px-3 py-2 rounded-lg border border-outline-variant bg-surface text-on-surface focus:border-primary focus:ring-1 focus:ring-primary outline-none"></textarea>
                        @error('capacityReason')
                            <p class="mt-1 text-xs text-error">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" wire:click="cancelCapacityDecrease"
                            class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg text-on-surface-variant hover:bg-surface-container-high transition-colors">
                            {{ __('common.action_cancel') }}
                        </button>
                        <button type="submit"
                            class="inline-flex items-center gap-1.5 px-4 py-2 bg-warning text-on-warning text-sm font-medium rounded-lg hover:opacity-90 transition-opacity">
                            <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_downward</span>
                            {{ __('games.action_confirm_capacity_decrease') }}
                        </button>
                    </div>
                </form>
            </div>
        @endif
    </section>
@endif
