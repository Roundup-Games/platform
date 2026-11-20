# Roundup Games — Complete Platform Inventory

> **Purpose:** Functional inventory for non-profit incorporation, funding applications, and collaborator onboarding.
> **Maintenance:** This is a living document, refreshed alongside the shipped platform — the repository is the source of truth for exact counts, versions, and history.

---

## Executive Summary

**Roundup Games** is a community discovery platform for tabletop gaming — board games, tabletop RPGs, card games, and related social play. It connects players with games, campaigns, events, and each other through proximity-based discovery, taste-matching algorithms, and a rich social graph. The platform is built as a production-grade Laravel application with Livewire full-stack components, serving both public-facing discovery pages and authenticated user workflows.

**Platform identity:** "There's a seat waiting for you." — emphasis on belonging, imaginative play, curiosity, and safety. Not competition-focused; sessions and campaigns are primary content, events are infrastructure.

**Tech stack:** Laravel · PHP · PostgreSQL · Redis · Livewire · Tailwind CSS · Alpine.js · Vite · Paddle (payments) · PostHog (analytics) · Filament (admin) · Escalated (helpdesk) — version specifics live in `composer.json`/`package.json` and the README, which stay authoritative.

---

## 1. Public-Facing Features

All public pages are locale-prefixed (`/{locale}/...`), SEO-optimized with sitemap generation, and CDN-cacheable for anonymous visitors

### 1.1 Landing & Static Pages

| Page | Route | Description | Status |
|------|-------|-------------|--------|
| Home | `/` | Landing page with hero, nearby sessions, brand messaging | ✅ Complete |
| How It Works | `/how-it-works` | Platform explainer | ✅ Complete |
| About | `/about` | Company information (redirects to /how-it-works) | ✅ Complete |
| Our Pledge | `/our-pledge` | Ethical commitment & transparency | ✅ Complete |
| Algorithms | `/our-pledge/algorithms` | Algorithm transparency disclosure | ✅ Complete |
| For Organizers | `/for-organizers` | Organizer-focused landing page | ✅ Complete |
| Contact | `/contact` | Contact form (rate-limited 5/min) | ✅ Complete |
| Safety Tools | `/safety-tools` | Safety tools education & best practices | ✅ Complete |
| Privacy Policy | `/privacy` | Privacy policy | ✅ Complete |
| Terms of Service | `/terms` | Terms of service | ✅ Complete |
| Impressum | `/impressum` | Legal impressum (DACH compliance) | ✅ Complete |

### 1.2 Discovery & Browsing

| Feature | Route | Description | Status |
|---------|-------|-------------|--------|
| Discovery Portal | `/discover` | Entry point with board game & adventure counts | ✅ Complete |
| Board Games Discovery | `/discover/board-games` | Proximity-based board game session finder with comprehensive filters (system, experience, vibe, language, complexity, price, categories, mechanics, proximity radius) | ✅ Complete |
| Adventures Discovery | `/discover/adventures` | TTRPG/campaign finder with additional play style, session type, session zero filters | ✅ Complete |
| Game System Catalog | `/game-systems` | Searchable directory of all game systems with filters (categories, mechanics, player count, complexity, play style) | ✅ Complete |
| Game System Detail | `/game-systems/{slug}` | Full game system page with categories, mechanics, designers, publishers, expansions, active sessions, FAQ, external links | ✅ Complete |
| Game Listings | `/games` | Public game session listing with filters | ✅ Complete |
| Game Detail | `/games/{id}` | Public game session detail (SEO-friendly) | ✅ Complete |
| Campaign Listings | `/campaigns` | Public campaign listing with filters | ✅ Complete |
| Campaign Detail | `/campaigns/{id}` | Public campaign detail (SEO-friendly) | ✅ Complete |
| Event Listings | `/events` | Public event listing with search, type, status, date filters | ✅ Complete |
| Event Detail | `/events/{slug}` | Full event page with announcements, registration counts | ✅ Complete |
| Team Directory | `/teams` | Public team listing with search and sort | ✅ Complete |
| Team Detail | `/teams/{slug}` | Team profile with active roster | ✅ Complete |
| GM Directory | `/gms` | Game Master directory filterable by specialization, game system, rating; sortable by rated/reviewed/sessions/newest; evergreen intro copy in both locales | ✅ Complete |
| City Hubs Index | `/cities` | Directory of live city hubs — a hub appears automatically when a city's public activity around verified venues crosses the qualifying threshold | ✅ Complete |
| City Hub | `/cities/{slug}` | Per-city landing page bundling upcoming public games, campaigns, events, and verified venues for a local scene | ✅ Complete |
| Public Profiles | `/u/{slug}` | Public user profiles with visibility-scoped content | ✅ Complete |

### 1.3 SEO & Performance Infrastructure

| Feature | Implementation | Status |
|---------|---------------|--------|
| XML Sitemap | Typed sitemaps (static pages, game systems, events, games, campaigns, profiles, venues, cities) with per-type freshness priorities | ✅ Complete |
| SEO Metadata | Per-entity overrides (title, description, image, canonical URL, robots directives) on 7 content types | ✅ Complete |
| CDN Caching | Public pages cached 60s client / 300s CDN, with private-page exclusion list | ✅ Complete |
| Cloudflare Integration | Cache rule sync command for route-based cache policies | ✅ Complete |
| Structured Data | schema.org markup: Organization (truthful Berlin-only areaServed, config-sourced sameAs/contactPoint), BreadcrumbList sitewide, FAQPage (FAQ + algorithms pages), WebSite + per-locale SearchAction sitewide | ✅ Complete |
| Agent surfaces (GEO) | llmstxt.org v2 `/llms.txt` map — generated with live stats, cached 1h, link-resolution-walked by tests; markdown alternates for the 9 EN pillar pages (`/en/{page}.md`, canonical Link header); `rel=describedby` site-wide; answer-crawler robots.txt posture (GPTBot, Claude family, Perplexity family, Google-Extended, Applebot, Amazonbot, Bytespider); per-entry BoardGameGeek source attribution on game-system pages | ✅ Complete |
| GEO measurement | Search Performance admin page: GSC digest trend, Core Web Vitals per template, IndexNow state, and an AI-surfaces section (GSC API gap statement, manual monthly spot-check checklist, first-party `/llms.txt` fetch telemetry in hourly buckets) | ✅ Complete |
| Temporal trust signals | "Last reviewed" notes on evergreen pillar pages and their markdown mirrors, single-sourced from `config/content-reviews.php` | ✅ Complete |

