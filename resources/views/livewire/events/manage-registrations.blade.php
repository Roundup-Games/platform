<div>
    {{-- Header --}}
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    Manage Registrations
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $event->name }}</p>
            </div>
            <a href="{{ route('events.detail', ['slug' => $event->slug]) }}" wire:navigate class="text-sm text-brand-dark hover:underline">
                ← Back to Event
            </a>
        </div>
    </x-slot>

    {{-- Flash messages --}}
    @if(session()->has('success'))
        <div class="mb-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-3 text-sm text-green-700 dark:text-green-400" role="status" aria-live="polite">
            {{ session('success') }}
        </div>
    @endif

    {{-- Summary Cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4 text-center">
            <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $this->statusCounts['total'] }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Total</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4 text-center">
            <p class="text-2xl font-bold text-green-600 dark:text-green-400">{{ $this->statusCounts['confirmed'] }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Confirmed</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4 text-center">
            <p class="text-2xl font-bold text-yellow-600 dark:text-yellow-400">{{ $this->statusCounts['pending'] }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Pending</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4 text-center">
            <p class="text-2xl font-bold text-red-600 dark:text-red-400">{{ $this->statusCounts['cancelled'] }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Cancelled</p>
        </div>
    </div>

    {{-- Filters --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4 mb-6">
        <div class="flex flex-wrap gap-3 items-end">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Search</label>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Name, email, or team..."
                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 focus:border-[#C12E26] focus:ring-[#C12E26] text-sm py-2" />
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Status</label>
                <select wire:model.live="filterStatus" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 focus:border-[#C12E26] focus:ring-[#C12E26] text-sm py-2">
                    <option value="">All Statuses</option>
                    <option value="pending">Pending</option>
                    <option value="confirmed">Confirmed</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="waitlisted">Waitlisted</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Type</label>
                <select wire:model.live="filterType" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 focus:border-[#C12E26] focus:ring-[#C12E26] text-sm py-2">
                    <option value="">All Types</option>
                    <option value="team">Team</option>
                    <option value="individual">Individual</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Payment</label>
                <select wire:model.live="filterPaymentStatus" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 focus:border-[#C12E26] focus:ring-[#C12E26] text-sm py-2">
                    <option value="">All Payments</option>
                    <option value="paid">Paid</option>
                    <option value="pending">Payment Pending</option>
                    <option value="not_required">Free</option>
                    <option value="refunded">Refunded</option>
                </select>
            </div>
            <button wire:click="clearFilters" class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 py-2">
                Clear
            </button>
        </div>
    </div>

    {{-- Registrations List --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm overflow-hidden">
        @if($this->registrations->isEmpty())
            <div class="text-center py-12">
                <p class="text-gray-500 dark:text-gray-400">No registrations found.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Registrant</th>
                            <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Type</th>
                            <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Division</th>
                            <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Status</th>
                            <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Payment</th>
                            <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Registered</th>
                            <th class="text-right px-4 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach($this->registrations as $registration)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                {{-- Registrant --}}
                                <td class="px-4 py-3">
                                    <div>
                                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ $registration->user?->name ?? 'Unknown' }}</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $registration->user?->email }}</p>
                                        @if($registration->team)
                                            <p class="text-xs text-brand-dark">Team: {{ $registration->team->name }}</p>
                                        @endif
                                    </div>
                                </td>

                                {{-- Type --}}
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $registration->registration_type === 'team' ? 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400' : 'bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400' }}">
                                        {{ ucfirst($registration->registration_type) }}
                                    </span>
                                </td>

                                {{-- Division --}}
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                                    {{ $registration->division ?? '—' }}
                                </td>

                                {{-- Status --}}
                                <td class="px-4 py-3">
                                    @php
                                        $statusColors = [
                                            'pending' => 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-400',
                                            'confirmed' => 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400',
                                            'cancelled' => 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400',
                                            'waitlisted' => 'bg-gray-100 dark:bg-gray-600 text-gray-600 dark:text-gray-300',
                                        ];
                                    @endphp
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $statusColors[$registration->status] ?? 'bg-gray-100 text-gray-600' }}">
                                        {{ ucfirst($registration->status) }}
                                    </span>
                                </td>

                                {{-- Payment --}}
                                <td class="px-4 py-3">
                                    @php
                                        $paymentColors = [
                                            'paid' => 'text-green-600 dark:text-green-400',
                                            'pending' => 'text-yellow-600 dark:text-yellow-400',
                                            'not_required' => 'text-gray-400',
                                            'refunded' => 'text-blue-600 dark:text-blue-400',
                                            'failed' => 'text-red-600 dark:text-red-400',
                                        ];
                                    @endphp
                                    <span class="text-xs font-medium {{ $paymentColors[$registration->payment_status] ?? 'text-gray-500' }}">
                                        @if($registration->payment_status === 'not_required')
                                            Free
                                        @else
                                            {{ ucfirst(str_replace('_', ' ', $registration->payment_status)) }}
                                        @endif
                                    </span>
                                </td>

                                {{-- Registered --}}
                                <td class="px-4 py-3 text-gray-500 dark:text-gray-400 text-xs">
                                    {{ $registration->created_at->format('M j, Y') }}
                                </td>

                                {{-- Actions --}}
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        @if($registration->status === 'pending')
                                            <button wire:click="approve('{{ $registration->id }}')" wire:confirm="Approve this registration?"
                                                class="text-xs px-2 py-1 rounded bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 hover:bg-green-200 dark:hover:bg-green-900/50 transition-colors">
                                                Approve
                                            </button>
                                            <button wire:click="reject('{{ $registration->id }}')" wire:confirm="Reject this registration?"
                                                class="text-xs px-2 py-1 rounded bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 hover:bg-red-200 dark:hover:bg-red-900/50 transition-colors">
                                                Reject
                                            </button>
                                        @endif

                                        @if($registration->payment_status === 'pending' && $registration->status !== 'cancelled')
                                            <button wire:click="confirmPayment('{{ $registration->id }}')" wire:confirm="Mark payment as received?"
                                                class="text-xs px-2 py-1 rounded bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 hover:bg-blue-200 dark:hover:bg-blue-900/50 transition-colors">
                                                ✓ Paid
                                            </button>
                                        @endif

                                        @if($registration->payment_status === 'paid')
                                            <button wire:click="markRefunded('{{ $registration->id }}')" wire:confirm="Mark this payment as refunded?"
                                                class="text-xs px-2 py-1 rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                                                Refund
                                            </button>
                                        @endif

                                        @if($registration->status !== 'cancelled')
                                            <button wire:click="cancelRegistration('{{ $registration->id }}')" wire:confirm="Cancel this registration?"
                                                class="text-xs px-2 py-1 rounded text-gray-500 dark:text-gray-400 hover:text-red-600 dark:hover:text-red-400 transition-colors">
                                                Cancel
                                            </button>
                                        @endif

                                        {{-- Internal notes toggle --}}
                                        @if($editingRegistrationId === (string) $registration->id)
                                            <button wire:click="$set('editingRegistrationId', null)" class="text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                                                Close
                                            </button>
                                        @else
                                            <button wire:click="editInternalNotes('{{ $registration->id }}')" class="text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300" title="Internal notes">
                                                📝
                                            </button>
                                        @endif
                                    </div>

                                    {{-- Inline notes editor --}}
                                    @if($editingRegistrationId === (string) $registration->id)
                                        <div class="mt-2 pt-2 border-t border-gray-100 dark:border-gray-700">
                                            <textarea wire:model="internalNotes" rows="2" placeholder="Internal notes (visible only to organizers)..."
                                                class="w-full rounded border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 text-xs"></textarea>
                                            <button wire:click="saveInternalNotes('{{ $registration->id }}')" class="mt-1 text-xs px-2 py-1 bg-[#C12E26] text-white rounded hover:bg-[#9A231F] transition-colors">
                                                Save Notes
                                            </button>
                                        </div>
                                    @endif

                                    {{-- Show existing notes --}}
                                    @if($registration->internal_notes && $editingRegistrationId !== (string) $registration->id)
                                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500 italic truncate max-w-[200px]" title="{{ $registration->internal_notes }}">
                                            📝 {{ Str::limit($registration->internal_notes, 40) }}
                                        </p>
                                    @endif

                                    {{-- Roster info for team registrations --}}
                                    @if($registration->roster && count($registration->roster) > 0)
                                        <details class="mt-1">
                                            <summary class="text-xs text-gray-400 cursor-pointer hover:text-gray-600 dark:hover:text-gray-300">Roster ({{ count($registration->roster) }})</summary>
                                            <div class="mt-1 pl-3 text-xs text-gray-500 dark:text-gray-400 space-y-0.5">
                                                @foreach($registration->roster as $member)
                                                    <p>{{ $member['name'] ?? 'Unknown' }} <span class="text-gray-400">({{ $member['role'] ?? '' }})</span></p>
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
            <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
                {{ $this->registrations->links() }}
            </div>
        @endif
    </div>
</div>
