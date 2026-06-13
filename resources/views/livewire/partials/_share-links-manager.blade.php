@props(['links', 'showActions' => true])

@php
    $entityTypeMap = [
        'App\Models\Game' => __('gws.link_type_game'),
        'App\Models\Campaign' => __('gws.link_type_campaign'),
        'App\Models\Event' => __('gws.link_type_event'),
        'App\Models\Team' => __('gws.link_type_team'),
    ];
@endphp

@if($links->count())
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-outline-variant/20">
                    <th class="text-left py-2 px-3 text-on-surface-variant font-medium">{{ __('gws.link_col_code') }}</th>
                    <th class="text-left py-2 px-3 text-on-surface-variant font-medium">{{ __('gws.link_col_label') }}</th>
                    <th class="text-left py-2 px-3 text-on-surface-variant font-medium">{{ __('gws.link_col_entity') }}</th>
                    <th class="text-right py-2 px-3 text-on-surface-variant font-medium">{{ __('gws.link_col_hits') }}</th>
                    <th class="text-left py-2 px-3 text-on-surface-variant font-medium">{{ __('gws.link_col_status') }}</th>
                    <th class="text-left py-2 px-3 text-on-surface-variant font-medium">{{ __('gws.link_col_created') }}</th>
                    @if($showActions)
                        <th class="text-right py-2 px-3 text-on-surface-variant font-medium">{{ __('gws.link_col_actions') }}</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach($links as $link)
                    <tr class="border-b border-outline-variant/10 hover:bg-surface-container-high/30 transition-colors">
                        <td class="py-2.5 px-3">
                            <code class="text-xs font-mono bg-surface-container-high px-1.5 py-0.5 rounded-sm">{{ $link->code }}</code>
                        </td>
                        <td class="py-2.5 px-3 text-on-surface">
                            {{ $link->label ?? '—' }}
                        </td>
                        <td class="py-2.5 px-3 text-on-surface-variant">
                            @if($link->linkable)
                                <span class="text-xs">{{ $link->linkable->name ?? $link->linkable->title ?? class_basename($link->linkable_type) }}</span>
                            @else
                                <span class="text-xs text-on-surface-variant/50">{{ $entityTypeMap[$link->linkable_type] ?? class_basename($link->linkable_type) }}</span>
                            @endif
                        </td>
                        <td class="py-2.5 px-3 text-right font-medium text-on-surface">
                            {{ number_format($link->hit_count ?? 0) }}
                        </td>
                        <td class="py-2.5 px-3">
                            @if($link->trashed())
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-sm text-xs font-medium bg-error-container text-on-error-container">
                                    {{ __('gws.link_status_revoked') }}
                                </span>
                            @elseif($link->isExpired())
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-sm text-xs font-medium bg-tertiary-container text-on-tertiary-container">
                                    {{ __('gws.link_status_expired') }}
                                </span>
                            @elseif($link->hasHitCap())
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-sm text-xs font-medium bg-secondary-container text-on-secondary-container">
                                    {{ __('gws.link_status_cap_reached') }}
                                </span>
                            @else
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-sm text-xs font-medium bg-primary-container text-on-primary-container">
                                    {{ __('gws.link_status_active') }}
                                </span>
                            @endif
                        </td>
                        <td class="py-2.5 px-3 text-on-surface-variant text-xs">
                            {{ $link->created_at->format('M j, Y') }}
                        </td>
                        @if($showActions)
                            <td class="py-2.5 px-3 text-right">
                                @unless($link->trashed())
                                    <button type="button"
                                        onclick="if(confirm(@js(__('gws.link_confirm_revoke')))) { Livewire.dispatch('revoke-link', { linkId: {{ $link->id }} }) }"
                                        class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded-sm bg-error-container/50 text-on-error-container hover:bg-error-container transition-colors">
                                        <span class="material-symbols-outlined text-sm" aria-hidden="true">link_off</span>
                                        {{ __('gws.link_action_revoke') }}
                                    </button>
                                @endunless
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@else
    <div class="text-center py-8">
        <span class="material-symbols-outlined text-3xl text-on-surface-variant/30 mb-2 block">link</span>
        <p class="text-sm text-on-surface-variant">{{ __('gws.content_no_share_links') }}</p>
    </div>
@endif
