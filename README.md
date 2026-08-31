# Roundup Games

Open-source, non-profit platform for finding, joining, and organizing in-person tabletop gaming — board games, tabletop RPGs, and card games. Players discover sessions, campaigns, events, venues, and compatible people nearby; organizers plan games, manage rosters, and collect fees. Bilingual (English/German), built for the DACH region.

**License:** AGPL-3.0-or-later · **Repo:** [Roundup-Games/platform](https://github.com/Roundup-Games/platform)

> For a complete feature-by-feature breakdown, see the [platform inventory](docs/PLATFORM_INVENTORY.md).

## Features

- **Games & campaigns** — one-shot games or recurring campaigns with sessions; public/protected/private visibility; application, invitation, waitlist, and bench flows with confirmation windows and signup cutoffs.
- **Events** — multi-day events with divisions, registration windows, early-bird pricing, announcements, and team/individual registration modes.
- **Discovery** — proximity-based search for board games and TTRPG adventures; filterable by game system, experience, vibe, safety tools, language, price, and complexity.
- **Game system catalog** — public pages per system (categories, mechanics, designers, publishers, active sessions), synced from BoardGameGeek plus a TTRPG seed, with a community request flow.
- **People & social graph** — follow/block relationships, friend-based invitations, public profiles with field-level privacy, and taste-matching (Jaccard similarity on shared preferences).
- **GM directory & workspace** — GM profiles with specializations and star ratings; subscriber-only workspace including a Session Zero builder and debriefing tooling.
- **Attendance & reliability** — peer-reported attendance with grief-resistant scoring (weight stacking, corroboration, volume quarantine), auto-completion, nudges, dispute resolution, and reliability tiers.
- **Teams** — team rosters with captain/coach/player/substitute roles, invites, and promotion flows.
- **Venues** — community-proposed and claimable commercial venues with public directory pages.
- **Discord integration** — OAuth login plus an optional bot: publish games to guilds, RSVP via interactions, daily calendar digest.
- **Notifications** — preference-routed across database, mail, and web push; weekly digest; one-click unsubscribe; invite opt-out and bounce suppression.
- **PWA** — installable, offline support, web push session reminders, personal iCal calendar feed.
- **Billing** — Paddle subscriptions and one-time charges (the GM workspace is subscriber-funded).
- **Support & admin** — Escalated-powered helpdesk with SLA automations; Filament admin panel with resources, reports, exports, and scheduled-task visibility.
- **Platform ops** — PostHog analytics (consent-aware), SEO with sitemaps and structured data, Cloudflare CDN cache-rule sync, user data exports and privacy anonymization jobs.

## Architecture

Classic Laravel monolith — one deployable app, Livewire for interactivity, services for domain logic.

- **Livewire components** (`app/Livewire/`) — full-page components per feature namespace, Blade templates in `resources/views/livewire/`, Alpine.js for progressive enhancement.
- **Service layer** (`app/Services/`) — all business logic; controllers and components orchestrate only.
- **Enums** (`app/Enums/`) — backed string enums are the single source of truth for state machines (`EventStatus::VALID_TRANSITIONS`, the `ParticipantStatus` lifecycle, `Visibility`).
- **Authorization** — model policies (`app/Policies/`) plus Spatie Permission with team/event-scoped roles (`ScopedRoleService`).
- **Jobs & scheduling** — Redis-backed queues supervised by Horizon; an extensive scheduled suite in `routes/console.php` (attendance sweeps, digests, BGG sync, privacy pruning, data audits).
- **Bilingual by construction** — every web route lives under `/{locale}/` (`en`/`de`); UI strings in `lang/{locale}/`; entity content translation via a polymorphic `translatable` table.
- **Visibility model** — public/protected/private enforced twice: at policy level for single entities and at query level for listings. Both must stay in sync.

**Database:** PostgreSQL with a squashed schema baseline in [`database/schema/pgsql-schema.sql`](database/schema/pgsql-schema.sql) — fresh installs load the baseline via `psql`, new migrations stack on top. See [`database/schema/README.md`](database/schema/README.md) for the runbook. CHECK constraints (not native enum types) on status columns; mixed UUID and integer primary keys.

**Domain language:** terms like *Game*, *Campaign*, *Session*, *Participant* have precise meanings — see [CONTEXT.md](CONTEXT.md) before writing docs or code that uses them. Architectural decisions are recorded in [`docs/adr/`](docs/adr/).

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Framework | Laravel 13 (PHP 8.5+) |
| Frontend | Livewire 4, Alpine.js, Blade, Vite (Node 24) |
| Styling | Tailwind CSS 4, subset Material Symbols icon font |
| Database | PostgreSQL (squashed baseline + migrations) |
| Queue / cache | Redis (predis) via Horizon |
| Auth | Laravel Breeze (Blade), Socialite (Google, Discord), Sanctum (API) |
| Billing | Laravel Cashier — Paddle |
| Email | Resend (with delivery webhooks + suppression) |
| Push | minishlink/web-push (VAPID) |
| Analytics | PostHog (cookie-consent aware) |
| Admin | Filament |
| Helpdesk | Escalated (escalated-dev) |
| Media | Spatie Media Library |
| Permissions | Spatie Permission (global + scoped roles) |
| Testing | Pest + PHPUnit |

## Getting Started

### Prerequisites

- PHP 8.5+, Composer
- Node.js 24+ (see `.nvmrc`)
- PostgreSQL (CI and production run 16; the schema baseline targets it) with the **`psql` client binary on PATH** — a fresh database bootstraps from the squashed schema via `psql`
- Redis (queue, cache, and Horizon all assume it)

### Installation

```bash
composer setup        # install, .env, key, migrate, npm install, build
```

Or step by step:

```bash
composer install
npm install
cp .env.example .env && php artisan key:generate
php artisan migrate
npm run build
```

### Seed Data

```bash
php artisan db:seed                       # roles, permissions, membership plans, TTRPG systems
php artisan bgg:seed-top500               # board game catalog from BoardGameGeek
```

### Running Locally

```bash
composer dev    # web server + queue listener + log tail + Vite, together
```

Or individually: `php artisan serve`, `php artisan queue:listen`, `npm run dev`.

### Environment

Copy `.env.example` and fill in your PostgreSQL/Redis connection details. Optional integrations — Paddle (sandbox default), Google/Discord OAuth, Discord bot, Resend, VAPID keys, PostHog, Cloudflare — disable themselves cleanly when unset.

## Testing

```bash
composer smoke        # smoke group only — run before every commit
composer test         # full suite, parallel
php artisan test --filter='GameTest|CampaignTest'
```

Tag critical-path tests with `->group('smoke')` (see [CONTRIBUTING.md](CONTRIBUTING.md)). CI additionally enforces Pint style, Eloquent best practices (`composer practices`), Larastan, and translation parity (`php artisan i18n:check`).

## Deployment

**Frontend assets:** `composer deploy:assets` — subsets the Material Symbols icon font (only glyphs actually used; rebuild after adding icons, or run `php artisan fonts:audit --fix`) and builds via Vite.

**CDN:** public pages are edge-cached at Cloudflare; cache rules are derived from Laravel routes and synced with `composer deploy:cdn` (needs `CF_ZONE_ID` + `CF_API_TOKEN`). Authenticated traffic always bypasses cache.

**Docker:**

```bash
docker compose up -d app worker
```

The `app` container serves on port 8199 and runs migrations on boot; the `worker` container runs Horizon (queue) and the scheduler — no nginx/fpm.

## Project Documentation

| Document | Contents |
|----------|----------|
| [docs/PLATFORM_INVENTORY.md](docs/PLATFORM_INVENTORY.md) | Complete functional feature inventory |
| [CONTEXT.md](CONTEXT.md) | Ubiquitous domain language (Game vs Campaign vs Session, dashboard terms) |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Workflow, code style, Eloquent practices, testing, DCO |
| [lang/CONTRIBUTING_TRANSLATIONS.md](lang/CONTRIBUTING_TRANSLATIONS.md) | Translation conventions and Weblate flow |
| [database/schema/README.md](database/schema/README.md) | Squashed schema baseline and re-squash runbook |
| [docs/DESIGN-SYSTEM-SNAPSHOT.md](docs/DESIGN-SYSTEM-SNAPSHOT.md) | Design system: colors, typography, components |
| [docs/adr/](docs/adr/) | Architecture decision records |
| [SECURITY.md](SECURITY.md) | Vulnerability reporting policy |
| [AI_POLICY.md](AI_POLICY.md) | Rules for AI-assisted contributions |

## Contributing

Contributions welcome — read [CONTRIBUTING.md](CONTRIBUTING.md) first (DCO sign-off required) and [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md). Licensed under [AGPL-3.0-or-later](LICENSE).
