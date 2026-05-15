{{-- GM Profile Tab --}}
{{-- Contains social links form and existing GM profile data --}}

@section('meta')
    <meta name="description" content="{{ __('profile.gm_profile_section_title') }}">
@endsection

<div class="space-y-6">
    {{-- Intro card --}}
    <div class="bg-primary/5 border border-primary/10 rounded-xl p-4 flex gap-3">
        <span class="material-symbols-outlined text-lg text-primary mt-0.5 shrink-0" aria-hidden="true">info</span>
        <p class="text-sm text-on-surface-variant">{{ __('profile.gm_social_links_intro') }}</p>
    </div>

    @if($socialLinksSaved)
        <div x-data="{ show: true }" x-show="show" x-transition x-init="setTimeout(() => show = false, 3000)"
             class="rounded-lg bg-secondary-container p-4" role="status" aria-live="polite">
            <p class="text-sm text-on-secondary-container flex items-center gap-2">
                <span class="material-symbols-outlined text-base" style="font-variation-settings: 'FILL' 1">check_circle</span>
                {{ __('profile.gm_social_links_saved') }}
            </p>
        </div>
    @endif

    {{-- Social Links Form --}}
    @include('livewire.profile.partials._social-links-form')
</div>
