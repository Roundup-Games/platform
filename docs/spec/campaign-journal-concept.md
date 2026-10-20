# Campaign Journal, "Previously On" & Recruitment Summary — Concept

> Status: DRAFT concept for milestone planning · Date: 2026-10-17
> Parent: `docs/spec/gm-membership-value-proposal.md` (§3.2)
> Agreed direction: one-shots go bulletin-first (no journal ceremony); campaign recaps accumulate
  into a campaign journal; "Previously On" composes into the relevant communication channels;
  chronicle content stays tied to the campaign, with an assisted **recruitment summary** flow into
  the public campaign description (with UI/UX guidance built in).

---

## 1. Split by context

| Context | Journal | Session-end flow | Why |
|---|---|---|---|
| **One-shot** (`campaign_id` null) | none | attendance + debriefing (exists) + bulletin board (exists) + auto-created sticky **Epilogue** bulletin with longer expiry | one-shots die of ceremony; zero overhead |
| **Campaign session** (`ttrpg` + campaign) | journal entry per session + goal/state updates | attendance + debrief + journal entry composer + Previously On for the *next* session | continuity is the value |

The split keys off existing data (`campaign_id`, `game_type`) — no schema discrimination needed.

---

## 2. Journal data model (system-agnostic by design)

```
campaign_journal_entries
  campaign_id, game_id (nullable — interlude entries allowed),
  author_user_id, author_participant_id (nullable — character link),
  kind: recap | in_character | prep_note,
  body (rich text), visibility: players | gms,
  in_game_date (free text, optional), created_at

campaign_goals
  campaign_id, title, description (optional),
  status: planned | active | achieved | failed | abandoned,
  status_changed_at_game_id (nullable), sort

character_state_changes
  campaign_id, participant_id, game_id,
  change_type: progression | item | condition | note,
  delta (free text — "Ti reached level 3", "lost the amulet")
```

- **No numbers, no sheets, no system coupling**: works as D&D quest log, PbtA front/threat board,
  CoC mystery thread. Kanka's lesson: free-form + author link carries the value.
- **Who writes:** GM writes `recap` and `prep_note` (`gms` visibility for the latter); any
  participant may write `in_character` entries (`players` visibility) — player-sourced journals
  instead of one overworked scribe.
- **Migration:** existing `game.recap` values become `kind=recap` entries (author = game owner);
  the write path moves to the journal; the `recap` column is retained read-only for legacy views
  then dropped in a later cleanup.
- **Reading surface:** campaign page gains a **Journal** tab: reverse-chronological timeline with
  kind filters (Recaps / In-character / GM notes per permission), goal board (kanban-lite list
  grouped by status), and per-character state timelines on participant cards.

