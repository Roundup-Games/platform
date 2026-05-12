# Roundup Games

Community gaming platform for the DACH region — find tabletop sessions, join campaigns, and discover players near you. Bilingual (English + German).

Built with Laravel 13, Livewire 4, and PostgreSQL.

---

## What It Does

Roundup Games connects tabletop gaming communities. Players discover nearby sessions, join campaigns, manage teams, and find compatible gaming partners. Organizers create games and events, manage rosters, collect fees via Paddle, and run recurring campaigns with session scheduling.

**Core features:**
- **Games & Campaigns** — Create one-shot games or recurring campaigns. Public/protected/private visibility. Application flows with auto-approve for public games. Campaign sessions inherit metadata from parent campaigns.
- **Discovery** — Location-aware search with proximity sorting. Filter by game system, vibe flags, experience level, safety tools, language, price, and complexity. BGG-powered game system catalog with 500+ entries.
- **Social Graph** — Follow players, manage friends/block lists. Friend-based game invitations. Public profiles with configurable field-level privacy.
- **Events & Registration** — Multi-day events with divisions, registration windows, team/individual modes, early bird pricing, and Paddle payment integration.
- **Teams** — Create teams with roster management (captain/coach/player/substitute roles). Invite, promote, demote, remove members.
- **GM System** — Game Master profiles with specializations, star ratings, and proficiency-tagged reviews. Subscriber-only GM workspace. Public GM directory with search and filters.
- **Waitlists & Benching** — Urgency-scaled waitlist for standalone games (FIFO with confirmation windows). Bench mechanics for campaigns and sessions.
- **Attendance & Reliability** — Peer-reported attendance with grief resistance (weight stacking, corroboration, volume quarantine). Reliability scores and tier badges on public profiles.
- **Notifications** — 33 notification types across 6 channels (database, mail, push). Preference-aware routing. Block-list filtering. Unsubscribe support.
- **PWA** — Installable with service worker, offline support, web push notifications, session reminders.
- **Admin Panel** — Filament-powered admin with 8 resources, BGG sync management, event attendance reports, membership reports, export capabilities.
- **Bilingual (EN/DE)** — Full i18n with `/{locale}/` routing, 22 PHP domain translation files per locale, entity content translation for events/announcements/teams, locale-aware date/currency formatting, localized emails.

---

## Architecture

```
app/
├── Console/Commands/       # Artisan commands (BGG sync, geocoding, scheduled sweeps)
├── Dto/                    # Data transfer objects (PushPayload, PwaEligibilityResult)
├── Enums/                  # 22 backed string enums (EventStatus, Visibility, VibeFlag, etc.)
├── Exceptions/             # BggApiException, BggParseException
├── Filament/               # Admin panel resources, pages, relation managers, reports
├── Http/
│   ├── Controllers/        # PageController, PaddleBillingController, SitemapController
│   └── Middleware/         # SetLocale, EnsureProfileComplete
├── Jobs/                   # Queued jobs (UpdateUserDiscoveryCache, HandleExpiredConfirmation, etc.)
├── Livewire/               # 58 full-page components + reusable widgets
│   ├── Billing/            # BillingPortal, MembershipPage
│   ├── Campaigns/          # CampaignsPage (hub), CreateCampaign, CampaignDetail
│   ├── Components/         # Reusable widgets (NearbySessions, SafetyToolPicker)
│   ├── Discovery/          # DiscoveryPage, DiscoveryPortal, BoardGamesDiscovery, AdventuresDiscovery
│   ├── Events/             # EventListing, EventDetail, CreateEvent, ManageEvent
│   ├── Games/              # GamesPage (hub), CreateGame, GameDetail, GameListing
│   ├── GM/                 # GmDirectory, GmWorkspace, SessionZero
│   ├── People/             # PeoplePage (following/followers/blocked/nearby tabs)
│   ├── Profile/            # Show (view/edit), Onboarding
│   ├── Reviews/            # WriteReview, ReportReview
│   └── Teams/              # BrowseTeams, TeamDetail, ManageTeam, ManageRoster, PendingInvites
├── Mail/                   # ContactFormSubmitted, localized mailables
├── Models/                 # 39 Eloquent models
├── Notifications/          # 33 notification classes + custom PushChannel
├── Observers/              # ActivityLogObserver, ReviewObserver
├── Policies/               # 11 policies (User, Team, Game, Campaign, Event, Review, MembershipType, etc.)
├── Relations/              # Custom StringKeyMorphMany for UUID morph relationships
├── Services/               # 33 service classes (business logic layer)
├── Traits/                 # HasTranslations, ManagesParticipants, HasGuestLocation, EscapesLikeWildcards
└── Translation/            # HasTranslations trait implementation

resources/
├── views/
│   ├── components/         # ~30 Blade components (x-gm-badge, x-user-link, x-registration-cta, etc.)
│   ├── layouts/            # app.blade.php (authenticated), public-layout.blade.php (guest)
│   ├── emails/             # Notification mail templates with shared layout
│   └── livewire/           # Component Blade templates organized by feature
├── js/                     # Alpine.js, guest-location helper, PWA install logic
└── css/                    # Tailwind CSS with "Digital Parlor" design system

lang/
├── en/                     # 22 domain files (auth, events, teams, games, campaigns, etc.)
└── de/                     # Matching German translations

database/
└── migrations/             # 96 migrations
```

