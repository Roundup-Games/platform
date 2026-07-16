# PostHog Analytics Integration

## Overview

Server-side (PHP SDK) and client-side (posthog-js) analytics with:
- Event tracking via ActivityLog → PostHogEventBridge
- Exception capture (5xx server-side + JS unhandled errors)
- Session replay with GDPR masking
- Feature flags (server + client)
- Surveys (NPS, feedback)
- User & team group analytics

## Configuration

All config lives in `config/posthog.php`. Env vars:

| Variable | Purpose | Default |
|---|---|---|
| `POSTHOG_API_KEY` | Project API key (public-safe) | none |
| `POSTHOG_HOST` | Instance URL | `https://eu.i.posthog.com` |
| `POSTHOG_ENABLED` | Global kill switch | `true` |
| `POSTHOG_SESSION_REPLAY_ENABLED` | Session replay toggle | `true` |
| `POSTHOG_REPLAY_SAMPLE_RATE` | 0.0–1.0 recording frequency | `0.5` |
| `POSTHOG_SURVEYS_ENABLED` | Survey rendering toggle | `true` |

## Feature Flags

### Server-side (Blade / PHP)

```blade
{{-- Boolean flag --}}
@featureFlag('my-flag')
  <p>Flag is on!</p>
@else
  <p>Flag is off.</p>
@endFeatureFlag

{{-- Multivariate flag --}}
@featureFlagVariant('experiment-theme', 'dark')
  <div class="dark-theme">...</div>
@endFeatureFlagVariant
@featureFlagVariant('experiment-theme', 'light')
  <div class="light-theme">...</div>
@endFeatureFlagVariant
```

```php
$flags = app(PostHogFeatureFlag::class);
if ($flags->isOn('my-flag')) { ... }
$variant = $flags->getVariant('my-experiment');
```

### Client-side (JS)

```js
const value = window.featureFlag('my-flag');
```

### Creating a Flag

1. Go to PostHog dashboard → Feature Flags → New Flag
2. Set the key (e.g. `show-release-banner`)
3. Choose type: Boolean or Multivariate
4. Set release conditions (users, cohorts, percentage)
5. Set `POSTHOG_FEATURE_FLAGS_ENABLED=true` in env (config `feature_flags.enabled`)

## Session Replay Masking

Replay recordings mask all inputs, images, and elements with `data-ph-mask`:

```blade
<p data-ph-mask>{{ $user->email }}</p>
```

Add `data-ph-mask` to any element displaying PII.

## Privacy & GDPR

- **Pseudonymized by design**: PostHog receives only the opaque internal user ID as the `distinctId`. **Name and email are never sent to PostHog** — events cannot be re-identified without this application's database. This is what makes our public "pseudonymized" privacy claim literally true.
- **Non-PII person properties only**: `identifyServerSide()` sends `locale`, `account_age_days`, `has_completed_onboarding`, coarse `country`, and `$set_once` signup cohort. Computed properties (game-system cluster, modality tendency, lifetime counts) are set asynchronously by `EnrichPostHogProfile`.
- **Anonymous IDs are not PII-derived**: anonymous users get a random session-scoped UUID (not an IP/UA hash).
- **Three consent tiers** (see `config/cookie-consent.php`):
  - `necessary` — auth, CSRF, locale.
  - `analytics` (opt-in) — pseudonymous PostHog events. **All** PostHog event capture and identify is gated behind this.
  - `marketing` (opt-in) — the only tier under which identifiable contact details may be shared with a provider for outreach. Not yet consumed by any processor; wired as the growth substrate.
- **Consent-gated surfaces**: `PostHogIdentifyUsers`, `PostHogEventBridge`, `PostHogAnalytics`, and the `link.hit` event all check `PostHogConsentChecker::hasAnalyticsConsent()`.
- **Exception tracking (legitimate interest)**: `PostHogExceptionReporter` captures unhandled 5xx errors **without** analytics consent (service stability is a legitimate interest under GDPR Art. 6(1)(f)). It records only the request **path** — never the full URL, query string, or form data — to avoid leaking share tokens or PII. This is disclosed in the privacy policy.
- **Session replay masking**: all inputs, images, and `[data-ph-mask]` elements are masked. Add `data-ph-mask` to any element displaying personal data.
- **Do Not Track**: the JS SDK respects the browser's DNT header; the identify middleware respects the `DNT` request header server-side.
- **GDPR erasure**: `DeletePostHogUserData` sends a `$delete` for the user's distinctId on account anonymization.

## Event Catalog

**Community activity** (via `ActivityLogService` → `PostHogEventBridge`, also shown in the in-app feed):
`game.created`, `game.completed`, `game.canceled`, `game.updated`, `campaign.*`, `game.player_joined`, `session.scheduled`, `session.recapped`, `session.debriefing_submitted`, `invitation.received`, `invitation.accepted`, `review.received`, `follow.received`.

**Product / funnel events** (via `PostHogAnalytics`, analytics-only, not in the feed):
- `user.signed_up` — `signup_method` (email/oauth), `oauth_provider`, `invite_match_count`.
- `onboarding.started` / `onboarding.completed` — drives the activation funnel.
- `attendance.recorded` — `attendance_status`, `resolution_context` (report/consensus/admin_override/host_cancel), `game_system`, `is_online`, `hours_to_session`. Powers reliability-by-cohort analysis (the platform's differentiator).
- `discovery.search` — filter signature + `result_count` + `zero_results` flag. Zero-result searches surface unmet demand.
- `link.hit` — anonymous short-link performance (consent-gated).

## Architecture

- **PostHogIdentifyUsers** middleware: identifies users on GET page loads (pseudonymous — opaque ID + non-PII properties).
- **PostHogEventBridge**: forwards ActivityLog community events to PostHog with enrichment.
- **PostHogAnalytics**: consent-gated direct capture for product/funnel events (signup, onboarding, attendance, discovery) that don't belong in the activity feed.
- **PostHogExceptionReporter**: captures 5xx exceptions, rate-limited per class, legitimate-interest (no consent gate; path-only).
- **PostHogFeatureFlag**: server-side flag evaluation with per-request cache.
- **EnrichPostHogProfile**: async job that sets computed non-PII person properties + team group analytics.
- **posthog.js**: client-side init, Livewire pageview tracking, session replay, surveys.
