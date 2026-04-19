<div>
    {{-- Header --}}
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-on-surface leading-tight">
                    {{ __('events.content_announcements') }}
                </h2>
                <p class="text-sm text-on-surface-variant mt-1">{{ $event->name }}</p>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('events.manage', ['slug' => $event->slug]) }}" wire:navigate
                   class="text-sm text-on-surface-variant hover:text-on-surface transition-colors">
                    {{ __('events.content_back_to_event') }}
                </a>
                <button wire:click="showCreateForm"
                        class="px-4 py-2 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-lg hover:opacity-90 transition-opacity text-sm font-medium inline-flex items-center gap-1">
                    <span class="material-symbols-outlined text-base" aria-hidden="true">add</span>
                    {{ __('events.content_new_announcement') }}
                </button>
            </div>
        </div>
    </x-slot>

    {{-- Flash --}}
    @if(session()->has('success'))
        <div class="mb-4 bg-secondary-container border border-secondary/20 rounded-lg p-3 text-sm text-on-secondary-container" role="status" aria-live="polite">
            {{ session('success') }}
        </div>
    @endif

    {{-- Summary Cards --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <div class="bg-surface-container-low rounded-xl shadow-ambient p-4 text-center">
            <p class="text-2xl font-bold text-on-surface">{{ $this->counts['total'] }}</p>
            <p class="text-xs text-on-surface-variant tracking-wide">{{ __('common.content_total') }}</p>
        </div>
        <div class="bg-surface-container-low rounded-xl shadow-ambient p-4 text-center">
            <p class="text-2xl font-bold text-secondary">{{ $this->counts['published'] }}</p>
            <p class="text-xs text-on-surface-variant tracking-wide">{{ __('common.status_published') }}</p>
        </div>
        <div class="bg-surface-container-low rounded-xl shadow-ambient p-4 text-center">
            <p class="text-2xl font-bold text-tertiary">{{ $this->counts['draft'] }}</p>
            <p class="text-xs text-on-surface-variant tracking-wide">{{ __('common.content_drafts') }}</p>
        </div>
        <div class="bg-surface-container-low rounded-xl shadow-ambient p-4 text-center">
            <p class="text-2xl font-bold text-primary">{{ $this->counts['pinned'] }}</p>
            <p class="text-xs text-on-surface-variant tracking-wide">{{ __('common.content_pinned') }}</p>
        </div>
    </div>

    {{-- Create/Edit Form --}}
    @if($showForm)
        <div class="bg-surface-container-low rounded-xl shadow-ambient p-6 mb-6">
            <h3 class="text-lg font-medium text-on-surface mb-4">
                {{ $editingId ? __('events.action_edit_announcement') : __('events.action_create_announcement') }}
            </h3>

            <div class="space-y-4">
                @php
                    $showDeTabs = in_array($event->content_language ?? 'en', ['de', 'de+en']);
                @endphp

                <div>
                    <label for="announcement-title" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('common.field_title') }}</label>
                    @if($showDeTabs)
                    <div class="mb-2">
                        <div class="flex gap-1">
                            <button type="button" wire:click="setLocaleTab('en')"
                                    class="px-3 py-1 text-xs font-medium border-b-2 transition-colors {{ $activeLocale === 'en' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface' }}">
                                EN
                            </button>
                            <button type="button" wire:click="setLocaleTab('de')"
                                    class="px-3 py-1 text-xs font-medium border-b-2 transition-colors {{ $activeLocale === 'de' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface' }}">
                                DE
                            </button>
                        </div>
                    </div>
                    @endif
                    @if(!$showDeTabs || $activeLocale === 'en')
                    <input type="text" id="announcement-title" wire:model="title" placeholder="{{ __('events.content_announcement_title') }}"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('title') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    @endif
                    @if($showDeTabs && $activeLocale === 'de')
                    <input type="text" wire:model="title_de" placeholder="{{ __('events.placeholder_german_announcement_title') }}"
                           class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm" />
                    @error('title_de') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    @endif
                </div>

                <div>
                    <label for="announcement-content" class="block text-sm font-medium text-on-surface-variant mb-1">{{ __('common.content_content') }}</label>
                    @if($showDeTabs)
                    <div class="mb-2">
                        <div class="flex gap-1">
                            <button type="button" wire:click="setLocaleTab('en')"
                                    class="px-3 py-1 text-xs font-medium border-b-2 transition-colors {{ $activeLocale === 'en' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface' }}">
                                EN
                            </button>
                            <button type="button" wire:click="setLocaleTab('de')"
                                    class="px-3 py-1 text-xs font-medium border-b-2 transition-colors {{ $activeLocale === 'de' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface' }}">
                                DE
                            </button>
                        </div>
                    </div>
                    @endif
                    @if(!$showDeTabs || $activeLocale === 'en')
                    <textarea id="announcement-content" wire:model="content" rows="5" placeholder="{{ __('events.content_write_your_announcement') }}"
                              class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"></textarea>
                    @error('content') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    @endif
                    @if($showDeTabs && $activeLocale === 'de')
                    <textarea wire:model="content_de" rows="5" placeholder="{{ __('events.placeholder_write_announcement_german') }}"
                              class="w-full bg-surface-container-high border border-transparent rounded-md text-on-surface placeholder:text-outline focus:border-secondary/20 focus:ring-2 focus:ring-secondary/20 shadow-sm"></textarea>
                    @error('content_de') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
                    @endif
                </div>

                <div class="flex items-center gap-6">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" wire:model="is_published" class="rounded border-outline text-primary focus:ring-primary/20" />
                        <span class="text-sm text-on-surface-variant">{{ __('common.action_publish_immediately') }}</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" wire:model="is_pinned" class="rounded border-outline text-primary focus:ring-primary/20" />
                        <span class="text-sm text-on-surface-variant">{{ __('common.content_pin_to_top') }}</span>
                    </label>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button wire:click="save" wire:loading.attr="disabled"
                            class="px-6 py-2.5 bg-gradient-to-r from-primary to-primary-container text-on-primary rounded-lg hover:opacity-90 transition-opacity text-sm font-medium">
                        <span wire:loading.remove>{{ $editingId ? __('common.field_update') : __('common.action_create') }} {{ __('events.content_announcement') }}</span>
                        <span wire:loading>{{ __('common.content_saving') }}</span>
                    </button>
                    <button wire:click="cancelForm"
                            class="px-4 py-2.5 text-on-surface-variant hover:text-on-surface text-sm transition-colors">
                        {{ __('common.action_cancel') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Filter Tabs --}}
    <div class="bg-surface-container-low rounded-xl shadow-ambient mb-6">
        <div class="border-b border-outline-variant px-4">
            <nav class="flex -mb-px">
                <button wire:click="setFilterStatus('')" class="px-4 py-3 text-sm font-medium border-b-2 transition-colors {{ $filterStatus === '' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface' }}">
                    {{ __('discovery.content_all') }}
                </button>
                <button wire:click="setFilterStatus('published')" class="px-4 py-3 text-sm font-medium border-b-2 transition-colors {{ $filterStatus === 'published' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface' }}">
                    {{ __('common.status_published') }}
                </button>
                <button wire:click="setFilterStatus('draft')" class="px-4 py-3 text-sm font-medium border-b-2 transition-colors {{ $filterStatus === 'draft' ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface' }}">
                    {{ __('common.content_drafts') }}
                </button>
            </nav>
        </div>

        {{-- Announcement List --}}
        <div class="divide-y divide-outline-variant/30">
            @if($this->announcements->isEmpty())
                <div class="text-center py-12">
                    <p class="text-on-surface-variant">{{ __('events.content_no_announcements_yet') }}</p>
                    <button wire:click="showCreateForm" class="mt-2 text-sm text-primary hover:underline">
                        {{ __('events.action_create_your_first_announcement') }}
                    </button>
                </div>
            @else
                @foreach($this->announcements as $announcement)
                    <div class="p-4 hover:bg-surface-container-low/50 transition-colors">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-1">
                                    @if($announcement->is_pinned)
                                        <span class="text-xs px-1.5 py-0.5 rounded bg-primary/10 text-primary inline-flex items-center gap-1">
                                            <span class="material-symbols-outlined text-xs" aria-hidden="true">push_pin</span>
                                            {{ __('common.content_pinned') }}
                                        </span>
                                    @endif
                                    @if($announcement->is_published)
                                        <span class="text-xs px-1.5 py-0.5 rounded bg-secondary-container text-on-secondary-container">{{ __('common.status_published') }}</span>
                                    @else
                                        <span class="text-xs px-1.5 py-0.5 rounded bg-tertiary/10 text-on-tertiary-container">{{ __('common.status_draft') }}</span>
                                    @endif
                                </div>
                                <h4 class="font-medium text-on-surface {{ $announcement->is_pinned ? 'text-base' : '' }}">{{ $announcement->title }}</h4>
                                <p class="text-sm text-on-surface-variant mt-1 line-clamp-2">{{ Str::limit($announcement->content, 200) }}</p>
                                <p class="text-xs text-on-surface-variant/60 mt-2">
                                    {{ __('common.field_by_author_date', ['author' => $announcement->author?->name ?? __('common.content_unknown'), 'date' => format_date($announcement->created_at, 'datetime')]) }}
                                </p>
                            </div>

                            {{-- Actions --}}
                            <div class="flex items-center gap-1 shrink-0">
                                <button wire:click="togglePin('{{ $announcement->id }}')"
                                        class="p-1.5 rounded text-on-surface-variant hover:text-primary transition-colors"
                                        aria-label="{{ $announcement->is_pinned ? __('common.content_unpin') : __('common.content_pin') }}"
                                        title="{{ $announcement->is_pinned ? __('common.content_unpin') : __('common.content_pin') }}">
                                    <span class="material-symbols-outlined text-base" aria-hidden="true">push_pin</span>
                                </button>
                                @if($announcement->is_published)
                                    <button wire:click="unpublishAnnouncement('{{ $announcement->id }}')"
                                            class="p-1.5 rounded text-on-surface-variant hover:text-tertiary transition-colors"
                                            aria-label="{{ __('common.action_unpublish') }}"
                                            title="{{ __('common.action_unpublish') }}">
                                        <span class="material-symbols-outlined text-base" aria-hidden="true">visibility_off</span>
                                    </button>
                                @else
                                    <button wire:click="publishAnnouncement('{{ $announcement->id }}')"
                                            class="p-1.5 rounded text-on-surface-variant hover:text-secondary transition-colors"
                                            aria-label="{{ __('common.action_publish') }}"
                                            title="{{ __('common.action_publish') }}">
                                        <span class="material-symbols-outlined text-base" aria-hidden="true">visibility</span>
                                    </button>
                                @endif
                                <button wire:click="editAnnouncement('{{ $announcement->id }}')"
                                        class="p-1.5 rounded text-on-surface-variant hover:text-on-surface transition-colors"
                                        aria-label="{{ __('events.action_edit_announcement') }}"
                                        title="{{ __('common.action_edit') }}">
                                    <span class="material-symbols-outlined text-base" aria-hidden="true">edit</span>
                                </button>
                                <button wire:click="deleteAnnouncement('{{ $announcement->id }}')" wire:confirm="{{ __('events.flash_delete_this_announcement') }}"
                                        class="p-1.5 rounded text-on-surface-variant hover:text-error transition-colors"
                                        aria-label="{{ __('events.action_delete_announcement') }}"
                                        title="{{ __('common.action_delete') }}">
                                    <span class="material-symbols-outlined text-base" aria-hidden="true">delete</span>
                                </button>
                            </div>
                        </div>
                    </div>
                @endforeach
            @endif
        </div>

        {{-- Pagination --}}
        @if($this->announcements->hasPages())
            <div class="px-4 py-3 border-t border-outline-variant">
                {{ $this->announcements->links() }}
            </div>
        @endif
    </div>
</div>
