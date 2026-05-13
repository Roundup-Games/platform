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

### Livewire Components

```php
use EvaluatesFeatureFlags;

if ($this->featureFlagIsOn('my-flag')) { ... }
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

- **No PII in analytics identifiers**: Anonymous users get a random session-scoped UUID (not IP-derived). Authenticated users are identified by their database ID only.
- **PII stays server-side**: User name, email, and locale are set exclusively via server-side `identify()` — never exposed in the DOM or client-side `identify()` call.
- **Session replay masking**: All inputs, images, and `[data-ph-mask]` elements are masked in recordings. Add `data-ph-mask` to any element displaying personal data.
- **Do Not Track**: The JS SDK respects the browser's DNT header.

## Architecture

- **PostHogIdentifyUsers** middleware: identifies users on GET page loads
- **PostHogEventBridge**: forwards ActivityLog events to PostHog with enrichment
- **PostHogExceptionReporter**: captures 5xx exceptions, rate-limited per class
- **PostHogFeatureFlag**: server-side flag evaluation with per-request cache
- **posthog.js**: client-side init, Livewire pageview tracking, session replay, surveys
