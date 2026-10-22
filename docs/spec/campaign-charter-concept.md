# Campaign Charter — Concept

> Status: DRAFT concept for milestone planning · Date: 2026-10-17
> Parent: `docs/spec/gm-membership-value-proposal.md` (§3.1)
> Agreed direction: safety content free forever; charter is the flagship professional tooling feature;
> "signed, living social contract" positioning.

---

## 1. Product framing

**One line:** *The charter collects everything that matters before session one, gets everyone's signed
agreement, and stays alive as the campaign evolves.*

**What it replaces:** today's `SessionZeroSurvey` — create-only, one flat content blob, single
"I acknowledge" button, no amendments. The charter keeps the model's good bones (UUID sharing,
confirmations, timestamps) and adds: complete section coverage, two-way boundary collection,
versioned signatures with re-agreement, and a GM tracking dashboard.

**Lifecycle:**

```
DRAFT ──publish──▶ ACTIVE (v1, collecting signatures)
                       │ GM edits
                       ├── minor edit ──────────▶ ACTIVE (vN, notify only)
                       └── material edit ───────▶ AMENDED (vN+1, re-agreement pending)
ACTIVE ──campaign end──▶ ARCHIVED (read-only, part of campaign history)
```

Charters attach to a **campaign** (not a bare game). One-shots keep using game-level
`safety_rules` fields — a "lite table expectations" mode for one-shot series is a possible v2,
out of scope here.

**Mutual agreement:** the GM countersigns their own charter. Charters bind both ways — this is a
community-values touch that costs nothing and reads well on the sign page: *"The GM has signed
this charter too."*

---

## 2. Structure — eight sections

Platform principles that shape this structure: **play is physical and face-to-face** — every location
and logistics field centres on real venues, and nothing here encourages online play; the single
exception is **communication**, where tables choose whatever tooling works for them (Discord,
WhatsApp, Telegram, anything agreed). And **AI & digital tooling requires explicit consent** —
see §5, new in this revision.

Sections are GM-authored unless marked otherwise. Fields the participant must explicitly agree to
are marked **[AGREE]**; informational fields are covered by the read-attestation. Participant-private
fields are marked **[PRIVATE]**.

### §1 Campaign Overview — informational
| Field | Type |
|---|---|
| Pitch / what this campaign is about | text (rich, 2000) |
| System, edition, allowed supplements | text + game-system relation (existing catalog) |
| Tone axes: serious↔lighthearted, gritty↔heroic, political↔dungeon-crawl | 3 × 5-point slider |
| Themes | multi-choice chips (politics, horror, intrigue, war, mystery, exploration, heist, romance, survival…) |
| Expected shape | choice: short arc (3–6) / ongoing / open-ended |
| Table size | number + "we may add players later" toggle |

### §2 Content Boundaries — table standard + **[PRIVATE]** participant answers
| Field | Type | Author |
|---|---|---|
| Table lines (hard no) | category checklist + free text | GM, visible to all |
| Table veils (fade to black) | category checklist + free text | GM, visible to all |
| My lines / my veils | same checklist, private | each participant **[PRIVATE]** |
| Note to GM | free text | each participant **[PRIVATE]** |

- Consent-in-Gaming pattern: the participant answers never appear to other players; the GM sees an
  aggregated view. The table standard is the *floor*; a participant's stricter private answer is
  honoured by the GM, never published.
- **Rule:** the GM can never edit a participant's private answers. Tightening a private boundary
  takes effect immediately; only the participant can loosen their own.

### §3 Safety Tools — **[AGREE]**
| Field | Type |
|---|---|
| Tools adopted | multi-choice from existing `SafetyTool` enum (session-zero, lines-and-veils, open-door, x-card, xno-card, script-change, breaks, stars-and-wishes, debriefing) + custom note |
| How to signal at this table | text |
| Revisit cadence | choice: check-in each session / end of arc / on request |
| Open Door in effect | boolean, default **on** |

Agreement item: *"I consent to the safety tools adopted by this table and will honour everyone's
boundaries."*

### §4 Table Policies — **[AGREE]**
| Field | Type |
|---|---|
| Player-versus-player | choice: none / limited (consent scene-by-scene) / open |
| Character death | choice: possible / GM may fudge / only by player consent |
| Advancement | choice: milestone / traditional XP / narrative |
| Loot & treasure policy | text (optional) |
| Homebrew & third-party content | choice: no / GM approval / free |
| Table etiquette (phones, rules disputes, split party) | multi-choice + text |

