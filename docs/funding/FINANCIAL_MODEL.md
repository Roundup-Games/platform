# Roundup Games — Non-Profit Financial Model

**Purpose:** A discussion document for early-collaborator and funder conversations about the viability of the
non-profit organisation behind roundup.games. It models seven financing streams plus a working-capital bridge
over five years, in three scenarios, with every figure grounded in research.

**Companion file:** `roundup-games-financial-model.xlsx` — an editable, formula-driven workbook. Change any
yellow input cell on the `Assumptions` or `Headcount` sheets and every model recalculates. The numbers below
are what that workbook produces from the default assumptions. The `Research & Benchmarks` sheet mirrors the
source list in §12 with live hyperlinks.

**Status:** Planning estimates for discussion only. Not audited, not a funding guarantee. Refresh with real
launch data as soon as it exists.

---

## 1. Executive summary

Roundup.games is a production-grade community platform for tabletop gaming (board games, TTRPGs, card games) —
already built, bilingual (EN/DE), DACH-focused, with payments, events, GM tooling, and a clear mission
("There's a seat waiting for you" — belonging, imaginative play, safety; not competition). The platform is the
non-profit's core programme.

The model covers **seven revenue streams**, **two early initiatives** (a Library of Things and a GM/organiser
certification programme) that add real-world infrastructure and a physical **community HQ**, a **headcount-driven
personnel** line (10 roles × 5 departments), and a dedicated **working-capital bridge** that makes the cumulative
funding need explicit rather than buried.

**The central finding:** with engineering investment locked in (1.5 FTE from Y2 — the platform is central to the
mission), personnel runs at ~50–62% of revenue in early years. This is normal for a service nonprofit, but it
means Base and Ambitious **cannot reach viability from earned revenue + project grants alone in Y1–Y3**. They
require an explicit **working-capital bridge** (capacity-building grants + a founder loan) drawn across the
launch years. The bridge is the honest fundraising target — modelled as its own line, not hidden in a deficit.

| 5-year outcome | Conservative | Base (Commercial Engine) | Ambitious |
|---|---:|---:|---:|
| Year-5 operating revenue | €97k | €625k | €1.79M |
| Year-5 net surplus (after bridge) | €2k | €35k | €432k |
| 5-year cumulative surplus | €46k | €48k | €539k |
| **Working-capital bridge required** | **€0** | **€170k** | **€115k** |
| Operating break-even (excl. bridge) | Y1 | **Y3** | Y3 |
| Year-5 total FTE | 0.65 | 4.20 | 7.70 |

Conservative needs no bridge (it stays volunteer-tiny). Base and Ambitious reach **operating break-even by Y3**
and turn genuinely surplus-generating by Y5; the bridge carries them through the launch years.

---

## 2. Vision & mission framing

- **Vision:** A world where everyone can find a seat at a table of imaginative, safe, in-person play near them.
- **Mission:** Build and steward the digital **and real-world** infrastructure that helps tabletop-gaming
  communities form, grow, and stay healthy — with belonging and safety as first principles.
- **Why a non-profit:** The platform's value is community trust and participation, not capture. A gemeinnützige
  structure aligns revenue with mission, unlocks grant funding, and signals to communities, GMs, and funders
  that the platform exists to serve them.

These framings determine eligibility for most of the grant funding in the model (civic participation, social
cohesion, inclusion, cultural participation) and for the real-world programmes (library access, certification,
community events) the mission implies.

---

## 3. The seven revenue streams

### Stream 1 — Commission on paid seats
When organisers charge for seats (event registrations, paid game sessions), the platform takes a percentage.
Paid seats × average seat price × commission rate. The model uses 6% rising to 13% (Base) as volume and value
grow. Paddle's ~3–5% processing fee is a separate direct cost.

### Stream 2 — GM membership subscriptions
A paid tier for Game Masters (workspace, session-zero builder, professional profile, reviews). Paying GMs ×
monthly price × 12. Priced as professional tooling: €7.99 rising to €14.99 (Base). Free-tier GM access is
preserved so the mission (more tables, more GMs) isn't gated.

