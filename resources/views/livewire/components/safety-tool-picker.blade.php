@php
    $groupedTools = $this->getGroupedTools;
@endphp

<div class="space-y-5" x-data="{ expandedInfo: {} }" wire:key="safety-tool-picker-{{ crc32(json_encode($selected)) }}">
    @foreach($groupedTools as $group)
        <div>
            <p class="text-xs font-medium text-on-surface-variant uppercase tracking-wider mb-3">
                {{ $group['category']->label() }}
            </p>

            <div class="space-y-2">
                @foreach($group['tools'] as $tool)
                    <div class="rounded-lg border transition-colors {{ $tool['isSelected'] ? 'border-primary/40 bg-primary/5' : 'border-outline/20 bg-surface-container-high hover:border-outline/40' }}">
                        {{-- Checkbox row --}}
                        <label class="flex items-start gap-3 px-3 py-2.5 cursor-pointer">
                            <input
                                type="checkbox"
                                value="{{ $tool['value'] }}"
                                {{ $tool['isSelected'] ? 'checked' : '' }}
                                wire:change="toggleTool('{{ $tool['value'] }}')"
                                @if($mode === 'display') disabled @endif
                                class="mt-0.5 rounded-sm border-outline/40 text-primary focus:ring-primary/20"
                                aria-label="{{ $tool['label'] }}"
                            />

                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-medium text-on-surface">{{ $tool['label'] }}</span>
                                    <button
                                        type="button"
                                        @click="expandedInfo['{{ $tool['value'] }}'] = !expandedInfo['{{ $tool['value'] }}']"
                                        class="text-on-surface-variant hover:text-on-surface transition-colors"
                                        :aria-expanded="expandedInfo['{{ $tool['value'] }}'] ? 'true' : 'false'"
                                        aria-label="More info about {{ $tool['label'] }}"
                                    >
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">info</span>
                                    </button>
                                </div>
                                <p class="text-xs text-on-surface-variant mt-0.5">{{ $tool['shortDescription'] }}</p>
                            </div>
                        </label>

                        {{-- Expandable full description --}}
                        <div
                            x-show="expandedInfo['{{ $tool['value'] }}']"
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0 max-h-0"
                            x-transition:enter-end="opacity-100 max-h-96"
                            x-transition:leave="transition ease-in duration-100"
                            x-transition:leave-start="opacity-100 max-h-96"
                            x-transition:leave-end="opacity-0 max-h-0"
                            class="px-3 pb-3 pl-10 hidden"
                            :class="{ 'hidden': !expandedInfo['{{ $tool['value'] }}'] }"
                        >
                            <p class="text-xs text-on-surface-variant leading-relaxed border-l-2 border-secondary/30 pl-3">
                                {{ $tool['fullDescription'] }}
                            </p>
                        </div>

                        {{-- Conditional textarea for tools that support text input (Lines & Veils) --}}
                        @if($tool['supportsText'] && $tool['isSelected'])
                            <div class="px-3 pb-3 pl-10">
                                <textarea
                                    wire:model.live="linesAndVeilsText"
                                    placeholder="{{ $tool['textPlaceholder'] }}"
                                    rows="2"
                                    @if($mode === 'display') disabled @endif
                                    class="w-full rounded-lg bg-surface-container border border-outline/20 text-on-surface text-sm placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors resize-y"
                                    aria-label="{{ $tool['label'] }} details"
                                >{{ $linesAndVeilsText }}</textarea>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

    {{-- Always-visible custom note textarea --}}
    <div>
        <label for="safety-custom-note" class="block text-sm font-medium text-on-surface mb-1">
            Custom Safety Note
        </label>
        <p class="text-xs text-on-surface-variant mb-2">Any additional safety arrangements or notes for your group.</p>
        <textarea
            id="safety-custom-note"
            wire:model.live="customNote"
            placeholder="e.g. We take a 10-minute break every hour"
            rows="2"
            @if($mode === 'display') disabled @endif
            class="w-full rounded-lg bg-surface-container-high border border-transparent text-on-surface text-sm placeholder:text-on-surface-variant focus:border-secondary/20 focus:ring-1 focus:ring-secondary/20 transition-colors resize-y"
            aria-label="Custom safety note"
        >{{ $customNote }}</textarea>
    </div>
</div>
