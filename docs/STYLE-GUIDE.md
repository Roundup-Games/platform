# Style Guide — Editorial Voice (EN) · Deutscher Stil (DE)

> **Status: Canon.** Voice and style mechanics ratified by D174 and D175 (2026-10-12; D175 extends the original du-form decision D131). Terminology — *which word means what* — lives in [`docs/GLOSSARY.md`](GLOSSARY.md). This document governs *how we write*: voice, register, typography, and format mechanics, in every surface — `lang/en` + `lang/de`, Blade/Livewire copy, transactional email, notifications, push, marketing/SEO pages, legal texts, and Filament admin (EN-only, same EN voice).
>
> Workflow (files, keys, parity, Weblate) lives in [`lang/CONTRIBUTING_TRANSLATIONS.md`](../lang/CONTRIBUTING_TRANSLATIONS.md).

## How to use this guide

- Two chapters: **Chapter 1** is binding for all English copy (the source voice). **Chapter 2** is binding for all German copy (including legal texts and the cookie banner). Weblate translators: Chapter 2 is your review checklist.
- **Division of labor:** GLOSSARY.md decides the nouns (Game/Runde, Host/GM, venue/Veranstaltungsort …) and the ban-list. This guide decides the sentences around them. When a rule here and a term there interact (e.g. contracted imperatives around the canonical *Anmelden*), both apply.
- This guide is the **target state**. Where the live corpus still violates a rule, the EN unification pass (M066/S03) and the DE pass (M066/S04) fix it; the corpus is not evidence against the canon.
- Rules marked **[style ruling]** were settled at guide level (corpus-dominant or uncontested), like the glossary's *[glossary ruling]* rows; they carry the same force as D-numbered rules.
- **Enforcement:** terminology is review-enforced — every copy PR and Weblate review reads this guide and the glossary ban-list first (deliberately not machine-grepped, so wording can change freely); placeholder parity is CI-checked (`i18n:check`).

---

# Chapter 1 — English: the roundup voice

## 1.1 The voice

Warm, plain-spoken, knowledgeable — the friend at the table who knows the scene and is happy you came.

- **Write like you talk.** Short sentences. Concrete nouns. A two-word shrug beats a formal sentence: "Your call." — not "The decision rests with you."
- **Address the reader as "you."** "We" is the platform speaking (apologies, policies, "We see you there").
- **Specific beats generic.** "Runde für Runde" energy in EN: "one session at a time", "a seat at your table". No filler ("vibrant", "seamless", "exciting") where a fact will do.
- **Warmth in the small moments.** Empty states, confirmations, and errors get the same care as heroes: "Check back soon for new adventures!", "No dues, no commitments, no pressure."

## 1.2 Sentence mechanics

- **Verb-first.** Lead with the action: "Join a game night", "Write a recap", "Try adjusting your filters." Instructions and CTAs start with the verb, never with the gerund or the noun ("Joining a game night" ✗).
- **Contractions on.** Use *you're, we're, that's, how'd, you'll*. Stiff copy reads as a stranger: "How'd :game go? Write a recap" ✓ — "How did :game go? Please write a recap" ✗.
- **Sentence case everywhere UI copy appears** — CTAs, buttons, labels, headings, table headers: "Create event", not "Create Event" **[style ruling; target state — corpus title-case gets swept in S03]**. Proper nouns keep their caps ("Join the Discord server").
- **Questions read like speech.** "Changed your mind?", "You're all set for :event!"

## 1.3 CTA conventions