Agreement item: *"I agree to the table policies above."*

### §5 AI & Digital Tools at the Table — **[AGREE]** (new)

Consent principles around AI-generated content and AI-enabled tooling during play. The platform's
stance mirrors its codebase AI policy spirit: **AI assistance is welcome when disclosed and
consented; undisclosed AI content or covert tooling is a boundary violation.**

| Field | Type |
|---|---|
| AI-generated content in prep (portraits, handout art, ambience/music, writing help for NPCs, homebrew, recaps) | choice: welcome with disclosure / case-by-case approval / not at this table |
| AI note-taking & transcription during sessions | choice: not at the table / GM private notes only / shared with table — with explicit inline notice that transcription captures **everyone's voice** and requires all-party consent |
| Dice | choice: physical dice at this table / dice apps fine / whatever's fun |
| Character sheets & reference tools | choice: paper preferred / digital fine / either |
| Photography of the table & miniatures | choice: ask every time / pre-approved, table-only sharing / no photos |
| Audio/video recording | choice: none / GM private notes only / shared with table |
| Custom clause | free text |

Agreement item: *"I agree to the AI & digital tools policy of this table, and I will disclose any
AI assistance I bring into our play."*

Photography and recording sit here deliberately: at a physical table they are consent questions of
exactly the same shape as AI transcription — something one participant does that captures others.

### §6 Logistics & Commitments — **[AGREE]** (physical play)

Every field here centres on **face-to-face play at real venues**. The platform does not encourage
online sessions; communication tooling is the single digital layer (see comms field).

| Field | Type |
|---|---|
| Venue | relation to existing Location catalog + "we rotate venues" option |
| Venue accessibility notes (step-free access, quiet spaces, venue rules) | text (optional, shown to participants pre-sign) |
| Food & drink arrangement | choice: venue rules apply / snack rotation / bring your own / group orders |
| Travel & arrival (parking, transit, arrive-N-minutes-early) | text (optional) |
| Session cadence | choice: weekly / biweekly / monthly / irregular |
| Session length | choice: 2h / 3h / 4h / other |
| Attendance expectation | text + choice: "missing >N sessions in a row may free your seat" |
| Absence notice | choice: asap / 24h / 48h before |
| Missing-player policy | choice: skip session / run PC as NPC / side one-shot |
| Communication channel — *the exception to face-to-face: tables are encouraged to pick whatever works for them* | multi-choice: platform messages / Discord / WhatsApp / Telegram / other agreed medium |

Agreement item: *"I commit to the scheduling, venue, and communication expectations to the best of
my ability, and I'll give notice when life happens."*

### §7 Character Creation — informational
| Field | Type |
|---|---|
| Build constraints (level/method, books, party composition) | text |
| Backstory hooks requested | boolean + text |

### §8 The Agreement — signatures
- Read-attestation: *"I have read this charter in full."* **[required]**
- The four **[AGREE]** items from §3, §4, §5, §6, each individually checkable. **[required]**
- Private boundary submission from §2 (optional fields, submission encouraged during signing).
- Timestamped signature, pinned to charter version. GM countersignature shown.

---

## 3. Versioning & amendment semantics

**Data model (evolution of existing tables):**

```
session_zero_surveys            (keep; add current_version, status enum: draft|active|archived)
session_zero_survey_versions    (survey_id, version_number, content JSON snapshot,
                                 changelog, is_material, published_at)
session_zero_confirmations      (add: survey_version_id, attestations JSON,
                                 private_answers JSON, status: signed|pending_reagreement)
```

**Material vs minor:**
- Material = any change to §2 table standard, §3 safety tools, §4 policies, §5 AI & digital tools
  policy, or §1 tone axes.
  → New immutable version; every participant's status resets to `pending_reagreement`;
  notification + re-sign required. No auto-agreement, ever — silence is never consent.
- Minor = logistics tweaks (§6 cadence/venue/comms), typo fixes, §7 updates.
  → New version; participants notified; signatures carry; status stays `signed`.

