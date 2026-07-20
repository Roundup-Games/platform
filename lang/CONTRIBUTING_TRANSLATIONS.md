# Contributing Translations

This guide explains how Roundup Games translations are structured and maintained. It is the reference for anyone — human or AI agent — adding, changing, or removing translatable strings.

## Contents

1. [Architecture Overview](#architecture-overview)
2. [Domain Definitions](#domain-definitions)
3. [Key Naming Rules](#key-naming-rules)
4. [Key Prefix Reference](#key-prefix-reference)
5. [When to Use `common.php` vs a Domain File](#when-to-use-commonphp-vs-a-domain-file)
6. [Adding a New Locale](#adding-a-new-locale)
7. [Adding New Translation Keys](#adding-new-translation-keys)
8. [Removing Translation Keys](#removing-translation-keys)
9. [Testing Translations](#testing-translations)
10. [File Format Reference](#file-format-reference)
11. [Quick Reference](#quick-reference)
12. [Weblate Workflow](#weblate-workflow)
13. [CI Parity Gate](#ci-parity-gate)
14. [Appendix: Running wlc via Docker](#appendix-running-wlc-via-docker)

---

## Architecture Overview

All translations use **Laravel PHP group files** with semantic dotted keys. Each locale lives in its own directory under `lang/`.

```
lang/
├── en/                  # English (primary / source of truth)
│   ├── auth.php
│   ├── billing.php
│   ├── campaigns.php
│   ├── common.php
│   ├── discovery.php
│   ├── emails.php
│   ├── events.php
│   ├── games.php
│   ├── location.php
│   ├── pages.php
│   ├── profile.php
│   ├── safety.php
│   └── teams.php
├── de/                  # German (same structure, same keys)
│   └── ...
└── CONTRIBUTING_TRANSLATIONS.md
```

Each domain file returns a **flat associative array** mapping `prefix_slug` → translated string. In Blade/PHP, keys are referenced as `__('domain.prefix_slug')`.

**Key stats:** 32 domain files per locale, ~3,160 keys per locale, full EN/DE parity (enforced by `php artisan i18n:check` in CI).

---

## Domain Definitions

| Domain | File | Description |
|--------|------|-------------|
| **auth** | `auth.php` | Registration, login, password reset, email verification |
| **billing** | `billing.php` | Payments, subscriptions, invoices, pricing plans |
| **campaigns** | `campaigns.php` | Recurring game campaigns, campaign sessions, scheduling |
| **common** | `common.php` | Strings shared across 3+ domains (generic labels, buttons) |
| **discovery** | `discovery.php` | Search, filtering, browse/discovery UI |
| **emails** | `emails.php` | Email templates, notification body copy |
| **events** | `events.php` | Events, tournaments, registrations, announcements |
| **games** | `games.php` | Board games, RPGs, game details, BGG integration |
| **location** | `location.php` | Venues, addresses, maps, geographic data |
| **pages** | `pages.php` | Static/landing page content, marketing copy |
| **profile** | `profile.php` | User profiles, avatars, preferences, account settings |
| **safety** | `safety.php` | Safety tools, session zero guidelines |
| **teams** | `teams.php` | Teams, membership, invitations, team management |

### How to choose

Find the feature area where the string appears. That's your domain. If the string is a generic button or label used in many places (e.g. "Save", "Cancel", "Optional"), it belongs in `common.php` — see the rule below.

---

## Key Naming Rules

### Format

All keys follow **flat snake_case** within each file:

```
{prefix}_{descriptive_slug}
```

The domain is the file name, so keys in `events.php` are referenced as `__('events.action_create_event')`.

### Slug Construction

Derive the slug from the English value: lowercase, strip special characters, replace spaces with underscores, truncate to a readable length.

| English value | Key |
|---------------|-----|
| Add competitive divisions for your event. | `action_add_competitive_divisions_for_your_event` |
| Browse Events → | `action_browse_events` |
| About this event | `content_about_this_event` |

When two different English values produce the same slug, append `_2`, `_3`, etc.

### Rules

- ✅ **Flat snake_case** — `action_create_event`, not `actionCreateEvent` or `action.create.event`
- ✅ **Sorted alphabetically** within each file for clean diffs
- ✅ **One level** — the key is a single slug, not a nested path
- ❌ **No nested arrays** — `['events' => ['action' => ['create' => '...']]]` is wrong
- ❌ **No double-domain prefix** — `events.events_create` → just `events.action_create`
- ❌ **No camelCase** — `actionCreateEvent` is wrong

---

## Key Prefix Reference

Use these prefixes to indicate the **role** of the string in the UI:

| Prefix | Purpose | Examples |
|--------|---------|---------|
| `action_` | Buttons, links, CTAs — things users click | `action_create_event`, `action_register_now` |
| `field_` | Form labels, field descriptions, table headers | `field_address`, `field_confirm_password` |
| `status_` | Status indicators, badges, state labels | `status_active`, `status_cancelled` |
| `flash_` | Flash/notification messages after actions | `flash_event_created`, `flash_profile_updated` |
| `error_` | Validation errors, error messages | `error_email_required` |
| `content_` | Body text, marketing copy, explanations, tooltips | `content_about_this_event`, `content_optional` |

If a string doesn't clearly fit one prefix, use `content_` as the default.

---

## When to Use `common.php` vs a Domain File

**Rule: 3+ domains use it → `common.php`. 1–2 domains → specific domain file.**

| String | Domains used in | Goes in |
|--------|----------------|---------|
| "Save" | events, games, campaigns, profile, teams | `common.php` |
| "Register for Event" | events only | `events.php` |
| "Cancel" | events, billing, campaigns | `common.php` |
| "Team name" | teams only | `teams.php` |

When in doubt, start domain-specific. It's easier to promote to `common.php` later than to split out.

---

## Adding a New Locale

To add a new language (e.g. French):

### 1. Register the locale

Add the locale code to `config/app.php`:

```php
'available_locales' => ['en', 'de', 'fr'],
```

The route constraint in `routes/web.php` reads from this config automatically — no route changes needed.

### 2. Copy the English directory

```bash
cp -r lang/en lang/fr
```

### 3. Translate all values

In every PHP file in `lang/fr/`, translate the **value** (right side of `=>`), never the **key** (left side).

### 4. Preserve placeholders

Keep `:event`, `:name`, `:count`, `:amount` etc. exactly as-is:

```php
// en/emails.php
'content_inviter_has_invited_you_to' => '**:inviter** has invited you to join the team **:team** on Roundup Games.',

// fr/emails.php
'content_inviter_has_invited_you_to' => '**:inviter** vous a invité à rejoindre l\'équipe **:team** sur Roundup Games.',
```

### 5. Update helpers if needed

`app/Helpers.php` contains `format_date()` and `format_currency()`. These use locale-group detection (24-hour locales, European decimal formatting). If the new locale needs different formatting, add it to the appropriate array in that file.

### 6. Update tests

The test suite includes locale-coverage tests. Run:

```bash
php artisan test --filter=LocaleTranslationTest
```

If the test asserts specific locales, add the new locale code there.

### 7. Update navigation/footer templates

Some templates may list locales explicitly (e.g. a language switcher). Search for hardcoded locale lists:

```bash
grep -rn "en.*de" resources/views/ --include="*.blade.php" | grep -i "locale\|lang\|language"
```

### 8. Verify

```bash
php artisan cache:clear
php artisan view:clear
```

Load pages in the new locale and verify translations render. Run the full test suite:

```bash
php artisan test
```

---

## Adding New Translation Keys

When you add a feature that needs new translatable strings:

1. **Pick the right domain file** (see [Domain Definitions](#domain-definitions)).
2. **Choose the right prefix** (see [Key Prefix Reference](#key-prefix-reference)).
3. **Construct the key** using snake_case of the English value with the prefix.
4. **Add to all locales** — at minimum `lang/en/` and `lang/de/`:

```php
// lang/en/events.php
'action_export_attendees' => 'Export Attendees',

// lang/de/events.php
'action_export_attendees' => 'Teilnehmer exportieren',
```

5. **Use in Blade/PHP** with Laravel's `__()` helper:

```blade
{{ __('events.action_export_attendees') }}
```

### Strings with Parameters

Use Laravel's `:param` placeholder convention:

```php
// lang/en/events.php
'flash_registered_count' => ':count registrations confirmed',

// In Blade:
{{ __('events.flash_registered_count', ['count' => $registrations->count()]) }}
```

---

## Removing Translation Keys

1. **Search the entire codebase** for usage:

```bash
grep -r "events.action_old_button" app/ resources/
```

2. **Remove the code** that references it first.
3. **Remove the key from all locale files** in the same commit.
4. **Don't leave commented-out translations** — git history preserves them.

---

## Testing Translations

### Smoke Test

After any change:

```bash
php artisan cache:clear
php artisan view:clear
```

Load the page in both locales and verify the translation renders.

### Key Parity Check

Every key in `lang/en/{domain}.php` must also exist in `lang/de/{domain}.php` (and all other locales). Run:

```bash
php -r "
  \$domains = ['auth','billing','campaigns','common','discovery','emails','events','games','location','pages','profile','safety','teams'];
  \$locales = array_filter(scandir('lang'), fn(\$d) => !in_array(\$d, ['.', '..', 'CONTRIBUTING_TRANSLATIONS.md']) && is_dir('lang/' . \$d));
  foreach (\$domains as \$d) {
    \$en = array_keys(include 'lang/en/' . \$d . '.php');
    foreach (\$locales as \$loc) {
      if (\$loc === 'en') continue;
      \$other = array_keys(include 'lang/' . \$loc . '/' . \$d . '.php');
      \$missing = array_diff(\$en, \$other);
      if (\$missing) echo \"\$loc missing from \$d: \" . implode(', ', \$missing) . \"\n\";
      \$extra = array_diff(\$other, \$en);
      if (\$extra) echo \"\$loc extra in \$d: \" . implode(', ', \$extra) . \"\n\";
    }
  }
  echo \"Parity check complete.\n\";
"
```

### Placeholder Preservation

Ensure `:param` placeholders match across locales:

```bash
grep -rn ":[a-zA-Z_]" lang/en/*.php
```

For each match, verify the same placeholder exists in `de/` (and other locales).

---

## File Format Reference

Each PHP file returns a flat associative array. Keys are sorted alphabetically:

```php
<?php

return [
    'action_add_division' => 'Add Division',
    'action_cancel_event' => 'Cancel Event',
    'content_about_this_event' => 'About this event',
    'error_registration_closed' => 'Registration is closed for this event.',
    'field_event_name' => 'Event Name',
    'flash_event_published' => 'Event published successfully!',
    'status_active' => 'Active',
];
```

### Escaping Rules

- **Single quotes** in values must be escaped: `\'`
- **Double quotes** do not need escaping in single-quoted strings
- **Placeholder colons** (`:name`) are preserved as-is — Laravel handles them at runtime
- **Use actual UTF-8 characters** — `→` not `&rarr;`, `ä` not `&auml;`

---

## Quick Reference

| Rule | Details |
|------|---------|
| New keys in PHP files only | `lang/{locale}/{domain}.php` |
| Use prefix conventions | `action_`, `field_`, `status_`, `flash_`, `error_`, `content_` |
| Flat snake_case slugs | No nesting, no camelCase |
| All locales required | Every key in `en/` must exist in every other locale |
| Preserve placeholders | `:param` names must match across locales |
| Sort keys alphabetically | Within each file, for clean diffs |
| Grep before removing | Check `app/` and `resources/` before deleting a key |
| Register new locales in config | `config/app.php` → `available_locales` |

---

## Weblate Workflow

[Hosted Weblate](https://hosted.weblate.org/) is the primary source for **translations** (the values in `lang/{locale}/*.php` for non-English locales). Translators work in Weblate's UI; finished translations flow back to this repository as pull requests.

The repository remains the source of truth for **keys and English source strings** (`lang/en/*.php`), because Blade references keys as `__('domain.key')` and the app must resolve them at runtime regardless of Weblate's state.

### Roles

| Artifact | Source of truth | Owner |
|----------|----------------|-------|
| Translation keys (`'some_key' =>`) | This repo (`lang/en/`) | Developers |
| English source values | This repo (`lang/en/`) | Developers |
| Translated values (`lang/de/`, future `lang/fr/`…) | Weblate | Translators |

### How changes flow

**Developers add or change English strings** (steps in [Adding New Translation Keys](#adding-new-translation-keys) above):

1. Add the key + English value to `lang/en/{domain}.php`.
2. Add a placeholder value (English copy is fine) to `lang/de/{domain}.php` so `i18n:check` parity holds locally and in CI.
3. Commit and push. The Hosted Weblate GitHub App auto-pulls the change into Weblate within seconds, so translators see the new string immediately.

**Translators work in Weblate:**

- They never edit files in the repo directly.
- On commit, Weblate pushes to the `weblate/translations` branch and opens a PR against `main`.
- The PR's CI run includes the `i18n:check` job (see [CI Parity Gate](#ci-parity-gate)) — if a translation drops a placeholder, duplicates a key, or breaks a convention, CI fails and the PR cannot merge until fixed.

### Reviewing translation PRs

Translation PRs look like any other PR but typically touch only `lang/{locale}/*.php` files. Review checklist:

- [ ] CI `i18n` job is green (parity, placeholders, conventions).
- [ ] Spot-check a few strings for the `:placeholder` names matching the English source.
- [ ] No keys were added or removed (Weblate should only change values).

Merge with a regular merge commit (Weblate is configured for `merge_style: merge` to preserve translator attributions).

### Locking translations during big merges

If you're doing a large refactor that touches many lang files at once, lock the affected components in Weblate first so translators don't work against a moving target:

```bash
# Via Docker (no native install needed). See "Appendix: Running wlc via Docker"
# in this document for token setup and the full command reference.
docker run --rm \
  --env WLC_URL=https://hosted.weblate.org/api/ \
  --env WLC_KEY="$WEBLATE_API_TOKEN" \
  weblate/wlc:2.1.0 lock roundup-games-platform/common

# ... do your refactor, push, let Weblate pull ...

docker run --rm \
  --env WLC_URL=https://hosted.weblate.org/api/ \
  --env WLC_KEY="$WEBLATE_API_TOKEN" \
  weblate/wlc:2.1.0 unlock roundup-games-platform/common
```

### Initial / one-time setup

The Weblate project is configured for **GitHub pull-request delivery** with these effective settings (verified via the REST API):

| Setting | Value |
|---------|-------|
| VCS backend | `github` (opens PRs, doesn't push directly to `main`) |
| Source repo | `https://github.com/Roundup-Games/platform.git` (HTTPS; public, anonymous read) |
| Push URL | `git@github.com:Roundup-Games/platform.git` (SSH via the hosted `weblate` GitHub user) |
| Push branch | `weblate/translations` |
| Merge style | `rebase` |
| `push_on_commit` | `true` |
| Linked components | All 32 code components are linked to `common`; changing `common` propagates to all of them |

The push path uses the shared **`weblate` GitHub user** (invited as an outside collaborator with Write access on `Roundup-Games/platform`). Hosted Weblate maintains SSH keys for this user; no deploy keys or embedded PATs to manage.

> **Note:** the Hosted Weblate GitHub App is installed on the org but does **not** provide push credentials for the existing components — they were imported with raw HTTPS URLs rather than through the App's import flow. The `weblate` SSH collaborator is the working outbound path. If a future Weblate version exposes an App-binding flow for existing components, switching to it is a one-time change to `common`'s `push` field (set to empty) and removing the `weblate` collaborator.

**Inbound (auto-pull)** is handled by the GitHub App's webhook on push-to-`main` — Weblate ingests new source strings within seconds. The repo being public means inbound worked even before the App was wired, which masked the outbound problem.

If you add a **new locale** (e.g. `lang/fr/`), create the corresponding component(s) in Weblate via the UI with the same VCS settings. Link them to `common` so the push/repo config is inherited.

---

## CI Parity Gate

Every pull request runs `php artisan i18n:check` as a dedicated CI job (see the `i18n` job in `.github/workflows/ci.yml`). This is what makes Weblate translation PRs safe to merge without manual review of every file.

The check enforces:

- **File parity** — every locale has the same set of domain files as `en/`.
- **Key parity** — every key in `lang/en/{domain}.php` exists in every other locale, and vice versa (no missing, no extra).
- **Duplicate keys** — no PHP array key collisions (which silently overwrite).
- **Untranslated values** — flags non-primary values identical to English (with a configured exemption list in `config/i18n.php` for factual strings like units, URLs, proper nouns).
- **Convention** — keys use the prefix + snake_case rules in [Key Prefix Reference](#key-prefix-reference).

Run it locally before pushing:

```bash
php artisan i18n:check

# JSON output for scripting:
php artisan i18n:check --json

# Skip convention checks (useful when deliberately adding keys that need a prefix exception):
php artisan i18n:check --no-convention
```

If the check fails on a Weblate PR, the fix belongs in Weblate (re-translate or fix the placeholder), not in the repo. If it fails on a developer PR, sync the missing/extra keys into the affected locale files before pushing.

Related commands:

```bash
php artisan i18n:missing       # keys referenced in code but missing from lang files
php artisan i18n:dead-strings  # keys in lang files never referenced in code
```

---

## Appendix: Running `wlc` via Docker

The [`wlc` CLI](https://github.com/WeblateOrg/wlc) is useful for ad-hoc Weblate operations the UI doesn't surface cleanly: `lock`/`unlock` every component during a big merge, force `commit`/`push`, inspect `repo` status, `download` a single translation file. You don't need to install it natively — the official Docker image works with no setup beyond having the API token.

### Token

Generate a token at <https://hosted.weblate.org/profile/#api> and persist it outside the repo (`~/.weblate.env`, not committed):

```bash
# Contains: WEBLATE_API_TOKEN=wlu_XXXXXXXXXXXXXXXX
chmod 600 ~/.weblate.env

# Load before running wlc:
set -a; source ~/.weblate.env; set +a
```

Treat the token like a password. It is never committed to the repo.

### Recipe

```bash
IMAGE=weblate/wlc:2.1.0   # pin the version; current stable
PROJECT=roundup-games-platform

docker run --rm \
  --env WLC_URL=https://hosted.weblate.org/api/ \
  --env WLC_KEY="$WEBLATE_API_TOKEN" \
  "$IMAGE" show "$PROJECT/common"
```

### Common commands

| Command | What it does |
|---------|--------------|
| `wlc ls "$PROJECT"` | List all components in the project |
| `wlc lock "$PROJECT/common"` | Lock a component (e.g. before a big repo-side merge) |
| `wlc unlock "$PROJECT/common"` | Unlock it again |
| `wlc commit "$PROJECT/common"` | Flush pending Weblate changes to its internal git |
| `wlc push "$PROJECT/common"` | Push committed changes (opens a PR to `weblate/translations`) |
| `wlc pull "$PROJECT/common"` | Pull upstream into Weblate (the webhook does this automatically) |
| `wlc repo "$PROJECT/common"` | Show repo status (pending commits, conflicts) |
| `wlc stats "$PROJECT/common"` | Translation progress per language |

See available image tags at <https://hub.docker.com/r/weblate/wlc/tags>. Bump the tag to upgrade.
