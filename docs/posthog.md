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
- **Consent-gated surfaces**: `PostHogIdentifyUsers`, `PostHogEventBridge`, `PostHogAnalytics`, and the `link.hit` event all check `PostHogConsentChecker::hasAnalyticsConsent()`. In webhook/queue contexts with no cookie (Paddle server-to-server webhooks), `PostHogAnalytics` falls back to the persisted `analytics_consent` column, which the identify middleware keeps in sync.
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
- `onboarding.step_completed` — per-step (1-4) drop-off signal; at adoption phase, onboarding friction is the #1 fixable growth lever.
- `user.signed_in` — captured on every authentication (credentials, OAuth, remember-me) via a `Login` event listener. `is_first_session` (authoritative, from `last_login_at`), `remember`, `guard`. The retention signal — makes return-visit frequency and session-gap cohorts measurable. Paired with `$set last_login_at` / `$set_once first_login_at` person properties.
- `attendance.recorded` — `attendance_status`, `resolution_context` (report/consensus/admin_override/host_cancel), `game_system`, `is_online`, `hours_to_session`. Powers reliability-by-cohort analysis (the platform's differentiator).
- `discovery.search` — filter signature + `result_count` + `zero_results` flag. Zero-result searches surface unmet demand.
- `link.hit` — anonymous short-link performance (consent-gated).
- `subscription.started` / `subscription.updated` / `subscription.canceled` / `subscription.payment_failed` — captured at the Paddle webhook boundary. Consent via the persisted `analytics_consent` column (no cookie in webhook context). Fires when subscription billing goes live; zero rework then.
- `application.submitted` — top of the matching funnel. `outcome` (approved/waitlisted/benched/pending), `visibility`, `is_full`.
- `application.approved` / `application.rejected` — host decisions. Closes the acceptance-rate metric.
- `participant.promoted` — bench → approved.
- `participant.removed` — host-initiated removal (churn signal).
- `notification.sent` / `notification.failed` — `category`, `channels`. Retention analytics: correlates 'received attendance nudge' with attendance outcomes.
- `consent.analytics_granted` — captured client-side ONLY when a user actively grants analytics consent via the banner. Makes the consent rate visible in PostHog.

### ⚠️ Event overlap — read before building metrics

A single user action can produce events on **two different timelines**. Do not
sum across these — they measure different things:

| User action | Owner timeline (community feed) | Applicant timeline (funnel) |
|---|---|---|
| Player joins a public game | `game.player_joined` | `application.submitted` (outcome=approved) |
| Host approves application | `game.player_joined` | `application.approved` |
| Host removes participant | `game.player_joined` (if was Approved) | `participant.removed` |

- `game.player_joined` (via `ActivityLogObserver` → `PostHogEventBridge`) lands on
  the **game owner's** timeline — it's a community-feed event ("someone joined
  your game"). It fires on both participant creation (status=Approved) and
  status-change-to-Approved.
- `application.*` / `participant.*` (via `PostHogAnalytics`) land on the
  **applicant's** timeline — they're funnel events ("I applied / was approved /
  was removed").

**Correct usage**: build acceptance-rate funnels from `application.*` events
(applicant timeline). Build "how active is this game/owner" from
`game.player_joined` (owner timeline). Never sum the two for a "total joins"
metric — that double-counts every join.

### Signal quality and consent bias

All analytics events represent **only users who granted analytics consent** —
a self-selected minority who are systematically more engaged than the median
player. This is structural (opt-in, defaults off) and correct for privacy, but
it means:

- **Trust the shape, not the absolute rate.** If 40% of consenting users drop
  off at onboarding step 2, that shape is reliable. But "40% of all users drop
  off" is an over-estimate — non-consenting users may behave differently.
- **Calibrate with the consent rate.** The `analytics_consent` column on
  authenticated users is the authoritative first-party denominator. Compare it
  to the `consent.analytics_granted` event count for the PostHog-visible rate.
  A low consent rate means every other metric is more skewed.
- **Anonymous→identified merge is automatic.** `posthog.identify()` merges the
  anonymous session into the identified user (PostHog native behavior). Pre-
  consent browsing is intentionally invisible.

## Architecture

- **PostHogIdentifyUsers** middleware: identifies users on GET page loads (pseudonymous — opaque ID + non-PII properties).
- **PostHogEventBridge**: forwards ActivityLog community events to PostHog with enrichment.
- **PostHogAnalytics**: consent-gated direct capture for product/funnel events (signup, onboarding, attendance, discovery) that don't belong in the activity feed.
- **RecordUserSignIn**: `Login` event listener capturing the return-visit signal; stamps the first-party `last_login_at` column always and forwards `user.signed_in` to PostHog only with consent.
- **PostHogExceptionReporter**: captures 5xx exceptions, rate-limited per class, legitimate-interest (no consent gate; path-only).
- **PostHogFeatureFlag**: server-side flag evaluation with per-request cache.
**Person properties** (set via identify, non-PII, for segmentation):
- Inline (`PostHogIdentifyUsers`, first GET of session): `locale`, `account_age_days`, `has_completed_onboarding`, `country`, `$set_once` `signup_date`/`signup_cohort_week`.
- On login (`RecordUserSignIn` listener): `$set last_login_at`, `$set_once first_login_at`.
- Async (`EnrichPostHogProfile`, on participatory events): `games_created_count`, `games_joined_count`, `modality` (online/in_person/mixed), `primary_game_system`, `reliability_tier`, plus first-event timestamps.
- First-touch (`PostHogAnalytics::identifyFirstTouch`, at signup): `$set_once` `first_touch_referer_domain`, `first_touch_entry_path`, `signup_content_type`, `signup_content_slug` — the SEO/acquisition signal including which public content page drove the signup.
- Subscription (`PaddleWebhookController`): `$set` `subscription_status`.
- **posthog.js**: client-side init, Livewire pageview tracking, session replay, surveys.