**Boundary carry-forward rule:** participant private answers (§2) carry across versions **unless
the table standard changed**, in which case each participant re-confirms their answers when
re-signing. A *loosened* table standard always requires explicit re-confirmation from each
participant (tightening never does).

**GM re-agreement tracker:** roster widget on the charter — signed vN / pending / nudge action
(one reminder per participant per version, rate-limited). After a configurable window (default
7 days) pending participants are simply shown as "not yet re-agreed". The GM decides what to do;
the platform never pressure-escalates.

**Mid-campaign joiners** sign the current version fresh — bound at join, prior versions readable
for context.

**Audit trail:** every version + every confirmation row is immutable history. Charter archive at
campaign end freezes it into the campaign's permanent record (feeds the *Chartered* badge and,
later, chronicle/recruitment flows).

---

## 4. UX flows

### GM authoring
- Stepper with the eight sections; per-section completion dots; autosave draft.
- Start from: **blank** / **quick-start** (pre-filled sane defaults) / **my previous charter**
  (member) / **saved template** (member).
- Templates are the professional-tooling angle: a GM's "standard charter" they refine once and
  reuse across campaigns.
- Publish → signature collection begins; the charter card appears on the campaign page
  (approved participants and owner — no draft existence leak). Deferred out of M070 (D234/D238,
  tracked gaps, not silent descopes): participant notifications on publish/amendment, and the
  session-page charter card.

### Participant signing
- Arrival via campaign page card or share link (existing UUID flow); the session-page
  card is deferred with D238.
- Reading-optimized single scroll: clean typography, section anchors, sticky progress rail,
  no editing chrome. §2 private answers inline at the relevant section.
- Signature panel at the end: read-attestation + four agreement checkboxes (§3 safety tools, §4 table policies, §5 AI & digital tools, §6 logistics) + sign button.
  Confirmed state shows signature timestamp + version. "You are bound by charter v3" language.
- Re-agreement: same page, banner "The charter was amended on {date} — please review and
  re-sign", with a **highlighted diff** of what changed since the version they signed
  (side-by-side or inline highlights; render from two content snapshots).

### GM dashboard (within GM workspace / campaign page)
- Signature roster: avatar, version signed, status chips, nudge button.
- Amendment composer: edit → preview diff → platform marks detected material changes → GM adds
  changelog note → publish.
- Charter health: % signed, days outstanding, revisit-cadence reminders landing in the existing
  notification system.

### End of campaign
- One-click archive; charter enters campaign history; contribution to *Chartered* badge progress
  requires "all participants signed" at some version.

---

## 5. Free / member split (per agreed philosophy)

**Free — the charter's function is safety infrastructure:**
- Create charters with all eight sections, all safety content.
- Signatures, amendments, re-agreement, versioning, diff views, reminders. (Never paywall the
  safety loop — including its continuity across amendments.)
- **2 active charters** concurrently.
- Full archive/history of own charters.

**GM Membership — professional scale & convenience:**
- Unlimited active charters.
- Saved templates + "start from my previous charter".
- Charter audit export (PDF/print for conventions, clubs, venue policies).
- Signature analytics (sign-through time, nudge effectiveness — later).

**Rationale:** safety continuity is non-negotiable free; a GM running 3+ concurrent campaigns or
reusing a refined standard charter is "taking this seriously" — that's the rewarded tier.

---

## 6. i18n & content notes

- All labels via translation keys (EN/DE, existing `session_zero.*` namespace extended; new
  `charter.*` namespace for new copy to avoid collision).
- Content JSON stays schema-versioned (`content_schema` int on versions) — sections evolve over
  time; snapshot rendering must tolerate old schemas.
- Category checklists for lines/veils ship with a curated default set (violence, sexual content,
  harm to children, substance use, bigotry, phobias, self-harm, animal harm…) — sourced from
  Consent in Gaming + TTRPG Safety Toolkit vocabulary, reviewed for both locales.
- AI-policy copy is principle-level ("disclosed & consented"), not tool-specific — it must age
  well as tooling changes.

## 7. Out of scope (explicit)

- One-shot "lite" charters (game-level expectations) — v2 candidate.
- Community-shared public templates — v2 candidate.
- AI-assisted charter drafting — later, member-only, never auto-applied.
- Any online-play enablement (VTT/video fields) — contrary to platform principle; if the platform
  ever revisits its offline stance, this section reopens deliberately, not by accident.