### 1.4 Utility Endpoints

| Endpoint | Purpose |
|----------|---------|
| `/link/{code}` | Short link resolution with analytics tracking |
| `/sitemap.xml` | Sitemap index |
| `/sitemap-{type}.xml` | Typed sitemaps |
| `/locale/switch/{locale}` | Locale switching |
| `/auth/{provider}/redirect` | OAuth redirect (Google) |
| `/auth/{provider}/callback` | OAuth callback |
| `/invite-optout/{emailHash}` | Email invite opt-out flow |
| `/notifications/unsubscribe/{user}/{category}` | One-click notification unsubscribe (signed URL, RFC 8058 POST) |
| `/calendar/{code}` | Personal webcal feed (token-authenticated iCal) |
| `/discord/interactions` | Discord interaction webhook (Ed25519-verified) |
| `/paddle/webhook` | Paddle payment webhook (CSRF-exempt) |

---

## 2. Authentication & Identity

### 2.1 Registration & Authentication

| Feature | Description | Status |
|---------|-------------|--------|
| Email/Password Registration | Standard Laravel Breeze registration with validation | ✅ Complete |
| Email Verification | Email verification flow with resend capability | ✅ Complete |
| Password Reset | Forgot password / reset password flow | ✅ Complete |
| Google OAuth | Sign in / sign up with Google; account linking | ✅ Complete |
| OAuth Account Linking | Link/unlink Google accounts from profile settings | ✅ Complete |
| Session Security | Password confirmation for sensitive actions | ✅ Complete |

### 2.2 Onboarding

| Feature | Description | Status |
|---------|-------------|--------|
| 4-Step Profile Wizard | Location (with browser geolocation) → Identity (pronouns, gender with GDPR consent) → Contact (phone) → Preferences (game systems, vibes) | ✅ Complete |
| Guest Location Carryover | Browser geolocation from guest browsing persists into onboarding | ✅ Complete |

### 2.3 User Profile

| Feature | Description | Status |
|---------|-------------|--------|
| Profile Editor | Full profile editing with tabs: Personal Info, Preferences, GM Profile, Linked Accounts, Notifications, Privacy, Social Links, Password, Danger Zone | ✅ Complete |
| Avatar Upload | Image upload with Spatie Media Library, 150×150 thumbnail | ✅ Complete |
| Game System Preferences | Favorite/avoid game systems with automatic expansion implication logic | ✅ Complete |
| Vibe Preferences | 6 paired sliders + 8 standalone tri-state flags for play style | ✅ Complete |
| Privacy Controls | Field-level visibility settings (location, game systems, vibes, campaigns, teams, friends list, stats) — each settable to everyone/friends/nobody | ✅ Complete |
| Notification Preferences | Per-category channel selection (database, email, push) | ✅ Complete |
| Calendar Sync | Google Calendar connection in Settings: connect/disconnect, per-source toggles (games, campaign sessions, events), status row with last sync, revoked re-auth banner — managed sync ships default-off pending Google scope verification (D300) | ✅ Complete |
| Social Links | Configurable social platform URLs (Discord, Mastodon, etc.) with validation | ✅ Complete |
| Public Profile | SEO-optimized public profile with visibility-scoped content, follow/block actions | ✅ Complete |
| Authenticated Profile | Full profile viewer with games, campaigns, GM reviews, reliability badge, team memberships | ✅ Complete |
| Reliability Score | Computed from attendance history: attended (+1), late_cancel (−0.3), no_show (−1). Tiers: New / Active / Reliable (>95%) | ✅ Complete |
| Profile Slug | User-chosen URL slug for public profiles | ✅ Complete |

### 2.4 Personal AI Agents (MCP)

| Feature | Description | Status |
|------|-------------|--------|
| MCP ops surface | The platform speaks the Model Context Protocol — users connect the AI assistant they already use via per-agent tokens issued in Settings | ✅ Complete |
| Agent roles | Each agent is read-only companion, participant, or GM workspace — it acts only as its user, under the same eligibility rules, rate limits, and audit trail as the website | ✅ Complete |
| Revocable | Per-agent tokens can be revoked at any time; usage is observable on the admin ops page | ✅ Complete |

---

## 3. Core Domain Features

### 3.1 Game Sessions

| Feature | Description | Status |
|---------|-------------|--------|
| Create Game | Multi-step form: type (oneshot/ttrpg/boardgame), game system, date/time, description, duration, price, language, location, visibility, experience level, complexity, vibe preferences, safety rules, player limits, bench mode, min reliability preference. Supports cloning from existing games. | ✅ Complete |
| Game Detail | Full authenticated view with participant management, waitlist handling, bench management, session end (debriefing + recap), share links, short link tracking, attendance reporting | ✅ Complete |
| Public Game Detail | SEO-friendly read-only view with share link support | ✅ Complete |
| Game Listings | Authenticated user's games with inline editing, cancel/complete actions | ✅ Complete |
| Apply to Game | Application form with optional message, pessimistic locking for double-submit protection | ✅ Complete |
| Manage Participants | Approve/reject applicants, manage invites, remove participants | ✅ Complete |
| Visibility Model | Public (anyone) / Protected (friends + teammates) / Private (owner + participants only). Short link & share token bypasses for non-terminal statuses. | ✅ Complete |
| Game Types | One-shot, TTRPG, Board Game — each with type-adaptive form fields | ✅ Complete |
| Session Recap | Host-only post-completion recap writing with participant notification | ✅ Complete |
| Session Debriefing | Post-game structured feedback with prompts | ✅ Complete |
| Attendance Reporting | Report attendance with grief resistance, dispute filing via helpdesk tickets | ✅ Complete |
| Auto-Attend | Automatic attendance marking after 48 hours for unreported participants | ✅ Complete |

### 3.2 Campaigns

