@php($section = $content['safety'] ?? [])
@php($tools = $section['tools'] ?? [])
@php($customNote = (string) ($section['custom_note'] ?? ''))
@php($signalHow = (string) ($section['signal_how'] ?? ''))

@if($tools !== [] || $customNote !== '' || $signalHow !== '')
<section id="charter-section-safety" class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 sm:p-8 scroll-mt-24">
    <div class="flex items-center gap-2 mb-6">
        <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">shield</span>
        <h3 class="font-heading text-lg font-semibold text-on-surface">{{ __('charter.section_safety_title') }}</h3>
    </div>

    <div class="space-y-6">
        @if($tools !== [])
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_safety_tools_label') }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach($tools as $toolValue)
                        @php($tool = \App\Enums\SafetyTool::tryFrom((string) $toolValue))
                        @if($tool)
                            <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-primary/10 text-primary text-xs font-medium">
                                <span class="material-symbols-outlined text-xs" aria-hidden="true">verified</span>
                                {{ $tool->label() }}
                            </span>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif

        @if($signalHow !== '')
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_safety_signal_how_label') }}</p>
                @include('livewire.charter.sections._prose', ['value' => $signalHow])
            </div>
        @endif

        @if($customNote !== '')
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_safety_custom_note_label') }}</p>
                @include('livewire.charter.sections._prose', ['value' => $customNote])
            </div>
        @endif

        {{-- Revisit cadence & Open Door --}}
        <div class="grid sm:grid-cols-2 gap-x-8 gap-y-4">
            @if(($section['revisit'] ?? '') !== '')
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_safety_revisit_label') }}</p>
                    <p class="mt-1 text-sm text-on-surface">{{ __('charter.label_revisit_'.$section['revisit']) }}</p>
                </div>
            @endif
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_safety_open_door_label') }}</p>
                <p class="mt-1 text-sm text-on-surface">
                    {{ ! empty($section['open_door']) ? __('common.action_yes') : __('common.action_no') }}
                </p>
            </div>
        </div>
    </div>
</section>
@endif