- One CTA = one verb, sentence case, verb-first. "Join waitlist", "Apply to join", "Register for event".
- **Canonical CTA verbs** (terminology, per GLOSSARY §1):

  | Action | CTA verb | Never on flow buttons |
  |---|---|---|
  | Join an open game | **Join** | "sign up" (marketing prose may use it loosely) |
  | Join a protected game | **Apply** / "Apply to join" | "sign up", bare "Request" |
  | Register for an event | **Register** | "sign up" |
  | Create something | **Create** ("Create game", "Create event") | "Plan" (reserved for planning-ahead nudges), "Add new" |
  | Browse/search | **Discover** | "Find …" as CTA, "Browse" as CTA (SEO titles use Browse; "find" lives in prose) |
  | Discord attendance | **RSVP** (Discord contexts only) | RSVP anywhere else |

- Ellipsis in placeholder CTAs and hints ("Any special requests, dietary requirements, or notes for the organizer…") — use the … character, never three dots.

## 1.4 Spelling: British values, American keys

- **Values (user-facing strings) use British spelling:** *cancelled*, *colour*, *favourite*, *organise*, *travelled*. The attested corpus fix is **cancelled** — live values say "Game canceled successfully." and become "Cancelled" in the S03 sweep.
- **Keys keep American spelling** where they mirror DB enums or long-standing key names — key renames break parity and references for zero user value: `activity_type_game_canceled`, `content_gm_subscription_canceled` keep their spelling while their values go British.
- "Organizer" is the canonical platform noun (GLOSSARY §2), not an *-ize* spelling choice — it stays "Organizer" in all locales' EN values.
- EN values never Germanize: no ß, no umlauts except in proper nouns (Düsseldorf), no „…“ quotes — English copy uses straight or curly English quotes ("…") and — for dashes (§2.6 rules the corpus-wide dash style; EN follows the em-dash half).

## 1.5 Banned jargon — never surfaced to users

These words never appear in user-facing EN copy (UI, email, notifications, push, marketing, legal):

- **"entity"** — say the thing: Game, Campaign, Team, Event, Venue (whichever the glossary noun is).
- **"record"** — same. "Your registration was removed", not "Your record was removed".
- **"UUID" / raw IDs** — never. Names and titles only; IDs are for logs and support.
- **Enum value names** — "protected" is a visibility enum value, not copy ("connections-only" is the user-facing label); "bulletin" is an internal key name, the surface says "Host update".
- **Developer shorthand** — "persist", "fetch", "payload", "invalid input" in prose. Errors say what happened and what to do next: "Too many attendance reports sent. Please try again in a minute."
- The only sanctioned exception: technical documentation pages (e.g. the pledge-algorithms formulas) keep code identifiers and SQL literal — translate the prose around them, never the tokens (`implied_favorites`, `avoided`, `published`).

---

# Chapter 2 — Deutsch: deutscher Stil

## 2.1 Anrede: du — immer (D175)

- **du everywhere**: app UI, transactional email, cookie banner, moderation notices, push, marketing, and legal texts (privacy/terms/impressum already comply). **Sie is banned** in all user-facing copy — including error messages and the cookie consent (the two known leaks get fixed in S04).
- **Capitalized Du in direct address**: Du, Dein, Dir, Dich, Deine — the house style, matching the corpus majority. Lowercase "du" only inside compound community prose where it reads as generic ("spielst du regelmäßig?").
- Third-person plural "sie/Sie" ("Die Veranstalter sagen Sie zu" = they say yes) is legitimate German — the ban is the address form, not the letters. Check context, not grep.
- D175 extends D131 (2026-08, "informal address is official"); D175 adds the cookie banner, moderation notices, and legal texts explicitly.

## 2.2 Imperative: contracted forms

- Prefer the **contracted casual imperative**: **Erzähl, Schau, Versuch, Gib, Passe, Komm, Sag** — not the uncontracted *Erzähle, Versuche* forms. This mirrors the EN contraction rule (§1.2): same energy in both locales.
  - "Passe deine Filter an." ✓ — "Versuche, deine Filter anzupassen." ✗ (bookish infinitive chain)
  - "Schau bald wieder vorbei!" ✓ · "Erzähl uns von dir." ✓