### Free / member placement
The journal itself — entries, goals, state changes, full history — is **free for the whole table**
(it's shared engagement content; players read it, players write into it). Member features are GM
workflow automation: **Previously On composition** (below), journal analytics, and the polished
**chronicle export** (print/PDF keepsake of the campaign — later).

---

## 3. "Previously On" — composition & channels

### 3.1 Composition

Auto-composed digest when the next campaign session is published **and** ~24h before it starts
(existing reminder scheduler hook):

```
PREVIOUSLY ON {Campaign Title} — session {N}
├── Excerpt: latest recap entry (first ~200 words — the excerpt stands alone,
│     no journal deep-link: one bulletin is one body, and a members-only
│     link would dead-end for one-off guests; D231)
├── Goals moved: entries where status_changed since last session
│     ✔ achieved: "Free the harbour master" · ✖ failed: "Broker the truce"
├── Loose threads: open character_state_changes (conditions, unreturned items)
├── ── optional ── player in_character teaser if the GM enabled it
└── Next session: {date/time}, {venue} — your RSVP state + CTA
```

**Draft-first:** v1 always composes as a *draft bulletin* the GM reviews and publishes with one
click (no auto-posting to players sight-unseen; protects against tone-deaf compositions). A
per-campaign setting "auto-publish Previously On" can be enabled later by the GM.

**Draft lifecycle (D230 — a draft is a projection, not a document).** Drafts are 100%
machine-composed and never hand-edited, so a draft is a projection of campaign state: it cannot
go stale and it cannot orphan. Concretely: the draft recomposes automatically when its target
session's date or venue changes (observer hook); the panel and publish always target the
campaign's *next scheduled session's* draft; composing a draft supersedes (deletes) any older
unpublished draft of the campaign; a session leaving `scheduled` takes its unpublished draft
with it. Journal writes made after the last compose (a late recap, moved goals) surface through
an explicit "Refresh draft" action. Publishing an older session's digest after a newer one
exists is impossible by design — the digest is forward-looking; history lives in the journal.

**Audience (D231):** the published digest is delivered to **all approved participants of the
session, including one-off guests** — a guest attended the very table being recapped — and the
publish panel states that audience explicitly so GM consent is informed. The channel matrix
fan-out (below) is unchanged.

**Membership: DECIDED — member feature.** Previously On is GM workflow automation, and it can
always be given away later if the strategy shifts; the reverse (un-gating) is harder.

### 3.2 Channel matrix (the "relevant communication channels")

| Channel | Mechanism | Default |
|---|---|---|
| **Bulletin board** (canonical, persistent) | bulletin post with extended expiry | on |
| **In-app notification** | existing NotificationService, new category `SessionContent` reuse | on |
| **Discord** | existing digest publisher / bulletin publish job targets the campaign's channel | on when guild linked |
| **Web push** | existing web-push stack, respects per-user prefs | opt-in per user |
| **Email** | existing notification channel, respects per-user prefs | opt-in per user |

Configuration lives on the campaign (Discord + bulletin defaults) and on the user (push/email),
so GM and participant preferences compose rather than collide: a participant who muted email
still gets the bulletin + Discord delivery.

**Principle note:** communication tooling is the *single encouraged digital layer* — the platform
is offline/face-to-face for play itself, but tables finding their own comms medium (Discord,
WhatsApp, Telegram, …) is expected and encouraged. Discord delivery of Previously On is squarely
inside that exception, not a loophole.

---

## 4. Recruitment summary — chronicle content tied to the campaign

Agreed model: journal content is **never** automatically public. The GM can, assisted, pull a
spoiler-safe **recruitment summary** into the public campaign description. Built-in UI/UX
guidance makes this safe and effective.

### 4.1 The flow

1. Campaign page → Journal tab → **"Recruit with this campaign"** (also surfaced when campaign
   visibility = public and seats are open).
2. Composer opens with an auto-drafted summary assembled only from **spoiler-safe sources**:
   campaign pitch & tone axes (from the charter/overview if present), sessions played count,
   active goals the GM marks safe (checkbox per goal), and optional hand-picked recap excerpts
   (GM selects entries; nothing is included by default).
3. **Spoiler-safety checklist** in the composer rail (visible, not tooltip-hidden):
   - ☐ Review every excerpt below — visitors see exactly the highlighted text
   - ☐ Names/details that reveal twists are removed (inline scrub: select text → redact)
   - ☐ Preview shows the *public* view, not the member/table view
4. GM edits freely → **Preview as a visitor** (renders the actual public campaign page) →
   **Apply to campaign description** (inserts/refreshes a marked "Campaign so far" block at a
   position the GM chooses) or copy-to-clipboard for external use.
5. The "Campaign so far" block is tagged internally; the campaign page shows a subtle
   "Last refreshed after session N" line, and the GM gets a **staleness nudge** after 3 new
   sessions without refresh ("Your recruitment summary is 3 sessions stale — refresh it?").

### 4.2 UX guidance embedded in the product

- Default-safe assembly (nothing spoiler-y is included unless explicitly chosen).
- Redaction is destructive-to-copy only — the journal itself is never edited by this flow.
- Explicit mental model in copy: *"This becomes public. Visitors considering your table will
  read it."*
- One-way door made reversible: the block can be removed any time; removing it never touches
  journal content.

Free feature — it drives platform-wide acquisition; gating it would cut our own flywheel.

---

## 5. Session-end flow changes (summary)

For **campaign sessions**, the session-end panel (existing `_session-end` partial) becomes:
attendance report (exists) → debriefing (exists) → **journal composer** (recap editor + quick
"add goal status change" + "log character state" inline widgets) → publish. Recap write is no
longer a separate write-once 2000-char text field; the old `writeRecap` path retires.

For **one-shots**: recap form removed; Epilogue bulletin auto-created on completion with prompt
copy "The table has scattered — leave a note for your fellow adventurers" and a 30-day expiry.

---

## 6. Out of scope (explicit)

- Public campaign chronicle pages (SEO surface) — deliberately deferred per agreed direction;
  revisit after recruitment summaries prove out.
- AI-assisted recap drafting — later, member-only, draft-first.
- Entity graphs (NPC/location wikis) — Kanka territory, not our job.
