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
                <a href="{{ route('events.manage', ['slug' => $event->slug]) }}"
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
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Title *</label>
                    <input type="text" wire:model="title" placeholder="Announcement title..."
                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26]" />
                    @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Content *</label>
                    <textarea wire:model="content" rows="5" placeholder="Write your announcement..."
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
                    <button wire:click="showCreateForm" class="mt-2 text-sm text-[#C12E26] hover:underline">
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
                                        <span class="text-xs px-1.5 py-0.5 rounded bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400">📌 Pinned</span>
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
                                        title="{{ $announcement->is_pinned ? 'Unpin' : 'Pin' }}">
                                    📌
                                </button>
                                @if($announcement->is_published)
                                    <button wire:click="unpublishAnnouncement('{{ $announcement->id }}')"
                                            class="p-1.5 rounded text-gray-400 hover:text-yellow-600 dark:hover:text-yellow-400 transition-colors text-xs"
                                            title="Unpublish">
                                        👁️
                                    </button>
                                @else
                                    <button wire:click="publishAnnouncement('{{ $announcement->id }}')"
                                            class="p-1.5 rounded text-gray-400 hover:text-green-600 dark:hover:text-green-400 transition-colors text-xs"
                                            title="Publish">
                                        ✅
                                    </button>
                                @endif
                                <button wire:click="editAnnouncement('{{ $announcement->id }}')"
                                        class="p-1.5 rounded text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors text-xs"
                                        aria-label="Edit announcement">
                                    Edit
                                </button>
                                <button wire:click="deleteAnnouncement('{{ $announcement->id }}')" wire:confirm="Delete this announcement?"
                                        class="p-1.5 rounded text-gray-400 hover:text-red-600 dark:hover:text-red-400 transition-colors text-xs"
                                        aria-label="Delete announcement">
                                    Delete
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
