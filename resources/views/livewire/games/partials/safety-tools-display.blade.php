@php
    $safetyRules = $safetyRules ?? [];
    $tools = $safetyRules['tools'] ?? [];
    $linesAndVeilsText = $safetyRules['lines_and_veils_text'] ?? '';
    $customNote = $safetyRules['custom_note'] ?? '';

    if (empty($tools) && empty($linesAndVeilsText) && empty($customNote)) {
        return;
    }
@endphp

<section class="bg-surface-container-low rounded-xl shadow-ambient p-6">
    <h2 class="text-xl font-heading font-bold tracking-tight text-on-surface mb-4 flex items-center gap-2">
        <span class="material-symbols-outlined text-xl" aria-hidden="true">shield</span>
        {{ __('Safety Tools') }}
    </h2>

    @if(!empty($tools))
        <div class="space-y-4">
            @foreach(\App\Enums\SafetyToolCategory::cases() as $category)
                @php
                    $categoryTools = collect($tools)
                        ->map(fn ($value) => \App\Enums\SafetyTool::tryFrom($value))
                        ->filter(fn ($tool) => $tool && $tool->category() === $category)
                        ->values();
                @endphp

                @if($categoryTools->isNotEmpty())
                    <div>
                        <p class="text-xs font-medium text-on-surface-variant uppercase tracking-wide mb-2">{{ $category->label() }}</p>
                        <div class="space-y-2">
                            @foreach($categoryTools as $tool)
                                <div class="flex items-start gap-3 p-3 bg-surface-container-high rounded-lg">
                                    <span class="material-symbols-outlined text-lg text-primary mt-0.5 shrink-0" aria-hidden="true">
                                        {{ $tool === \App\Enums\SafetyTool::XCard || $tool === \App\Enums\SafetyTool::XnoCard ? 'block' : ($tool === \App\Enums\SafetyTool::Breaks ? 'coffee' : 'verified_user') }}
                                    </span>
                                    <div>
                                        <p class="text-sm font-medium text-on-surface">{{ $tool->label() }}</p>
                                        <p class="text-xs text-on-surface-variant mt-0.5">{{ $tool->shortDescription() }}</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    @endif

    @if($linesAndVeilsText)
        <div class="mt-4 pt-4 border-t border-outline-variant/20">
            <p class="text-xs font-medium text-on-surface-variant uppercase tracking-wide mb-2">{{ __('Lines & Veils Details') }}</p>
            <p class="text-sm text-on-surface whitespace-pre-line">{{ $linesAndVeilsText }}</p>
        </div>
    @endif

    @if($customNote)
        <div class="mt-4 pt-4 border-t border-outline-variant/20">
            <p class="text-xs font-medium text-on-surface-variant uppercase tracking-wide mb-2">{{ __('Custom Safety Note') }}</p>
            <p class="text-sm text-on-surface whitespace-pre-line">{{ $customNote }}</p>
        </div>
    @endif

    <div class="mt-4 pt-4 border-t border-outline-variant/20">
        <p class="text-xs text-on-surface-variant">
            <a href="{{ route('safety-tools') }}" wire:navigate class="text-primary hover:underline">{{ __('Learn more about safety tools') }}</a>
        </p>
    </div>
</section>
