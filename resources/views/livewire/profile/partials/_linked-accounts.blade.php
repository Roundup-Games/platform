{{-- Linked Accounts --}}
<section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
    <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-4 flex items-center gap-2">
        <span class="material-symbols-outlined text-lg text-on-surface-variant" aria-hidden="true">link</span>
        {{ __('profile.field_linked_accounts') }}
    </h2>

    <div class="space-y-3">
        @forelse($linkedAccounts as $linkedAccount)
            <div class="flex items-center justify-between p-3 bg-surface-container-low rounded-lg">
                <div class="flex items-center gap-3">
                    @if($linkedAccount->provider === 'google')
                        <span class="material-symbols-outlined text-xl text-on-surface-variant" aria-hidden="true">mail</span>
                    @endif
                    <div>
                        <p class="text-sm font-medium text-on-surface capitalize">{{ $linkedAccount->provider }}</p>
                        <p class="text-xs text-on-surface-variant">{{ __('common.field_connected_date', ['date' => format_date($linkedAccount->created_at, 'date')]) }}</p>
                    </div>
                </div>

                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                    <span class="material-symbols-outlined text-xs" style="font-variation-settings: 'FILL' 1" aria-hidden="true">check_circle</span>
                    {{ __('common.content_connected') }}
                </span>
            </div>
        @empty
            <div class="flex items-center justify-between p-3 bg-surface-container-low rounded-lg">
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined text-xl text-on-surface-variant" aria-hidden="true">mail</span>
                    <div>
                        <p class="text-sm font-medium text-on-surface">{{ __('common.content_google') }}</p>
                        <p class="text-xs text-on-surface-variant">{{ __('common.content_not_connected') }}</p>
                    </div>
                </div>
                <a href="{{ route('oauth.redirect', 'google') }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-outline-variant rounded-lg text-xs font-medium text-on-surface-variant hover:bg-surface-container-high transition-colors">
                    <span class="material-symbols-outlined text-sm" aria-hidden="true">add</span>
                    {{ __('common.action_connect') }}
                </a>
            </div>
        @endforelse
    </div>
</section>
