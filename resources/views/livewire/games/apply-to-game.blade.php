<div class="py-8">
    <div class="max-w-4xl mx-auto space-y-6">
        {{-- Back --}}
        <a href="{{ route('games.detail', $game->id) }}" class="inline-flex items-center gap-1 text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            Back to Game
        </a>

        {{-- Game Header --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm overflow-hidden">
            <div class="h-3 bg-[#C12E26]"></div>
            <div class="p-6">
                <h1 class="text-2xl font-['Oswald'] font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide">
                    {{ $game->name }}
                </h1>
                @if($game->gameSystem)
                    <p class="mt-1 text-sm text-[#C12E26] font-medium">{{ $game->gameSystem->name }}</p>
                @endif
            </div>
        </div>

        {{-- Flash Messages --}}
        @if(session()->has('info'))
            <div class="rounded-md bg-blue-50 dark:bg-blue-900/20 p-4">
                <p class="text-sm text-blue-700 dark:text-blue-400">{{ session('info') }}</p>
            </div>
        @endif

        {{-- Already participant or applied --}}
        @if($isParticipant || $hasExistingApplication)
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6 text-center">
                <p class="text-gray-600 dark:text-gray-300">
                    @if($isParticipant)
                        You are already a participant of this game.
                    @else
                        You have already applied to this game.
                    @endif
                </p>
                <a href="{{ route('games.detail', $game->id) }}" class="mt-4 inline-flex items-center px-4 py-2 bg-[#C12E26] hover:bg-[#9A231F] text-white text-sm font-medium rounded-md transition-colors">
                    View Game
                </a>
            </div>
        @else
            {{-- Application Form --}}
            <section class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
                <h2 class="text-xl font-['Oswald'] font-bold uppercase text-gray-900 dark:text-gray-100 tracking-wide mb-4">
                    @if($game->visibility === 'public')
                        Join Game
                    @else
                        Apply to Join
                    @endif
                </h2>

                @if($game->visibility === 'protected')
                    <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                        This is a protected game. Your application will be reviewed by the game owner before you can join.
                    </p>
                @endif

                <form wire:submit="submitApplication" class="space-y-4">
                    <div>
                        <label for="message" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Message to the host <span class="text-gray-400">(optional)</span>
                        </label>
                        <textarea wire:model="message" id="message" rows="4"
                            placeholder="Tell the host why you'd like to join..."
                            class="block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-[#C12E26] focus:ring-[#C12E26] text-sm"
                            data-testid="application-message"></textarea>
                        @error('message')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    @error('message')
                        {{-- General errors shown above, specific errors shown here --}}
                    @enderror

                    <button type="submit"
                        class="inline-flex items-center px-6 py-2.5 bg-[#C12E26] hover:bg-[#9A231F] text-white text-sm font-medium rounded-md transition-colors">
                        @if($game->visibility === 'public')
                            Join Game
                        @else
                            Submit Application
                        @endif
                    </button>
                </form>
            </section>
        @endif
    </div>
</div>