| Feature | Description | Status |
|---------|-------------|--------|
| Create Campaign | Full form: name, game system, location, description, recurrence (weekly/biweekly/monthly), time, session duration, price, language, visibility, player limits, experience, complexity, vibes, safety rules, bench mode | ✅ Complete |
| Campaign Detail | Participant management, waitlist, bench, share links, session scheduling | ✅ Complete |
| Public Campaign Detail | SEO-friendly public view | ✅ Complete |
| Campaign Listings | Authenticated user's campaigns with inline editing, cancel/complete | ✅ Complete |
| Apply to Campaign | Application form with pessimistic locking | ✅ Complete |
| Manage Participants | Full participant lifecycle management | ✅ Complete |
| Add Session to Campaign | Create new Game (session) linked to campaign, pre-populates roster | ✅ Complete |
| Recurrence | Weekly, bi-weekly, monthly scheduling | ✅ Complete |
| Visibility | Same 3-tier model as games (Public / Protected / Private) | ✅ Complete |
| Campaign Charter | Signable, versioned table agreement: boundaries, safety tools, table policies, AI & digital-tools consent, commitments — with private player answers; charter publish/amend fans out notifications to approved participants. Safety core free; unlimited charters/templates with membership | ✅ Complete |
| Campaign Journal | Per-campaign journal (recaps, goals, character arcs) with Previously On catch-up digests and spoiler-safe recruitment summaries | ✅ Complete |

### 3.3 Waitlist System

| Feature | Description | Status |
|---------|-------------|--------|
| Automatic Promotion | When a spot opens (player leaves), next waitlisted player is promoted | ✅ Complete |
| Urgency-Scaled Confirmation | Confirmation windows scale by urgency: 12 hours (days away) → 30 minutes (minutes before session) | ✅ Complete |
| Expiration Handling | Delayed job dispatched with confirmation deadline; expired confirmations trigger next-in-line promotion | ✅ Complete |
| Max Expiration Rejection | Players who miss too many confirmations are permanently rejected | ✅ Complete |
| Safety Net Sweep | Every 5 minutes, sweeps for missed expirations as backup | ✅ Complete |

### 3.4 Bench (Overflow) System

| Feature | Description | Status |
|---------|-------------|--------|
| Bench Mode | Campaigns and games can enable bench for overflow management | ✅ Complete |
| Concurrency-Safe | Row locking prevents race conditions during bench promotion | ✅ Complete |
| Promotion | Manual bench-to-active promotion by organizers | ✅ Complete |

### 3.5 Events

| Feature | Description | Status |
|---------|-------------|--------|
| Create Event | 5-step wizard: Basic Info → Venue/Location → Registration & Fees → Schedule & Rules → Review. Supports early bird discounts, max teams/participants, divisions | ✅ Complete |
| Event Detail | Public event page with announcements, registration counts | ✅ Complete |
| Event Listings | Public listing with search, type, status, date filters | ✅ Complete |
| Manage Event | Tabbed UI: details, venue, registration, divisions, rules. Status management (draft → published → registration_open → in_progress → completed) | ✅ Complete |
| Event Registration | Individual and team registration modes, team roster selection, division picker | ✅ Complete |
| Manage Registrations | Search, filter by status/type/payment. Approve/reject/cancel. Internal notes | ✅ Complete |
| Event Announcements | CRUD for event announcements with pin/unpin, publish/draft, translatable content | ✅ Complete |
| Event Types | Tournament, Convention, Game Day, League, Other | ✅ Complete |
| Registration Types | Individual, Team, or Both | ✅ Complete |
| Divisions | Configurable divisions for team events | ✅ Complete |
| Fee Structure | Individual registration fee, team registration fee (in cents) | ✅ Complete |
| Status State Machine | Draft → Published → Registration Open → In Progress → Completed (+ Cancelled, Archived) with validation | ✅ Complete |

### 3.6 Teams

| Feature | Description | Status |
|---------|-------------|--------|
| Create Team | Name, description, city, country, colors (primary/secondary), founded year | ✅ Complete |
| Team Detail | Public team profile with active roster | ✅ Complete |
| Browse Teams | Public listing with search and sort | ✅ Complete |
| Manage Team | Captain-only settings editor | ✅ Complete |
| Manage Roster | Invite by email, assign roles (captain/coach/player/substitute), edit jersey number/position, remove members | ✅ Complete |
| Team Invitations | Pending invitations page; accept/decline; prevents dual active memberships | ✅ Complete |
| Team Roles | Captain, Coach, Player, Substitute — each with distinct permissions | ✅ Complete |
| Team Branding | Primary and secondary colors for visual identity | ✅ Complete |

### 3.7 Game Systems

| Feature | Description | Status |
|---------|-------------|--------|
| Game System Catalog | Searchable directory with filters (categories, mechanics, player count, complexity) | ✅ Complete |
| Game System Detail | Full page with categories, mechanics, designers, publishers, expansions, active sessions, FAQ, external links, showcases, instructions | ✅ Complete |
| BoardGameGeek Sync | Automated sync from BGG XML API with batch processing, taxonomy import (categories, mechanics, publishers, designers, families) | ✅ Complete |
| BGG Seed | Top 500 BGG games + automatic expansion discovery | ✅ Complete |
| BGG Sync Logs | Admin-visible sync history with status, timing, and error tracking | ✅ Complete |
| TTRPG Systems | Extended fields: creator, player range, source, SP rating, FAQ, external links, showcases, instructions | ✅ Complete |
| StartPlaying Integration | TTRPG metadata crawled from StartPlaying.games | ✅ Complete |
| Request New System | User-facing game system request form (creates helpdesk ticket) | ✅ Complete |
| My Requests | View own game system requests and status | ✅ Complete |
| Categories & Mechanics | Taxonomy managed via admin panel, with similar-item cross-references | ✅ Complete |
| Platform Scores | Popularity scores computed daily with type-specific weights | ✅ Complete |

---

## 4. Social & Community

### 4.1 Social Graph

