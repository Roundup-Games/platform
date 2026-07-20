{{-- Linked Accounts --}}
{{-- Provider-agnostic: iterates OAuthProvider::cases() so adding a new login
     provider updates both the connected list and the connect-affordance list
     automatically. Connected accounts are listed first (in DB order), then a
     connect card is rendered for every supported provider the user has NOT
     yet linked. --}}
<section class="bg-surface-container-lowest rounded-xl shadow-ambient p-6">
    <h2 class="text-lg font-heading font-semibold tracking-tight text-on-surface mb-4 flex items-center gap-2">
        <span class="material-symbols-outlined text-lg text-on-surface-variant" aria-hidden="true">link</span>
        {{ __('profile.field_linked_accounts') }}
    </h2>

    <div class="space-y-3">
        @php
            // linked_accounts.provider is enum-cast (OAuthProvider|null via
            // tryFrom). Pluck the backed values once for the unconnected diff.
            $linkedValues = $linkedAccounts
                ->pluck('provider')
                ->map(fn ($p) => $p instanceof \App\Enums\OAuthProvider ? $p->value : (string) $p)
                ->all();
        @endphp

        @foreach($linkedAccounts as $linkedAccount)
            @php
                $provider = $linkedAccount->provider;
                $label = $provider?->label()
                    ?? ucfirst((string) ($linkedAccount->getRawOriginal('provider') ?? ''));
            @endphp
            <div class="flex items-center justify-between p-3 bg-surface-container-low rounded-lg">
                <div class="flex items-center gap-3">
                    @if($provider)
                        <x-oauth-provider-icon :provider="$provider" class="w-5 h-5 text-on-surface-variant" />
                    @else
                        <span class="material-symbols-outlined text-xl text-on-surface-variant" aria-hidden="true">link</span>
                    @endif
                    <div>
                        <p class="text-sm font-medium text-on-surface">{{ $label }}</p>
                        <p class="text-xs text-on-surface-variant">{{ __('common.field_connected_date', ['date' => format_date($linkedAccount->created_at, 'date')]) }}</p>
                    </div>
                </div>

                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-secondary-container text-on-secondary-container">
                    <span class="material-symbols-outlined text-xs" style="font-variation-settings: 'FILL' 1" aria-hidden="true">check_circle</span>
                    {{ __('common.content_connected') }}
                </span>
            </div>
        @endforeach

        @foreach(\App\Enums\OAuthProvider::cases() as $provider)
            @if(! in_array($provider->value, $linkedValues, true))
                <div class="flex items-center justify-between p-3 bg-surface-container-low rounded-lg">
                    <div class="flex items-center gap-3">
                        <x-oauth-provider-icon :provider="$provider" class="w-5 h-5 text-on-surface-variant" />
                        <div>
                            <p class="text-sm font-medium text-on-surface">{{ $provider->label() }}</p>
                            <p class="text-xs text-on-surface-variant">{{ __('common.content_not_connected') }}</p>
                        </div>
                    </div>
                    <a href="{{ route('oauth.redirect', $provider->value) }}"
                       class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-outline-variant rounded-lg text-xs font-medium text-on-surface-variant hover:bg-surface-container-high transition-colors">
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">add</span>
                        {{ __('common.action_connect') }}
                    </a>
                </div>
            @endif
        @endforeach
    </div>
</section>