### Design Patterns

- **Service Layer** — Business logic lives in dedicated services (AttendanceService, WaitlistService, BenchService, PeopleDiscoveryService, etc.). Controllers and Livewire components orchestrate services, never contain business rules.
- **Trait Deduplication** — Shared patterns extracted to traits: `ManagesParticipants` (game + campaign invitations), `HasGuestLocation` (browser location bridge), `HasTranslations` (entity content translation), `EscapesLikeWildcards` (search query safety).
- **Policy-Based Authorization** — 11 model policies with `before()` global admin bypass, scoped role checks via `ScopedRoleService`, and ownership fallback. Visibility enforcement at both policy level (single-entity) and listing level (query-time).
- **Event-Driven Side Effects** — Observers for activity logging and review aggregate computation. Event dispatch for social actions (follow/block triggers discovery cache invalidation).
- **Grief-Resistant Scoring** — Attendance reliability uses multiplicative weight stacking (low reliability × volume quarantine × timeliness decay) with auto-corroboration from independent reporters.
- **Enum-Driven State Machines** — `EventStatus::VALID_TRANSITIONS`, `ParticipantStatus` lifecycle (approved/rejected/pending/waitlisted/benched), `AttendanceStatus` tracking. Enums are the single source of truth.

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| **Framework** | Laravel 13 (PHP 8.3+) |
| **Frontend** | Livewire 4, Alpine.js, Blade templates |
| **Styling** | Tailwind CSS 3 with ~35 Material Design color tokens |
| **Typography** | Noto Serif (headings), Inter (body), Material Symbols Outlined (icons) |
| **Database** | PostgreSQL (96 migrations, 39 models, 22 enums) |
| **Cache/Queue** | Redis (predis 3.4) |
| **Auth** | Laravel Breeze (Blade stack), Socialite (Google OAuth) |
| **Billing** | Laravel Cashier (Paddle) — subscriptions, one-time charges, webhooks |
| **Admin** | Filament v5 — 8 resources, 5 relation managers, reports, exports |
| **Media** | Spatie Media Library (avatars, BGG cover images) |
| **Permissions** | Spatie Permission (4 roles, 32 permissions, team + event scoping) |
| **Testing** | Pest 4 (213 test files, ~5,000+ tests) |
| **Email** | Resend |
| **PWA** | Web Push (minishlink/web-push), service worker, install prompt |
| **Infrastructure** | Docker, Vite 8 |

---

## Getting Started

### Prerequisites

- PHP 8.3+
- PostgreSQL 15+
- Redis 7+
- Node.js 20+
- Composer 2

### Installation

