# GM Membership Value Proposition — Proposal & Research Synthesis

> Status: DECISIONS RECORDED 2026-10-17 — see below · DRAFT for discussion · Date: 2026-10-17
> Scope: Session Zero charters, session recap / campaign journal revamp, badge & achievement system,
> GM subscription pricing at €9.50/month.
> Inputs: full codebase audit (2026-10-17) + four research investigations (competitive GM monetization,
> badge/achievement design systems, session zero & safety-tool culture, campaign journal tooling).

**Decisions recorded 2026-10-17:**

- Safety content stays free forever (core value, non-negotiable).
- Campaign Charter is the flagship concept → detailed in `campaign-charter-concept.md`.
- "Previously On" approved; composes into the relevant communication channels → `campaign-journal-concept.md`.
- Badge system: **full set in wave 1**; durable add/maintain/grant architecture required → `badges-achievement-concept.md`.
- Scarcity / Arctic-Code-Vault effect agreed (Founding Supporter, Founding Table).
- Pricing held at €9.50/month; promotions may run on top later. Baseline established early.
- Monetization philosophy: core loop (participating + organizing free sessions) always free;
  engagement features not gated by default; serious supporters rewarded with professional tooling
  and workflows; manual comp/discounted memberships (fixed period or permanent) via admin tooling.
- Chronicle content stays tied to the campaign; assisted spoiler-safe **recruitment summary** into
  the public campaign description approved (with embedded UI/UX guidance).
- **Previously On = member feature** (decided; can be un-gated later if strategy shifts).
- **Charter free tier = 2 active charters; unlimited for paying GMs** (decided).
- **Badge earnability rule:** badges whose criteria are meetable without paying must be earnable by
  free users; only membership-certifying badges (Supporting Member, Founding Supporter) require a
  membership (paid or comped).
- **Offline-first principle:** the platform centres physical, face-to-face play. Location and
  logistics tooling never encourages online sessions. Communication is the single exception —
  tables choose their own comms medium (Discord, WhatsApp, Telegram, …), expected and encouraged.
- **AI & digital tools consent:** charters include an AI & Digital Tools at the Table section —
  AI-generated content in prep, AI note-taking/transcription (all-party consent), app dice,
  sheets, photography, and recording. Principle: disclosed & consented AI is welcome; covert
  AI content or tooling is a boundary violation.

---

## 1. Where we actually stand (code audit)

| Pillar | Readiness | Current state |
|---|---|---|
| Session Zero | **~60%** | `SessionZeroSurvey` with safety tools (enum-backed), lines & veils, tone/genre, house rules, content warnings, player expectations. Participant confirmations (`SessionZeroConfirmation`, idempotent, timestamped). **Create-only**: no edit, no versioning, no re-agreement, no scheduling/logistics/PvP/death-policy fields. |
| Session recaps | **~20%** | Single write-once `recap` column on `Game` (max 2000 chars), owner-only, after completion. No campaign rollup, no accumulation, no journal. |
| Bulletin board | **~85%** | `GameBulletin` per game with expiry, auto-expire on game completion, Discord publishing (`PublishGameBulletinToDiscord`), notifications. Solid. |
| Badges / achievements | **~5%** | Nothing exists. But event-driven foundations are strong: `ActivityLogService`, observers, 37 notification types, PostHog bridge. Display surfaces exist: GM Directory, public profiles. |
| Discord | **~90%** | Guilds, guild organizers, RSVP cards, digests, bulletin publishing, bot install service. Badge triggers ("connected a guild", "manages a connection") are derivable almost for free. |
| Billing / pricing | **Infra ~80%, value split 0%** | Paddle + local plans + `MembershipType` + `LocalSubscription` all working. **GM plan is currently FREE** (`type=local`, `price_cents=0`). No GM feature is gated on payment anywhere — `isGmActive` is role-based, granted by the free plan. Moving to €9.50 is the platform's **first real paywall decision**, not a price update. |