| Feature | Description | Status |
|---------|-------------|--------|
| Follow | Instant asymmetric follow (Twitter-style); no approval needed | ✅ Complete |
| Friendship | Automatic detection of mutual follow = friendship | ✅ Complete |
| Block | Block/unblock with full visibility exclusion | ✅ Complete |
| People Page | Three tabs: Following, Followers, Blocked. With nearby discovery section | ✅ Complete |
| Friend Search | Debounced search with chip-based selection | ✅ Complete |
| Mutual Friends | Mutual friend calculation for social proof | ✅ Complete |
| Social Proof in Discovery | Friends' activity shown in discovery results | ✅ Complete |

### 4.2 Discovery & People Matching

| Feature | Description | Status |
|---------|-------------|--------|
| Nearby People Discovery | Geohash-based geographic matching with tier expansion (4-char → 3-char → 2-char) | ✅ Complete |
| Taste Compatibility | Jaccard similarity scoring on game system preferences + vibe flags | ✅ Complete |
| Privacy-Gated | Existing privacy settings control discoverability; no separate toggle | ✅ Complete |
| Score Composition | 70% taste compatibility + 30% social proof, with graceful reweighting for private fields | ✅ Complete |
| Discovery Cache | Async cache warming via Redis with per-user ShouldBeUnique jobs | ✅ Complete |
| Guest Support | Location-gated guest browsing via browser geolocation + localStorage | ✅ Complete |
| Proximity Search | 10km bounding box pre-filter + Haversine sort for games/events | ✅ Complete |
| Hub Detection | Geographic concentration detection via location_id GROUP BY, cached by geohash tile | ✅ Complete |

### 4.3 Reviews

| Feature | Description | Status |
|---------|-------------|--------|
| Write Review | Star rating (1-5) + body text + proficiency tags for GM reviews | ✅ Complete |
| Review Eligibility | Eligibility gate: must be approved participant, date passed, not already reviewed | ✅ Complete |
| GM Review Aggregation | Automatic aggregate computation (average rating, review count, top-3 proficiency badges) | ✅ Complete |
| Report Review | Report a review — creates helpdesk ticket in Safety department | ✅ Complete |
| Report Content | General content report for users, games, campaigns — creates helpdesk ticket | ✅ Complete |

### 4.4 GM (Game Master) Ecosystem

| Feature | Description | Status |
|---------|-------------|--------|
| GM Directory | Public directory filterable by specialization, game system, rating | ✅ Complete |
| GM Workspace | Authenticated workspace: upcoming sessions, recent reviews, player stats, active campaigns, charter surveys (Session Zero practice), link analytics | ✅ Complete |
| GM Profile | Role-gated profile with bio, specializations, social links, stats | ✅ Complete |
| Campaign Charter surveys | Safety tool selection, lines & veils, tone/genre, house rules, content warnings, player expectations — the Session Zero practice inside the Campaign Charter flow. Shareable UUID link | ✅ Complete |
| GM Role Management | Automatic GM role activation/revocation tied to subscription lifecycle | ✅ Complete |

### 4.5 Badges

| Feature | Description | Status |
|---------|-------------|--------|
| Badge definitions | Admin-managed badge definitions (category, rarity, criteria) — badges are grounded in real platform history, never points-based | ✅ Complete |
| Badge awards | Earned from real events (first hosted table, completed journaled campaign, reliability milestones) — displayed on profiles | ✅ Complete |
| Public badge gallery | `/badges` — public gallery of every earnable badge, grouped by category and rarity | ✅ Complete |

---

## 5. Notifications & Communication

Every notification is a corpus class rendered per channel — in-app, email, Discord, and web push — routed by the recipient's per-category channel preferences and speaking one design language across all four.

### 5.1 Notification System

| Feature | Description | Status |
|------|-------------|--------|
| Notification Bell | Sidebar bell with unread count badge, dropdown with recent grouped notifications. Polls every 30 seconds. | ✅ Complete |
| Notification History | Full paginated history grouped by type + day with expandable groups | ✅ Complete |
| Per-Category Preferences | Users choose delivery channels per category (database, email, Discord, push), with a per-category settings shortcut on every bell row | ✅ Complete |
| One-Click Unsubscribe | Signed-URL category unsubscribe honoring RFC 8058 — the List-Unsubscribe header and body link share one signature; scanners can't trip it (GET confirms, POST writes) | ✅ Complete |
| Weekly Digest | One weekly summary mail for users who keep email off for noisy categories — entity-named rows deep-linked to the thing they're about, with its own one-click opt-out | ✅ Complete |
| Cross-Channel Read Sync | Acting on an email CTA or push action marks the matching in-app notification read — the bell badge stays truthful whichever channel the user acted on | ✅ Complete |
| Burst Batching | Notification bursts inside a 5-minute defer window collapse into one digest per channel (recipient × category × entity collapse keys, MaxSize 10 early flush); single standalone events still deliver immediately; flush via delayed job + every-minute sweep with processed_at exactly-once idempotency | ✅ Complete |
| Engagement Counters | Per-category daily aggregates (sent/delivered/unsubscribed per channel) feeding the verification harness | ✅ Complete |

### 5.2 Notification Corpus

Every corpus class ships an explicit render per channel — a channel's voice is written, never derived, and a class may deliberately opt out of a channel. The corpus spans:

- **Entity lifecycle** — invitations, updates, cancellations, completions for games, campaign sessions, and events (unified entity classes)
- **Participation** — applications approved/rejected, joins, removals, benching, below-minimum warnings, session reminders, recaps, debriefings
- **Waitlist & bench** — promotions with confirmation deadlines, expirations, permanent rejections, nachrücken notices
- **Attendance** — peer-reported attendance, resolution outcomes
- **Social** — follows, applications, bulletins, announcements
- **Moderation & account** — suspensions, content removals, warnings (mail-on by default)
- **Game system requests & teams** — request outcomes, team invitations, roster changes
- **Weekly digest** — mail-only summary (deliberately no bell row or DM)

A corpus render harness re-renders every class in both locales and machine-enforces the cross-cutting invariants (branded theme, preheaders, unsubscribe lines, read-marker CTAs, verb-first copy) — a new class that misses one fails CI. See `docs/notification-verification.md`.

### 5.3 Email Channel

