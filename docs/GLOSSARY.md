# Glossary — Canonical Terminology (EN · DE)

> **Status: Canon.** Ratified by D174, D175, D177 (2026-10-12); D176 and D179 constrain where these terms may appear. This document is the single source of truth for user-facing terminology in every surface: `lang/en` + `lang/de`, Blade/Livewire copy, transactional email, notifications, push, marketing/SEO pages, legal texts, and Filament admin (EN-only, same EN canon).
>
> It supersedes the audit draft (`.gsd/research/content-audit-2026-10/02-glossary-draft.md`) wherever a ratified decision ruled differently: **Game DE = Runde** (not Spielsitzung), **GM everywhere** (not the SL hybrid), **venue DE = Veranstaltungsort** (not Spielort), **Basisspiel** (not Grundspiel).

## How to use this glossary

- Every concept has **one canonical EN term and one canonical DE term**. Everything in the *Retired variants* column is banned in user-facing copy — see the consolidated [Global ban-list](#global-ban-list).
- Canonical **DE terms are review-enforced**: every copy change is checked against this glossary in review (copy PRs, Weblate review, the native DE review gate). Terminology is deliberately not machine-enforced — wording must be free to change in the locale files without breaking checks.
- The glossary is the **target state**. Where the live corpus still contains a retired variant, the DE pass (M066/S04) removes it; the corpus is not evidence against the canon.
- **Spelling rule:** EN *values* use British spelling ("cancelled"); translation *keys* may keep American spelling for DB-enum parity — see `docs/STYLE-GUIDE.md`.
- **German address form:** du everywhere, capitalized Du/Dein in direct address (D175). "Sie" is banned in all user-facing copy, including cookie consent and legal texts.
- Not every ruling below has its own D-number. Rows marked **[glossary ruling]** implement uncontested audit recommendations or corpus-dominance choices consistent with the D177 rationale (dominant term, correct German, minimal churn); they carry the same force as the D-numbered rows.

---

## 1. Core concepts

| Term (EN canonical) | Definition (user-facing) | DE canonical | Retired variants (ban-list) |
|---|---|---|---|
| **Game** | A single scheduled play date that players sign up for. | **Runde** | EN: "game session", standalone "session", "listing" as entity labels — "session" survives only as a qualifier inside campaign prose ("the next session of your campaign"). DE: **Sitzung** (committee-speak; banned everywhere except inside "Session Zero"), Spielsitzung, bare "Spiel" for the session sense (collides with the physical game), "Session" as the default label (loanword allowed **only** where precision genuinely requires it, e.g. disambiguating from a campaign or round count). |
| **Game System** | The catalog entry for a physical game — board game, card game, or RPG (BGG data). | **Spielsystem** | EN: "board game" as a type tab, bare "games" for the catalog. Never shorten to "game" — that collides with Game (the session). |
| **Campaign** | A recurring group of game sessions with a shared roster. | **Kampagne** | — |
| **Event** | A registered, ticketed get-together that hosts multiple tables. | **Event** | EN: "get-together" as the entity name (allowed only as descriptive flavor prose). DE: "Veranstaltung" as the entity label (allowed only as generic prose), "Get-Together", and "Treffen" — Treffen is reserved for Gathering. |
| **Table** | A game session hosted under an event umbrella. | **Tisch** | EN: "table" as a synonym for a standalone Game (e.g. "Upcoming Tables") — Table is event-scoped; the seat metaphor ("a seat at your table") is fine. |
| **Gathering** | A casual, multi-system game session, such as a board game night. | **Treffen** | DE: the untranslated "Gathering" loanword (3 known leaks). Note: **Spieleabend is not banned** — it is the example word: use Treffen as the type label and Spieleabend (e.g. "Offener Spieleabend") in examples and placeholders. |
| **One-shot** | A single, self-contained adventure session. | **One-Shot** | Casing drift: labels "One-Shot", prose "one-shot". |
| **TTRPG / Adventures** | The RPG side of discovery: TTRPG systems, campaigns, one-shots. | **TTRPG / Abenteuer** | EN: "Tabletop Adventures" as a tab or type name — type label is TTRPG, browse surface is Adventures, "tabletop adventures" survives only as a descriptive phrase. |
| **Game Day** | An event type: a day of open gaming. | **Spieltag** | — |
| **Convention** | An event/venue type for larger organized gatherings. | **Convention** | DE: "Konvention" (reads like a treaty; the German scene says Convention — Conn, RPC, Gen Con). |
| **Base game / Expansion** | The standalone game versus its add-on content. | **Basisspiel / Erweiterung** | DE: "Grundspiel" (audit option, not ratified); corpus typo "Basispiel" → fix to Basisspiel. |
| **Session Zero** | The pre-game conversation and survey that sets expectations and safety. | **Session Zero** | DE: translating it. "Session Zero" stays whole and untranslated — the lone exception that keeps the word "Session" in German copy. |
| **Complexity** | How heavy a game system is, 1–5 from Light to Heavy. | **Komplexität** | EN: "weight" as a separate user-facing label (that is BGG-internal vocabulary; values Light/Heavy are fine). |
| **Join / Apply / Register / RSVP** | The join-verb rule: **Join** for open games, **Apply** for protected games, **Register** for events, **RSVP** only in Discord contexts. | **Anmelden** (Join, event registration) · **Bewerben** (Apply) · **RSVP** (Discord) · **Registrieren** (account creation only — see ruling below) | EN: "sign up" on flow buttons (marketing prose may use it loosely). DE: using **Anmeldung** where **Bewerbung** is meant (protected-game applications — fix `games.php` misuse); Bewerbung outside the apply-to-join sense. **Registrierung/registrieren in join-flow senses** (joining a game or registering for an event — say Anmeldung). |

## 2. People & roles

| Term (EN canonical) | Definition (user-facing) | DE canonical | Retired variants (ban-list) |
|---|---|---|---|
| **Host** | The person who creates and runs a game or campaign. | **Host** | EN: "Owner" (the code role stays `owner` internally but is never surfaced), "organizer" for games/campaigns. DE: Gastgeber, Veranstalter, Organisator (when meaning the game host), Besitzer, Spieleigentümer, Ersteller; the verb "hosten" → use **leiten / ausrichten** (D174). |
| **Organizer** | The person or team running an event; also the umbrella marketing term ("For Organizers"). | **Organisator** [glossary ruling] | DE: "Veranstalter" (it caused the host/organizer blur — "Nachricht an den Veranstalter" meant *message to the host*; retired to keep Host and Organizer unambiguous). Rationale: corpus-dominant (51 uses), pairs intact with Co-Organisator, zero churn — the D177 dominance pattern. |
| **Co-organizer** | Someone delegated to manage an event's registrations and announcements. | **Co-Organisator** | — |
| **Game Master (GM)** | The host of a TTRPG session — public profile, ratings, and tools; spell out "Game Master" on first use, "GM" after. | **Game Master / GM** | DE: **Spielleiter, Spielleitung, SL** — banned everywhere (D174). This is a deliberate divergence from the German community default (see [Conventions mapping](#conventions-mapping-bgg--ttrpg--german-tabletop-norms)). Compounds are always hyphenated: GM-Verzeichnis, GM-Profil, GM-Arbeitsbereich — "GM Verzeichnis" (missing hyphen) is banned. |
| **Player** | Someone who joins games; the default participant role. | **Spieler** | — |
| **Participant** | Anyone on a roster, including waitlisted and benched people. | **Teilnehmende** | EN: "attendee", loose "player" in roster-management copy. DE: "Anmeldender" outside event-registration contexts. |
| **Registrant** | A person registered for an event (event-registration contexts only). | **Anmeldende** [glossary ruling] | EN: "attendee", "registered participants". |
| **Captain** | The leader of a Team (Teams lives behind login — D176). | **Kapitän** | — |
| **GM Profile / GM Directory** | The public GM profile with specializations, reviews, and social links; the browsable directory of GMs. | **GM-Profil / GM-Verzeichnis** | DE: "Game-Master-Profil", "GM Verzeichnis" (spacing), any SL-based rendering ("Spielleiter-…"). |

## 3. States & lifecycle

| Term (EN canonical) | Definition (user-facing) | DE canonical | Retired variants (ban-list) |
|---|---|---|---|
| **Approved / Pending / Rejected / Removed** | Application and roster states. | **Angenommen / Ausstehend / Abgelehnt / Entfernt** | DE: "Genehmigt" (anti-Amtsdeutsch — *genehmigen → annehmen*, see `docs/STYLE-GUIDE.md`; corpus value to fix in the DE pass). |
| **Waitlist / Waitlisted** | The ordered overflow queue with automatic promotion when seats free up. | **Warteliste / auf der Warteliste** | Promotion vocabulary: DE uses **nachrücken** (auto or manual); "befördern" is banned (D174). |
| **Bench / Benched** | The host-curated overflow pool; promoted manually, with no deadline. | **Ersatzbank / auf der Ersatzbank** | DE: "Bank", "Bankmodus", "auf der Bank" (ambiguous — a financial institution or a park bench). Bench Mode = **Ersatzbank-Modus**. Promotion off the bench also uses **nachrücken**. |
| **Scheduled / Completed / Cancelled** | Game and campaign lifecycle statuses. | **Geplant / Abgeschlossen / Abgesagt** | EN: "canceled" in values (values are British; keys may stay American — see `docs/STYLE-GUIDE.md`). |
| **Draft / Published** | The visibility lifecycle for events. | **Entwurf / Veröffentlicht** | — |
| **Registration Open / Closed** | Whether an event currently accepts new registrations. | **Anmeldung offen / geschlossen** | DE: "Registrierung". |
| **Signup Cutoff** | The time after which a game stops accepting signups. | **Anmeldeschluss** | — (The games-say-signup / events-say-registration split is deliberate and documented here; Anmeldeschluss covers both in DE.) |
| **Seat / spot** | "Seat" in demotion and roster copy ("your seat was reclaimed"); "spot" in availability copy ("a spot opened up"). | **Platz** | EN: mixing seat/spot freely. DE: "Platz" legitimately covers both — no split needed. |
| **Attended / No-show / Late Cancel / Excused / Cancelled Early** | Resolved attendance states feeding the reliability score. | **Teilgenommen / Nicht erschienen / Kurzfristig abgesagt / Entschuldigt / Frühzeitig abgesagt** | — (corpus is consistent; pinned here so it stays that way). |
| **Reliable / Active / Newcomer** | Reliability tiers. | **Zuverlässig / Aktiv / Neuling** | — |

## 4. Places

| Term (EN canonical) | Definition (user-facing) | DE canonical | Retired variants (ban-list) |
|---|---|---|---|
| **Venue** | A verified public place to play — café, game store, library. | **Veranstaltungsort** | DE: "Venue", "Spielort", "Location" (D177 — corrected corpus split is Veranstaltungsort 44 / Venue 4 / Spielort 1 / Location 0; the audit draft's Spielort preference was based on mixed-locale counts and is superseded). EN: —. |
| **Location** | Any place a session happens, including private addresses. | **Ort** | DE: the "Location" loanword. EN: "place" in UI labels ("Find a place to play" as prose is fine). |
| **City hub** | The curated landing page for one city's games, events, and venues. | **Stadt-Hub** | DE: "Städte-Hub" (plural grammar applied to a single city). |
| **Venue Directory** | The browsable list of venues. | **Veranstaltungsort-Verzeichnis** (page/SEO surfaces) · **Veranstaltungsorte** (nav label — see ruling below) | Any retired venue variant inside this label. |

## 5. Trust & safety

| Term (EN canonical) | Definition (user-facing) | DE canonical | Retired variants (ban-list) |
|---|---|---|---|
| **Player Reliability Score** | The attendance-based metric of how reliably you show up; expand fully on first use, "reliability" afterwards. | **Zuverlässlichkeitswert** [glossary ruling] | DE: Zuverlässigkeitsstatistiken, Zuverlässigkeitsbewertung, bare "Zuverlässigkeit" as the metric name. EN: "attendance rate" as a synonym. |
| **Attendance report / dispute / resolution** | The post-game consensus mechanism for who showed up. | **Anwesenheitsmeldung / Einspruch / Auflösung** | — |
| **Safety tools** | Structured practices for boundaries at the table. | **Safety-Tools** [glossary ruling] | DE: Sicherheitstools, Sicherheitswerkzeuge. The named techniques stay in English (see X-Card). |
| **X-Card** | Tap the card to pause or skip content — no explanation needed. | **X-Card** | DE: "X-Karte" (D174: the German scene keeps the name, consistent with Session Zero staying untranslated). |
| **Lines & Veils** | Hard limits (lines) and fade-to-black topics (veils). | **Lines & Veils** | DE: translating it. |
| **Script Change / Open Door / Breaks / Stars & Wishes** | The rest of the canonical safety toolkit. | kept in English | DE: translating them. |
| **Recap** | The host-written summary published after a session. | **Recap** | DE: "Zusammenfassung" as a label (fine as prose). |
| **Debriefing** | The structured post-game questionnaire for participants. | **Debriefing** | — (distinct from Recap: the host writes the recap; participants answer the debriefing). |
| **Host Updates** | Timeboxed posts from the host to participants. | **Host-Updates** | DE: "Spielleiter-Update" (SL leak — align with GM canon). The internal key name "bulletin" never surfaces. |
| **Connections-only** | Visibility setting: only people you follow and teammates can see it. | **Nur für Verbindungen** | The enum value "protected" must never surface in copy. |
| **Block / Report / Moderation** | Safety actions against users and content. | **Sperren / Melden / Moderation** | — |

## 6. Platform features

| Term (EN canonical) | Definition (user-facing) | DE canonical | Retired variants (ban-list) |
|---|---|---|---|
| **Discovery** | The browse/search surface for games, campaigns, and events. CTA = **Discover**, SEO = **Browse**, "find" only in prose. | **Entdecken** | EN: "Find …" as CTAs, "Browse" as a CTA. DE: Durchsuchen; "die Entdeckung" as a page noun ("Zurück zur Entdeckung" → "Zurück zu Entdecken"). |
| **Dashboard** | Your personal landing page after login. | **Dashboard** | — |
| **GM Workspace** | The GM's command center — sessions, reviews, charter health, link analytics. | **GM-Arbeitsbereich** | DE: unhyphenated "GM Arbeitsbereich", bare "Workspace". |
| **Campaign Charter** | The signable, versioned table agreement for campaigns: boundaries, safety tools, table policies, AI & digital-tools consent, and commitments — with private player answers. Optional by design; the safety core is free. | **Kampagnen-Charta** | EN: "Session Zero builder/survey" as a feature name (Session Zero stays valid as the safety *practice* in prose). DE: "(der) Charter", "Session-Zero-Builder". |
| **Plan (flow)** | The guided creation flow. | **Planen** | EN: "Plan" on create CTAs — create CTAs say **Create**; Plan belongs to planning-ahead nudges. |
| **Follow / Follower / Connections** | The lightweight social graph; "connections" is the umbrella term. Non-obvious: "friends" = people you follow. | **Follower / folgen / Verbindungen** | — (documented here so translators stop hunting for a "friend" feature that does not exist). |
| **Team** | A standalone group with captain, roster, and invites. | **Team** | — (Teams is behind login with no guest surface — D176; its esports-flavored vocabulary stays quarantined there.) |
| **Membership** | Paid supporter tiers that fund the platform; "subscription" only inside the billing portal. | **Mitgliedschaft** | EN: "subscription" in general copy. |
| **Share link / Invite** | Direct-join links and email/friend invites. | **Link / Einladung** | — |
| **RSVP** | Indicate that you will attend — Discord contexts only. | **RSVP** | EN/DE: RSVP outside Discord contexts. Discord's own vernacular ("My seat") stays; but Discord copy must still call a Game a Game (Runde), not an "event". |

---

## Conventions mapping (BGG · TTRPG · German tabletop norms)

Where the canon aligns with each community norm — and where it deliberately diverges.

| Concept | BGG norm | TTRPG norm | German tabletop norm | Canon | Verdict |
|---|---|---|---|---|---|
| Physical game | board game / expansion | — | Brettspiel / Erweiterung | Game System / Spielsystem | ✓ aligned |
| Heaviness | "weight" (avg 1–5) | — | Komplexität | Complexity / Komplexität, Light/Heavy values | ✓ acceptable; "weight" stays BGG-internal |
| Session host | — | GM / game master | **Spielleitung (SL)** strongly dominant | **GM / Game Master** | ⚠ deliberate divergence (D174): one loanword beats the corpus's 5-way split; product compounds hyphenate (GM-Verzeichnis) |
| The session itself | — | session / table ("my table") | **Runde / Spielrunde / Abend**; "Sitzung" = committee meeting | Game (EN) · **Runde** (DE) | ⚠ deliberate divergence on EN ("Game", code-aligned); DE follows community norm |
| Recurring play | — | campaign | Kampagne | Campaign / Kampagne | ✓ aligned |
| Single session | — | one-shot | One-Shot | One-shot / One-Shot | ✓ aligned |
| Pre-game talk | — | session zero | Session Zero kept | Session Zero | ✓ aligned |
| Safety toolkit | — | X-Card, Lines & Veils, Script Change, Stars & Wishes… | mostly kept in English | full canonical set kept in English | ✓ aligned (X-Card, not X-Karte) |
| Big gathering | — | convention / con | **Convention / Con** | Convention (EN + DE) | ⚠ DE diverges from "Konvention" — the loanword is the scene standard |
| Open play day | — | game day | Spieltag | Game Day / Spieltag | ✓ aligned |
| Board game night | — | game night | **Brettspielabend / Spieleabend** | Gathering = **Treffen** label + **Spieleabend** in examples | ⚠ explicit mapping (D177): Treffen names the type, Spieleabend paints the picture |
| Player group (the people) | — | group / party | **Spielerunde** | no platform term yet (Team is competitive) | noted for the future — do not coin one ad hoc in copy |
| Place to play | FLGS | venue / store / shop | Location / Spielort / Laden | Venue / **Veranstaltungsort** (D177) | ⚠ one word each; Location loanword retired |

## Decision log

| ID | Scope | What it settled for terminology |
|---|---|---|
| **D174** | content/i18n | The terminology canon itself: EN — Game, Game System, Campaign, Event (not get-together), Host + Organizer actor pair (Owner banned from user copy), Join/Apply/Register/RSVP rule, TTRPG. DE — **Runde**, **GM/Game Master everywhere** (SL/Spielleiter/Spielleitung banned), **Ersatzbank**, **nachrücken** (not befördern), **leiten/ausrichten** (not hosten), X-Card kept, **Basisspiel**, **Stadt-Hub**, **Convention**, Anmeldung vs Bewerbung allocation, du-form. Full table = this document. |
| **D175** | content/i18n | German address form: **du** everywhere (app UI, transactional email, cookie banner, moderation notices, legal texts); capitalized Du/Dein in direct address; **Sie banned** in user-facing copy. Voice mechanics live in `docs/STYLE-GUIDE.md`. |
| **D176** | product/ia | Teams demoted behind login, no guest surface, teams sitemap dropped. Terminology consequence: Team/Captain vocabulary stays quarantined in auth-only surfaces; guest copy never mentions Teams. |
| **D177** | content/i18n | Completes D174's open terms with corrected corpus data: DE venue = **Veranstaltungsort** (split 44/4/1/0 — Venue/Spielort/Location banned as DE venue terms); DE gathering = **Treffen** label + Spieleabend examples (untranslated "Gathering" banned). Supersedes the audit draft's Spielort preference, which was based on mixed-locale counts. |
| **D179** | content/guest | Public content policy v2 (supersedes D178). Terminology consequences: marketing copy references **shipped features only**; aspirational initiatives appear solely on "The Road Ahead" under "Future ideas", explicitly labeled as needing community feedback; "The Road Ahead" is the canonical roadmap-page name; standing content-freshness check at every milestone close includes re-checking this glossary against new terms. |
| **D243** | content/i18n | Campaign Charter canon (D179 freshness check applied to M070): EN **Campaign Charter**, DE **Kampagnen-Charta** (die Charta — M070 native-reviewed pass). "Session Zero builder/survey" banned as a feature name (Session Zero remains the safety *practice*); DE "(der) Charter" banned. |
| **D242** | charter | Charter notifications shipped (supersedes D234's deferral): publish + amendment fan out to approved participants via NotificationService after commit; charter_published/charter_amended categories, in-app+push+Discord defaults. D238's session-page card found already shipped — both discovery gaps closed. |
| **D180** | content/i18n | Sense-scoped the Registrierung ban and added the venue-nav ruling (2026-10-14, post-M066 review). **Registrieren/Registrierung is allowed for account creation** (auth CTAs, verification mail, invite copy) and the legal company-register sense (Impressum) — it stays banned for game/event join flows, which say Anmeldung. Rationale: forcing Anmeldung onto both login and signup produces two identical CTAs ("Anmelden"/"Anmelden"), an active UX harm; standard German splits Registrieren (account) from Anmelden (login/join). **Venue Directory nav label may use the bare plural Veranstaltungsorte** — the 29-char full compound wraps and breaks the one-row desktop nav at lg in DE; the page title and SEO surfaces keep the full compound. |

## Global ban-list

Consolidated. If a word appears below, it must not appear in user-facing copy (UI, email, notifications, push, marketing, legal) in that language.

**English:** Owner · get-together (as the entity name) · attendee · listing · "table" for a standalone game · "weight" as a label · "canceled" in values (keys may stay American) · "sign up" on flow buttons · RSVP outside Discord · entity/record/UUID jargon (see `docs/STYLE-GUIDE.md`).

**German:** Sitzung (everywhere except inside "Session Zero") · Spielleiter / Spielleitung / SL (including compounds like Spielleiter-Update) · hosten · befördern (promotion off waitlist/bench) · Bank / Bankmodus / "auf der Bank" (bench sense) · Venue / Spielort / Location (venue sense) · "Location" (location sense) · Städte-Hub · Konvention · Grundspiel · X-Karte · Sicherheitstools / Sicherheitswerkzeuge · Registrierung / registrieren (**join-flow senses only** — joining a game, registering for an event; allowed for account creation and the legal company register per D180) · Genehmigt (→ Angenommen) · "Gathering" (loanword) · Get-Together · Treffen (as Event) · Veranstaltung (as the entity label; generic prose only — the Event entity is **Event**) · Veranstalter · Gastgeber · Besitzer / Spieleigentümer / Ersteller · Organisator (when meaning the game host) · Spielsitzung · bare "Spiel"/"Spiele" for the session entity (Game = **Runde**) · Durchsuchen · "die Entdeckung" · bare "Workspace" · unhyphenated GM compounds ("GM Verzeichnis", "GM Arbeitsbereich") · Sie / Sie-form (D175) · translated safety-toolkit names (Lines & Veils, Script Change, Open Door, Breaks, Stars & Wishes, Debriefing stay in English).

## Maintenance

- **Amending the canon:** a new or changed term requires a recorded decision (extend D174/D177 or record a successor), an update to these tables and the ban-list, and an extension of the guard tests. Do not edit copy to coin a term ad hoc.
- **Standing freshness check:** every milestone close re-checks this glossary against newly shipped features (D179 §7).
- **Cross-references:** editorial voice and DE style mechanics live in `docs/STYLE-GUIDE.md`; contributor workflow in `lang/CONTRIBUTING_TRANSLATIONS.md`.
