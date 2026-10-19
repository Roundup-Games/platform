@php($section = $content['character'] ?? [])
@php($buildConstraints = (string) ($section['build_constraints'] ?? ''))
@php($backstoryGuidance = (string) ($section['backstory_guidance'] ?? ''))

@if($buildConstraints !== '' || ! empty($section['backstory_requested']) || $backstoryGuidance !== '')
<section id="charter-section-character" class="bg-surface-container-lowest rounded-xl shadow-ambient p-6 sm:p-8 scroll-mt-24">
    <div class="flex items-center gap-2 mb-6">
        <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">person</span>
        <h3 class="font-heading text-lg font-semibold text-on-surface">{{ __('charter.section_character_title') }}</h3>
    </div>

    <div class="space-y-6">
        @if($buildConstraints !== '')
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_character_build_constraints_label') }}</p>
                @include('livewire.charter.sections._prose', ['value' => $buildConstraints])
            </div>
        @endif

        @if(isset($section['backstory_requested']))
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_character_backstory_requested_label') }}</p>
                <p class="mt-1 text-sm text-on-surface">
                    {{ ! empty($section['backstory_requested']) ? __('common.action_yes') : __('common.action_no') }}
                </p>
            </div>
        @endif

        @if($backstoryGuidance !== '')
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-on-surface-variant">{{ __('charter.field_character_backstory_guidance_label') }}</p>
                @include('livewire.charter.sections._prose', ['value' => $backstoryGuidance])
            </div>
        @endif
    </div>
</section>
@endif
