# Badge & Achievement System — Concept

> Status: DRAFT concept for milestone planning · Date: 2026-10-17
> Parent: `docs/spec/gm-membership-value-proposal.md` (§3.3)
> Agreed direction: **full badge set in wave 1**; durable solution for adding, maintaining and
> granting badges down the line; money-badges stay honest ("Supporter", never "Great GM").

---

## 1. Design principles (from research, now policy)

1. **Hard-to-fake signals only** — payment state, outcome ratings by others, verified attendance,
   calendar time, completed work. Never raw activity volume, never self-report.
2. **Status plus function** — badges should increasingly confer or precede privileges (profile
   showcase slots, directory ranking signals, priority listing later).
3. **Rarity is legible** — every badge tooltip shows "% of eligible community has earned this".
4. **Lapse honestly** — requalifying badges grey out with "last earned" rather than vanish;
   one-time badges never expire and never become earnable again once closed.
5. **No purchasable prestige** — subscription badges certify *support*, not skill.
6. **Grace over punishment** — streaks allow grace skips; a lost badge must never cause rage-quit.

---

## 2. Architecture

### 2.1 Definition registry — code is the source of truth, DB is runtime state

- A `Badges` code registry (one class/enum entry per badge) declaring: `key`, category, tiers
  (with thresholds), display copy (translation keys), icon, `mode` (one-time | milestone |
  recurring | requalifying), `eligibility` scope, and its **evaluator class**.
- A seeder/command syncs definitions into a `badge_definitions` table (idempotent upsert keyed on
  `key`). Admins can **toggle active** and edit display metadata (icon/copy overrides) but cannot
  invent grant logic in the UI — logic lives in reviewed code. This is the "adding and maintaining
  down the line" story: PR adds a registry entry + evaluator + translations; seed syncs; done.

### 2.2 Awarding pipeline

```
domain event ──▶ BadgeListener ──▶ evaluator(s) ──▶ BadgeService::award()/upgrade()
                                                    ├── idempotent (no duplicate rows)
                                                    ├── notification (in-app + email prefs)
                                                    └── PostHog capture (badge_awarded)
scheduler (nightly) ──▶ sweep evaluators ──▶ requalify / lapse / revoke-sweep
```

- **`badge_user`** rows: `user_id, badge_key, tier, first_awarded_at, last_awarded_at,
  requalified_through (nullable), revoked_at (nullable), evidence_type + evidence_id (polymorphic:
  the subscription, guild link, attendance report, campaign…), source: automatic|manual`.
- **Evaluators** implement a small interface (`evaluate(User): ?award`, `sweep(): void`).
  Event-driven evaluators answer "did this event cross a threshold"; sweep evaluators handle
  streaks, tenure, and requalification windows.
- **Revocation** is rare and explicit: failed requalification (lapse, greyed), fraud/manual admin
  action. Milestones already earned are never re-taken away (e.g., Campaign Compleat is forever,
  Trusted Organizer is "currently active or last held in Q3").

### 2.3 Admin tooling (Filament — the "maintaining" surface)

- `BadgeDefinitionResource`: list (grouped by category, rarity shown), toggle active, edit display
  metadata, view holders, "run evaluator sweep now".
- `BadgeUserResource`: inspect any user's badges; **manual grant/revoke with required reason**
  (community recognition, staff, corrections); full audit trail table (`badge_events`:
  awarded/upgraded/lapsed/revoked/granted-manually + actor + reason).
- Scheduled monitor page entry for the nightly sweep + rarity job.
- **Retirement flow:** deactivating a definition stops new awards; existing rows stay displayed
  (flagged "retired badge" — Arctic Code Vault semantics preserved).

### 2.4 Rarity & display

- Nightly job: holders / eligible users per badge (eligibility scopes defined per badge, e.g.
  "GMs" for GM badges, "all registered users" for participation badges), cached, shown as
  rarity class (Common <60% / Uncommon 20–60% / Rare 5–20% / Elite 1–5% / Legendary <1%).
- Display surfaces (wave 1): public profile badge shelf with **featured** selection (users pick
  up to 3; showcase slots — status→function later), GM Directory cards (top badge), game listing
  author chip (subscriber + Compleat-class only, avoid noise), `/badges` gallery page with
  categories, filters, and rarity (aspirational loop + SEO).
- Icons: one consistent custom glyph set (tabletop vocabulary: d20, crown, quill, map pin, dice
  cup, anvil…) — design-system coordinated, not mixed emoji.

### 2.5 Comp memberships (agreed operations model)

- Filament action on `User`: **grant GM Membership comp** — set period (ends_at) or permanent;
  creates a flagged local subscription (`source: comp, granted_by, reason`). Comps carry full
  member benefits **and** the Supporting Member badge (a comp is a membership at price zero).
- Future paid promotions on top of the held €9.50 base price run through Paddle discounts;
  badges key off *membership state*, never price paid, so promo members are indistinguishable —
  correct.
- **Founding Supporter** = active GM membership (paid **or comped**) during year one — inclusive
  of the community contributors the comps exist for; scarcity comes from the time window, not
  the payment.

---

## 3. The full badge set (wave 1)

Categories: Support & Tenure · Community Infrastructure · GM Craft · Participation · Events &
Organizing · Milestones.