| Feature | Description | Status |
|------|-------------|--------|
| Branded design system | One warm amber/cream roundup theme for every notification mail and mailable — light-locked (clients that recolor break dual-scheme mail), with per-class EN/DE inbox preview lines | ✅ Complete |
| Trust headers | List-Unsubscribe + List-Unsubscribe-Post (RFC 8058 one-click) stamped at a single channel chokepoint; mailto fallback configurable | ✅ Complete |
| Calendar attachments | iTIP invitations ride along with the mail (see 5.5) | ✅ Complete |
| Escalated mail branding | Helpdesk ticket mails carry the roundup brand via view overrides (vendor package untouched) | ✅ Complete |
| Welcome mail | Purpose-written single-CTA welcome (EN/DE), queued and suppression-aware, covering form signup and both OAuth branches | ✅ Complete |

### 5.4 Push & Discord Channels

| Feature | Description | Status |
|------|-------------|--------|
| Web Push via VAPID | Standard Web Push API with VAPID authentication; service-worker render layer with a vitest-pinned test suite | ✅ Complete |
| Rich payloads | Action buttons (e.g. Confirm Spot on waitlist promotions), event timestamps, per-category urgency/TTL, replace-and-renotify for deadline-critical pushes, monochrome Android tray badge | ✅ Complete |
| Subscription hygiene | Poison-key detection with deterministic probing (never mass-deletes on a VAPID problem), expiry cleanup scoped per user, stale subscription pruning | ✅ Complete |
| Session Reminders | Automated 24h and 1h push reminders for upcoming games | ✅ Complete |
| Discord DMs | Every corpus class carries an explicit Discord DM render — semantic status colors (emerald default, red cancellation, grey ambient), host/cover-enriched invitations, and `allowed_mentions` hardened so user text can never trigger @everyone/@role/@user | ✅ Complete |

### 5.5 Calendar (ICS) Lifecycle

| Feature | Description | Status |
|------|-------------|--------|
| iTIP mail lifecycle | Hand-rolled RFC 5545/5546 writer: METHOD:REQUEST invitations, same-UID SEQUENCE-bumped updates when schedule/venue/duration change, METHOD:CANCEL revocations — one shared builder with octet-exact folding and TEXT escaping | ✅ Complete |
| Personal webcal feed | Token-authenticated `/calendar/{code}` feed (METHOD:PUBLISH) mirroring the user's confirmed games, campaign sessions, and event registrations on a rolling 90-day window; cancellations publish STATUS:CANCELLED | ✅ Complete |
| Add-to-calendar links | Google Calendar template deep links on the game page and Discord card | ✅ Complete |
| Google Calendar managed sync | OAuth-connected Google calendars with encrypted tokens and revocation detection: deterministic event ids shared with the ICS lifecycle, idempotent ensure/update/cancel/delete (sendUpdates=none on every mutation, callers cannot override), queued triggers on sanctioned participant/registration writes, bounded reconcile sweep sharing the feed's DesiredCalendarSet commitment query | ✅ Complete |

### 5.6 Discord Bridge

| Feature | Description | Status |
|------|-------------|--------|
| OAuth login | Sign in / sign up with Discord; per-user link with DM routing preview | ✅ Complete |
| Guild cards | Games published to linked guilds as rich cards (roster state, seat availability), refreshed in place on RSVP changes | ✅ Complete |
| Interactions | "My seat" RSVP menu with localized ephemeral Join/Leave confirmations; joins run the same screened participant pipeline as the web (public games only) | ✅ Complete |
| Bulletins & digests | Game bulletins post to guild threads; daily calendar digest per guild | ✅ Complete |
| Identity resolution | Discord snowflake ↔ roundup account resolution with graceful unlinked on-ramps | ✅ Complete |

## 6. Billing & Membership

### 6.1 Paddle Integration

| Feature | Description | Status |
|---------|-------------|--------|
| Subscription Checkout | Paddle.js checkout for membership plans (monthly + annual) | ✅ Complete |
| One-Time Payments | Paddle checkout for event registration fees | ✅ Complete |
| Webhook Processing | Paddle webhook handler (CSRF-exempt) for payment events | ✅ Complete |
| Customer Portal | Paddle customer portal URL for billing management | ✅ Complete |
| Subscription Cancellation | Cancellation flow with automatic GM role revocation | ✅ Complete |
| Membership Plans | Configurable membership types (active/inactive/archived) with Paddle price ID integration | ✅ Complete |
| Local Plans | Support for non-Paddle plans (e.g., free GM subscription) | ✅ Complete |

### 6.2 Billing Pages

| Page | Description | Status |
|------|-------------|--------|
| Membership Page | Lists active plans with checkout initiation | ✅ Complete |
| Checkout | Paddle checkout initialization (subscription + one-time modes) | ✅ Complete |
| Billing Portal | Customer portal, subscription cancellation | ✅ Complete |
| Billing Support | Dedicated billing support ticket form | ✅ Complete |

---

## 7. Administration & Operations

### 7.1 Filament Admin Panel

The admin panel at `/admin` provides comprehensive back-office management.

**Resources:** one admin resource per managed entity — Users, Teams, Games, Campaigns, Events, Membership Types, Game Systems (with conditional BGG/TTRPG sections and taxonomy management), Categories and Mechanics (with similar-item cross-references), Cities and Locations (venues), Departments, Tickets, Badge Definitions, BGG Sync Logs — with relation managers for rosters/registrations and per-entity SEO overrides where relevant.

**Report pages:**
- Event Attendance Report — filterable table with CSV export
- Membership Report — filterable table with CSV export
- Signup Attribution Report — signup funnel attribution

**System pages:** scheduled-task visibility, queue structure, PHP runtime observability, Core Web Vitals, search performance (GSC digest, AI-surface telemetry), MCP usage, city-hub settings.

**Dashboard widgets:** system info (environment, deploy timestamp, database size, cache stats), queue health (pending/failed jobs per queue), user stats (totals, profile completion, verification trends).

**Custom Ticket View (Escalated Integration):**
- Game System Request handling: Sync from BGG, Search BGG, Create Manually
- Review Report moderation: Dismiss, Remove Review, Escalate
- Content Report moderation: Dismiss, Warn User, Remove Content, Suspend User, Escalate
- Data Export generation: Runs GDPR export, generates signed download URL, resolves ticket

