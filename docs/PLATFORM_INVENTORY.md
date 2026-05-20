# Roundup Games — Complete Platform Inventory

> **Purpose:** Comprehensive functional inventory for non-profit incorporation, funding applications, and collaborator onboarding.
> **Generated:** 2026-05-20 | **Source:** Full codebase analysis (403 PHP files, 183 Blade views, 170 migrations, 46 completed
development milestones)

---

## Executive Summary

**Roundup Games** is a community discovery platform for tabletop gaming — board games, tabletop RPGs, card games, and related social play. It connects players with games, campaigns, events, and each other through proximity-based discovery, taste-matching algorithms, and a rich social graph. The platform is built as a production-grade Laravel application with Livewire full-stack components, serving both public-facing discovery pages and authenticated user workflows.

**Platform identity:** "There's a seat waiting for you." — emphasis on belonging, imaginative play, curiosity, and safety. Not competition-focused; sessions and campaigns are primary content, events are infrastructure.

**Tech stack:** Laravel 13 · PHP 8.x · PostgreSQL · Redis · Livewire 4 · Tailwind CSS · Alpine.js · Vite · Paddle (payments) · PostHog (analytics) · Filament (admin) · Escalated (helpdesk)

---

## Platform Scale

| Metric | Count |
|--------|-------|
| Total PHP source files | 403 |
| Lines of application PHP code | 57,787 |
| Lines of frontend code (Blade + JS + CSS) | 24,070 |
| Livewire components | 66 |
| Eloquent models | 42 |
| Enum types | 22 |
| Service classes | 43 |
| Notification types | 37 |
| Console commands | 29 |
| Filament admin resources/pages | 63 |
| Blade templates | 183 |
| Database migrations | 170 |
| Authorization policies | 14 |
| Middleware classes | 9 |
| Queued job types | 9 |
| Development milestones completed | 46 |
| Architectural decisions recorded | 63 |
| Supported locales | 2 (English, German) |
| Third-party Composer packages | 212 |

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
| GM Directory | `/gms` | Game Master directory filterable by specialization, game system, rating; sortable by rated/reviewed/sessions/newest | ✅ Complete |
| Public Profiles | `/u/{slug}` | Public user profiles with visibility-scoped content | ✅ Complete |

### 1.3 SEO & Performance Infrastructure

| Feature | Implementation | Status |
|---------|---------------|--------|
| XML Sitemap | 7 sitemap types (static, game-systems, events, games, campaigns, teams, profiles) | ✅ Complete |
| SEO Metadata | Per-entity overrides (title, description, image, canonical URL, robots directives) on 7 content types | ✅ Complete |
| CDN Caching | Public pages cached 60s client / 300s CDN, with private-page exclusion list | ✅ Complete |
| Cloudflare Integration | Cache rule sync command for route-based cache policies | ✅ Complete |
| Structured Data | SEO foundation with schema.org markup | ✅ Complete |

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
| `/notifications/unsubscribe/{user}/{category}` | One-click notification unsubscribe (signed URL) |
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
| Social Links | Configurable social platform URLs (Discord, Mastodon, etc.) with validation | ✅ Complete |
| Public Profile | SEO-optimized public profile with visibility-scoped content, follow/block actions | ✅ Complete |
| Authenticated Profile | Full profile viewer with games, campaigns, GM reviews, reliability badge, team memberships | ✅ Complete |
| Reliability Score | Computed from attendance history: attended (+1), late_cancel (−0.3), no_show (−1). Tiers: New / Active / Reliable (>95%) | ✅ Complete |
| Profile Slug | User-chosen URL slug for public profiles | ✅ Complete |

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
| GM Workspace | Authenticated workspace: upcoming sessions, recent reviews, player stats, active campaigns, session zero surveys, link analytics | ✅ Complete |
| GM Profile | Role-gated profile with bio, specializations, social links, stats | ✅ Complete |
| Session Zero Surveys | Safety tool selection, lines & veils, tone/genre, house rules, content warnings, player expectations. Shareable UUID link. | ✅ Complete |
| GM Role Management | Automatic GM role activation/revocation tied to subscription lifecycle | ✅ Complete |

---

## 5. Notifications & Communication

### 5.1 Notification System

| Feature | Description | Status |
|---------|-------------|--------|
| Notification Bell | Sidebar bell with unread count badge, dropdown with recent grouped notifications. Polls every 30 seconds. | ✅ Complete |
| Notification History | Full paginated history grouped by type + day with expandable groups | ✅ Complete |
| Per-Category Preferences | Users choose delivery channels per category (database, email, push) | ✅ Complete |
| One-Click Unsubscribe | Signed URL for instant email unsubscribe per category | ✅ Complete |
| 34 Notification Types | See full list below | ✅ Complete |

### 5.2 Notification Types (34)