Key structural facts that shape the design:

- `campaign_id` on `Game` cleanly discriminates **one-shot vs campaign session** — the bulletin/journal UX split maps onto existing data with zero schema changes.
- `GameType` enum is `board_game | ttrpg | gathering` — journal features target `ttrpg` campaigns only.
- Financial model (docs/funding) already assumed GM membership at €7.99→€14.99; €9.50 lands inside the modelled range, and right at the Roll20 Pro anchor (~$9.99/mo) for GM tooling.

---

## 2. Research summary — what the outside world does

### 2.1 GM monetization (Roll20, Foundry, StartPlaying, Demiplane, Alchemy, Warhorn, …)

Four models exist: per-user subscriptions (Roll20 ~€4.6/€9.2 tiers), one-time GM licenses (Foundry €46.28 — verified live; Fantasy Grounds), marketplace take-rates on paid seats (StartPlaying, players pay GMs €10–30/seat), free/donation (Owlbear). Five ideas worth borrowing for a discovery platform at €9.50:

1. **Status badges everywhere** (Roll20's Plus/Pro nameplate): paying GMs get a persistent badge on every listing, profile, and message. Cheap to build, high conversion pressure.
2. **Trust portfolio** (StartPlaying): ratings + sessions-run + verification accumulate free; membership unlocks *showcasing* them.
3. **GM workflow moat** (Foundry prep journals, SPG screening): session-zero charters + campaign journal save real hours — utility justifies the price on its own.
4. **"Pays for itself" commission waiver** (inverted StartPlaying take-rate): if paid games are ever enabled, members pay 0% platform fee. ~2 paid sessions/month makes €9.50 net-positive. Future hook, strong story.
5. **Recaps as public discovery content** (nobody does this well): member campaigns publish "campaign chronicles" — SEO landing pages + social proof; the GM pays, everyone at the table (and future players) benefits.

*Uncertainty flag: only Foundry's price was live-verifiable; re-check Roll20 EU pricing before publishing marketing copy that references it.*

### 2.2 Badge / achievement design (Discord, Steam, Xbox/PS, Stack Overflow, Duolingo, Strava, GitHub, Airbnb, Kickstarter)

What separates *earned-feeling* from *cheesy*: badges certify **verifiable, hard-to-fake signals** (payment, rated outcomes, calendar time, completed work), display on a **profile surface people actually visit**, and ideally **unlock real privileges**. Best mechanics to steal:

- **Discord Nitro**: pay badge = status *and* function; boosting framed as gifting community, not buying status.
- **PS trophy rarity %**: showing "% of community earned this" on every badge makes modest badges legible and rare badges prestigious.
- **Stack Overflow**: reputation *is* privileges; anti-gaming via daily caps and vote reversal.
- **Strava Local Legend**: rewards frequency + local tenure (most efforts on a segment in 90 days) — perfect for venue loyalty.
- **GitHub Arctic Code Vault**: permanently unearnable one-time badges are the *most* prestigious; scarcity is the point.
- **Airbnb Superhost**: quarterly **requalification on outcomes rated by others** — hardest to game; pairs milestone badges with a *currently active* label.
- **Steam years-of-service / showcase slots**: anniversary badges + status→function ladder (badges unlock profile showcase space).

Anti-patterns to design against: leaderboard leagues (reward volume → junk events), purchasable pseudo-prestige (money badges must be honest: "Supporting Member", not "Great GM"), no-upkeep labels that decay ("ran one event in 2019"), self-reported attendance (needs organizer roll-call / outcome signals), streak rage-quit (always 1–2 grace skips).

### 2.3 Session zero culture (Consent in Gaming, Lines & Veils, X-Card/Script Change/Luxton/Open Door, Same Page Tool)

Community consensus converges on a **signed social contract ("campaign charter")** covering: tone/themes, content boundaries, house rules, **and logistics** (cadence, attendance, absence policy, missing-player policy, comms channel, platform, camera). Best-practice revision flow:

1. GM edits create a new **immutable version**; prior signatures preserved.
2. Minor edits → notify only. **Material edits** (safety tools, boundaries, PvP, death policy, tone) → participants reset to *pending re-agreement*; non-responders flagged to GM after a window — **silence is never consent**.
3. Boundary changes: tightening takes effect immediately; loosening requires the participant's own action, never the GM's.
4. Full audit trail: who agreed to which version, when.

StartPlaying/Demiplane/D&D Beyond only do one-way "GM states expectations" — **nobody has a signed, versioned charter. Genuine white space.**

### 2.4 Campaign journals (Obsidian Portal, Kanka, LegendKeeper, DDB Game Log, PBP culture)

Mature tools = **chronological dated entries over an entity graph**, with per-entry GM-secret visibility. The unsolved problem industry-wide: getting recaps *written* and *read*. What works:

- Kanka: free-form journal entries with an **author↔character link** (player POV journals, not one GM scribe); quests as status-tracked goals; **Discord webhooks** as the read-loop.
- LegendKeeper: inline **secret blocks** inside public pages.
- PBP forums: the log *is* the game — enforced reading.
- Epic Words is **dead** (domain squatted) — standalone journal tools die; journals must be embedded in the discovery/retention loop. That's exactly our position.

---

## 3. Proposals

### 3.1 Session Zero → "Campaign Charter" v2

**Brand it a charter, not a survey.** "I signed the charter" is community-legible language.

**Add missing sections** (all system-agnostic):
- Scheduling & logistics: cadence, session length, attendance expectation, absence notice, missing-player policy (skip / NPC / one-shot), comms channel, platform + camera policy.
- Table policies: PvP (none/limited/open), character death (possible/fudging/consent-only), advancement model (milestone/traditional/narrative), loot policy.
- Character creation constraints (free text, optional).

**Agreement split** — informational sections get a single "I have read the charter" ack; commitment sections get explicit per-item agreement: *"I consent to the safety tools and will honour boundaries"*, *"I commit to the scheduling and etiquette expectations"*. Timestamped signature row per participant (already have `confirmed_at`).

**Versioning (the differentiator):**
- `SessionZeroSurveyVersion` (immutable snapshots) + confirmations pinned to a version.
- Material vs minor edit detection (safety/PvP/death/tone fields = material).
- Material edit → pending re-agreement state + notification; GM sees a re-agreement tracker; no auto-agreement, ever.
- GM never edits a participant's private boundary answers; loosening requires the participant's own edit.

**Pricing placement: safety content stays free forever.** Free GMs get the charter's full function — all sections, signatures, amendments, re-agreement, versioning, diff views, reminders (never paywall the safety loop) — with **2 active charters** concurrently (decided; see R084). Membership gates *scale and convenience*: saved templates ("bring your standard charter"), start-from-previous-charter, unlimited concurrent charters, and audit export.

### 3.2 Recaps → split by context, journal for campaigns

**One-shots (`campaign_id` null, `ttrpg` or `board_game`):** kill the recap form entirely. Session-end flow = attendance + debrief (exists) + bulletin board (exists) + one auto-created sticky **"Epilogue" bulletin thread** for anyone who wants a coda. Zero ceremony. The existing auto-expire-on-completion behaviour stays, with epilogues granted a longer default window.

**Campaigns:** replace the single recap column with a **campaign journal** — minimal but complete:

```
campaign_journal_entries
  campaign_id, game_id (session link), author_participant_id,
  kind: recap | in_character | prep_note,
  body (rich text), visibility: players | gms,
  in_game_date (optional free text), created_at

campaign_goals
  campaign_id, title, status: planned | active | achieved | failed,
  game_id (the session where status changed), sort

character_state_changes
  campaign_id, participant_id, game_id,
  change_type: progression | item | condition | note,
  delta (free text — "Ti reached level 3", "lost the amulet")
```

- Free-form + system-agnostic by design: works as D&D quest log, PbtA front/threat tracker, CoC mystery thread. No character sheets, no numbers — Kanka proves free-form + author-link carries the value.
- **Player-sourced entries**: any participant may write `in_character` journal entries (visible to table); GM keeps `gms`-visible prep notes inline — LegendKeeper's secret-block pattern.
- Migration: existing `game.recap` values become `kind=recap` entries; keep writing to the journal, drop the column write path.

**"Previously On" mechanic (the read-loop nobody else has):** on session publish (and/or 24h reminder — the scheduler exists), auto-compose from the last journal entry + goal status changes + open character states since the previous session → post to the game's bulletin board **and the campaign's Discord channel** (digest publisher exists), plus a one-click "write this session's recap" link for the GM. Recaps get read because they arrive where players already are, right when they're needed.

**Future (v2): public "Campaign Chronicles"** — member campaigns can publish a read-only chronicle view (journal + goal timeline, GM-curated): SEO landing pages, social proof, the acquisition flywheel from research finding #5. Requires visibility curation and moderation thought; not launch scope.

**Pricing placement (decided — see D185 and campaign-journal-concept.md §2):** the journal itself — entries, goals, state changes, full history — is **free for the whole table** (shared engagement content; players read and write it). Member features are GM workflow automation: **Previously On composition and channel delivery**, journal analytics, and the later polished chronicle export. Conversion moment: a GM who has journaled for months and wants the recap loop automated.

### 3.3 Badge & achievement system

**Architecture** (event-driven, fits existing observers/ActivityLog):
- `BadgeDefinition` — code-defined registry (enum-like) with: key, name, description, category, icon, tiers, rarity class, one-time vs recurring/requalifying.
- `UserBadge` — user_id, badge_key, tier, awarded_at, evidence (polymorphic ref), plus `revoked_at` for requalification failures.
- Awarding via domain events already flowing (subscription activated, guild linked, session completed, attendance reported, review posted). Idempotent award + revocation job for requalifying badges (Airbnb model).
- **Rarity percentage** computed nightly (count holders / eligible users) and shown on every badge tooltip (PS trophy pattern).
- Display surfaces: badge row on public profile (choose up to N featured), GM Directory cards, game listing author chip, Discord card (later), and a `/badges` gallery page (SEO + aspiration loop).

**Launch badge set** (names are proposals; all system- and role-agnostic):

*Support & tenure (Discord/Steam pattern):*
| Badge | Trigger | Tiers |
|---|---|---|
| **Founding Supporter** | GM membership purchased in year one | one-time, permanent, never re-earnable |
| **Supporting Member** | active paid membership (any tier) | laps: Year 1 / 2 / 3… |
| **Still Rolling** | years since first hosted game | 1 / 3 / 5 |

*Discord (near-free to build given existing infra):*
| Badge | Trigger | Tiers |
|---|---|---|
| **Guild Linked** | a guild you manage is connected ≥30 days | single |
| **Guild Steward** | months managing a linked guild that has hosted ≥2 campaigns/events through the platform | Silver 6mo / Gold 12mo + 3 |

*GM craft (GitHub/SO pattern — verifiable outcomes):*
| Badge | Trigger | Tiers |
|---|---|---|
| **Campaign Compleat** | brought a campaign to actual completion (status=completed, ≥N sessions, ≥M journal entries) | 1 / 3 / 5 completions |
| **Mentor** | published 5/5 session review on a reviewer's first session with this GM, ≥5 distinct accounts (derived from existing review data — D190) | Bronze 5 / Silver 15 / Gold 40 |
| **Chartered** | collected signed agreements from a full table (all participants) | 1 / 10 / 50 charters fully signed |

*Players & regulars (Strava/Duolingo-sans-toxicity pattern):*
| Badge | Trigger | Tiers |
|---|---|---|
| **Regulars' Table** | consecutive months with ≥1 attended session (1 grace skip per 6 months) | 3 / 6 / 12 months |
| **Local Legend: [Venue]** | most verified table-hours at one venue in 90 days | live label, must be actively defended |
| **Season Ticket** | ≥1 event attended per season for a year | yearly |

*Organizers (Airbnb requalification pattern):*
| Badge | Trigger | Tiers |
|---|---|---|
| **Trusted Organizer** | quarterly requalification: ≥3 events run, ≥4.5 avg attendee rating, <10% unannounced cancellations | Bronze→Gold by consecutive qualifying quarters; greyed when lapsed |
| **Con Runner** | single event with 50+ registered attendees | 50 / 100 / 250 |

*One-time scarcity:*
| Badge | Trigger |
|---|---|
| **Founding Table** | joined the platform in launch year — permanent, unearnable after |

**Guardrails:** no purchasable prestige (money badges say "Supporter", never "Great GM"); attendance signals come from organizer roll-calls / attendance reports, never self-check-in; streaks always have grace skips; requalifying badges grey out rather than vanish (history stays honest).

### 3.4 Pricing — GM Membership €9.50/month

**Plan structure:**
- **GM (free)**: run unlimited games & campaigns, GM workspace basics, full charter function incl. safety tools, versioning and re-agreement (2 active charters; R084), bulletin boards, journal for the whole table, badges (earnable free tiers), recruitment summary.
- **GM Membership €9.50/mo · €95/yr (2 months free)**: everything above + unlimited charters with templates, start-from-previous and audit export, unlimited journal history + Previously On automation + Discord delivery, full analytics, badge showcase priority (featured badges, chronicle-ready profile), subscriber badge w/ year laps, future: paid-game commission waiver.

**Price-point honesty:** €9.50 reads slightly unusual next to charm pricing (€9 / €9.99) — but "under ten euros" positioning is clean, it's mid-range of the financial model (7.99→14.99), and it anchors just under Roll20 Pro. Keep 9.50 if the "below €10" story matters; otherwise test €9. Annual at €95 preserves the story (€7.92/mo effective).

**Founding campaign:** first 100/200 GM members get **Founding Supporter** (permanent badge, never re-earnable) + founding annual price locked (e.g. €79/yr forever). Scarcity + urgency that money can't later buy — the Arctic Code Vault effect, deliberately.

**Grandfathering:** existing free GM subscriptions stay active for a generous window (e.g. 3 months of GM Membership free) before the split lands — never strip access people already have without notice.

---

## 4. Sequencing recommendation

| Phase | Deliverable | Why this order |
|---|---|---|
| **1. Badge engine + subscriber/Discord/tenure badges** | `BadgeDefinition`/`UserBadge`, award pipeline, profile display, rarity job | Visible value fast; subscriber badge must exist the day charging starts; Discord badges are nearly free given infra |
| **2. Charter v2** | New sections, agreement split, versioning + re-agreement | Highest perceived value; pure differentiator; no competitor has it |
| **3. Campaign journal + Previously On** | Journal tables, entry UX, goals, state changes, migration of `recap`, bulletin/Discord hook | Deepest build, deepest moat, conversion driver |
| **4. Price flip + founding campaign** | Paddle GM plan, free/paid gates, grandfathering, founding offer | Only after 1–2, ideally 3 — charge when the value story is complete |

Instrument from day 1 (PostHog is wired): charter completion rate, re-agreement turnaround, journal adoption per campaign, Previously On → recap-written lift, badge → profile CTR, free→paid conversion funnel.

---

## 5. Open questions for discussion

1. **Free/paid philosophy** — additive monetization (recommended) vs gating some currently-free features?
2. **Chronicles public** — should campaign journals ever be publicly visible (SEO play), and under what curation/moderation?
3. **Price charm** — keep €9.50 vs test €9 / €9.99; annual €95 vs €79 founding.
4. **Badge scope for v1** — full set above, or subscriber+Discord+tenure first and craft/player/organizer badges in a second wave?