### 7.2 Escalated Helpdesk Integration

| Feature | Description | Status |
|---------|-------------|--------|
| Ticket System | Full helpdesk with conversations, internal notes, satisfaction ratings | ✅ Complete |
| Departments | Game Systems, Safety, Billing, Account support | ✅ Complete |
| Ticket Types | game_system_request, review_report, content_report, attendance_dispute, data_export_request | ✅ Complete |
| Custom Moderation Actions | Per-ticket-type header actions (BGG sync, content moderation, account actions) | ✅ Complete |
| Automation Engine | Runs every minute for ticket routing and auto-actions | ✅ Complete |
| Auto-Escalation | Tickets auto-escalate based on configurable rules | ✅ Complete |
| SNOOZE/Wake | Tickets can be snoozed with automatic wake | ✅ Complete |
| Auto-Close | Resolved tickets auto-closed daily | ✅ Complete |
| Activity Purging | Old activity log entries purged daily | ✅ Complete |
| Email Settings | Configurable email templates and sending | ✅ Complete |
| SSO Settings | Single sign-on configuration | ✅ Complete |
| Plugin Management | Enable/disable helpdesk plugins | ✅ Complete |
| Role-Based Access | escalated-agent (Platform Admin + Service Admin) and escalated-admin (Platform Admin only) gates | ✅ Complete |

### 7.3 Admin User Management Commands

| Command | Purpose |
|---------|---------|
| `admin:user:create` | Create admin user with role assignment |
| `admin:user:promote` | Assign admin role to existing user |
| `admin:user:demote` | Remove admin roles |
| `admin:user:disable` | Disable user account (blocks login, kills sessions) |
| `admin:user:enable` | Re-enable disabled user |
| `admin:user:list` | List admin users with role assignments |

---

## 8. Scheduled Operations

Verified against `routes/console.php`; all one-server guarded where order matters.

| Frequency | Task | Purpose |
|-----------|------|---------|
| Every 1 min | Helpdesk automations | Ticket routing and auto-actions |
| Every 5 min | Session reminders | Push reminders for 24h/1h upcoming games |
| Every 5 min | Waitlist expiration sweep | Safety net for missed confirmation expirations |
| Every 5 min | Helpdesk escalation & SNOOZE wake | Auto-escalation evaluation; wake snoozed tickets |
| Every 5 min | Discovery cache sweep | Recompute discovery caches for active nearby users |
| Every 5 min | Observability scan | Error-pattern scanning for the ops digest |
| Every 10 min | Discovery cache sweep | (windowed sweep for active viewers — see above) |
| Every 15 min | Google Calendar reconcile | Drift-heal synced calendars: one bounded job per active connection (config cadence, default 15 min) |
| Every 30 min | Attendance sweeps | Resolve reports, auto-complete sessions, send nudges |
| Hourly | City hub recompute | Refresh per-city hub activity |
| Daily 02:00 | Auto-close resolved | Close resolved helpdesk tickets |
| Daily 03:00 | Platform scores | Recompute game system popularity scores |
| Daily 03:00 | Short link pruning | Expire/soft-delete old short links, hard-delete analytics |
| Daily 03:15 | Subscription expiry | Expire ended memberships |
| Daily 03:30 | Activity purging | Helpdesk activity log cleanup |
| Daily 04:30 | Export pruning | Delete expired GDPR export ZIPs |
| Daily 04:45 | Failed-job pruning | Trim the failed-jobs table |
| Daily 05:00 | Data audit | Integrity audit across participant rosters and counters |
| Daily 05:15 | Data repair | Auto-repair audit findings |
| Daily 06:00 | Discord digest | Publish the daily calendar digest per guild |
| Daily 06:30 | Badge sweep + observability digest | Award earned badges; email the ops digest |
| Daily 06:45 | Badge rarity | Recompute badge rarity distribution |
| Weekly Mon 03:00 | BGG weekly sync | Full game system data refresh |
| Weekly Mon 04:00 | Weekly digest | One summary mail per opted-in user |
| Weekly Mon 05:00 | Core Web Vitals check | CWV measurements refresh |
| Weekly Mon 06:00 | GSC digest | Search Performance trend digest |
| Weekly Sun 03:00 | Push subscription cleanup | Remove stale push subscriptions |
| Weekly Sun 04:00 | Invite email anonymization | Privacy: anonymize old invite emails on completed entities |
| Monthly | App visit pruning | Remove old app visit tracking data |

---

## 9. Privacy, Compliance & Data Protection

| Feature | Description | Status |
|---------|-------------|--------|
| GDPR User Data Export | Complete user data export (profile, OAuth, games, campaigns, events, reviews, teams, activity, push subs, social links, media) as ZIP with SHA-256 manifest. Signed download URL with 7-day expiry. | ✅ Complete |
| Account Anonymization | Full PII stripping on account deletion. Hard-deletes Tier 1 private data (OAuth, push subs, discovery views, preferences, visits, subscriptions, social links). Cancels orphaned entities. | ✅ Complete |
| PostHog Data Deletion | Dispatches `$delete` event to remove analytics data for anonymized users | ✅ Complete |
| Invite Email Anonymization | Weekly anonymization of invitee emails on completed/cancelled entities (>90 days) | ✅ Complete |
| Short Link IP Hashing | IP addresses hashed with SHA-256 (app key salt) in analytics | ✅ Complete |
| Invite Opt-out | Email-based opt-out flow for game/campaign invitations | ✅ Complete |
| Cookie Consent | Spatie cookie consent with analytics gating (respects DNT header) | ✅ Complete |
| Profile Privacy | Field-level visibility controls (6 fields, 3 levels each) | ✅ Complete |
| Notification Unsubscribe | One-click per-category unsubscribe via signed URLs | ✅ Complete |
| Geolocation Privacy | Geohash grid snapping for privacy defense against trilateration | ✅ Complete |
| Policy Update Notices | Global banner for privacy/terms policy updates with acceptance tracking | ✅ Complete |
| Algorithm Transparency | Public page explaining discovery and matching algorithms | ✅ Complete |

---

## 10. Internationalization (i18n)