### Stream 3 — Branded events (community)
Roundup.games-organised community events — game days, mini-conventions. Events × attendance × ticket price,
less per-attendee cost. Small community events net ~20–40% after direct costs. These are also mission activities.

### Stream 4 — Library of things (borrowing tier)
A physical library at the community HQ where subscribed members borrow game systems, figurines, and
paraphernalia. Library-tier members × monthly tier fee × 12. **Inventory is sourced via partnerships with
publishers (demo copies / overstock), merchants (discounts), and players (donations) — not bought at retail**
(cuts the inventory line ~70%). The lending platform is **built in-house** (dev cost in Personnel; no licence
fee). The research is explicit that lending revenue alone doesn't cover early costs — hence the bridge.

### Stream 5 — Certification program (GMs and organisers)
Certification opens closer collaboration with roundup.games: custom events for private/business clients, plus
marketing and social-graph opportunities. New certifications × certification fee (€99 rising to €289).
Curriculum development is front-loaded; per-certificate assessment €25–€55.

### Stream 6 — B2B events (commission on certified-GM bookings)
The commercial payoff of certification. Corporate clients book curated tabletop team-building delivered by
certified GMs; roundup takes a matchmaking commission. B2B events × attendance × price × commission (10–20%).
High-margin marketplace revenue with near-zero direct cost.

### Stream 7 — Grants & donations
**Grants:** EU (CERV €75k–€500k+, 90% funded, transnational; Creative Europe up to €500k; Erasmus+) and German
(Aktion Mensch, TV Lottery, state Stiftungen, ESF+ — €2k–€50k, faster). **Donations:** Germany averages ~€415/
donor/year (median ~€300); only 46–49% donate (low globally); 35% would give more with greater transparency.

---

## 4. The shared-space model

The Library of Things and the certification/B2B activities **share one physical community HQ per city**. Space
is modelled once (rent + utilities + fit-out), not duplicated per initiative. One HQ serves four revenue uses:
lending library, branded events (the HQ is the venue), certification training, and B2B events. Sharing one
space across four uses is what keeps the real-world programme affordable. Locations scale 0→8 (Ambitious).

---

## 5. Personnel & organisation (headcount-driven)

Personnel is built bottom-up from a **role × department × FTE** matrix on the `Headcount` sheet, each role priced
at DACH all-in annual cost (gross × ~1.22 *Arbeitgeberanteil*). The `Personnel` line is a live `SUMPRODUCT`.

### Salary benchmarks used (gross/year, Germany 2025–26)
| Role | Gross band | All-in/FTE |
|---|---|---:|
| Lead / Senior Engineer (Laravel) | €70k–€90k | €90,000 |
| Mid Engineer (Laravel) | €50k–€65k | €65,000 |
| Community Manager (nonprofit) | €42k–€58k | €50,000 |
| HQ / Space Coordinator | €34k–€45k | €42,000 |
| Event Coordinator | €37k–€48k | €45,000 |
| Fundraising / Grants Lead | €45k–€60k | €60,000 |
| Partnerships Manager | €45k–€60k | €60,000 |
| Library Coordinator | €33k–€42k | €40,000 |
| Certification Lead | €42k–€55k | €55,000 |
| Finance / Office Manager | €37k–€48k | €45,000 |

### The volunteer boundary
Paid staff cover **only accountability/continuity roles**. Everything else is volunteer-staffed with paid
oversight — this is how Libraries of Things stay viable per the research:
- **Events:** setup/teardown, registration desk, game librarians — volunteer-staffed.
- **Library counter:** 2–4 regular volunteers per location, coordinated by the HQ Coordinator.
- **Content:** EN/DE translation, moderation, documentation — volunteer-contributed.
- **Engineering:** the platform is built; non-critical work takes open-source-style contributions.

### Finance/Admin boundary
Bookkeeping stays **outsourced** (in the Legal line) until Base Y4 / Ambitious Y3, when an in-house hire is
cheaper than external services.