**Game/Campaign Lifecycle:**
1. ApplicationApproved — Application approved
2. ApplicationRejected — Application rejected
3. ParticipantJoined — Someone joined your game/campaign
4. ParticipantRemoved — Someone removed from game/campaign
5. PlayerBenched — Player placed on bench
6. BelowMinPlayersWarning — Game drops below minimum players
7. GameCancelled / CampaignCancelled — Entity cancelled
8. GameCompleted / CampaignCompleted — Entity completed
9. GameUpdated / CampaignUpdated — Entity details changed
10. GameInvitation / CampaignInvitation — Invited to entity
11. SessionAddedToCampaign — New session in campaign
12. SessionReminder — Push-only 1h/24h reminders (from artisan command)
13. RecapPosted — Host writes game recap
14. DebriefingAvailable — Debriefing prompts available

**Waitlist:**
15. WaitlistPromoted — Promoted from waitlist with confirmation deadline
16. ConfirmationExpired — Confirmation window expired
17. WaitlistExpiredRejected — Permanently rejected after too many expirations

**Attendance:**
18. AttendanceReported — Someone reported your attendance
19. DisputeResolved — Attendance dispute resolved

**Social:**
20. NewFollower — Someone follows you
21. NewApplication — Someone applies to your game/campaign

**Reviews:**
22. ReviewReported — A review was reported

**Admin/Moderation:**
23. AccountSuspended — Account suspended via content report
24. ContentRemoved — Content removed via report
25. ContentReportWarning — Admin warning via report

**Game Systems:**
26. GameSystemRequestApproved — Request approved
27. GameSystemRequestDuplicate — Request closed as duplicate
28. GameSystemRequestRejected — Request rejected

**Teams:**
29. TeamInvitation — Invited to a team
30. TeamMemberRemoved — Removed from a team

### 5.3 Push Notifications (PWA)

| Feature | Description | Status |
|---------|-------------|--------|
| Web Push via VAPID | Standard Web Push API with VAPID key authentication | ✅ Complete |
| Subscribe/Unsubscribe | API endpoints for push subscription management | ✅ Complete |
| Session Reminders | Automated 24h and 1h push reminders for upcoming games | ✅ Complete |
| Stale Subscription Cleanup | Weekly cleanup of stale push subscriptions (>180 days) | ✅ Complete |

---

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

**Resources (10):**

| Resource | Model | Key Capabilities |
|----------|-------|-----------------|
| Users | User | Full profile editing, role assignment, SEO overrides, disable/enable with audit log, linked accounts viewer |
| Teams | Team | Team management with roster via relation manager, SEO overrides |
| Games | Game | Game session management with participants relation manager, SEO overrides |
| Campaigns | Campaign | Campaign management with participants relation manager, SEO overrides |
| Events | Event | Full event management with registrations + announcements relation managers, SEO overrides |
| Membership Types | MembershipType | Plan management (active/inactive/archived, Paddle/local) |
| Game Systems | GameSystem | Rich game system editing with conditional BGG/TTRPG sections, taxonomy management, SEO overrides |
| Categories | GameSystemCategory | Category management with similar-category cross-references |
| Mechanics | GameSystemMechanic | Mechanic management with similar-mechanic cross-references |
| BGG Sync Logs | BggSyncLog | Read-only sync history (status, timing, error tracking) |

**Report Pages (2):**
- Event Attendance Report — filterable table with CSV export (14 columns)
- Membership Report — filterable table with CSV export (11 columns)

**Dashboard Widgets (3):**
- System Info — Environment, deploy timestamp, database size, cache stats, versions
- Queue Health — Pending jobs (per-queue breakdown), failed jobs
- User Stats — Total users, profile completion rate, email verification rate, recent verification trends

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