| Feature | Description | Status |
|---------|-------------|--------|
| Supported Locales | English (en), German (de) | ✅ Complete |
| URL Strategy | `/{locale}/` prefix for all routes; root redirect to detected locale | ✅ Complete |
| Translation Architecture | Domain-based PHP translation files per locale (games, campaigns, events, teams, billing, profile, discovery, notifications, emails, pages, common, and more) — parity and dead-key hygiene machine-enforced | ✅ Complete |
| Entity Language | Fixed enum (de, en, de+en) on games, campaigns, events — drives content validation rules | ✅ Complete |
| Translatable Content | Event announcements support per-locale content via spatie/laravel-translatable | ✅ Complete |
| Content Fallback | Show requested locale, fall back to available with visual indicator | ✅ Complete |
| Informal German | Consistent 'du' formality across all German translations | ✅ Complete |
| i18n Tooling | 3 artisan commands: integrity check, dead string detection, missing key reporting | ✅ Complete |

---

## 11. Analytics & Observability

### 11.1 PostHog Integration

| Feature | Description | Status |
|---------|-------------|--------|
| Event Tracking | Activity events forwarded to PostHog via bridge pattern | ✅ Complete |
| User Identification | Server-side user identification once per session | ✅ Complete |
| Profile Enrichment | Async profile enrichment (game created, player joined, campaign created, session scheduled, etc.) | ✅ Complete |
| Team Group Analytics | PostHog group analytics for team-level insights | ✅ Complete |
| Feature Flags | Per-request cached feature flag evaluation with graceful fallback | ✅ Complete |
| Exception Reporting | 5xx exceptions reported to PostHog, rate-limited to 10/min per exception class | ✅ Complete |
| Consent Gating | All analytics respect cookie consent + DNT header | ✅ Complete |
| Data Deletion | User deletion triggers PostHog `$delete` event | ✅ Complete |

### 11.2 Activity Logging

| Feature | Description | Status |
|---------|-------------|--------|
| Activity Log Service | Central resilient logging (failures logged, never throw) | ✅ Complete |
| Model Observers | Automatic logging for Game, Campaign, GameParticipant, Review, UserRelationship events | ✅ Complete |
| Game Activity Feed | Social-circle-scoped activity feeds (created, joined, completed, session_recaps, session_scheduled) | ✅ Complete |
| Campaign Activity Feed | Campaign-specific activity tracking | ✅ Complete |
| PostHog Bridge | All activity events forwarded to PostHog for analytics | ✅ Complete |

### 11.3 Dashboard Intelligence

| Feature | Description | Status |
|---------|-------------|--------|
| Smart Prompts | Contextual dashboard suggestions (pending invitations → upcoming session → just completed → empty week → new follower → fallback) | ✅ Complete |
| Cached Sections | Week data (5min), feed (15min), trending (10min), opportunities (10min), contributions (60min) | ✅ Complete |
| Trending Nearby | Geohash-tile-based trending games (14-day window, sorted by participants) | ✅ Complete |

---

## 12. Shareable Links & Virality

| Feature | Description | Status |
|---------|-------------|--------|
| Short Links | Unique short codes for games and campaigns (7-36 char alphanumeric) | ✅ Complete |
| Share Tokens | Persistent share tokens for entity access bypass | ✅ Complete |
| Link Analytics | Click tracking with hashed IP, reduced UA, referer hostname. PostHog `link.hit` event. | ✅ Complete |
| Share Intent Processing | Cookie-based attribution — auto-join game/campaign after registration via share link | ✅ Complete |
| Link Lifecycle | Auto-expire for completed entities (grace period), soft-delete expired, hard-delete analytics >90 days | ✅ Complete |
| GM Link Management | Short link management in GM workspace with top referrer analytics | ✅ Complete |

---

## 13. PWA (Progressive Web App)

| Feature | Description | Status |
|---------|-------------|--------|
| Service Worker | Offline support with cache strategies | ✅ Complete |
| Web App Manifest | Installable as native app | ✅ Complete |
| Offline Queue | Queued actions sync when connectivity restored | ✅ Complete |
| Update Toast | New version notification | ✅ Complete |
| Install Prompt | Smart install prompt with eligibility gating | ✅ Complete |
| PWA Eligibility | Score-based: 2 of 3 (visit days, game participation, social investment) + baseline (profile + location) | ✅ Complete |
| Visit Tracking | Daily app visit tracking for engagement metrics and PWA eligibility | ✅ Complete |
| Push Notifications | Web Push API with VAPID authentication | ✅ Complete |

---

## 14. Architecture & Design System

### 14.1 Visual Identity