```bash
# Clone and install dependencies
composer install
npm install

# Environment setup
cp .env.example .env
php artisan key:generate

# Database (PostgreSQL 15+ required)
createdb roundup_games
php artisan migrate

# Seed with sample data (roles, permissions, membership plans)
php artisan db:seed

# Frontend assets
npm run build
```

Configure `.env` with your PostgreSQL, Redis, Paddle, Google OAuth, and Resend credentials.

### Running Locally

```bash
# Start the dev server (includes Vite, queue worker, and log tail)
composer dev

# Or start services individually:
php artisan serve                  # Web server
php artisan queue:listen           # Process queued jobs (email, push, cache)
npm run dev                        # Vite dev server with HMR
```

### Key Environment Variables

```env
DB_CONNECTION=pgsql
DB_DATABASE=roundup_games
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=predis

# Optional (features degrade gracefully without these)
PADDLE_SANDBOX=true
GOOGLE_CLIENT_ID=
RESEND_KEY=
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
```

### Seed Data

```bash
# Core roles, permissions, and membership plans
php artisan db:seed

# BGG game system catalog (500+ board games with categories/mechanics)
php artisan bgg:seed-top500

# TTRPG systems from StartPlaying.games (71 systems, 40 genres, 17 mechanics)
php artisan db:seed --class=StartPlayingSeeder
```

---

## Testing

### Smoke Tests

Run the critical-path suite before every commit:

```bash
composer smoke
```

168 tests covering: authentication, registration, OAuth, billing, games, campaigns, events, teams, notifications, discovery, safety tools, and visibility policies. If `composer smoke` is green, you're safe to commit — the full suite is CI's job.

**Adding a smoke test:** Tag any Pest test with `->group('smoke')` and add a `// smoke:` comment explaining why it's on the critical path:

```php
test('guest can view public game', function () {
    // smoke: core visibility — guests must see public games
})->group('smoke');
```

### Full Suite

```bash
php artisan test
```

~5,000+ tests across 213 files. Takes 10+ minutes. Some pre-existing failures exist in areas under active development.

### Running Specific Tests

```bash
# Single file
php artisan test tests/Feature/Games/GameTest.php

# Multiple files (pipe-delimited)
php artisan test --filter='GameTest|CampaignTest'

# By directory
php artisan test tests/Feature/Policies/
```

### Test Infrastructure

- PostgreSQL test database (`roundup_games_test`) — Testcontainers available but not required
- PHPUnit memory limit: 1024MB (suite is large)
- Test bootstrap: `tests/bootstrap.php` with locale URL defaults
- Shared helpers: `tests/Pest.php` with `seedPermissions()` and `seedRoles()`

---

## Key Concepts

### Visibility Model

Every game and campaign has one of three visibility levels:
- **Public** — visible to everyone (guests included). Applications auto-approved.
- **Protected** — visible to owner's friends and teammates. Applications require manual approval.
- **Private** — visible to owner and participants only. No applications.

Policies enforce single-entity access; listing components enforce query-time filtering. Both must stay in sync.

### Locale Routing

All web routes live under `/{locale}/` (`/en/`, `/de/`). The `SetLocale` middleware validates the locale, persists it to session, and injects it via `URL::defaults()` so all `route()` calls automatically include the locale parameter. Root `/` redirects based on session → Accept-Language → fallback.

### Permission Scoping

Spatie Permission is configured with team support. The `team_id` column (varchar 36) supports both integer Team IDs and UUID Event IDs. Global roles have `team_id=null`; scoped roles (Team Admin, Event Admin) use the entity's primary key. `ScopedRoleService` handles permission resolution with `try/finally` exception safety.

### Discovery Pipeline

PeopleDiscoveryService uses a 4-phase pipeline: geohash-based candidate retrieval → bulk preference loading → privacy-aware Jaccard similarity scoring → paginated results. Cached for 5 minutes per user+geohash tile. Invalidated on follow/unfollow/block.

---

## Project Conventions

### Translation Keys