| Frequency | Task | Purpose |
|-----------|------|---------|
| Every 1 min | Helpdesk Automation | Ticket routing and auto-actions |
| Every 5 min | Session Reminders | Push reminders for 24h/1h upcoming games |
| Every 5 min | Waitlist Expiration Sweep | Safety net for missed confirmation expirations |
| Every 5 min | Helpdesk Escalation | Auto-escalation evaluation |
| Every 5 min | Helpdesk SNOOZE Wake | Wake snoozed tickets |
| Every 10 min | Discovery Cache Sweep | Recompute discovery caches for active nearby users |
| Every 30 min | Auto-Attend Sweep | Auto-attend participants after 48h |
| Daily 02:00 | Auto-Close Resolved | Close resolved helpdesk tickets |
| Daily 03:00 | BGG Weekly Sync | Sync all game systems from BGG (also runs weekly Mon) |
| Daily 03:00 | Platform Scores | Recompute all game system popularity scores |
| Daily 03:00 | Short Link Pruning | Expire/soft-delete old short links, hard-delete analytics >90 days |
| Daily 03:30 | Activity Purging | Helpdesk activity log cleanup |
| Daily 04:30 | Export Pruning | Delete expired GDPR export ZIPs |
| Weekly Sun 03:00 | Push Subscription Cleanup | Remove stale push subscriptions |
| Weekly Sun 04:00 | Invite Email Anonymization | Privacy: anonymize old invite emails on completed entities |
| Monthly | App Visit Pruning | Remove old app visit tracking data |
| Weekly Mon 03:00 | BGG Weekly Sync | Full game system data refresh |

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
| Translation Architecture | 13 domain-based PHP translation files per locale (auth, events, teams, games, campaigns, billing, profile, discovery, safety, location, emails, pages, common) | ✅ Complete |
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
| Backend Framework | Laravel 13 (PHP 8.x) |
| Full-Stack Components | Livewire 4 (66 components) |
| Frontend Styling | Tailwind CSS + CSS custom properties |
| Frontend JS | Alpine.js (bundled with Livewire) |
| Build Tool | Vite 8 |
| Database | PostgreSQL |
| Cache & Queue | Redis (predis) |
| Authentication | Laravel Breeze + Google OAuth |
| Authorization | Spatie Laravel Permission (scoped roles: global, team, event) |
| Media | Spatie Laravel Media Library |
| SEO | ralphjsmit/laravel-seo |
| Admin Panel | Filament 4 |
| Helpdesk | Escalated (custom integration) |
| Payments | Laravel Paddle (Paddle SDK) |
| Analytics | PostHog (custom integration) |
| Geocoding | Nominatim (OpenStreetMap) with 1-hour cache |
| BGG Integration | Custom XML API client |
| Translatable Content | spatie/laravel-translatable |
| Web Push | Minishlink/WebPush |

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
| `short-links:hash-ips` | Hash raw IPs in analytics for PII compliance |
| `short-links:prune` | Expire/soft-delete old short links + analytics |
| `location:add-geohash` | Backfill geohash columns for locations |
| `location:migrate` | Migrate location JSON to normalized table |
| `migrate:share-tokens` | Migrate share tokens to ShortLink records |
| `users:backfill-slugs` | Generate slugs for users missing them |
| `anonymize:stale-invite-emails` | Privacy: anonymize old invite emails |
| `exports:prune` | Delete expired GDPR export ZIPs |
| `pwa:prune-visits` | Remove old app visit tracking data |
| `pwa:prune-stale-subscriptions` | Remove stale push subscriptions |
| `pwa:send-session-reminders` | Send push reminders for upcoming games |
| `discovery:sweep-active` | Recompute discovery caches for active users |
| `attendance:sweep-auto-attend` | Auto-attend after 48 hours |
| `waitlist:sweep-expired-confirmations` | Handle expired waitlist confirmations |
| `cloudflare:cache-rules` | Sync Cloudflare cache rules from routes |
| `i18n:check` | Verify translation file integrity |
| `i18n:dead-strings` | Find unused translation keys |
| `i18n:missing` | Report missing translation keys |
| `fonts:audit` | Audit icon usage vs config |
| `posthog:test-event` | Test PostHog connectivity |
| `startplaying:crawl` | Crawl StartPlaying.games for TTRPG metadata |

---

## 16. Development History & Maturity

The platform has been developed through **46 completed milestones**, demonstrating systematic growth:

| Phase | Milestones | Focus |
|-------|-----------|-------|
| Foundation | M001–M002 | Laravel migration, production hardening |
| Design | M003 | Full visual rebrand ("The Tactile Hearth" design system) |
| Internationalization | M004, M011, M046 | DACH launch prep, i18n restructuring, translatable content |
| Data & Content | M005, M015, M020 | BGG data collection, game system knowledge base, StartPlaying integration |
| Core Features | M006, M007, M008 | Campaign-session inheritance, game listings, player profile enrichment |
| Community | M009, M010, M023 | Landing pages, discovery rebuild, board games vs adventures fork |
| Social | M012, M013, M014 | Follow system, visibility, auth-gated UX |
| GM Ecosystem | M021 | GM profiles, workspace, and reviews |
| Engagement | M016, M017, M022, M036 | Nearby people discovery, notifications, dashboard revamp |
| Infrastructure | M018, M027, M041 | Async discovery pipeline, PWA + push, PostHog analytics |
| Quality | M019, M030–M034 | Test suite remediation (5 milestones dedicated to testing) |
| UX Polish | M024, M025, M029 | Language overhaul, profile redesign, type-adaptive forms |
| Operations | M026, M035, M037–M039, M044 | Game system request queue, shareable links, email invites, waitlist hardening, short links |
| Trust & Compliance | M040, M042, M043, M045 | SEO, helpdesk, trust branding, GDPR compliance |
| Enhancements | M028, M037 | Reliability scores, engagement signals |

---

## Summary Assessment

**Platform maturity: Production-ready.** All 16 feature domains are fully implemented with no scaffolded or placeholder functionality.

The codebase demonstrates:

- **Depth over breadth:** Each domain has complete lifecycle management (create → manage → discover → share → archive) with proper authorization, notifications, and edge case handling.
- **Privacy-by-design:** GDPR compliance, field-level privacy controls, geolocation privacy, consent-gated analytics, data export and anonymization — all implemented, not planned.
- **Operational maturity:** 17 scheduled tasks, 9 queued jobs, 6 model observers, comprehensive admin tooling, and a fully integrated helpdesk for user support.
- **Community-first architecture:** Social graph, taste-matching discovery, waitlist with urgency scaling, GM ecosystem, and review system — all designed for community building, not competition.
- **International readiness:** Full German localization with DACH-specific compliance (Impressum), translatable content, and locale-aware routing.
- **63 documented architectural decisions** showing deliberate, recorded choices rather than ad-hoc development.