### Engineering is locked
Engineering FTE (Lead + Mid) is treated as fixed across scenarios because the platform is the mission's core —
it is not a lever to cut. In Base this is 1.5 FTE from Y2 (~€122.5k/yr ≈ 60% of Y3 revenue), which is the single
reason a bridge is unavoidable (see §6).

---

## 6. The working-capital bridge

A dedicated revenue line — **distinct from operating/project grants** — that covers the cumulative funding gap
while commercial revenue scales. It makes the launch-years funding need **explicit and visible as a fundraising
target**, rather than a buried deficit.

**What it is:** capacity-building grants (non-repayable) **plus** a founder loan (repayable from Y5+ surplus).
**When drawn:** Y1–Y4, tapering to €0 by Y5 when the org is self-sustaining.

| Bridge draw (EUR) | Y1 | Y2 | Y3 | Y4 | Y5 | **Total** |
|---|---:|---:|---:|---:|---:|---:|
| Conservative | 0 | 0 | 0 | 0 | 0 | **€0** |
| Base | 50,000 | 80,000 | 10,000 | 30,000 | 0 | **€170,000** |
| Ambitious | 45,000 | 70,000 | 0 | 0 | 0 | **€115,000** |

**Why a line and not just bigger grants?** Project/program grants are restricted to specific activities and
recognised over the project period. The bridge is **unrestricted working capital** — it pays the salaries and
rent that restricted grants can't, precisely when the org needs flexibility. Separating it also keeps the
**operating result visible**: the memo line *"Operating result excl. bridge"* shows the underlying business
honestly (loss-making Y1–Y2, break-even Y3, surplus Y5), while the bridge line shows how that gap is funded.

**Conservative needs none** because it stays at ≤0.65 FTE — lean enough that project grants + donations cover
everything.

---

## 7. Why Y4 needs a bigger bridge draw (the smoothing explained)

In Base, the bridge draw at **Y4 (€30k) is larger than Y3 (€10k)** — and larger than the Y4 operating deficit
alone (€13.4k). Two compounding effects hit simultaneously that year:

1. **The operating dip.** Personnel steps up from €223k (Y3) to €260k (Y4) — the Partnerships, Library, and
   Certification roles each take on more hours as the initiatives scale. Commercial revenue grows, but not
   enough in a single year to absorb a €37k personnel jump. The result is a €13.4k operating deficit (worse
   than Y3's €3.8k).

2. **The first year of corporation tax.** By Y4, commercial revenue (commission + GM + events + library +
   certification + B2B) has scaled enough that the commercial **surplus crosses the €45,000/year exemption**.
   The illustrative gemeinnützig tax provision (~15% of commercial surplus above €45k) therefore kicks in for
   the first time — adding ~€13k of tax in Y4.

So Y4's total cash gap is ~€26k (€13.4k operating + €13k tax), which the €30k bridge draw covers (with a small
buffer). **The bridge is the instrument that smooths this** — without it, Y4 would post a €26k deficit and the
cumulative position would deteriorate just as the org is supposed to be reaching escape velocity.

> **Note on the tax assumption:** the model treats all commercial revenue uniformly for the €45k exemption. In
> practice, membership fees and purpose-serving activities (library, certification, likely events) may qualify
> as **Zweckbetrieb** (fully exempt), which would substantially reduce or eliminate this Y4 tax. That is an
> upside not modelled — confirm Zweckbetrieb classification with a Steuerberater. If it holds, the Y4 bridge
> draw shrinks toward €15k.

---

## 8. Scenarios & the Base path

The Base scenario uses the **Commercial Engine** path: engineering locked, commercial levers (commission rate,
GM pricing, B2B volume, certification fees) pushed harder than the other scenarios, grants peaking at Y3 then
tapering as earned revenue takes over, and a mid-weight team (not the full programme staff from Y1).