- **Uncontracted imperatives are allowed in longer instructional sentences** ("Erstelle einen neuen Account…" stays natural; don't force contractions where they clang). **Pick one register per surface, not per key** — the audit found Erzähle/Erzähl mixed inside single files.
- Requests soften with "bitte" or "gerne", never with Amtsdeutsch hedging (§2.3): "Bitte versuch es in einer Minute noch einmal." ✓

## 2.3 Anti-Amtsdeutsch

The register is a gaming community, not a Behörde or an insurance letter. No permit-application vocabulary anywhere — including errors, moderation copy, and legal-adjacent text.

| Banned (Amtsdeutsch) | House word | Example |
|---|---|---|
| genehmigen / Genehmigt / Genehmigung | **annehmen / Angenommen / Annahme** | "Bewerbung angenommen" — the state value *Genehmigt* becomes **Angenommen** (GLOSSARY §3) |
| beantragen ("Beitritt beantragen") | **bewerben** | "Zum Mitmachen bewerben" |
| Unannehmlichkeiten | **Umstände** | "Entschuldige die Umstände." |
| Entität | **Eintrag** (or the glossary noun) | "Maximale Anzahl an Einträgen erreicht" |
| Teilnehmerkreis | **Teilnehmer / Teilnehmende** | — |
| "Bitte versuchen Sie es erneut" | du + contracted (§2.1, §2.2) | "Bitte versuch es noch einmal." |
| Ihre Eingabe / hereby-formalities | direct du prose | — |

Also retire homework-register words: "Danke für deine Reflexion!" → "Danke, dass du dir Zeit dafür nimmst!"

## 2.4 Idioms & calques — translate the idiom, not the words

English idioms get **German idioms**, never word-by-word calques:

| EN idiom | DE rendering | Never |
|---|---|---|
| show up (attend) | **kommen / hinzugehen** | "auftauchen" (= surface from water) |
| "ran :count sessions strong" | "umfasste :count Runden" | "lief :count Sitzungen stark" |
| by design | "konzeptionell" / "von Anfang an mitgedacht" | "vom Design her" |
| "on offer" (planned game) | "geplantes Spiel" / "Auch geplant" | "im Angebot" (= on sale, false friend) |
| join the waitlist | "Auf die Warteliste" | "Warteliste beitreten" |
| "Changed your mind?" | "Anders überlegt?" | "Dir haben sich die Pläne geändert?" (mistranslation) |
| "Your call." | "Du entscheidest." | "Deine Entscheidung." (noun-ified, stiff) |

- Keep **table metaphors** — they work in German ("ein Platz an deinem Tisch").
- Keep the community loanwords the glossary ratifies: GM, Session Zero, X-Card, One-Shot, Convention, Safety-Tools. Loanword policy is not a translator's judgment call; it's the glossary's.
- Emotion survives: ":count Spieler **lieben** dieses Spiel" — not "mögen dies".

## 2.5 Placeholder safety — no fixed article before `:entity`

German articles agree with gender; `:entity`/`:name` placeholders don't carry one. **Never put a fixed inflected article directly before a placeholder** whose referent varies (Spiel m. / Kampagne f. / Team n.):

- ✗ "…hat sich bei **deinem** :entity beworben" → renders "bei deinem Kampagne".
- ✓ **Quotes:** "Bewerbung für „:entity“" — the quotation marks make any gender correct.
- ✓ **Rephrase:** "Deine Bewerbung für :entity ist eingegangen" / possessive-free constructions ("Du hast :entity erstellt").
- ✓ **Last resort (engineering):** pass the entity type with its article — only when the sentence truly needs one (flag for the S02 mechanics slice).
- Same care for `:count` agreement and actor-verb fragments (":actor1 und :actor2 :verb" needs plural verb variants — an engineering fix, not per-key copy).

## 2.6 Typography & format rules

- **Quotes:** German low-high „…“ in DE copy (EN uses its own — §1.4). For placeholders inside quotes, use the German pair around the placeholder: „:entity“.
- **Ellipsis:** the … character, never "...".
- **Dashes [style ruling]:** parenthetical/interrupting dashes are **— (em dash, with spaces)** in both locales, corpus-wide — the corpus already runs 207 (en) / 196 (de) em dashes vs a handful of en dashes, so this ratifies dominance and ends the mixed-within-one-file drift. **– (en dash, no spaces) is only for numeric/letter ranges:** 1–5, 24–48 Stunden, A–Z, 14:00–18:00 Uhr.
- **Numbers & units:** thin (non-breaking) space between number and unit — **20 km**, 5 MB. Decimal comma. Currency after the amount with space: **5,00 €**.
- **Times:** 24-hour clock, always with **Uhr** after a bare time: "Morgen um 19:00 Uhr"; ranges keep the en dash ("14:00–18:00 Uhr").
- **Plural of loanwords:** follow corpus canon — "Communitys" (not "Communities") where the glossary/corpus has ruled; don't re-latinize ad hoc.
- **Legal citations stay current:** §5 DDG (not §5 TMG), §18 Abs. 2 MStV (not §55 RStV) — both locales (S04/S05 sweep).

## 2.7 Pluralization (`trans_choice` / `{1}…|[2,*]…`)

- German "N X" plurals are often **invariant** — don't copy EN's two branches blindly. Keep both branches only when the noun form actually changes: `{1} 1 Sitzung|[2,*] :count Sitzungen` (and prefer the canon **Runde(n)**).
- Never ship copy-pasted EN branches: "{1} 1 joined|[2,*] :count joined" → "1 mal teilgenommen" / ":count mal teilgenommen" or restructure.
- German treats **0 as plural** (`[2,*]` branch), unlike EN ("0 games" reads plural in German usage — "0 Spiele").
- Slash-gender forms are banned by [`CONTRIBUTING_TRANSLATIONS.md`](../lang/CONTRIBUTING_TRANSLATIONS.md) (gender-colon notation breaks `:placeholder` checks); use Teilnehmende / pairwise forms.

## 2.8 Transcreation: the informational content must survive

Rhythm, puns, and sentence music may be adapted freely — **the informational content of the EN source must survive**. A German reader must not learn less than an English reader.

- ✗ Cautionary examples from the audit: the About intro's accountability premise (organizers/data/standards accountable to you, not investors) was replaced with an invented paragraph about dice and stories; "Find tabletop games … one session at a time" became "Finde deine Leute. Entdecke neue Welten." — functional message dropped. Both are defects, not transcreation.
- ✓ Good transcreation: "You're all set for :event!" → "Du bist dabei: :event!" — same information, German music.
- **Never silently rewrite brand or marketing copy.** If the German needs different content than the EN allows, change the EN first.
- **DACH localization goes all the way:** phone placeholders "+49 30 12345678", postal codes "z.B. 10115", local game examples ("Zug um Zug", DSA) — the venues/session-zero files show the ceiling.
- Code identifiers, enum tokens, and SQL stay literal in DE (pledge-algorithm pages); translate the prose around them.

---

## Enforcement & maintenance

- **Machine-checked:** key/placeholder parity (`php artisan i18n:check`, CI). The terminology ban-list and this style guide are deliberately review-enforced, not machine-grepped — the documents live in git and changes land through review.
- **Review-time:** voice, register, idiom, and transcreation rules — every copy PR and Weblate review runs down the two chapter checklists.
- **Amending:** like the glossary — a rule change requires a recorded decision (extend D174/D175 or record a successor), an update here, and an update of the structural guards (canon pins, key parity) where applicable.
- **Cross-references:** terminology in [`docs/GLOSSARY.md`](GLOSSARY.md); translation workflow in [`lang/CONTRIBUTING_TRANSLATIONS.md`](../lang/CONTRIBUTING_TRANSLATIONS.md).
