<div>
    {{-- Header --}}
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-on-surface leading-tight">
                    {{ __('events.action_manage_registrations') }}
                </h2>
                <p class="text-sm text-on-surface-variant mt-1">{{ $event->name }}</p>
            </div>
            <a href="{{ route('events.detail', ['slug' => $event->slug]) }}" wire:navigate class="text-sm text-primary hover:underline">
                {{ __('events.content_back_to_event') }}
            </a>
        </div>
    </x-slot>

    {{-- Flash messages --}}
    @if(session()->has('success'))
        <div class="mb-4 bg-secondary-container border border-secondary/20 rounded-lg p-3 text-sm text-on-secondary-container" role="status" aria-live="polite">
            {{ session('success') }}
        </div>
    @endif

    {{-- Summary Cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <div class="bg-surface-container-low rounded-xl shadow-ambient p-4 text-center">
            <p class="text-2xl font-bold text-on-surface">{{ $this->statusCounts['total'] }}</p>
            <p class="text-xs text-on-surface-variant tracking-wide">{{ __('common.content_total') }}</p>
        </div>
        <div class="bg-surface-container-low rounded-xl shadow-ambient p-4 text-center">
            <p class="text-2xl font-bold text-secondary">{{ $this->statusCounts['confirmed'] }}</p>
            <p class="text-xs text-on-surface-variant tracking-wide">{{ __('events.status_confirmed') }}</p>
        </div>
        <div class="bg-surface-container-low rounded-xl shadow-ambient p-4 text-center">
            <p class="text-2xl font-bold text-tertiary">{{ $this->statusCounts['pending'] }}</p>
            <p class="text-xs text-on-surface-variant tracking-wide">{{ __('common.status_pending') }}</p>
        </div>
        <div class="bg-surface-container-low rounded-xl shadow-ambient p-4 text-center">
            <p class="text-2xl font-bold text-error">{{ $this->statusCounts['cancelled'] }}</p>
            <p class="text-xs text-on-surface-variant tracking-wide">{{ __('events.status_cancelled') }}</p>
        </div>
    </div>

    {{-- Filters --}}
    <div class="bg-surface-container-low rounded-xl shadow-ambient p-4 mb-6">
        <div class="flex flex-wrap gap-3 items-end">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-medium text-on-surface-variant mb-1">{{ __('discovery.action_search') }}</label>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="{{ __('emails.content_name_email_or_team') }}"
                    class="w-full bg-surface-container-high border border-transparent rounded-lg text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 text-sm py-2" />
            </div>
            <div>
                <label class="block text-xs font-medium text-on-surface-variant mb-1">{{ __('common.content_status') }}</label>
                <select wire:model.live="filterStatus" class="bg-surface-container-high border border-transparent rounded-lg text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 text-sm py-2">
                    <option value="">{{ __('discovery.content_all_statuses') }}</option>
                    <option value="pending">{{ __('common.status_pending') }}</option>
                    <option value="confirmed">{{ __('events.status_confirmed') }}</option>
                    <option value="cancelled">{{ __('events.status_cancelled') }}</option>
                    <option value="waitlisted">{{ __('common.content_waitlisted') }}</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-on-surface-variant mb-1">{{ __('events.field_type') }}</label>
                <select wire:model.live="filterType" class="bg-surface-container-high border border-transparent rounded-lg text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 text-sm py-2">
                    <option value="">{{ __('discovery.content_all_types') }}</option>
                    <option value="team">{{ __('events.content_team') }}</option>
                    <option value="individual">{{ __('common.content_individual') }}</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-on-surface-variant mb-1">{{ __('billing.content_payment') }}</label>
                <select wire:model.live="filterPaymentStatus" class="bg-surface-container-high border border-transparent rounded-lg text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 text-sm py-2">
                    <option value="">{{ __('billing.content_all_payments') }}</option>
                    <option value="paid">{{ __('billing.content_paid') }}</option>
                    <option value="pending">{{ __('billing.field_payment_pending') }}</option>
                    <option value="not_required">{{ __('billing.content_free') }}</option>
                    <option value="refunded">{{ __('billing.status_refunded') }}</option>
                </select>
            </div>
            <button wire:click="clearFilters" class="text-sm text-on-surface-variant hover:text-on-surface py-2">
                {{ __('common.action_clear') }}
            </button>
        </div>
    </div>

    {{-- Registrations List --}}
    <div class="bg-surface-container-low rounded-xl shadow-ambient overflow-hidden">
        @if($this->registrations->isEmpty())
            <div class="text-center py-12">
                <p class="text-on-surface-variant">{{ __('events.content_no_registrations_found') }}</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-surface-container">
                        <tr>
                            <th class="text-left px-4 py-3 text-xs font-medium text-on-surface-variant tracking-wide">{{ __('common.content_registrant') }}</th>
                            <th class="text-left px-4 py-3 text-xs font-medium text-on-surface-variant tracking-wide">{{ __('events.field_type') }}</th>
                            <th class="text-left px-4 py-3 text-xs font-medium text-on-surface-variant tracking-wide">{{ __('events.content_division') }}</th>
                            <th class="text-left px-4 py-3 text-xs font-medium text-on-surface-variant tracking-wide">{{ __('common.content_status') }}</th>
                            <th class="text-left px-4 py-3 text-xs font-medium text-on-surface-variant tracking-wide">{{ __('billing.content_payment') }}</th>
                            <th class="text-left px-4 py-3 text-xs font-medium text-on-surface-variant tracking-wide">{{ __('events.status_registered') }}</th>
                            <th class="text-right px-4 py-3 text-xs font-medium text-on-surface-variant tracking-wide">{{ __('profile.content_actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/30">
                        @foreach($this->registrations as $registration)
                            <tr class="hover:bg-surface-container-low/50">
                                {{-- Registrant --}}
                                <td class="px-4 py-3">
                                    <div>
                                        <x-user-link :user="$registration->user" :show-avatar="false" />
                                        <p class="text-xs text-on-surface-variant">{{ $registration->user?->email }}</p>
                                        @if($registration->team)
                                            <p class="text-xs text-primary">{{ __('events.field_team_name_2', ['name' => $registration->team?->name]) }}</p>
                                        @endif
                                    </div>
                                </td>

                                {{-- Type --}}
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $registration->registration_type === 'team' ? 'bg-primary/10 text-primary' : 'bg-tertiary/10 text-on-tertiary-container' }}">
                                        {{ ucfirst($registration->registration_type) }}
                                    </span>
                                </td>

                                {{-- Division --}}
                                <td class="px-4 py-3 text-on-surface-variant">
                                    {{ $registration->division ?? '—' }}
                                </td>

                                {{-- Status --}}
                                <td class="px-4 py-3">
                                    @php
                                        $statusColors = [
                                            'pending' => 'bg-tertiary/10 text-on-tertiary-container',
                                            'confirmed' => 'bg-secondary-container text-on-secondary-container',
                                            'cancelled' => 'bg-error-container text-on-error-container',
                                            'waitlisted' => 'bg-surface-container text-on-surface-variant',
                                        ];
                                    @endphp
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $statusColors[$registration->status] ?? 'bg-surface-container text-on-surface-variant' }}">
                                        {{ ucfirst($registration->status) }}
                                    </span>
                                </td>

                                {{-- Payment --}}
                                <td class="px-4 py-3">
                                    @php
                                        $paymentColors = [
                                            'paid' => 'text-secondary',
                                            'pending' => 'text-tertiary',
                                            'not_required' => 'text-on-surface-variant',
                                            'refunded' => 'text-primary',
                                            'failed' => 'text-error',
                                        ];
                                    @endphp
                                    <span class="text-xs font-medium {{ $paymentColors[$registration->payment_status] ?? 'text-on-surface-variant' }}">
                                        @if($registration->payment_status === 'not_required')
                                            {{ __('billing.content_free') }}
                                        @else
                                            {{ ucfirst(str_replace('_', ' ', $registration->payment_status)) }}
                                        @endif
                                    </span>
                                </td>

                                {{-- Registered --}}
                                <td class="px-4 py-3 text-on-surface-variant text-xs">
                                    {{ format_date($registration->created_at, 'date') }}
                                </td>

                                {{-- Actions --}}
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        @if($registration->status === 'pending')
                                            <button wire:click="approve('{{ $registration->id }}')" wire:confirm="{{ __('events.flash_approve_this_registration') }}"
                                                class="text-xs px-2 py-1 rounded bg-secondary-container text-on-secondary-container hover:opacity-90 transition-opacity">
                                                {{ __('events.action_approve') }}
                                            </button>
                                            <button wire:click="reject('{{ $registration->id }}')" wire:confirm="{{ __('events.content_reject_this_registration') }}"
                                                class="text-xs px-2 py-1 rounded bg-error-container text-on-error-container hover:opacity-90 transition-opacity">
                                                {{ __('common.action_reject') }}
                                            </button>
                                        @endif

                                        @if($registration->payment_status === 'pending' && $registration->status !== 'cancelled')
                                            <button wire:click="confirmPayment('{{ $registration->id }}')" wire:confirm="{{ __('billing.flash_mark_payment_as_received') }}"
                                                class="text-xs px-2 py-1 rounded bg-primary/10 text-primary hover:opacity-90 transition-opacity">
                                                {{ __('billing.status_paid') }}
                                            </button>
                                        @endif

                                        @if($registration->payment_status === 'paid')
                                            <button wire:click="markRefunded('{{ $registration->id }}')" wire:confirm="{{ __('billing.flash_mark_this_payment_as_refunded') }}"
                                                class="text-xs px-2 py-1 rounded bg-surface-container text-on-surface-variant hover:bg-surface-container-high transition-colors">
                                                {{ __('billing.content_refund') }}
                                            </button>
                                        @endif

                                        @if($registration->status !== 'cancelled')
                                            <button wire:click="cancelRegistration('{{ $registration->id }}')" wire:confirm="{{ __('events.flash_cancel_this_registration') }}"
                                                class="text-xs px-2 py-1 rounded text-on-surface-variant hover:text-error transition-colors">
                                                {{ __('common.action_cancel') }}
                                            </button>
                                        @endif

                                        {{-- Internal notes toggle --}}
                                        @if($editingRegistrationId === (string) $registration->id)
                                            <button wire:click="$set('editingRegistrationId', null)" class="text-xs text-on-surface-variant hover:text-on-surface">
                                                {{ __('common.action_close') }}
                                            </button>
                                        @else
                                            <button wire:click="editInternalNotes('{{ $registration->id }}')" class="text-xs text-on-surface-variant hover:text-on-surface" title="{{ __('common.field_internal_notes') }}">
                                                <span class="material-symbols-outlined text-sm" aria-hidden="true">edit_note</span>
                                            </button>
                                        @endif
                                    </div>

                                    {{-- Inline notes editor --}}
                                    @if($editingRegistrationId === (string) $registration->id)
                                        <div class="mt-2 pt-2 border-t border-outline-variant/30">
                                            <textarea wire:model="internalNotes" rows="2" placeholder="{{ __('common.content_internal_notes_visible_only_to_organizers') }}"
                                                class="w-full bg-surface-container-high border border-transparent rounded text-on-surface text-xs focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20"></textarea>
                                            <button wire:click="saveInternalNotes('{{ $registration->id }}')" class="mt-1 text-xs px-2 py-1 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded hover:opacity-90 transition-opacity">
                                                {{ __('common.field_save_notes') }}
                                            </button>
                                        </div>
                                    @endif

                                    {{-- Show existing notes --}}
                                    @if($registration->internal_notes && $editingRegistrationId !== (string) $registration->id)
                                        <p class="mt-1 text-xs text-on-surface-variant/60 italic truncate max-w-[200px]" title="{{ $registration->internal_notes }}">
                                            <span class="material-symbols-outlined text-xs" aria-hidden="true">edit_note</span>
                                            {{ Str::limit($registration->internal_notes, 40) }}
                                        </p>
                                    @endif

                                    {{-- Roster info for team registrations --}}
                                    @if($registration->roster && count($registration->roster) > 0)
                                        <details class="mt-1">
                                            <summary class="text-xs text-on-surface-variant cursor-pointer hover:text-on-surface">{{ __('events.content_roster_count', ['count' => count($registration->roster)]) }}</summary>
                                            <div class="mt-1 pl-3 text-xs text-on-surface-variant space-y-0.5">
                                                @foreach($registration->roster as $member)
                                                    <p>{{ $member['name'] ?? __('common.content_unknown') }} <span class="text-on-surface-variant/60">({{ $member['role'] ?? '' }})</span></p>
                                                @endforeach
                                            </div>
                                        </details>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            <div class="px-4 py-3 border-t border-outline-variant">
                {{ $this->registrations->links() }}
            </div>
        @endif
    </div>
</div>