| Element | Description |
|---------|-------------|
| Design System | "The Tactile Hearth" — warm amber (#835500) primary, cream (#fbf9f1) surfaces, warm-tinted shadows |
| Typography | Inter (body, variable 100-900), Noto Serif (headings, variable 100-900 + italic) |
| Icons | Material Symbols Outlined (self-hosted subset) |
| Dark Mode | Full dark mode with warm dark variants (#1b1c17, #2a2b24), CSS custom properties |
| Color System | Material Design 3-inspired token system (primary/secondary/tertiary/surface/error/inverse) |
| Radius Scale | sm(0.25rem), md(0.75rem), lg(1rem), xl(1.5rem), 2xl(2rem) |

### 14.2 Technical Architecture

| Component | Technology |
|-----------|-----------|
| Backend Framework | Laravel (PHP) — version per `composer.json` |
| Full-Stack Components | Livewire |
| Frontend Styling | Tailwind CSS + CSS custom properties |
| Frontend JS | Alpine.js (bundled with Livewire) |
| Build Tool | Vite |
| Database | PostgreSQL (squashed schema baseline + stacked migrations) |
| Cache & Queue | Redis (predis) |
| Authentication | Laravel Breeze + Google/Discord OAuth |
| Authorization | Spatie Laravel Permission (scoped roles: global, team, event) |
| Media | Spatie Laravel Media Library |
| SEO | ralphjsmit/laravel-seo |
| Admin Panel | Filament |
| Helpdesk | Escalated (custom integration) |
| Payments | Laravel Cashier — Paddle |
| Analytics | PostHog (custom integration) |
| Geocoding | Nominatim (OpenStreetMap) with 1-hour cache |
| BGG Integration | Custom XML API client |
| Translatable Content | spatie/laravel-translatable |
| Web Push | Minishlink/WebPush (VAPID) |
| Email | Resend (delivery webhooks + suppression) |

### 14.3 Authorization Model

Three-tier scoped RBAC via `ScopedRoleService`:

- **Global Roles** — Platform Admin, Games Admin (full platform access)
- **Team-Scoped Roles** — Captain, Coach (team-level management)
- **Event-Scoped Roles** — Organizer, staff (event-level management)
- **Special Roles** — Game Master (GM profile + workspace access)
- **Guest Access** — Public pages, discovery, game/campaign listings, profiles (visibility-gated)

### 14.4 Middleware Pipeline

| # | Middleware | Purpose |
|---|-----------|---------|
| 1 | EnsureUserNotDisabled | Force-logout disabled users |
| 2 | EnsureLocaleDefaults | Set URL defaults for non-locale routes |
| 3 | CachePublicPages | CDN caching for anonymous GETs (60s/300s) |
| 4 | TrackAppVisit | Daily visit tracking for authenticated users |
| 5 | PostHogIdentifyUsers | Analytics identification (consent-gated) |
| 6 | ProcessShareIntent | Auto-join games/campaigns after registration |
| 7 | CookieConsent | Cookie consent banner state |

---

## 15. Utility & DevOps Commands

| Command | Purpose |
|---------|---------|
| `bgg:sync --ids=` | Sync specific game systems from BGG |
| `bgg:seed-top500` | Seed top 500 BGG games + expansions |
| `bgg:weekly-sync` | Weekly sync of all game systems |
| `platform-scores:compute` | Compute all platform popularity scores |
| `export:user-data {user}` | Generate GDPR user data export ZIP |
| `pwa:generate-vapid-keys` | Generate VAPID key pairs for web push |
| `pwa:send-session-reminders` | Send push reminders for upcoming games |
| `pwa:prune-stale-subscriptions` | Remove stale push subscriptions |
| `pwa:prune-visits` | Remove old app visit tracking data |
| `notifications:weekly-digest` | Send the weekly summary mail |
| `discord:publish-digest` | Publish the daily guild calendar digest |
| `attendance:resolve` / `attendance:auto-complete` / `attendance:nudge` | Attendance resolution, session auto-completion, nudges |
| `waitlist:sweep-expired-confirmations` | Handle expired waitlist confirmations |
| `badges:sweep` / `badges:rarity` / `badges:sync` | Award earned badges, recompute rarity, sync definitions |
| `subscriptions:expire` | Expire ended memberships |
| `cityhubs:recompute` | Refresh per-city hub activity |
| `discovery:sweep-active` | Recompute discovery caches for active users |
| `data:audit` / `data:repair` | Integrity audit and auto-repair |
| `observability:scan` / `observability:digest` | Error-pattern scanning and the ops digest |
| `cwv:check` | Core Web Vitals measurements |
| `seo:gsc-digest` / `seo:dedupe-game-systems` | Search Performance digest; dedupe game systems |
| `cloudflare:cache-rules` | Sync Cloudflare cache rules from routes |
| `short-links:prune` | Expire/soft-delete old short links + analytics |
| `anonymize:stale-invite-emails` | Privacy: anonymize old invite emails |
| `exports:prune` | Delete expired GDPR export ZIPs |
| `i18n:check` / `i18n:dead-strings` / `i18n:missing` | Translation integrity, dead keys, missing keys |
| `fonts:audit` | Audit icon usage vs config |
| `posthog:test-event` | Test PostHog connectivity (non-production only) |

One-off migration/backfill commands (`locations:backfill-slugs`, `users:backfill-slugs`,
`tickets:backfill-subjects`, `short-links:hash-ips`, `migrate:share-tokens`,
`location:add-geohash`, `location:migrate`, `invites:canonicalize-gmail`,
`startplaying:crawl`) completed their migrations and were removed — recover them
from git history if a fresh environment ever needs them. `demo:seed`, `demo:teardown`,
and `posthog:test-event` live in `App\Console\Dev` and are registered only outside
production (see AppServiceProvider::boot).

---

## 16. Development History & Maturity

The platform is developed through a documented, milestone-based process: every feature area ships as a planned milestone with recorded architectural decisions, verification evidence, and a human review loop — then is refined through scheduled depth-review passes. The full history is public in the repository (commit log plus the decision records in `docs/adr/`); this inventory describes the shipped result, not the chronology.

Quality posture at a glance:

- **Testing:** a full Pest feature suite plus PHPUnit unit tests, a vitest suite for the service worker, and a smoke group that runs before every commit. CI additionally enforces code style, static analysis (Larastan), Eloquent best practices, platform invariants, and translation parity.
- **Review culture:** cross-cutting surfaces (notifications, mail, discovery) carry corpus-level machine pins so new code cannot silently drift from the design language.

---

## Summary Assessment

**Platform maturity: Production-ready.** All feature domains in this inventory are fully implemented with no scaffolded or placeholder functionality.

The codebase demonstrates:

- **Depth over breadth:** Each domain has complete lifecycle management (create → manage → discover → share → archive) with proper authorization, notifications, and edge case handling.
- **Privacy-by-design:** GDPR compliance, field-level privacy controls, geolocation privacy, consent-gated analytics, data export and anonymization — all implemented, not planned.
- **Operational maturity:** A comprehensive scheduled-operations suite (section 8), Redis-backed queues with Horizon, model observers, per-category engagement counters, and a fully integrated helpdesk for user support.
- **Community-first architecture:** Social graph, taste-matching discovery, waitlist with urgency scaling, GM ecosystem, and review system — all designed for community building, not competition.
- **International readiness:** Full German localization with DACH-specific compliance (Impressum), translatable content, and locale-aware routing — machine-enforced for parity and dead-key hygiene.
- **Deliberate architecture:** Architectural decisions are recorded as they are made (`docs/adr/`), and a maintained domain glossary (`docs/GLOSSARY.md`, `CONTEXT.md`) keeps the ubiquitous language stable across code, copy, and both locales.