### Support & Tenure
| Badge | Mode | Trigger | Tiers | Signal source |
|---|---|---|---|---|
| **Founding Supporter** | one-time, closes | GM membership active (paid or comp) during platform year one | — | subscription activation |
| **Supporting Member** | recurring | active GM (or player) membership | Year 1 / 2 / 3 / 5 laps | subscription state (sweep) |
| **Still Rolling** | milestone | years since first hosted game | 1 / 3 / 5 years | first owned game date |
| **Founding Table** | one-time, closes | account created during launch year | — | registration date |

### Community Infrastructure (Discord)
| Badge | Mode | Trigger | Tiers | Signal source |
|---|---|---|---|---|
| **Guild Linked** | milestone | a guild you manage stayed connected ≥30 days | — | `discord_guild_organizers` + connection state |
| **Guild Steward** | milestone | months managing a linked guild that has hosted ≥2 platform campaigns/events through the integration | Silver 6mo / Gold 12mo + 3 events | guild link + linked entity counts (sweep) |

### GM Craft (verifiable outcomes)
| Badge | Mode | Trigger | Tiers | Signal source |
|---|---|---|---|---|
| **Campaign Compleat** | milestone | campaign completed with ≥6 sessions run and journal entries for ≥80% of sessions (the journal is what proves the story) | 1 / 3 / 5 completions | campaign status + journal coverage |
| **Chartered** | milestone | charter fully signed by the entire table (at any version) | 1 / 10 / 50 charters | charter confirmation records |
| **Mentor** | milestone | published 5/5 session review where the reviewed session was the reviewer's first with this GM, from ≥5 distinct accounts (DECIDED 2026-10-17, D190: derived from existing review data — no new review-question surface; "newcomer" = first session with this GM, platform tenure irrelevant) | Bronze 5 / Silver 15 / Gold 40 | published Game reviews + participation history |

### Participation (players & regulars)
| Badge | Mode | Trigger | Tiers | Signal source |
|---|---|---|---|---|
| **Regulars' Table** | recurring (streak) | consecutive months with ≥1 verified attendance; 1 grace skip per 6 months | 3 / 6 / 12 months | attendance reports (never self-check-in) |
| **Local Legend: [Venue]** | requalifying | most verified table-hours at one venue in trailing 90 days | live per venue | attendance + venue join |
| **Season Ticket** | recurring | ≥1 event attended per season (quarter) for 4 consecutive quarters | per year | event attendance |

### Events & Organizing
| Badge | Mode | Trigger | Tiers | Signal source |
|---|---|---|---|---|
| **Trusted Organizer** | requalifying (quarterly) | per quarter: ≥3 events run, ≥4.5 avg attendee rating, <10% unannounced cancellations | Bronze→Gold by consecutive qualifying quarters; greyed when lapsed | event outcomes + reviews |
| **Con Runner** | milestone | single event with 50+ registrations completed | 50 / 100 / 250 | event registration data |

### Milestones (activation, deliberately cheap)
| Badge | Mode | Trigger | Tiers | Signal source |
|---|---|---|---|---|
| **Debut** | one-time | first game hosted | — | first owned game |
| **First Seat** | one-time | first attendance verified | — | first attendance report |

**Anti-gaming notes per trigger:** attendance-based badges use organizer roll-calls/attendance
reports (existing `AttendanceReport` flow), never self check-in; Mentor requires *distinct*
accounts under the first-session-with-this-GM rule (D190) — deliberate trade-off: rotating a few
cooperative friends across a GM's sessions can farm it, accepted at current community scale and
mitigated by the attendance-gated deduplicated review flow plus admin manual revoke with reason
+ audit rather than by the signal itself; Compleat requires journal coverage so empty "completion" farming fails; Trusted
Organizer's cancellation metric uses unannounced (no-notice) cancellations only; Local Legend
hours are computed from reported attendance, decayed at 90 days.

---

## 4. Notifications & feed moments

- Award moment: in-app notification + email per preferences; celebratory but restrained copy;
  "share to feed" action posting to the community activity feed (opt-in).
- Lapse moment: quiet — email-free, badge simply greys with tooltip "Re-earn by…".
- Rarity recompute is nightly; gallery page shows "awarded N times, X% of eligible".

## 5. Free / member placement — earnability rule (decided)

**Rule:** any badge whose criteria can be met without paying **must** be earnable by free users;
badges that certify non-free things are the only ones closed to free users — and there are exactly
two: **Supporting Member** and **Founding Supporter** (they certify the membership itself, paid or
comped). Everything else in the catalogue is free-earnable. Badges are community recognition, not
a member perk — gating them would poison the honesty of the whole system. Member-adjacent surface
is **showcase visibility** (bigger shelf, featured placement, chronicle-ready profile blocks later)
— never eligibility.

## 6. Rollout order within wave 1

1. Engine + registry + `badge_user` + notifications + Filament admin (definitions, manual grants,
   audit) — ship dark.
2. Support & Tenure + Infrastructure (subscriber, founding, Discord, tenure, milestones) — the
   set that must exist on pricing day; enable gallery + profile shelf.
3. GM Craft + Participation + Events (Compleat, Chartered, streaks, Local Legend, Trusted
   Organizer, Con Runner, Season Ticket) — as charter/journal/attendance signals land. Mentor
   ships with the first cohort in step 2: derived from existing review data per D190.