| Driver (Year 1 / Year 5) | Conservative | Base | Ambitious |
|---|---|---|---|
| Monthly active users | 300 / 3,000 | 1,200 / 50,000 | 3,000 / 120,000 |
| Commission take rate | 6% → 8% | 9% → 13% | 8% → 11% |
| B2B events/yr | 0 / 10 | 6 / 150 | 6 / 200 |
| Community HQ locations | 0 / 2 | 1 / 5 | 1 / 8 |
| Grants/year | €15k / €60k | €55k / €160k | €80k / €420k |
| Personnel/year | €6k / €38k | €77k / €306k | €87k / €477k |
| Total FTE (Y1 / Y5) | 0.1 / 0.65 | 1.1 / 4.2 | 1.35 / 7.7 |

**Conservative** — lean volunteer-led, DACH-only, ≤0.65 FTE, net-positive throughout, no bridge.
**Base (Commercial Engine)** — regional platform, HQ from Y1, commercial streams pushed, grants taper after Y3,
€170k bridge. Operating break-even at Y3.
**Ambitious** — pan-European, multiple HQs, large EU grant wins, €115k bridge Y1–Y2 only, self-sustaining from Y3.

---

## 9. Five-year projection results

### Conservative (no bridge required)
| EUR | Y1 | Y2 | Y3 | Y4 | Y5 |
|---|---:|---:|---:|---:|---:|
| Operating revenue | 17,573 | 32,739 | 54,301 | 73,837 | 96,935 |
| Bridge drawn | 0 | 0 | 0 | 0 | 0 |
| Net surplus | 4,452 | 250 | 18,778 | 20,385 | 2,365 |
| Cumulative | 4,452 | 4,701 | 23,479 | 43,864 | 46,228 |

### Base — Commercial Engine (€170k bridge)
| EUR | Y1 | Y2 | Y3 | Y4 | Y5 |
|---|---:|---:|---:|---:|---:|
| Operating revenue | 69,945 | 155,089 | 295,306 | 388,961 | 625,253 |
| Bridge drawn | 50,000 | 80,000 | 10,000 | 30,000 | 0 |
| **Operating result excl. bridge** | −48,319 | −76,359 | −3,807 | −13,442 | +72,906 |
| **Net (after bridge)** | +1,681 | +3,641 | +4,184 | +3,544 | +35,435 |
| Cumulative | 1,681 | 5,322 | 9,506 | 13,051 | 48,485 |

The underlying business (excl. bridge) reaches operating break-even at **Y3** (−€3.8k ≈ break-even) and turns
genuinely surplus at Y5 (+€72.9k). The bridge carries Y1–Y4; Y5 needs none.

### Ambitious (€115k bridge, Y1–Y2 only)
| EUR | Y1 | Y2 | Y3 | Y4 | Y5 |
|---|---:|---:|---:|---:|---:|
| Operating revenue | 112,685 | 257,248 | 519,824 | 949,164 | 1,786,854 |
| Bridge drawn | 45,000 | 70,000 | 0 | 0 | 0 |
| **Operating result excl. bridge** | −40,728 | −66,576 | +34,429 | +126,059 | +545,570 |
| **Net (after bridge)** | +4,272 | +2,160 | +18,975 | +81,650 | +431,590 |
| Cumulative | 4,272 | 6,432 | 25,407 | 107,057 | 538,647 |

Ambitious becomes self-sustaining from Y3 and produces a €432k surplus by Y5.

---

## 10. Viability assessment & sensitivities

**The model is viable in all three scenarios.** Conservative is net-positive every year with no bridge. Base and
Ambitious reach operating break-even by Y3 and turn surplus-generating by Y5; the bridge carries the launch
years. The honest read on risk:

1. **Engineering is the structural constraint.** At 1.5 FTE (€122.5k/yr ≈ 60% of Y3 Base revenue), no amount of
   cost-cutting elsewhere reaches Y3 viability from earned revenue alone — the floor analysis shows ~€146k of
   grants are unavoidable at Y3 with this engineering team. The bridge exists because this is acknowledged, not
   hidden.
2. **The bridge is a fundraising target, not magic money.** Base's €170k must actually be raised (capacity
   grants + founder loan). Treat it as the Y1–Y2 fundraising headline.
3. **Grant timing is the dominant risk.** A delayed EU grant in Y2 forces a larger bridge draw. Mitigation:
   several smaller German foundation grants (€2k–€50k) in parallel, not one large EU grant.
