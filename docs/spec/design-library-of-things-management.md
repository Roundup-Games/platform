# Library of Things — Board Game Lending & Management

> **Purpose:** Design specification for a physical board-game lending library integrated with the roundup.games platform — inventory, check-in/check-out, reservations, deposits, condition, hygiene, and real-time player-facing availability.
> **Status:** Design complete · **integration assertions pending S01 spike verification** (webhook payload, post-checkin status routing, Paddle floor-capture mechanism) · pre-milestone
> **Authored:** 2026-07-07
> **Source:** Research + design interview (OSS landscape survey, Snipe-IT API verification, domain-model grounding against the roundup codebase)

---

## 1. Executive summary

Roundup Games will operate a physical **Library of Things** — a circulating collection of board games that members can borrow, reserve, and return. No turnkey open-source tool exists for *board-game* lending; the niche is split between collection trackers (which don't lend) and library systems built for books (wrong data model). The realistic anchor is **Snipe-IT**, a mature open-source asset manager written in PHP/Laravel — the same stack as roundup — with native check-out/check-in, barcode scanning, custom fields, locations, audits, a REST API, and a webhook integration.

The architecture is a **strict split of responsibility**:

- **Snipe-IT** (Docker sidecar, its own database) is the system of record for *physical state* — where a box is, who holds it, its barcode, and its physical/audit condition. Operators run check-out/in there.
- **roundup.games** is the system of record for *everything else* — the catalog, borrower identity, reservations, deposits, incidents, borrower standing, age gates, value-tiered integrity, hygiene gates, and borrowing budgets. Players never touch Snipe-IT.

The two systems stay consistent through Snipe-IT's webhook (push) plus a reconciliation poller (pull), feeding a local read-only projection (`library_copies`) that the player-facing catalog reads. **Players are never Snipe-IT users** — only operators are. Reservations live entirely in roundup; the physical handover is reconciled via a privacy-preserving claim mechanism (a reservation code and a pickup token) so a steward can fulfil a reservation without ever seeing the borrower's identity.

The library is unambiguously a **native, brain-heavy** feature of roundup. Snipe-IT integration is roughly 30% of the effort; the remaining 70% is library domain logic (deposits, standing, age, hygiene, value-tiered integrity, budgets) that no off-the-shelf tool provides — and which constitutes the feature's competitive differentiation. Estimated effort to a real, operating lending library: **8–10 weeks** across eight slices.

---

## 2. Reader and intent

**Reader:** the engineer or agent who will implement this milestone, and the stakeholder making scope decisions.

**After reading this document, you should be able to:**

1. Explain *why* Snipe-IT is the sidecar and *what* lives on each side of the boundary.
2. Implement the data model, state machines, and sync layer without ambiguity.
3. Reproduce the reservation-to-handover flow, including the privacy-preserving claim.
4. Justify each locked decision and know which follow-ups were deliberately deferred.

This is a design specification, not a build log. It documents the destination.

---

## 3. Background and goals

A pillar of roundup's strategy is a real-world Library of Things: the organization sources and curates board games, tracks their presence, quality, and status, and operates a check-in/check-out process — including reservation for pickup and planned drop-off. The platform must give players real-time information about what is available to them.

**Goals:**

- Maintain an inventory of physical copies, each tied to a title (`GameSystem`), with condition, completeness, value, and location.
- Operate a complete lending lifecycle: reserve → approve → pick up → borrow → return → sanitize → re-lend.
- Collect and reconcile a **Pfand** (deposit) to protect against loss and damage.
- Track a full incident history per copy and per borrower, and derive a **borrower standing** signal from it.
- Enforce **tier-based limits** (concurrent copies, loan duration, deposit brackets) tied to membership.
- Permit **carry-over** of unused borrowing days between subscription intervals, capped by tier.
- Enforce **age restrictions** using existing title data.
- Enforce **hygiene** as a mandatory gate in the lending workflow.
- Scale integrity effort to **perceived value** — cheap games get light checks; rare/premium games get manifests, waivers, and inspection.
- Present all of the above to players as a near-real-time catalog inside roundup, without exposing operator tooling or borrower PII.

---

## 4. Non-goals (MVP)

These are explicitly deferred, not forgotten:

- **Shipping fulfilment.** The reservation schema is *ship-ready* (nullable address/carrier/tracking fields) but only pickup at a `Location` or `Event` is built in the MVP.
- **Multi-region tenancy.** One shared library; one Snipe-IT instance. Revisit if regions grow into independent pools.
- **Speculative reservations against non-`Available` copies.** Only `Available` copies are reservable in the MVP.
- **Instant push to players.** Player freshness is 30–60s Livewire polling. A broadcast layer (Reverb) is an optional later phase.
- **E-signature vendor integration** for waivers. Digital in-app acceptance (checkbox + versioned text + IP/timestamp) is legally sufficient for a community library.
- **Piece-level community currency / borrow credits.** Carry-over is a day budget, not a currency; do not build both.

---

## 5. Solution overview

```
                 ┌─────────────────────────────────────┐
   operators ──▶ │ Snipe-IT (sidecar)                  │  system of record for
   (stewards)    │  PHP/Laravel + own MySQL            │  PHYSICAL STATE
                 │  barcode UI, check-out/in,          │  (location, holder, condition)
                 │  audits, status labels              │
                 └────────────────┬────────────────────┘
                   webhook (push) │ API poll (reconcile, every 5 min)
                                  ▼
                 ┌─────────────────────────────────────┐
   players ────▶ │ roundup.games (this app)            │  system of record for
                 │  library_copies (projection)        │  CATALOG, IDENTITY,
                 │  library_reservations               │  RESERVATIONS, and all
                 │  library_deposits / incidents /     │  LENDING LOGIC
                 │    budgets / sanitizations          │
                 │  Filament oversight + Livewire      │
                 └─────────────────────────────────────┘
```

The boundary is the load-bearing decision. Each field has exactly one owner; nothing is two-way-synced. This prevents drift and keeps each system comprehensible in isolation.

---

## 6. System-of-record split

| Concept | Owner | Mirrored to the other? |
|---|---|---|
| Physical box: location, holder, barcode, physical status | Snipe-IT | → projected read-only into `library_copies` |
| Title metadata (BGG, players, complexity, age, value tier) | roundup (`GameSystem`) | → mirrored into Snipe-IT Asset Model custom fields |
| Borrower identity and auth | roundup (`User`) | **never** sent to Snipe-IT |
| Reservation / hold | roundup (`library_reservations`) | **never** in Snipe-IT |
| Deposit (Pfand) ledger | roundup (`library_deposits`) | never in Snipe-IT |
| Location geo/proximity/venue | roundup (`Location`) | only name + address mirrored to Snipe-IT |
| Operator/steward identity | roundup staff `User` | → mirrored to Snipe-IT User (join key in `employee_num`) |

**Consequence:** the player read path depends only on the local projection. Snipe-IT being briefly unavailable degrades operator tooling, not the player catalog.

---

## 7. Domain model

### 7.1 Existing roundup models we reuse

These already exist and are extended, not reinvented:

- **`GameSystem`** — the title entity, BGG-linked, already carries `age_rating`, `min_players`/`max_players`/`optimal_players`, `complexity_rating`, `average_play_time`, `base_game_id` (expansion link). This is the catalog spine; physical copies hang off it.
- **`Location`** — physical sites with geo, geohash, venue type, proximity. Only name + address mirror to Snipe-IT.
- **`User`** — player identity. Extended with `birth_year` (nullable, consent-gated) for age enforcement.
- **`MembershipType`** — subscription tiers, Paddle-linked. Extended with library-policy fields (concurrent copies, loan duration, deposit bracket, carry-over cap).
- **`local_subscriptions`** — subscription intervals; the boundary against which borrow budgets and carry-over are computed.
- **`Event`** — gatherings; pickup and drop-off anchor to events.
- **Paddle** — already the payment provider; used for Pfand holds and refunds with no new vendor.
- **Filament** — operator oversight surface.
- **`WaitlistService`** (existing, for game sessions) — the capacity-limited-queue pattern is reusable for title waitlists in a later phase.

### 7.2 New tables and models

| Table | Purpose | Key fields |
|---|---|---|
| `library_copies` | Read-only projection of physical copies | `snipe_asset_id`, `asset_tag`, `game_system_id`, `location_id`, `physical_status` (enum: `acquired`/`available`/`checked_out`/`needs_cleaning`/`in_repair`/`retired_lost`), `condition`, `completeness`, `value_tier`, `replacement_value_cents`, `manifest` (nullable JSON, high-tier only), `sanitized_at`, `last_checkin_at`, `last_synced_at` |
| `library_reservations` | Player reservations | `user_id`, `copy_id`, `status` (enum, see §8.3), `cancellation_reason` (nullable enum: `withdrawn`/`rejected`/`superseded`/`early`/`late`/`no_show`/`payment_failed`/`age_verification_failed`/`holiday_drift`/`lost`), `pickup_location_id`, `pickup_event_id`, `fulfilment_method` (`pickup`/`ship`), nullable `ship_address`/`carrier`/`tracking`, `reservation_code`, `pickup_token`, `pickup_deadline` (auto-cancel if not picked up by this), `return_date` (borrower's chosen open-day return date; anchors the day-cost debit), `requested_at`, `approved_at`, `ready_at`, `fulfilled_at`, `returned_at`, `cancelled_at`, `debited_days` (calendar-day span debited at request; refunded/reconciled per §11.2) |
| `library_deposits` | Pfand ledger (per-reservation top-ups and tier-floor movements) | `reservation_id` (nullable for floor-scope rows), `subscription_id` (for floor-scope rows), `type` (`top_up` / `floor_capture` / `floor_encumber` / `floor_release` / `floor_drawdown` / `floor_replenish` / `floor_refund` / `exceptional_charge`), `amount_cents` (signed; ±0 for encumber/release), `replacement_value_cents` (on encumber/release rows, for committed-exposure accounting), `status` (top-up rows: `held`/`released`/`refunded`/`partial`/`forfeited`/`refund_pending`/`refund_failed`; floor rows: `posted`; `exceptional_charge`: `posted`/`settled`), `paddle_transaction_id`, `paddle_refund_id` |
| `library_incidents` | Loss / damage / lateness / age-misrepresentation, per copy + borrower | `copy_id`, `reservation_id`, `user_id`, `type` (enum: `late_return`/`minor_damage`/`major_damage`/`loss_nonreturn`/`no_show_repeat`/`age_misrepresentation`/`other`), `severity` (integer 0–20; defaults to the type's weight, operator-overridable — for damage types within ±2 of the default, for `other` anywhere 0–10; this is the value used in standing), `weight_at_creation` (alias snapshot of `severity` at incident creation, for audit), `damage_amount_cents` (nullable; operator-entered charge for damage, capped at the copy's `replacement_value_cents`), `photos`, `resolution`, `attributed_at` |
| `library_borrow_budgets` | Per-user × interval day ledger | `user_id`, `interval_ends_at`, `granted_days`, `used_days`, `held_days`, `carried_in_days`, `carried_out_days` (see §11.2 for definitions) |
| `library_waiver_acceptances` | High-value waiver records | `reservation_id`, `user_id`, `waiver_version`, `accepted_at`, `ip`, `user_agent`, `paper_signed` flag |
| `library_sanitizations` | Hygiene log (real cleans and skipped-with-reason) | `copy_id`, `reservation_id` (nullable; links to the loan whose return triggered cleaning, for incident attribution), `sanitized_at`, `sanitized_by`, `method`, `skipped` (bool), `sanitization_skipped_reason` (nullable; matches §8.1 invariant wording), `notes` |
| `library_open_days` | Per-location open-day schedule | `location_id` (nullable for library-wide default), `weekday` (0–6), `open_time`, `close_time`, `effective_from`, `effective_until` (nullable) |
| `library_calendar_sources` | External holiday feeds | `id`, `location_id` (nullable), `source_type` (`ical`/`holiday_api`), `source_url`/`config`, `region`, `cached_until` |
| `library_policy` | Admin-configurable thresholds (single-row config table, typed columns) | `value_tier_low_cents`, `value_tier_high_cents`, `carry_over_caps` (JSON map of tier→cap), `standing_thresholds` (JSON map of standing band→score range), `standing_weights` (JSON map of incident type→default weight), `recency_full_days` (default 90), `recency_zero_days` (default 365), `request_timeout_hours` (default 24), `late_cancel_window_hours` (default 2), `ready_lead_time_hours` (default 24; how long before `pickup_deadline` to auto-pull), `non_return_grace_days` (default 14), `sanitization_rules` (JSON) |
| `library_sync_health` | Sync-layer last-error persistence (agent-first observability) | `id`, `direction` (`webhook_in`/`poll_in`/`outbound`), `last_success_at`, `last_error_at`, `last_error_message`, `retry_count`, `phase` |

**Conventions:** UUID primary keys (matches roundup's existing UUID-on-string convention). No soft business logic hidden in JSON blobs where a column will do — `MembershipType` library-policy fields are dedicated columns, not metadata JSON. Currency is integer cents.

**Key cardinalities:** `library_copies` ↔ `library_reservations` is 1:N historical, **1:N for `requested`** (concurrent requests allowed — see §8.4) **and 1:0..1 for locked states** (`approved`/`ready_for_pickup`/`fulfilled`) — enforced by a partial unique index on `(copy_id) WHERE status IN ('approved','ready_for_pickup','fulfilled')` (**`'requested'` is deliberately excluded** so multiple players can express interest; the lock moves to approval, resolving the §8.4 race). `library_reservations` ↔ `library_deposits` is 1:N (a reservation has at most one `top_up` row; floor movements reference `subscription_id`; an `exceptional_charge` row references `reservation_id`). `library_reservations` ↔ `library_waiver_acceptances` is 1:N (re-acceptance on waiver-version change). `library_reservations` ↔ `library_incidents` is 1:N. `library_reservations.pickup_location_id` and `pickup_event_id` are XOR when `fulfilment_method = 'pickup'` (one or the other required); both NULL when `fulfilment_method = 'ship'`. `library_deposits.subscription_id` → `local_subscriptions.id` is a FK. `library_incidents.reservation_id` and `library_sanitizations.reservation_id` are nullable (an incident can reference a copy without a reservation; a sanitization can be event-driven).

---

## 8. State machines

A copy has **two independent truths** at any moment. Conflating them into one flat enum is the classic library-system mistake; it causes collisions (an on-shelf-but-reserved copy has no single honest status). They are kept separate.

### 8.1 Physical status (Snipe-IT-authoritative, mirrored to `library_copies.physical_status`)

**States:** `acquired` (just catalogued, not yet ready), `available` (on the shelf, lendable), `checked_out` (in a borrower's custody), `needs_cleaning` (returned but not yet sanitised), `in_repair` (damaged, awaiting repair), `retired_lost` (terminal — written off or reported lost).

**Transitions (each with a defined trigger):**

| From | To | Trigger |
|---|---|---|
| `acquired` | `available` | operator marks ready (initial cleaning done, catalogued) |
| `available` | `checked_out` | steward scans checkout in Snipe-IT (assigned_to = steward) — webhook mirrors to roundup |
| `available` | `in_repair` | operator sets aside (pre-existing damage noticed) |
| `checked_out` | `needs_cleaning` | **check-in — MANDATORY next state; never `available` directly** (see invariant) |
| `checked_out` | `retired_lost` | borrower reports loss / non-return after grace period (§11.1) |
| `needs_cleaning` | `available` | operator sanitises (or logs `sanitization_skipped_reason` for sealed items) |
| `needs_cleaning` | `in_repair` | sanitisation reveals damage → opens incident (§10.4) |
| `in_repair` | `available` | repair complete |
| `in_repair` | `retired_lost` | uneconomical to repair — operator writes off |
| `available` | `retired_lost` | shelf write-off / on-shelf loss discovered |
| `needs_cleaning` | `retired_lost` | damage during cleaning is total |
| `retired_lost` | `available` | **lost-then-found** (routine in libraries — a reported-lost copy surfaces; operator re-catalogues) |

**The critical invariant: check-in does not return to `available`.** Check-in transitions `checked_out → needs_cleaning`. Sanitisation then transitions `needs_cleaning → available` (or `needs_cleaning → in_repair` if damage is found). This is a mandatory intermediate state on the return path, never skippable except by an explicit, logged override (`sanitization_skipped_reason`). A copy physically back on the shelf is not yet physically ready.

**Reservation propagation rule.** When a copy leaves the reservable set (`available`) via any transition (`→ in_repair`, `→ retired_lost`):
- **`requested` reservations** are automatically moved to `cancelled (reason: copy_unavailable)` with a full day refund, and the players are notified.
- **Locked reservations** (`approved`/`ready_for_pickup`) require operator action — the copy is already committed. The operator cancels them with reason `copy_unavailable` (full day refund + any top-up Pfand refunded) OR, if the copy is repairable and will return to `available` before `pickup_deadline`, leaves them alone. `fulfilled` reservations are not affected (the goods are already in the borrower's hands; a post-checkout problem is an incident, not a cancellation). This prevents reservations from hanging against an unlendable copy while giving the operator a clear action for the committed cases.

### 8.2 Logical lendability (roundup, derived at query time, never stored as a flag)

```
is_lendable(copy) =
       physical_status == 'available'
   AND no_locked_reservation(copy)
```

where `no_locked_reservation(copy)` means no reservation in a **locked state** (`approved` / `ready_for_pickup` / `fulfilled`). **`requested` is NOT a locked state** — concurrent `requested` reservations are allowed (§8.4), and the copy remains lendable while reservations are merely `requested`. This definition is what makes the §8.4 concurrent-request mechanic work.

`Needs Cleaning`, `In Repair`, `Checked Out`, and `Retired/Lost` copies are **invisible to players** — the catalog filters on `physical_status = Available`. Operators see dirty copies in a saved Snipe-IT status filter (no custom cleaning UI needed). Players never know a dirty copy exists until it is clean.

### 8.3 Reservation lifecycle (roundup)

```
requested → approved → ready_for_pickup → fulfilled → returned
    │   │       │ │             │   │               │
    │   │       │ │             │   │               └─ (non-return after grace period, default 14d) → cancelled (lost), copy → retired_lost
    │   │       │ │             │   └─ (player cancels ≤2h pre-pickup / no-show) → cancelled (late), copy freed, −1 day
    │   │       │ │             └─ (player cancels >2h pre-pickup) → cancelled (early), copy freed, full refund
    │   │       │ └─ (age verification fails at desk) → cancelled (age_verification_failed), full refund + incident (§9.5)
    │   │       └─ (top-up capture fails) → cancelled (payment_failed), full refund
    │   └─ (operator rejects) → cancelled (rejected)
    ├─ (another request approved first) → cancelled (superseded), full refund (§8.4)
    └─ (player cancels pre-approval / request times out at 24h) → cancelled (withdrawn)

(holiday-feed invalidates return date pre-pickup → shifted or cancelled (holiday_drift) per §11.2)
```

- `requested` — player created it; **borrowing days are debited immediately** — the calendar-day span from pickup to the borrower's chosen open-day `return_date` (see §11.2) — to counter parallel-request asset blocking. **Concurrent `requested` reservations on the same copy are allowed** (the copy remains `available` and lendable until approval); approval resolves the race — see §8.4. Awaits operator approval (or auto-approval by policy). Requests have a timeout (see temporal fields below).
- `approved` — operator approved; roundup issues the reservation code and pickup token. **Pfand handling depends on value tier** (see §11.1): routine low/medium-value borrows are covered by the player's membership-tier Pfand floor (no per-reservation payment); high-value borrows trigger a per-reservation top-up Pfand capture at this moment. Approval is the lock moment — the copy becomes unavailable to others — so the required collateral is verified in place here. **If top-up capture fails, the reservation drops to `cancelled (payment_failed)` with full day refund** (see §11.1).
- `ready_for_pickup` — **trigger:** the operator pulls the copy to the pickup point, OR a timer fires at **`pickup_deadline − ready_lead_time_hours`** (admin-configurable in `library_policy`, default 24h — i.e. the copy is staged 24h before the pickup window opens). Copy is held for the player; physically still `available` but reserved (so hidden from other players via the lendability rule).
- `fulfilled` — steward scanned checkout in Snipe-IT; webhook reconciled the reservation; **borrowing days stay in `held_days` (not yet converted — see §11.2)**; collateral was already verified at approval (covered by the tier floor, or a top-up Pfand captured for high-value). **The handover endpoint enforces `ready_for_pickup` state before allowing checkout** (a steward scanning checkout against an `approved`-but-not-`ready` reservation is rejected).
- `returned` — steward scanned check-in; webhook reconciled; deposit refund queued; sanitization gate scheduled; borrow days reconciled to the actual calendar-day duration (early return on an earlier open day refunds the difference; late return adds days and may feed §12).

**Temporal fields (disambiguated).** The reservation has three distinct time concepts, each in its own column: (a) **`requested_at`** anchors the **request timeout** (auto-cancel if not approved within `library_policy.request_timeout_hours`, default 24h); (b) **`pickup_deadline`** is the latest pickup time — once `ready_for_pickup`, auto-cancel if not picked up by this; the **2h cancellation window** (§11.2) is measured against `pickup_deadline`; (c) **`return_date`** is the borrower's chosen open-day return date, set at request — it anchors the **day-cost debit** (`debited_days` = calendar days from pickup to `return_date`). These are separate columns; the old `desired_from`/`desired_until`/`expires_at` naming is retired.

**Cancellation accounting.** Early cancellation (>2h before `pickup_deadline`) releases the copy, refunds any per-reservation top-up Pfand, and refunds all debited days — no penalty. This applies from any pre-`fulfilled` state (`requested`, `approved`, `ready_for_pickup`). **No-show or late cancellation (within 2h of `pickup_deadline`) releases the copy and any top-up Pfand** (no goods were at risk) **but deducts a flat 1 calendar day** as an operational-overhead disincentive. Pre-approval cancellation or request timeout refunds all debited days, no penalty. The tier floor is untouched by individual reservation cancellations — it's a subscription-scope balance, not a per-reservation hold.

### 8.4 Concurrent-request resolution

Because a copy stays lendable while reservations against it are merely `requested`, two players can request the same copy. The resolution rules (first decision point wins; losers auto-handled):

1. **On approval of Player A**, the copy is locked. All other `requested` reservations for that copy are moved to `cancelled (superseded)`, their debited days are refunded in full, and those players are notified ("another member was approved first; your request was not charged").
2. **If approval is not the model** (auto-approval by policy is on), the first `requested` reservation wins on a `created_at`-ordered lock at approval time; same supersede rule for the rest.
3. **No waitlist auto-enrollment** in the MVP (title waitlists are deferred per §19). Superseded players may re-request when the copy returns to `Available`.

This keeps the catalog non-blocking (players aren't prevented from expressing interest) while making the lock moment unambiguous and the losers' accounting clean.

---

## 9. The handover mechanism (privacy-preserving claim)

The hardest UX problem: a steward must identify and fulfil a reservation *without* seeing the borrower's identity (players are not Snipe-IT users; Snipe-IT must not hold player PII). The solution is a shared-secret claim exchanged out-of-band, with roundup as the only system that can bind the claim to a person.

### 9.1 The two codes

At approval, roundup issues:

- **Reservation code** — human-friendly, e.g. `LIB-7Q3K`. Used for verbal confirmation ("I'm here for LIB-7Q3K").
- **Pickup token** — an opaque 32-character random string, encoded in a QR. The bearer claim. Guess-resistant.

Both are stored on `library_reservations`; the player sees both in their "My Reservations" page with a scannable QR.

### 9.2 The handover lookup

Roundup exposes a narrow, read-only internal endpoint:

```
GET /api/internal/library/handover/{pickupToken}
```

Authenticated by a shared operator secret plus the token. It returns **only what the steward needs to execute the handover — no name, no email, no user id**:

- `reservation_code` — to confirm against the player's spoken code.
- `copy_asset_tag` — to confirm against the box the steward scanned (mismatch = block).
- `value_tier` and derived workflow flags: `requires_waiver`, `requires_inspection`.
- `deposit_covered_by` (`floor` or `top_up`) and, if `top_up`, `top_up_amount_cents` and `top_up_status` — **confirmation only; all Pfand is resolved digitally (floor as a subscription-scope balance, top-up captured at approval). The steward never handles money.**
- `age_restricted` (a boolean flag, not the rating) — roundup has already verified age at reservation time; the steward sees "age verified" or "verify ID."

### 9.3 The sequence at the table

```
Player (app)              Steward (Snipe-IT)           roundup (brain)
────────────              ────────────────────         ───────────────
shows QR + "LIB-7Q3K"     scans box barcode
                          GET handover/{token}
                          ← reservation_code: LIB-7Q3K ✓
                          ← asset_tag matches box ✓
                          ← deposit: covered by FLOOR (or top-up HELD at approval)
                          ← waiver: REQUIRED (high value)
                          ← age: verified ✓
   player accepts waiver (in app), steward confirms
                          → POST /hardware/:id/checkout
                            (assigned_to = steward)
                                                      ← webhook: checkout
                                                      → reservation fulfilled
                                                        (floor covers; or top-up held)
Player sees: "fulfilled ✓"
```

The player never touches Snipe-IT. The steward never sees a name. The shared secret is the claim; roundup is the sole binder of claim to person.

### 9.4 Returns (mirror image)

Player returns the box → steward scans **check-in in Snipe-IT** → webhook → roundup flips reservation to `returned`, schedules the sanitization gate, and: if the copy was covered by a per-reservation top-up Pfand, refunds it (net of any damage discovered); if covered by the floor, the floor balance simply becomes available again for future borrows. Returns use the same lookup in reverse to confirm the returning copy matches an outstanding reservation.

### 9.5 Edge cases

- **Wrong box scanned:** token's expected asset tag ≠ scanned tag → endpoint returns mismatch → block.
- **No-show or late cancellation (within 2h of pickup):** `pickup_deadline` passes or the player cancels inside the 2h window → roundup cancels the reservation, frees the copy, **releases any per-reservation top-up Pfand** (no goods were at risk; the tier floor is untouched — it's a subscription-scope balance) but **deducts a flat 1 calendar day** as operational-overhead disincentive (see §11.2). Early cancellation (>2h before `pickup_deadline`) releases the copy, any top-up Pfand, and all debited days with no penalty. Steward sees "expired" if they try the token. **Reason distinction:** system auto-cancel at `pickup_deadline` expiry → reason `no_show`; player-initiated cancel inside the 2h window → reason `late`; both count toward the `no_show_repeat` incident trigger (§12.2).
- **Stolen code:** the token is opaque and single-use-ish (bound to one reservation); the human code is confirmation only, not a bearer.
- **Offline venue:** the human code `LIB-7Q3K` works *without* the endpoint as a fallback (matched to a printed pick-list). The endpoint is the fast path, not the only path.
- **High-value offline:** waiver can be signed on paper and flagged by the operator, reconciled later.
- **Age verification fails at the desk:** the steward's in-person ID check shows the picker-up is below the title's age threshold (the steward checks the **ID's age against the title's threshold** — both visible at the desk: the physical ID and the threshold derived from `GameSystem.age_rating`; the steward never sees the borrower's recorded `birth_year`). → reservation moves to `cancelled (age_verification_failed)`, copy freed, debited days refunded in full, any top-up Pfand refunded. A `library_incidents` row is opened with `type = age_misrepresentation`, **weight 8** (major-damage equivalent — see §12.2), because misrepresenting age to access restricted material is a serious integrity violation with legal exposure for the library. **Reconciliation note:** roundup's pre-flight `birth_year` check (at request time) is advisory only — the in-person ID check is authoritative. A discrepancy (recorded `birth_year` said 18+, ID says 16) is logged as the incident and may trigger a `birth_year` correction flow for the user account. This preserves the privacy model: the steward verifies age from the ID against the title, never from the borrower's record.

---

## 10. Board-game-specific concerns

### 10.1 Value-tiered completeness tracking

Board games are hundreds of pieces; integrity rigor should scale with risk, which scales with value. A copy's `value_tier` (low / medium / high) is computed from `replacement_value_cents` against admin-configurable thresholds (defaults: low < €40, medium €40–150, high > €150 or rare/OOP/collector). The tier drives the completeness method *and* the workflow:

| Value tier | Completeness method | Workflow impact | Pfand |
|---|---|---|---|
| **Low** | binary + notes | standard self-service | minimal |
| **Medium** | piece-count checksum ("expected 142 → counted 139") | quick count at handover | modest |
| **High** | full component manifest + photos | waiver signed + on-the-spot inspection at pickup/drop-off | higher |

The `manifest` column (nullable JSON) is populated only for high-tier copies and is **operator-entered in roundup only** — it never touches Snipe-IT (a component manifest is a board-game-specific concept Snipe-IT has no model for; per §6, roundup is the catalog/title authority). Thresholds are admin-editable in Filament; `value_tier` is recomputed when thresholds change. If a threshold edit changes a copy's tier mid-reservation, the reservation keeps the collateral terms it had at approval (top-up, if any, is not retroactively refunded or captured); the new tier applies to the next reservation.

### 10.2 Condition capture at both handovers

Photo + grade at check-out **and** check-in. This is what makes incident attribution provable — "the tear happened during borrower X's loan." Photos live in roundup's media library (not Snipe-IT). Pairs directly with the incident system and with Pfand refunds.

### 10.3 Age restriction

Title side is free (`GameSystem.age_rating`, BGG-populated). Borrower side uses `User.birth_year` (nullable, consent-gated): `current_year − birth_year ≥ age_threshold`. Enforcement runs at reservation time; the steward sees only a boolean "age verified" flag, never the rating or the birth year.

### 10.4 Hygiene / sanitization

Mandatory after every return: check-in → `needs_cleaning` → operator sanitizes → `available`. Operator override exists for sealed/unopened items but still flows through `needs_cleaning` with a logged `sanitization_skipped_reason` — one uniform audit shape for every return. Pre-event bulk sanitize reuses the same path triggered by event scheduling rather than a return. Sanitization can reveal damage, in which case the copy diverts to `in_repair` and opens an incident tied to the last borrower via `library_sanitizations.reservation_id`. **Skipped-sanitization attribution:** if a return's sanitize was skipped (`skipped = true`) and the *next* borrower reports damage, attribution falls to the skipping borrower (the `reservation_id` on the skip log identifies them), not the next borrower — the skip record preserves the chain. The skip is also reviewable in the operator's sanitization queue.

---

## 11. Financial and policy

### 11.1 Pfand (deposit) ledger

**Hybrid scheme: tier floor + high-value top-up.** Two collateral layers, via Paddle (already integrated — no new vendor):

1. **Tier floor** — a Pfand amount bundled into each `MembershipType`, captured with the subscription (within Paddle's natural subscription flow and refund window). This is the player's standing collateral; it covers routine low/medium-value borrows with **zero per-reservation payment friction**. The floor is a subscription-scope balance, not a per-reservation hold.
2. **Per-reservation top-up** — when a borrower requests a copy whose replacement value exceeds the *available* floor (floor minus currently-committed exposure, see accounting below), a top-up Pfand is captured at approval for the gap. This keeps collateralization tight exactly where it matters (high-value items) without front-loading a large lump sum.

This hybrid was chosen over a full subscription-bond after weighing three costs: Paddle doesn't model a long-held wallet that gets nibbled over months (refund windows make returning a multi-year deposit awkward), holding customer funds for subscription durations expands the regulatory surface (segregated-account and refund-timeline rules), and a large upfront deposit is an equity barrier off-brand for a community library. The floor captures the friction-reduction where it counts (routine borrows); the top-up preserves tight collateral where risk concentrates.

**Floor accounting (append-only ledger).** The floor is modeled as a sequence of `library_deposits` rows keyed by `subscription_id` (not `reservation_id`):

| Event | `type` | `amount_cents` sign | Notes |
|---|---|---|---|
| Subscription activates | `floor_capture` | + | The tier's floor amount, captured with the subscription via Paddle |
| Floor-covered borrow approved | `floor_encumber` | (metadata only, ±0) | Records `reservation_id` + `replacement_value_cents` as committed exposure; **does not move the balance**, only the committed total |
| Floor-covered borrow returned | `floor_release` | (metadata only, ±0) | Releases the encumbrance for that reservation — **fires at sanitization-clear (`needs_cleaning → available`), NOT at return**, so damage discovered during cleaning can still draw down the floor before the encumbrance frees (avoids a collateral double-count window) |
| Damage charged on a floor-covered copy | `floor_drawdown` | − | Reduces the balance (see §12.1) |
| Borrower tops up after a drawdown | `floor_replenish` | + | Restores the balance; required to continue high-value borrowing |
| Subscription cancelled (items clear) | `floor_refund` | − (to balance 0) | Refunds the remaining balance |

The accounting identities an engineer implements against:

```
floor_balance(sub)      = SUM(amount_cents) WHERE subscription_id = sub AND type LIKE 'floor%'
floor_committed(sub)    = SUM(e.replacement_value_cents)
                          FROM library_deposits e
                          WHERE e.subscription_id = sub
                            AND e.type = 'floor_encumber'
                            AND NOT EXISTS (
                              SELECT 1 FROM library_deposits r
                              WHERE r.subscription_id = sub
                                AND r.type = 'floor_release'
                                AND r.reservation_id = e.reservation_id
                            )
available_floor(sub)    = floor_balance(sub) − floor_committed(sub)
```

The reservation gate at approval: `replacement_value_cents ≤ available_floor(sub)` → borrow is floor-covered (no top-up); else → capture a top-up of `replacement_value_cents − available_floor(sub)`. Both the floor and the top-up live in the same `library_deposits` ledger (the top-up rows use `type = top_up` and key on `reservation_id`), so all Pfand value flows are one append-only log.

**Top-up state machine:** `held` (at approval, via Paddle capture) → `released` (clean early cancellation before the 2h window) / `refunded` (clean return) / `partial` (damage) / `forfeited` (loss or non-return). On **top-up capture failure at approval**, the reservation moves to `cancelled (payment_failed)`, debited days are refunded in full, and the copy returns to lendable. Refunds and partial forfeits reconcile against the condition captured at check-in and any incident record.

**Floor lifecycle:** captured at subscription activation; encumbered/released per floor-covered borrow; drawn down by damage charges at discovery (see §12.1); replenished by the borrower as a condition of continued borrowing; refunded on subscription cancellation (within Paddle's refund window — beyond it, settled by SEPA credit as a rare operational path) once all lent items are confirmed in good state. **Cancellation with active borrows blocks the refund** until items are returned; **non-return after a configurable grace period (default 14 days) forfeits the remaining balance** via the §12.1 loss path.

**Mid-subscription tier changes.** Upgrade (floor grows): the delta is captured immediately as a new `floor_capture` row (the member unlocks more borrowing power now). Downgrade (floor shrinks): **the floor stays at the old level until the next interval boundary**, then drops to the new tier's floor — this avoids mid-loan payment friction when committed exposure exceeds the new floor. Access features (concurrent copies, loan duration, carry-over cap) follow the new tier immediately; only the floor lags to interval end. This is the least-surprising rule and avoids surprising the member with a top-up at the moment of downgrade.

**No-shows and late cancellations *release* both layers** (no goods were at risk — the encumbrance is released for the floor; any top-up is `released`) — the no-show disincentive lives in the borrow-day ledger (§11.2), not the deposit, keeping the two mechanisms cleanly separated: Pfand protects the goods; day-penalties protect operational throughput.

### 11.2 Borrow budget and carry-over

A **day ledger**, not a currency. Each subscription interval grants N borrowing days (per tier). The accounting rules below are designed so a player cannot block assets they don't intend to use.

**Debit timing — immediate at request.** A reservation **debits days the moment it is created**, not at checkout. This is what stops parallel-request abuse: a player cannot place ten simultaneous reservations, because each one immediately consumes their day balance, naturally capping concurrent holds at what their budget affords.

```
library_borrow_budgets: granted_days, used_days, held_days, carried_in_days, carried_out_days, interval_ends_at
```

**Field definitions.**
- `granted_days` — days credited at the start of the subscription interval (per tier).
- `held_days` — days debited for open reservations (`requested`/`approved`/`ready_for_pickup`/`fulfilled`) not yet returned. Restored on clean cancellation; converted to `used_days` on `returned`.
- `used_days` — days consumed by completed (`returned`) loans, counted as the actual calendar-day span.
- `carried_in_days` — days carried in from the previous interval at rollover.
- `carried_out_days` — days carried out to the next interval at this interval's rollover (computed as `min(remaining_unused, tier_carry_cap)`; the outflow that becomes the next interval's `carried_in_days`).
- `interval_ends_at` — the subscription-interval boundary (from `local_subscriptions`) against which rollover and re-grant are computed.

A player's **available days** at any moment = `granted_days + carried_in_days − used_days − held_days`.

**Unit — calendar days.** Day-cost is counted in **calendar days**, not working days. A game borrowed Friday and returned Monday is in the borrower's hands for the whole weekend; those days are charged. The library being closed on a Sunday does not make the game free on Sunday — the cost reflects the days the goods are actually held.

**Open days govern *returns*, not charges.** The library is only open on certain days (its scheduled open days, configurable per `Location` or library), and closed on **bank holidays sourced from external calendar feeds** (region-aware; e.g. a German or Romanian public-holiday iCal subscription or holiday API). A copy can only be physically returned on an **open day** — a scheduled open day that is not a bank holiday. This is what makes weekend and holiday days non-avoidable in the charge: the borrower cannot return on a closed day, so any loan spanning a closed period necessarily includes those days in its calendar-day span. The open-day constraint shapes *when you can return*; calendar days shape *what you pay*.

**Minimum available to reserve.** A player must have at least the calendar-day span (pickup → chosen return date) available to place the request. Because the chosen return date must be an open day, a borrower whose only feasible return falls after a weekend or a block of bank holidays must have the credit to cover those intervening closed days — the system will not let them place a reservation they cannot afford to "return on time." There is no separate reserve buffer — the immediate-debit mechanic itself is the gate.

**UI/UX requirement (load-bearing).** The return-date picker offers **open days only** (no weekends, no bank holidays), and the resulting calendar-day cost is shown **live, before confirmation**, in plain language (e.g. "Borrow Fri 10 → Tue 14 = 4 calendar days; Pfand covered by your Gold floor"). The borrower must never be surprised by the day count at fulfilment or return. This is first-class in the catalog/reservation UI, not an afterthought.

**Open-day calendar service (new dependency).** A service determines, for a given `Location` and date, whether the day is open: the library's scheduled open days (configurable) **minus** bank holidays pulled from **external calendar sources** (iCal/.ics subscription or a regional holiday API). Sources are admin-configurable and region-tagged; results are cached (holidays change slowly) and re-validated on a schedule. This service powers both the return-date picker and the reservation gate.

**Holiday-feed drift (edge case).** If the feed changes and invalidates a reservation's chosen return date (it becomes a bank holiday):
- **Detected before pickup** (`requested`/`approved`/`ready_for_pickup`): roundup auto-shifts the return to the next open day, recomputes the day-cost, and notifies the player. If the player now lacks the credit for the longer span, the reservation is cancelled with a full refund.
- **Detected after pickup** (`fulfilled`): roundup auto-shifts the return to the next open day and **the extra days are not charged** — roundup absorbs the calendar drift as an operational cost. The player is notified. The rule is: don't punish the player for feed volatility they didn't cause.

**Debit span start.** `debited_days` is computed as **calendar days from `pickup_deadline` to `return_date`** — both chosen by the borrower at request time (both must be open days; the return-date picker enforces this). This makes the day-cost knowable at request time (no waiting for an actual pickup event), which is what makes the immediate-debit and the "minimum available to reserve" gate computable. At fulfilment the actual checkout timestamp is recorded but the *debit* doesn't change; at return the actual span reconciles against `debited_days` (early/late per below).

**Reconciliation.**
- Clean early cancellation (>2h before pickup): held days released in full, no penalty.
- **No-show or late cancellation (within 2h of pickup): held days released, then a flat 1-calendar-day penalty deducted** (operational-overhead disincentive). The penalty is flat regardless of reservation length, so it is predictable and explainable.
- Fulfilment (`fulfilled`): no day-ledger change — the hold remains in `held_days` (the goods are now in the borrower's hands but the loan isn't complete; actual duration isn't known until return).
- Return (`returned`): held days convert to used days, counted as the **actual calendar-day span** (checkout → checkin). If actual < debited (early return), the difference is refunded to the interval's available balance. If actual > debited (late return), additional days are charged at the going rate and persistent lateness feeds §12.
- Early return (returned on an earlier open day than expected): unused calendar-day portion refunded.
- **Late return (returned after the expected return date):** additional calendar days charged at the going rate; persistent lateness feeds `library_incidents` and `borrower_standing` (§12). **Late return crossing a subscription-interval boundary:** the charge always posts to the **originating interval's** budget row (the `library_borrow_budgets` row whose `interval_ends_at` matches the reservation's interval, identified via the loan's `requested_at`), even if `returned_at` falls in a later interval. If that interval has already closed and carried out, the row is allowed to go negative (overdrawn); the deficit is deducted from the **next interval's grant before carry-in is applied** (i.e. `carried_in_days` is reduced by the overdraw, floored at 0, and any remaining deficit becomes an `exceptional_charge` debt per §12.1). This keeps each loan's accounting local to one ledger row and avoids cross-interval drift.

At interval end (boundary from `local_subscriptions`), remaining unused days carry to the next interval **up to a tier cap** (defaults: free 0 / mid 7 / top 14 days, admin-editable). The cap prevents the "bank 200 days then monopolize the library" pathology. Carry-over is a retention perk, not core; defaulting low tiers to zero is deliberate.

### 11.3 Tier policy

`MembershipType` gains dedicated library-policy columns: max concurrent copies, default loan duration, carry-over cap, and **Pfand floor amount** (the standing collateral bundled with the tier). These drive runtime rules at reservation time (e.g. Silver: 2 copies × 14 days, €40 floor; Gold: 5 × 30 days, €120 floor). The floor determines which borrows are cashless-routine (item value ≤ available floor) vs. which trigger a top-up capture (item value > available floor).

---

## 12. Risk management

### 12.1 Incidents

`library_incidents` records loss, damage, and lateness per copy and per borrower, with photo evidence and a resolution. Damage discovered during sanitization or check-in is attributed to the last borrower using the handover condition captures. **Damage-charge amount** is operator-entered (`damage_amount_cents`), capped at the copy's `replacement_value_cents`. **Charge ordering:** draw down the tier floor first; if the floor is exhausted, the active reservation's top-up Pfand covers the gap; if both are exhausted (loss of a high-value item), the remainder is billed as an `exceptional_charge`. **Enforcement:** an unsettled `exceptional_charge` row blocks the borrower from new reservations (a reservation-time gate checks for `status IN ('posted','refund_failed')` rows on the borrower's account — analogous to the standing gate but for debt, not points). This ordering keeps the floor as the routine shock-absorber and preserves tight collateral on high-value items.

### 12.2 Borrower standing

A **computed** signal from `library_incidents`, not a stored mutable flag (no standing table). Transparent, points-based, with recency decay, so it is explainable to players and auditable.

**Incident creation triggers** (when each type is written):
- `late_return` — auto-created by the return reconciler when `returned_at > return_date + 0 calendar days`.
- `minor_damage` / `major_damage` — created by the operator during check-in or sanitization when damage is found (severity picks minor vs major).
- `loss_nonreturn` — auto-created when the non-return grace period (`library_policy.non_return_grace_days`) elapses without return; copy → `retired_lost`, floor forfeited per §12.1.
- `no_show_repeat` — auto-created on the **2nd+** no-show/late-cancel within 90 days (1st is a warning notification only, no incident).
- `age_misrepresentation` — created by the §9.5 age-fail-at-desk flow.
- `other` — operator-created for discretionary issues.

Standing is recomputed on any new incident for that borrower.

**Score = Σ (incident_weight × recency_factor)** over all of a borrower's incidents, where:

| Incident type | Weight (default, admin-editable) |
|---|---|
| `late_return` | 1 |
| `minor_damage` (reparable) | 3 |
| `major_damage` (significant value loss) | 8 |
| `loss_nonreturn` | 20 |
| `no_show_repeat` (2nd+ within 90 days; 1st is warning-only, no incident) | 2 |
| `age_misrepresentation` (failed ID at desk) | 8 |
| `other` (operator-discretionary; weight = `severity`, 0–10) | `severity` value |

`recency_factor` is a **flat-then-linear** curve (not pure linear from day 0): **1.0 for the first `recency_full_days` (default 90) days, then linear decay from 1.0 at day 90 to 0.0 at `recency_zero_days` (default 365) days, and 0.0 thereafter.** Concretely, for an incident `d` days old: `recency_factor = d ≤ 90 ? 1.0 : (d ≥ 365 ? 0.0 : 1.0 − (d − 90) / (365 − 90))`. This makes the first 90 days full-weight (a recent incident counts fully), then tapers over the rest of the year, and expires entirely after a year. All thresholds are admin-editable in `library_policy`.

**Standing tiers (derived from score, thresholds admin-editable in `library_policy`):**

| Score | Standing | Runtime effect |
|---|---|---|
| 0–2 | Good | normal |
| 3–7 | Watch | none automatic; surfaced to operators |
| 8–15 | Restricted | higher Pfand required (top-up mandatory regardless of value tier) |
| > 15 | Suspended | new reservations blocked; in-flight reservations allowed to complete |

**Computation model:** derived on read from `library_incidents`, cached with a short TTL (recomputed on any new incident for that borrower). Surfaced to the player in their library dashboard with a plain-language breakdown ("Restricted: 2 incidents in the last 90 days"), so the gate is never opaque. Operators can see the breakdown in Filament; a manual override (e.g. to clear a disputed incident) is an incident resolution action, not a standing mutation — standing always reflects the incident record.

### 12.3 High-value waivers

Digital in-app by default: checkbox + versioned waiver text + IP + timestamp, stored in `library_waiver_acceptances`. Paper fallback for offline, flagged by the operator who retains the physical copy. No e-signature vendor — the digital record is legally sufficient for a community library and fully auditable.

---

## 13. Integration design

### 13.1 Inbound: Snipe-IT → roundup (projection)

1. **Webhook receiver (push, seconds latency).** Snipe-IT's Webhook Integration fires on checkout/checkin/update/delete. The receiver is signed/verified (shared secret or HMAC; IP allowlist fallback), updates `library_copies` keyed by `snipe_asset_id`, idempotent on event id.
2. **Reconciliation poller (pull, every 5 min).** Paged `GET /hardware?sort=updated_at&order=desc&limit=200` since last sync. Catches anything the webhook missed (webhooks are best-effort, notification-shaped). Detects deletes (assets in our projection but absent from Snipe-IT after a full sweep → marked `retired_lost` locally, never hard-deleted).
3. **Throttle-aware.** Respect Snipe-IT API throttling; back off on 429; page with cursor.

> **The webhook is coarse and notification-shaped. Always reconcile by poll. Never treat the webhook alone as source of truth.**

### 13.2 Outbound: roundup → Snipe-IT (catalog)

1. **Title sync** — on `GameSystem` create/update, push to the corresponding Asset Model and custom fields. Queued job.
2. **Location sync** — on `Location` create/update, push name + address only. **Custom-field encryption:** the fields roundup mirrors into Snipe-IT (`_bgg_id`, `_age_rating`, `_replacement_value`, `_min_players`, etc.) are operational metadata the sync layer must read plainly, so they are created **unencrypted**. Snipe-IT's field encryption is reserved for genuinely sensitive per-asset data (donor PII, acquisition notes), which roundup keeps on its own side anyway and does not mirror.
3. **Operator checkout (optional).** In the MVP, stewards use Snipe-IT's own UI to check out; roundup consumes the webhook. A Filament-driven checkout bridge is a later option if a unified operator UX is wanted.

### 13.3 Conflict and failure handling

- Sync lag is visible: each `library_copies` row carries `last_synced_at`; the player catalog shows "updated Xs ago" when stale; operators see a sync-health badge.
- Failed webhook → caught by poller. Failed poller → alert + last error persisted (phase, timestamp, retry count) for agent-first diagnosability.
- The player read path never blocks on Snipe-IT.

### 13.4 Spike-failure contingencies (Plan B/C)

S01 exists to retire three load-bearing integration assumptions. Each has a documented fallback so the design is not blocked if the assumption fails:

1. **Snipe-IT cannot route post-checkin to `Needs Cleaning` (the sanitization gate's foundation).**
   - *Plan B:* checkin lands in `Available` in Snipe-IT; roundup's projection derives the gate from `sanitized_at < last_checkin_at` and treats the copy as non-lendable until an operator action clears it. Operators action it from roundup's Filament sanitization queue, not Snipe-IT. Player-visible behavior is identical (dirty copy hidden).
   - *Plan C:* if even the timestamp heuristic is unreliable, roundup owns `physical_status` authoritatively for the check-in/Needs-Cleaning/Available segment and writes it back to Snipe-IT via the API after each transition (Snipe-IT becomes a display mirror for this segment rather than the source of truth). This narrows Snipe-IT's authoritative scope but preserves the invariant.
2. **Paddle cannot capture a floor "with the subscription" as a single flow** (cashier-paddle subscriptions are a single recurring price).
   - *Plan B:* the floor is a **separate one-off charge** at subscription activation (Paddle one-off transaction), tracked via webhook. Refund on cancellation is a one-off refund. This is within Paddle's normal one-off flow and refund windows; it just isn't bundled into the subscription line item.
   - *Plan C:* if even one-off-charge-then-long-hold is awkward, fall back to **per-reservation Pfand only** (the original D19-before-hybrid model) for MVP and treat the tier floor as a v1.1 enhancement. **Blast-radius note:** under Plan C the `floor_*` ledger row types and §12.1's "draw down the floor first" ordering become dead logic for MVP (revived only if/when the floor ships later); the top-up ledger and the rest of the design are unaffected.
3. **Webhook payload carries no usable event id / action type** (idempotency and routing can't key off the payload).
   - *Plan B:* the receiver treats the webhook as a **invalidation signal** ("something changed for asset X") and fetches the asset's current state via `GET /hardware/:id`, diffing against the projection. Idempotency is by `(asset_id, fetched_state_hash)` rather than by event id. Heavier per-event, but robust to any payload shape.
   - *Plan C:* if webhooks are too coarse even for invalidation, drop push entirely and rely on the 5-minute reconciliation poller alone (latency degrades from seconds to ≤5 min; acceptable for a physical-handover domain).

Any Plan B/C invocation is recorded as a decision in §18 and may adjust slice scope or estimates. The design does not assume all three assumptions hold; it assumes each has a workable fallback.

**Open-day calendar source — Plan B.** If the S01 spike finds no usable iCal/holiday-API feed for the library's region(s), S04 ships with **manual open-day configuration only** (operators enter open days and known holidays by hand via Filament, no external feed). This removes the return-date-picker automation and the holiday-drift handling (§11.2/D26) for MVP but keeps the rest of the day-ledger and reservation flow intact. External feed integration becomes a v1.1 enhancement. This is a scoped retreat, not a blocker.

---

## 14. Player-facing experience

The catalog lists copies grouped by `GameSystem`, filtered by `Location`, `physical_status = Available`, `condition`, player count, and complexity — reusing roundup's existing discovery filter patterns. Each copy shows availability, home location, and pickup options at upcoming events. A "Reserve" button authors a `library_reservations` row; no Snipe-IT call on the hot path.

**Freshness:** MVP is Livewire polling at 30–60s (a physical handover does not need sub-second freshness). A broadcast layer (Laravel Reverb) broadcasting `LibraryCopyUpdated` events is an optional later phase for instant badge updates. "Real-time" is built once either way — Snipe-IT does not deliver it out of the box.

---

## 15. Operator experience

- **Stewards use Snipe-IT's UI** for check-out/in, barcode scanning, bulk edits, and physical audits. This is the lowest-overhead, fastest-to-MVP choice and leverages a mature tool.
- **Filament provides oversight only** over the roundup-owned domain: sync health, reservation management, incident review, borrower standing, deposit reconciliation, sanitization queue, drop-off run lists.
- **Operator identity** mirrors to Snipe-IT Users with the roundup UUID as join key (`employee_num`). Start with local Snipe-IT accounts; SAML/SCIM SSO is a later hardening step if the operator pool grows.

---

## 16. Deployment

- **Snipe-IT as a Docker service** alongside roundup, with its own MySQL/MariaDB — separate from roundup's PostgreSQL. Two databases to back up (add to the backup runbook).
- **Internal network only; not exposed publicly.** Operators reach it via the staff auth boundary.
- **Secrets** (Snipe-IT API token, webhook secret) live in roundup's `.env` via the normal secrets flow, never committed.
- **Health check:** roundup pings Snipe-IT and reports sync lag on the operator dashboard.

---

## 17. Phase plan / delivery roadmap

MVP lands at the end of **S06**. The MVP definition: **a player can browse the catalog, reserve (with all gates — age, value-tier, budget, deposit), and complete a pickup/return; an operator can run the library end-to-end from Snipe-IT checkout/check-in plus a minimal Filament surface for reservation approval, sanitization queue, and deposit/incident oversight.** S05 delivers the lending logic and sanitization gate; S06 delivers the operator oversight surface that makes the library runnable. Splitting S05/S06 keeps each slice reviewable; the MVP is honestly at S06.

| Slice | Goal | Est. |
|---|---|---|
| **S01 — Build spike** | De-risk the integration contract: Dockerize Snipe-IT; seed an Asset Model + Assets; verify `checkout`/`checkin`/`bytag`; **capture the webhook payload**; measure throttling; confirm post-checkin status routing; go/no-go | 2–3 days |
| **S02 — Projection + inbound sync** | `library_copies`; Snipe-IT API client (bearer, throttled, paged); signed webhook receiver; 5-min reconciliation scheduler; sanitization status mirroring; sync-health persistence | ~1 week |
| **S03 — Title/location outbound sync** | `GameSystem` → Asset Model sync (incl. `age_rating`, `replacement_value` custom fields); `Location` → Location sync; custom fieldset provisioning; reconciliation for models/locations | ~4 days |
| **S04 — Player catalog + reservations + age gate + budgets** | Catalog page (Livewire, reusing discovery filters); `library_reservations` + state machine; **migrations for `User.birth_year` and `MembershipType` library-policy columns (this slice owns them — S05 depends on them)**; age-restriction gate; value-tiered completeness at browse; `library_borrow_budget` day ledger (**calendar-day unit**, **immediate debit at request**, **open-day return picker with live cost preview**, no-show penalty); **open-day calendar service (external holiday feeds, per-location)**; ship-ready schema fields | ~2 weeks |
| **S05 — Deposits + incidents + standing + carry-over + handover lookup + sanitization gate** | Hybrid Pfand: tier floor (captured with subscription) + per-reservation top-up for high-value; floor draw-down on damage; `library_incidents`; `borrower_standing` derivation (points + recency); carry-over rollover on interval end; **handover lookup endpoint** (the lookup + token mechanism; the *fulfilment wiring* that completes a handover via webhook is S06); waiver records; **mandatory sanitization gate (check-in → needs_cleaning → available)** | ~2.5 weeks |
| **S06 — Operator oversight + fulfilment wiring** | Filament oversight resource (sync health, reservations, incidents, standing, deposit ledger, sanitization queue, drop-off run lists); auto-fulfil-on-webhook; high-tier inspection/waiver flow | ~1 week |
| **S07 — Drop-off planning + audits + analytics** | Drop-off run lists (copy → Event/Location/date); Snipe-IT Audit integration for periodic condition; condition-at-handover proof; usage/dead-stock reports | ~1 week |
| **S08 — Hardening** | Operator SSO (optional); backup runbook; monitoring/alerts on sync lag; rate-limit/backoff; Reverb push (optional Phase 2) | ongoing |

---

## 18. Decision log

All decisions below are locked. Each was made deliberately during the design interview.

| # | Decision | Choice | Rationale |
|---|---|---|---|
| D1 | Off-the-shelf anchor | Snipe-IT sidecar | Only OSS with native lending primitives on a matching PHP/Laravel stack; documented for non-IT use |
| D2 | System boundary | Snipe-IT owns physical state; roundup owns everything else | Prevents drift; each field has one owner; players never become Snipe-IT users |
| D3 | Player identity in Snipe-IT | Never | Avoids PII leakage, account duplication, and scope creep |
| D4 | Reservation location | roundup only | Players aren't Snipe-IT users; reservations are authored and held in roundup |
| D5 | Operator UX | Stewards use Snipe-IT UI; roundup stays read-side + oversight | Lowest overhead, fastest MVP, leverages mature tool |
| D6 | Library scope | Single shared library, one Snipe-IT instance | Simplest ops; `Location` distinguishes physical sites |
| D7 | Fulfilment | Pickup now; schema ship-ready | Pickup covers community-library reality; nullable ship fields avoid future rewrite |
| D8 | Age data | `User.birth_year`, consent-gated | Sufficient for all rating systems; lower PII/GDPR exposure than DOB |
| D9 | Value tiers | Configurable; defaults < €40 / €40–150 / > €150-or-rare | Tunes with the collection without deploys |
| D10 | Completeness method | Value-tiered (binary → count → manifest) | Integrity effort scales with risk |
| D11 | Sanitization | Mandatory after every return; `Needs Cleaning` is a real status | Builds trust; uniform audit shape; player catalog never shows a dirty copy |
| D12 | Carry-over | Day budget, tiered caps (0/7/14 days) | Retention perk, not currency; cap prevents hoarding |
| D13 | Waiver | Digital in-app + paper fallback | Auditable; no vendor dependency; legally sufficient |
| D14 | Pfand provider | Paddle (already integrated) | No new vendor |
| D15 | State machines | Separate physical status (Snipe-IT) from derived lendability (roundup) | Avoids flat-enum collisions |
| D16 | Speculative reservations | Strict: only `Available` is reservable | Trivial to explain; no race conditions |
| D17 | Player freshness | 30–60s Livewire polling; Reverb optional later | Physical handover doesn't need sub-second freshness |
| D18 | Barcode | Snipe-IT auto `asset_tag` for MVP | Can adopt `{bgg_id}-{copy}` scheme later |
| D19 | Pfand model | Hybrid: tier floor (with subscription) + per-reservation top-up for high-value | Floor makes routine borrows cashless; top-up preserves tight collateral where risk concentrates; avoids the Paddle-fit, regulatory, and equity costs of a full subscription-bond |
| D20 | No-show / late-cancel penalty | Flat −1 lending day; Pfand released | Operational-overhead disincentive; keeps goods-protection (Pfand) and throughput-protection (days) cleanly separated |
| D21 | Borrow-day debit timing | Immediate at request | Counters parallel-request asset blocking — players self-limit to what their budget affords |
| D22 | Full subscription-bond (rejected) | Considered and rejected in favor of hybrid (D19) | Paddle doesn't model a long-held wallet; multi-year deposits exceed card-network refund windows; holding customer funds for subscription durations expands regulatory surface; large upfront deposit is an equity barrier off-brand for a community library |
| D23 | Borrow-day unit | Calendar days (not working days) | Charges reflect the days goods are actually in the borrower's hands; a closed library on Sunday doesn't make the game free on Sunday |
| D24 | Return-date constraint | Must be an open day (library open AND not a bank holiday from external feeds) | Returns only happen when the library is open; this is what makes weekend/holiday days non-avoidable in the calendar-day charge; requires external calendar-source integration |
| D25 | Mid-subscription tier change | Upgrade: floor delta captured immediately. Downgrade: floor deferred to next interval boundary | Unlocks more borrowing on upgrade now; avoids mid-loan payment friction on downgrade; access features follow new tier immediately, only the floor lags |
| D26 | Holiday-feed drift | Pre-pickup: shift + recompute + refund if credit short. Post-pickup: shift + extra days not charged (roundup absorbs drift) | Don't punish the player for feed volatility they didn't cause |
| D27 | Age-verification failure at desk | Cancel reservation with full refund + `library_incidents` row (type `age_misrepresentation`, weight 8) | Misrepresenting age for restricted material is a serious integrity violation with legal exposure; weight 8 → Restricted standing on first offense; self-corrects false `birth_year` |
| D28 | Manifest provenance | Operator-entered in roundup only; never mirrored to Snipe-IT | Component manifest is board-game-specific; Snipe-IT has no model for it; roundup is the catalog authority (§6) |

---

## 19. Open questions and future follow-ups

**Resolved during design** (recorded for traceability): age data, value tiers, sanitization cadence, carry-over caps, waiver execution, fulfilment model, library scope, operator UX, barcode scheme, player freshness, borrow-day unit (calendar days), open-day return constraint, external calendar sources, Pfand model (hybrid floor + top-up).

**Deliberately deferred follow-ups** (candidates for later milestones):

- **Title waitlists.** When all copies of a title are out, a hold queue. Reuses the existing `WaitlistService` capacity-limited-queue pattern.
- **Renewal flow.** Extend a current loan (distinct from subscription carry-over).
- **Usage analytics → acquisition.** Most-borrowed, never-borrowed (dead stock), turnaround time — to inform purchasing.
- **Shipping fulfilment.** Schema is ready; build the carrier/address path only if pickup proves insufficient.
- **Multi-region tenancy.** Independent regional pools via Snipe-IT's multi-tenancy or separate instances.
- **Speculative reservations against `Needs Cleaning` copies.** Allow only if ready before the pickup window; deferred to avoid predictive load and races.
- **Instant player push (Reverb).** When 30–60s freshness is no longer acceptable.
- **Operator SSO (SAML/SCIM).** When the operator pool outgrows local accounts.
- **Acquisition provenance and insurance valuation workflows.** Donated/purchased/sponsored tracking and write-off accounting beyond the `replacement_value_cents` field.
- **Piece-level component manifest tooling.** If shrinkage justifies escalation beyond the value-tiered defaults.

---

## 20. Appendix A — S01 build spike verification checklist

The spike exists to convert assumptions into a verified contract before the sync layer is built. It covers **both** integration surfaces: Snipe-IT (read/check-in-out) **and** Paddle (the hybrid Pfand mechanism).

**Snipe-IT:**

- [ ] Snipe-IT runs in Docker next to roundup; health endpoint reachable.
- [ ] Asset Model + Category + Fieldset + Status Labels created (via UI or seeder).
- [ ] `POST /hardware/:id/checkout` and `/checkin` succeed with a bearer token.
- [ ] `GET /hardware/bytag/:asset_tag` returns the asset (barcode lookup).
- [ ] **Webhook fires on checkout/checkin; payload captured and documented** (the key unknown — does it carry an event id / action type?).
- [ ] **Webhook signing confirmed**: does Snipe-IT sign webhooks (HMAC/shared secret)? If not, confirm the IP-allowlist fallback is workable.
- [ ] **Update/delete webhook payloads captured**: confirm webhooks fire on asset update/delete (not just checkout/checkin) and carry enough to detect deletions for the reconciliation poller.
- [ ] Post-checkin status routing confirmed: configurable non-default status (preferred, §13.4 Plan A) vs. roundup-derived `needs_cleaning` from `sanitized_at < last_checkin_at` (Plan B) vs. roundup-authoritative write-back (Plan C).
- [ ] Throttling limit measured; paging via `?limit=` confirmed.
- [ ] Custom-field encryption behaviour confirmed for the mirrored fields (expected: readable in plain via API; §13.2).

**Paddle (hybrid Pfand):**

- [ ] **Floor capture — Plan A (primary) vs Plan B**: can the floor be **bundled with the subscription** in a single Paddle checkout (Plan A, the design's stated mechanism), or only as a **separate one-off charge** at activation (Plan B)? Verify both; the design defaults to whichever the spike confirms. If only Plan B works, update §11.1/D19 to name Plan B as primary.
- [ ] **Top-up capture-at-approval verified**: a one-off charge captured at a non-subscription moment (reservation approval) via Paddle — confirm `held` state is achievable.
- [ ] **Partial refund verified**: can a captured top-up be *partially* refunded (damage path, `partial` status)? If not, Plan B is two transactions (full refund + new damage charge).
- [ ] **Forfeit / no-refund path verified**: confirm a captured charge can be left unredeemed (loss/non-return path, `forfeited` status).
- [ ] **Refund window measured**: how long after capture can a refund hit the original payment method? Determines whether floor refunds on subscription cancellation need a SEPA-credit fallback.

**Open-day calendar source:**

- [ ] **Holiday-feed sourceability**: identify and test at least one concrete iCal/.ics subscription or regional holiday API covering the library's region(s) (e.g. Germany, Romania). Confirm coverage, reliability, update cadence, and licence. This is a hard S04 dependency; a 30-minute feasibility check here de-risks the heaviest slice.

**Cross-cutting:**

- [ ] Operator login works (local account first; SAML/SCIM path noted for later).
- [ ] Go/no-go decision recorded, with any contract deviations and which Plan (A/B/C) applies for each of the three contingencies.

---

## 21. Appendix B — Snipe-IT capabilities relied upon

Verified against Snipe-IT v8.6.x documentation.

| Need | Snipe-IT feature |
|---|---|
| Check-out / check-in | `POST /hardware/:id/checkout`, `POST /hardware/:id/checkin` |
| Barcode lookup | `GET /hardware/bytag/:asset_tag` |
| Custom board-game attributes | Custom Fields + Fieldsets (CRUD via API, incl. associate/disassociate) |
| Physical sites | Locations (CRUD via API) |
| Condition / lifecycle state | Status Labels (custom: Available, Checked Out, Needs Cleaning, In Repair, Retired/Lost) |
| Periodic condition checks | Audits (`/hardware/:id/audit`, audit due/overdue) |
| Event notifications | Webhook Integration (coarse, notification-shaped — always reconcile by poll) |
| API access | REST + bearer token (personal access tokens), throttled, OpenAPI spec available |
| Operator auth | SAML, SCIM, LDAP/AD (local accounts sufficient to start) |

**Capability caveats that shaped the design:**

- The webhook is **coarse and notification-shaped**, not a full entity webhook. The reconciliation poller is mandatory, not optional.
- Custom fields can be **encrypted**, which affects how the API returns them and how they project.
- Snipe-IT has **no native expansion concept** — encoded via `_base_game_id` / `_is_expansion` custom fields, joined on `GameSystem.base_game_id`.
- Location mapping is **lossy** — geohash, proximity, and venue metadata stay in roundup; only name + address mirror.
- Post-checkin status routing is **unverified** and is the spike's job to confirm (see Appendix A).
