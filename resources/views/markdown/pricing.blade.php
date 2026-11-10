{{-- Markdown alternate of /en/pricing (M078/S04). Source: the same
     MembershipType data source as app/Livewire/Billing/PublicPricing.php
     (purchasable Paddle tiers, ordered by price) plus the billing copy
     in lang/en|de/billing.php. Sync duty: when PublicPricing's tier
     query or the pricing page copy changes, mirror the change here —
     tier prices/features are read live from the database on every
     request, so only the surrounding copy can drift.
     NOTE: every interpolation is raw ({!! !!}) on purpose — these views
     serve text/markdown, and Blade's {{ }} would entity-encode
     apostrophes/ampersands into the body (&#039; etc.). Pinned by
     MarkdownAlternateTest's HTML-chrome/entity ban.
 --}}
@php
    // Same source and ordering contract as PublicPricing::render():
    // only purchasable (Paddle) plans with a configured price id.
    $plans = \App\Models\MembershipType::active()
        ->where('type', 'paddle')
        ->where('paddle_price_id', '!=', '')
        ->orderBy('price_cents')
        ->get();
@endphp
# {!! __('billing.content_pricing') !!}

{!! __('billing.content_pricing_subtitle') !!}

## {!! __('billing.content_pricing_free_heading') !!}

{!! __('billing.content_creating_events_and_registering_for') !!}

## {!! __('billing.content_membership') !!}

{!! __('billing.content_pricing_membership_pitch') !!}

@if ($plans->count())
@foreach ($plans as $plan)
### {!! $plan->name !!}

{!! format_currency($plan->price_cents) !!} / {!! trans_choice('billing.content_duration_months', $plan->duration_months) !!}

@if ($plan->description)
{!! $plan->description !!}
@endif

@if ($plan->metadata['features'] ?? [])
@foreach ($plan->metadata['features'] as $feature)
- {!! $feature !!}
@endforeach
@endif

@endforeach
@else
{!! __('billing.content_no_plans_available_yet') !!} {!! __('billing.content_membership_plans_are_coming_soon_check_back_later') !!}
@endif

{!! __('billing.content_all_memberships_auto_renew_cancel') !!}

{!! __('billing.content_all_payments_processed_securely_via_paddle') !!}