4. **The community HQ is the second-largest swing factor** after personnel. Locations, rent, fit-out are the
   editable levers; sharing one space across four revenue uses keeps it affordable.
5. **Personnel runs ~50–62% of revenue early, settling to ~40–49% by Y5** — normal for a service nonprofit. The
   Headcount sheet makes every role editable; sliding any FTE down directly extends runway.

**Sensitivity to test live:** on the Assumptions sheet, set the `bridge` row to €0 across all years. Base
collapses to a €142k cumulative deficit by Y4 — that single edit shows exactly what the bridge is buying.

---

## 11. Key decisions to resolve with collaborators

1. **Legal form: gemeinnütziger e.V. vs. gGmbH.** gGmbH is cleaner for the commercial activity (commission,
   B2B, certification) and HQ liability; e.V. is lighter and membership-governed. Both keep purpose-serving
   revenue tax-exempt. **Confirm Zweckbetrieb classification** for library/certification/events — if it holds,
   the Y4 tax (and thus the Y4 bridge draw) shrinks materially.
2. **Bridge composition.** What split of the €170k (Base) is capacity grants (non-repayable) vs. founder loan
   (repayable from Y5 surplus)? Who provides/recommends the capacity grants?
3. **First-HQ city and venue model.** Which DACH city? Leased space, or in-kind partnership with a library /
   community centre / maker-space (how most sustainable LoTs start)?
4. **Publisher/merchant partnership pipeline.** Who secures in-kind game donations and merchant discounts, and
   by when? This sets the inventory line and is a fundraising ask as much as an ops one.
5. **Year-1 grant pipeline.** 1–2 German foundation grants (€5k–€30k) + one EU civic/participation grant in
   progress, framed as "community infrastructure."
6. **Entity-platform relationship.** The model assumes the non-profit operates the platform and real-world
   programmes under one roof.

---

## 12. Sources

Every figure is traceable to a linked source. (Links verified at time of writing; if a page moves, the named
organisation + figure is the search key.) Full hyperlinks live on the `Research & Benchmarks` sheet.

### Salaries & staffing (DACH)
- Laravel-Entwickler — Laravel salary guide (Germany: junior €30–45k, mid €50–65k, senior €70–90k) — https://www.laravel-entwickler.de/en/laravel-developer-salary-a-comprehensive-guide/
- Glassdoor — PHP Laravel Developer salary Germany (avg €67,000) — https://www.glassdoor.com/Salaries/germany-laravel-php-developer-salary-SRCH_IL.0,7_IN96_KO8,29.htm
- GermanTechJobs — Laravel salary Germany (avg €58,000) — https://germantechjobs.de/en/salaries/Laravel/all/all
- PayScale — Program Manager, Non-Profit (Germany, avg €49,974) — https://www.payscale.com/research/DE/Job=Program_Manager%2C_Non-Profit_Organization/Salary
- SalaryExpert — Non-Profit Director (Germany, avg €42,646) — https://www.salaryexpert.com/salary/job/non-profit-director/germany
- SalaryExplorer — Fundraising & Non-Profit (Germany) — http://www.salaryexplorer.com/salary-survey.php?loc=81&loctype=1&job=5&jobtype=1
- EngageAnywhere — Employee costs in Germany (Arbeitgeberanteil ~21–23%) — https://engageanywhere.com/blog/calculating-employee-costs-in-germany-step-by-step/
- FMC Group — Total cost of employment in Germany (20–26% above gross) — https://fmcgroup.com/employment-cost-germany/

### German donations & giving culture
- IW Köln — "Fast jeder zweite Deutsche spendet" (€415/donor/yr avg) — https://www.iwkoeln.de/studien/dominik-h-enste-jennifer-potthoff-fast-jeder-zweite-deutsche-spendet.html
- DIW SOEPpapers — "Spenden in Deutschland" — https://www.diw.de/documents/publikationen/73/diw_01.c.738864.de/diw_sp1074.pdf
- World Giving Report / CAF + Maecenata (46–49% donate; transparency lever) — https://www.maecenata.eu/2026/06/03/world-giving-report-2025-deutschland-spendet-weiterhin-unterdurchschnittlich
- DZI — Spendenstatistik — https://www.dzi.de/spendenberatung/spendenauskunfte-und-information/spendenstatistik/

