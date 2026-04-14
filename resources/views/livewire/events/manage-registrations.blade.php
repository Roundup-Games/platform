<div>
    {{-- Header --}}
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-on-surface leading-tight">
                    Manage Registrations
                </h2>
                <p class="text-sm text-on-surface-variant mt-1">{{ $event->name }}</p>
            </div>
            <a href="{{ route('events.detail', ['slug' => $event->slug]) }}" wire:navigate class="text-sm text-primary hover:underline">
                ← Back to Event
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
            <p class="text-xs text-on-surface-variant tracking-wide">Total</p>
        </div>
        <div class="bg-surface-container-low rounded-xl shadow-ambient p-4 text-center">
            <p class="text-2xl font-bold text-secondary">{{ $this->statusCounts['confirmed'] }}</p>
            <p class="text-xs text-on-surface-variant tracking-wide">Confirmed</p>
        </div>
        <div class="bg-surface-container-low rounded-xl shadow-ambient p-4 text-center">
            <p class="text-2xl font-bold text-tertiary">{{ $this->statusCounts['pending'] }}</p>
            <p class="text-xs text-on-surface-variant tracking-wide">Pending</p>
        </div>
        <div class="bg-surface-container-low rounded-xl shadow-ambient p-4 text-center">
            <p class="text-2xl font-bold text-error">{{ $this->statusCounts['cancelled'] }}</p>
            <p class="text-xs text-on-surface-variant tracking-wide">Cancelled</p>
        </div>
    </div>

    {{-- Filters --}}
    <div class="bg-surface-container-low rounded-xl shadow-ambient p-4 mb-6">
        <div class="flex flex-wrap gap-3 items-end">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-medium text-on-surface-variant mb-1">Search</label>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Name, email, or team..."
                    class="w-full bg-surface-container-high border border-transparent rounded-lg text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 text-sm py-2" />
            </div>
            <div>
                <label class="block text-xs font-medium text-on-surface-variant mb-1">Status</label>
                <select wire:model.live="filterStatus" class="bg-surface-container-high border border-transparent rounded-lg text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 text-sm py-2">
                    <option value="">All Statuses</option>
                    <option value="pending">Pending</option>
                    <option value="confirmed">Confirmed</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="waitlisted">Waitlisted</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-on-surface-variant mb-1">Type</label>
                <select wire:model.live="filterType" class="bg-surface-container-high border border-transparent rounded-lg text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 text-sm py-2">
                    <option value="">All Types</option>
                    <option value="team">Team</option>
                    <option value="individual">Individual</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-on-surface-variant mb-1">Payment</label>
                <select wire:model.live="filterPaymentStatus" class="bg-surface-container-high border border-transparent rounded-lg text-on-surface focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 text-sm py-2">
                    <option value="">All Payments</option>
                    <option value="paid">Paid</option>
                    <option value="pending">Payment Pending</option>
                    <option value="not_required">Free</option>
                    <option value="refunded">Refunded</option>
                </select>
            </div>
            <button wire:click="clearFilters" class="text-sm text-on-surface-variant hover:text-on-surface py-2">
                Clear
            </button>
        </div>
    </div>

    {{-- Registrations List --}}
    <div class="bg-surface-container-low rounded-xl shadow-ambient overflow-hidden">
        @if($this->registrations->isEmpty())
            <div class="text-center py-12">
                <p class="text-on-surface-variant">No registrations found.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-surface-container">
                        <tr>
                            <th class="text-left px-4 py-3 text-xs font-medium text-on-surface-variant tracking-wide">Registrant</th>
                            <th class="text-left px-4 py-3 text-xs font-medium text-on-surface-variant tracking-wide">Type</th>
                            <th class="text-left px-4 py-3 text-xs font-medium text-on-surface-variant tracking-wide">Division</th>
                            <th class="text-left px-4 py-3 text-xs font-medium text-on-surface-variant tracking-wide">Status</th>
                            <th class="text-left px-4 py-3 text-xs font-medium text-on-surface-variant tracking-wide">Payment</th>
                            <th class="text-left px-4 py-3 text-xs font-medium text-on-surface-variant tracking-wide">Registered</th>
                            <th class="text-right px-4 py-3 text-xs font-medium text-on-surface-variant tracking-wide">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/30">
                        @foreach($this->registrations as $registration)
                            <tr class="hover:bg-surface-container-low/50">
                                {{-- Registrant --}}
                                <td class="px-4 py-3">
                                    <div>
                                        <p class="font-medium text-on-surface">{{ $registration->user?->name ?? 'Unknown' }}</p>
                                        <p class="text-xs text-on-surface-variant">{{ $registration->user?->email }}</p>
                                        @if($registration->team)
                                            <p class="text-xs text-primary">Team: {{ $registration->team->name }}</p>
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
                                            Free
                                        @else
                                            {{ ucfirst(str_replace('_', ' ', $registration->payment_status)) }}
                                        @endif
                                    </span>
                                </td>

                                {{-- Registered --}}
                                <td class="px-4 py-3 text-on-surface-variant text-xs">
                                    {{ $registration->created_at->format('M j, Y') }}
                                </td>

                                {{-- Actions --}}
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        @if($registration->status === 'pending')
                                            <button wire:click="approve('{{ $registration->id }}')" wire:confirm="Approve this registration?"
                                                class="text-xs px-2 py-1 rounded bg-secondary-container text-on-secondary-container hover:opacity-90 transition-opacity">
                                                Approve
                                            </button>
                                            <button wire:click="reject('{{ $registration->id }}')" wire:confirm="Reject this registration?"
                                                class="text-xs px-2 py-1 rounded bg-error-container text-on-error-container hover:opacity-90 transition-opacity">
                                                Reject
                                            </button>
                                        @endif

                                        @if($registration->payment_status === 'pending' && $registration->status !== 'cancelled')
                                            <button wire:click="confirmPayment('{{ $registration->id }}')" wire:confirm="Mark payment as received?"
                                                class="text-xs px-2 py-1 rounded bg-primary/10 text-primary hover:opacity-90 transition-opacity">
                                                ✓ Paid
                                            </button>
                                        @endif

                                        @if($registration->payment_status === 'paid')
                                            <button wire:click="markRefunded('{{ $registration->id }}')" wire:confirm="Mark this payment as refunded?"
                                                class="text-xs px-2 py-1 rounded bg-surface-container text-on-surface-variant hover:bg-surface-container-high transition-colors">
                                                Refund
                                            </button>
                                        @endif

                                        @if($registration->status !== 'cancelled')
                                            <button wire:click="cancelRegistration('{{ $registration->id }}')" wire:confirm="Cancel this registration?"
                                                class="text-xs px-2 py-1 rounded text-on-surface-variant hover:text-error transition-colors">
                                                Cancel
                                            </button>
                                        @endif

                                        {{-- Internal notes toggle --}}
                                        @if($editingRegistrationId === (string) $registration->id)
                                            <button wire:click="$set('editingRegistrationId', null)" class="text-xs text-on-surface-variant hover:text-on-surface">
                                                Close
                                            </button>
                                        @else
                                            <button wire:click="editInternalNotes('{{ $registration->id }}')" class="text-xs text-on-surface-variant hover:text-on-surface" title="Internal notes">
                                                <span class="material-symbols-outlined text-sm" aria-hidden="true">edit_note</span>
                                            </button>
                                        @endif
                                    </div>

                                    {{-- Inline notes editor --}}
                                    @if($editingRegistrationId === (string) $registration->id)
                                        <div class="mt-2 pt-2 border-t border-outline-variant/30">
                                            <textarea wire:model="internalNotes" rows="2" placeholder="Internal notes (visible only to organizers)..."
                                                class="w-full bg-surface-container-high border border-transparent rounded text-on-surface text-xs focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20"></textarea>
                                            <button wire:click="saveInternalNotes('{{ $registration->id }}')" class="mt-1 text-xs px-2 py-1 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded hover:opacity-90 transition-opacity">
                                                Save Notes
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
                                            <summary class="text-xs text-on-surface-variant cursor-pointer hover:text-on-surface">Roster ({{ count($registration->roster) }})</summary>
                                            <div class="mt-1 pl-3 text-xs text-on-surface-variant space-y-0.5">
                                                @foreach($registration->roster as $member)
                                                    <p>{{ $member['name'] ?? 'Unknown' }} <span class="text-on-surface-variant/60">({{ $member['role'] ?? '' }})</span></p>
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
