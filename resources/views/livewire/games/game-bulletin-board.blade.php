<div>
    @if($canViewBoard)
        <section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
            <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-xl" aria-hidden="true">campaign</span>
                {{ __('games.title_bulletin_board') }}
            </h2>

            {{-- Creation form (host only, game must be scheduled) --}}
            @if($canCreateBulletin)
                <form wire:submit="create" class="mb-6">
                    <div class="relative">
                        <textarea
                            wire:model="content"
                            rows="3"
                            maxlength="280"
                            placeholder="{{ __('games.placeholder_bulletin') }}"
                            class="w-full rounded-lg border border-outline-variant bg-surface px-4 py-3 text-sm text-on-surface placeholder:text-on-surface-variant focus:border-primary focus:ring-1 focus:ring-primary resize-none transition-colors"
                            aria-label="{{ __('games.label_bulletin_aria') }}"
                        ></textarea>
                        <div class="flex items-center justify-between mt-2">
                            <span class="text-xs {{ strlen($content) > 260 ? 'text-error' : 'text-on-surface-variant' }}">
                                {{ strlen($content) }} / 280
                            </span>
                            <button
                                type="submit"
                                class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg bg-primary text-on-primary hover:opacity-90 transition-opacity disabled:opacity-50"
                                wire:loading.attr="disabled"
                            >
                                <span class="material-symbols-outlined text-base" aria-hidden="true">send</span>
                                {{ __('games.action_bulletin_post') }}
                            </button>
                        </div>
                    </div>

                    @error('content')
                        <p class="mt-1 text-xs text-error">{{ $message }}</p>
                    @enderror
                </form>
            @endif

            {{-- Bulletin list --}}
            @if($bulletins->count())
                <div class="space-y-3" role="list">
                    @foreach($bulletins as $bulletin)
                        <div class="bg-surface rounded-lg p-4 border border-outline-variant/30" role="listitem">
                            <p class="text-sm text-on-surface whitespace-pre-line">{{ $bulletin->content }}</p>
                            <div class="flex items-center gap-3 mt-3 text-xs text-on-surface-variant">
                                <span class="font-medium">{{ $bulletin->user->name ?? __('games.label_bulletin_unknown_host') }}</span>
                                <span>&middot;</span>
                                <span>{{ $bulletin->created_at->diffForHumans() }}</span>
                                @if($bulletin->expires_at)
                                    <span>&middot;</span>
                                    <span class="inline-flex items-center gap-1">
                                        <span class="material-symbols-outlined text-xs" aria-hidden="true">schedule</span>
                                        {{ __('games.content_bulletin_expires_at', ['time' => $bulletin->expires_at->diffForHumans()]) }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    @endif
</div>