Translations use PHP group files at `lang/{locale}/{domain}.php` with semantic dotted keys:

```php
__('games.flash_game_created')       // Flash message
__('events.field_registration_fee')  // Form label
__('common.action_cancel')           // Shared button text
```

22 domain files per locale. Key naming convention: `action_` (buttons), `field_` (labels), `status_` (states), `flash_` (messages), `error_` (validation), `content_` (marketing). See `lang/CONTRIBUTING_TRANSLATIONS.md` for full rules.

### Livewire Components

- Full-page components in feature namespaces (`App\Livewire\Games\GameDetail`)
- Reusable widgets in `App\Livewire\Components\` (`NearbySessions`, `SafetyToolPicker`)
- Never expose Eloquent models as public properties — use `#[Locked]` with primitive types
- Use `rules()` method instead of `#[Validate]` attributes (Livewire v4 compatibility)

### Database

- PostgreSQL with CHECK constraints on enum columns (not native enum types)
- UUID primary keys on Event, GMProfile, Review, GameSystemRequest, SessionZeroSurvey/Confirmation models
- Integer primary keys on Game, Campaign, Team, User models
- All migration indexes explicitly named for reliable rollback
- Polymorphic translations table (`translatable_type`/`translatable_id`) for entity content translation

### Frontend

- "Digital Parlor" design system: amber primary (#835500), cream surfaces (#fbf9f1), warm shadows
- `wire:navigate` on all internal links for SPA-style page transitions
- `font-heading` and `font-body` tokens — never reference font families directly
- Decorative SVGs get `aria-hidden="true"`, icon-only buttons get `aria-label`
- Mobile-first: all pages render correctly at 375px before desktop

---

## Deployment

### Icon Font Subsetting

Material Symbols Outlined is subset to only the ~170 icons used across templates, enums, and JS. This reduces the font from ~1.1 MB (full set) to ~160 KB.

```bash
# Rebuild the subset (run after adding new icons to templates)
bash build-tools/subset-icons.sh

# Audit icon usage vs config/fonts.php
php artisan fonts:audit          # report gaps
php artisan fonts:audit --fix    # auto-add missing icons to config
```

When adding a new `material-symbols-outlined` icon to any Blade template, PHP enum, or JS file, run `bash build-tools/subset-icons.sh` to regenerate the subset. The build script also auto-discovers icons not yet in `config/fonts.php`.

### Frontend Assets

```bash
npm run build
```

### Cloudflare Cache Rules

Cache rules are derived from Laravel routes and synced to Cloudflare automatically. Two rules are managed:

| Rule | What | Edge TTL |
|---|---|---|
| Static assets (`/build/`, `/fonts/`, `/icons/`) | Immutable hashed assets | 1 year |
| Public pages (anonymous visitors) | All locale-prefixed routes without auth middleware | 5 min |

Authenticated users (session cookie present) always bypass cache. The `CachePublicPages` middleware controls origin headers.

```bash
# Preview what would change (no API calls)
php artisan cloudflare:cache-rules --dry-run

# Apply rules to Cloudflare
php artisan cloudflare:cache-rules

# Force re-apply
php artisan cloudflare:cache-rules --force
```

**Setup:** Create a Cloudflare API token with **Zone → Cache Rules → Edit** and **Zone → Cache Purge → Purge** permissions. Add to `.env`:

```
CF_ZONE_ID=your_zone_id
CF_API_TOKEN=your_api_token
```

Rules prefixed `[roundup-auto]` are managed by the command. Manual rules in the Cloudflare dashboard are preserved.

When adding a new public route, just redeploy and run `php artisan cloudflare:cache-rules` — the expression regenerates from current routes.

### Composer Deploy Shortcuts

```bash
composer deploy:assets   # subset icons + vite build
composer deploy:cdn      # sync cloudflare cache rules
```

---

## Docker

```bash
# Production build
docker compose up -d

# With queue worker
docker compose up -d app worker
```

The app container serves on port 8199. The worker container runs `php artisan queue:work redis --queue=default,discovery`.