### Recurring giving benchmarks
- Neon One — Recurring Giving Statistics 2026 (~$938/recurring donor/yr) — https://neonone.com/resources/blog/recurring-giving-statistics/
- Qgiv — Fundraising Statistics — https://www.qgiv.com/blog/fundraising-statistics/

### EU grants
- CERV Programme 2026 — guide (€75k–€500k+; 90% funded) — https://global-disruption.com/eu-calls/cerv/index.html
- EUFundingPortal — CERV calls — https://eufundingportal.eu/programme/cerv/
- Creative Europe — calls (up to €500k) — https://eufundingportal.eu/programme/creative-europe/
- Culture Action Europe — next Creative Europe / AgoraEU — https://cultureactioneurope.org/news/proposed-e8-6-billion-for-culture-and-democracy-in-the-next-eu-budget/
- Erasmus+ — overview — https://eufundingportal.eu/erasmus-plus/

### German foundations & programmes
- Aktion Mensch — Förderung — https://www.aktion-mensch.de/foerderung
- reflecta Fördermittelkompass — Social Support Germany (€2k–€50k) — https://foerdermittelkompass.reflecta.org/foerderungen/soziales-foerderungen-deutschland?locale=en
- Bund.de Förderdatenbank — https://www.foerderdatenbank.de/

### German non-profit / tax law
- Council on Foundations — Nonprofit Law in Germany (§5(1) Nr.9 KStG; Zweckbetrieb 7% VAT) — https://cof.org/content/nonprofit-law-germany
- GermanCompanyFormation — Non-Profits in Germany (commercial surplus taxed above €45,000/yr) — https://germancompanyformation.com/guides/what-is-non-profit-organizations-in-germany
- Liesegang Partner — Tax-Exempt Status for NPOs (§52 AO) — https://www.liesegang-partner.com/knowhow/corporate-law/translate-to-englisch-unlocking-tax-benefits-how-non-profits-in-germany-can-achieve-tax-exempt-status

### Library of things
- Share Shed — Setting Up & Sustaining a LoT (membership < full costs early; inventory often donated) — https://www.shareshed.org.uk/wp-content/uploads/2024/12/Setting-Up-and-Sustaining-a-Library-of-Things-FINAL-2.pdf
- Shareable — How to start a Library of Things — https://www.shareable.net/how-to/how-to-start-a-library-of-things/
- Shareable — LoT Toolkit (myTurn ~€2,700/yr; build-vs-buy reference — roundup builds its own) — https://shareable.net/library-of-things-toolkit

### Space & inventory capex
- FinancialModelExcel — Indie board game pub startup (library $10k–$30k retail) — https://financialmodelexcel.com/blogs/cost-open/indie-board-game-pub
- StartupFinancialProjection — Board game cafe — https://startupfinancialprojection.com/blogs/capex/board-game-cafe

### Corporate / B2B events
- TeamBonding — Team building cost ($45–$60/person) — https://www.teambonding.com/cost/
- Leaders Institute — Corporate team building cost ($35–$75/person) — https://www.leadersinstitute.com/get-a-price/
- IRL Game Shop — Corporate team building ($20–$25/person board-game) — https://www.irlgameshop.com/service/corporate-team-building-events/

### Tabletop grants
- TTGDA — Scholarships list — https://www.ttgda.org/scholarships
- Washington State Library — TTRPG Innovation Grants — https://www.sos.wa.gov/about-office/news/2025/libraries-washington-apply-now-tabletop-gaming-material-grants
- ALA Game On! Grants 2025 — https://games.ala.org/game-on-grant-applications-open-for-2025/
- Gen Con — Participation Grants — https://www.gencon.com/gen-con-indy/participation-grants

### Event economics
- Dataintelo — Fan Conventions Market Report 2034 (revenue mix) — https://dataintelo.com/report/fan-conventions-market
