<div>
    {{-- Header --}}
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    Announcements
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $event->name }}</p>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('events.manage', ['slug' => $event->slug]) }}" wire:navigate
                   class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
                    ← Back to Event
                </a>
                <button wire:click="showCreateForm"
                        class="px-4 py-2 bg-[#C12E26] text-white rounded-lg hover:bg-[#9A231F] transition-colors text-sm font-medium">
                    + New Announcement
                </button>
            </div>
        </div>
    </x-slot>

    {{-- Flash --}}
    @if(session()->has('success'))
        <div class="mb-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-3 text-sm text-green-700 dark:text-green-400" role="status" aria-live="polite">
            {{ session('success') }}
        </div>
    @endif

    {{-- Summary Cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4 text-center">
            <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $this->counts['total'] }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Total</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4 text-center">
            <p class="text-2xl font-bold text-green-600 dark:text-green-400">{{ $this->counts['published'] }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Published</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4 text-center">
            <p class="text-2xl font-bold text-yellow-600 dark:text-yellow-400">{{ $this->counts['draft'] }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Drafts</p>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4 text-center">
            <p class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ $this->counts['pinned'] }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Pinned</p>
        </div>
    </div>

    {{-- Create/Edit Form --}}
    @if($showForm)
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6 mb-6">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                {{ $editingId ? 'Edit Announcement' : 'Create Announcement' }}
            </h3>

            <div class="space-y-4">
                <div>
                    <label for="announcement-title" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Title *</label>
                    <input type="text" id="announcement-title" wire:model="title" placeholder="Announcement title..."
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="announcement-content" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Content *</label>
                    <textarea id="announcement-content" wire:model="content" rows="5" placeholder="Write your announcement..."
                              class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]"></textarea>
                    @error('content') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center gap-6">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" wire:model="is_published" class="rounded border-gray-300 text-[#C12E26] focus:ring-[#C12E26]" />
                        <span class="text-sm text-gray-700 dark:text-gray-300">Publish immediately</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" wire:model="is_pinned" class="rounded border-gray-300 text-[#C12E26] focus:ring-[#C12E26]" />
                        <span class="text-sm text-gray-700 dark:text-gray-300">Pin to top</span>
                    </label>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button wire:click="save" wire:loading.attr="disabled"
                            class="px-6 py-2.5 bg-[#C12E26] text-white rounded-lg hover:bg-[#9A231F] transition-colors text-sm font-medium">
                        <span wire:loading.remove>{{ $editingId ? 'Update' : 'Create' }} Announcement</span>
                        <span wire:loading>Saving...</span>
                    </button>
                    <button wire:click="cancelForm"
                            class="px-4 py-2.5 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 text-sm transition-colors">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Filter Tabs --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm mb-6">
        <div class="border-b border-gray-200 dark:border-gray-700 px-4">
            <nav class="flex -mb-px">
                <button wire:click="setFilterStatus('')" class="px-4 py-3 text-sm font-medium border-b-2 transition-colors {{ $filterStatus === '' ? 'border-[#C12E26] text-[#C12E26]' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                    All
                </button>
                <button wire:click="setFilterStatus('published')" class="px-4 py-3 text-sm font-medium border-b-2 transition-colors {{ $filterStatus === 'published' ? 'border-[#C12E26] text-[#C12E26]' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                    Published
                </button>
                <button wire:click="setFilterStatus('draft')" class="px-4 py-3 text-sm font-medium border-b-2 transition-colors {{ $filterStatus === 'draft' ? 'border-[#C12E26] text-[#C12E26]' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                    Drafts
                </button>
            </nav>
        </div>

        {{-- Announcement List --}}
        <div class="divide-y divide-gray-100 dark:divide-gray-700">
            @if($this->announcements->isEmpty())
                <div class="text-center py-12">
                    <p class="text-gray-500 dark:text-gray-400">No announcements yet.</p>
                    <button wire:click="showCreateForm" class="mt-2 text-sm text-brand-dark hover:underline">
                        Create your first announcement
                    </button>
                </div>
            @else
                @foreach($this->announcements as $announcement)
                    <div class="p-4 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-1">
                                    @if($announcement->is_pinned)
                                        <span class="text-xs px-1.5 py-0.5 rounded bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 inline-flex items-center gap-1">
                                            <svg aria-hidden="true" class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"/></svg>
                                            Pinned
                                        </span>
                                    @endif
                                    @if($announcement->is_published)
                                        <span class="text-xs px-1.5 py-0.5 rounded bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400">Published</span>
                                    @else
                                        <span class="text-xs px-1.5 py-0.5 rounded bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-400">Draft</span>
                                    @endif
                                </div>
                                <h4 class="font-medium text-gray-900 dark:text-gray-100 {{ $announcement->is_pinned ? 'text-base' : '' }}">{{ $announcement->title }}</h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1 line-clamp-2">{{ Str::limit($announcement->content, 200) }}</p>
                                <p class="text-xs text-gray-400 dark:text-gray-500 mt-2">
                                    By {{ $announcement->author?->name ?? 'Unknown' }} · {{ $announcement->created_at->format('M j, Y \a\t g:i A') }}
                                </p>
                            </div>

                            {{-- Actions --}}
                            <div class="flex items-center gap-1 shrink-0">
                                <button wire:click="togglePin('{{ $announcement->id }}')"
                                        class="p-1.5 rounded text-gray-400 hover:text-blue-600 dark:hover:text-blue-400 transition-colors"
                                        aria-label="{{ $announcement->is_pinned ? 'Unpin' : 'Pin' }}"
                                        title="{{ $announcement->is_pinned ? 'Unpin' : 'Pin' }}">
                                    <svg aria-hidden="true" class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"/></svg>
                                </button>
                                @if($announcement->is_published)
                                    <button wire:click="unpublishAnnouncement('{{ $announcement->id }}')"
                                            class="p-1.5 rounded text-gray-400 hover:text-yellow-600 dark:hover:text-yellow-400 transition-colors"
                                            aria-label="Unpublish"
                                            title="Unpublish">
                                        <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L3 3m6.878 6.878L21 21"/></svg>
                                    </button>
                                @else
                                    <button wire:click="publishAnnouncement('{{ $announcement->id }}')"
                                            class="p-1.5 rounded text-gray-400 hover:text-green-600 dark:hover:text-green-400 transition-colors"
                                            aria-label="Publish"
                                            title="Publish">
                                        <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    </button>
                                @endif
                                <button wire:click="editAnnouncement('{{ $announcement->id }}')"
                                        class="p-1.5 rounded text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
                                        aria-label="Edit announcement"
                                        title="Edit">
                                    <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                </button>
                                <button wire:click="deleteAnnouncement('{{ $announcement->id }}')" wire:confirm="Delete this announcement?"
                                        class="p-1.5 rounded text-gray-400 hover:text-red-600 dark:hover:text-red-400 transition-colors"
                                        aria-label="Delete announcement"
                                        title="Delete">
                                    <svg aria-hidden="true" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                @endforeach
            @endif
        </div>

        {{-- Pagination --}}
        @if($this->announcements->hasPages())
            <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
                {{ $this->announcements->links() }}
            </div>
        @endif
    </div>
</div>
