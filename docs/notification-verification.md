# Notification Verification with the roundup-dev Application

## Overview

Notification output (Discord cards + DMs, RSVP interaction buttons) is verified
against a **second, dedicated Discord application** — `roundup-dev` — that joins
the existing test guild alongside the production bot (D282).

Why a second application: Discord allows one Interactions Endpoint URL per
application and routes component interactions (RSVP button clicks) to the owning
application. Roundup posts cards via bot REST, so test-guild clicks on
production-bot cards would otherwise hit the production backend and perform real
joins. With `roundup-dev`, non-prod environments publish cards through the dev
bot, and its button-clicks route to the **non-prod** interactions endpoint.

**The production application, its bot, and its Interactions Endpoint URL are
never touched by this setup.**

## 1. Create the roundup-dev Application (Discord Developer Portal — user step)

1. Create a new application at <https://discord.com/developers/applications>,
   named `roundup-dev` (distinct from the production application).
2. From **General Information**, collect the three values the backend needs:
   - **Application ID** → `DISCORD_BOT_APPLICATION_ID`
   - **Public Key** (Ed25519, hex) → `DISCORD_BOT_PUBLIC_KEY`
3. Under **Bot**, create the bot and reveal/copy its token → `DISCORD_BOT_TOKEN`.
   Reset the token only via the portal; never commit it anywhere.
4. Set **General Information → Interactions Endpoint URL** to the non-prod
   endpoint (see §3 below):
   `https://<non-prod-host>/discord/interactions`
5. Invite the bot to the existing test guild: under **OAuth2 → URL Generator**
   check the `bot` and `applications.commands` scopes plus the permissions the
   production bot uses (send messages, create public threads — same shape), and
   open the generated URL while logged into an account that can manage the guild.

> Do **not** change anything on the production application's Developer Portal
> page. Its Interactions Endpoint URL stays pointed at the production backend.

## 2. Environment Matrix

All three Discord keys must come from the **same** application in every
environment. Never mix a dev token with a prod public key (or any other
combination) — component interactions only verify for the owning application,
so a mismatched keyset fails closed at best and routes test clicks to the wrong
backend at worst.

| Environment | `DISCORD_BOT_TOKEN` | `DISCORD_BOT_PUBLIC_KEY` | `DISCORD_BOT_APPLICATION_ID` |
| --- | --- | --- | --- |
| local dev | dev app | dev app | dev app |
| homelab non-prod | dev app | dev app | dev app |
| production | **prod app** (unchanged) | **prod app** (unchanged) | **prod app** (unchanged) |

Check what is live at any time:

```bash
php artisan config:show services.discord.bot_application_id
```

The last 4 characters of that value are the `app_id_short` log marker (§4).

## 3. Interactions Endpoint URL Options

The endpoint is `POST /discord/interactions` (route `discord.interactions`),
guarded by the `discord.signature` middleware — Ed25519 verification over
(timestamp + raw body), fail closed: an unset or wrong public key means every
interaction request 401s. Pick one option for the non-prod host:

### Option A — Homelab edge (persistent)

Point the dev application's Interactions Endpoint URL at the homelab non-prod
edge domain, e.g. `https://roundup-nonprod.example.com/discord/interactions`.
Best for repeated verification sessions: no tunnel to keep alive, and the URL
survives restarts.

### Option B — Local tunnel (ad hoc)

Expose the local dev server through a tunnel (e.g. `cloudflared tunnel
--url http://localhost:8000` or an ngrok HTTPS tunnel) and paste the resulting
`https://…trycloudflare.com`/`https://….ngrok.app` host into the portal's
Interactions Endpoint URL. Faster to spin up for one-off checks, but the URL
changes with each tunnel session and must be re-entered in the portal.

## 4. Confirming Which Application Is Live

Two independent signals:

- **Log marker (in code):** every `notification.discord_dm_sent` /
  `notification.discord_dm_skipped` / `notification.discord_dm_api_error` log
  context carries `app_id_short` — the last 4 characters of the configured
  `services.discord.bot_application_id`. Match it against the dev vs prod
  application ids to attribute any log line to the right application.
- **Guild member list:** in the test guild, the `roundup-dev` bot posts cards
  with its own avatar/name; anything posted by the production bot there is a
  misconfiguration (check the env matrix).

## 5. Verification Flow (slice demo)

1. Confirm the env matrix (§2) — non-prod shows the **dev** application id.
2. Trigger a card post to the test guild (e.g. publish a game in non-prod) and
   confirm the card appears **as the roundup-dev bot**.
3. Click an RSVP button on the card: the interaction must land on the non-prod
   `POST /discord/interactions` (option A or B from §3) — a correct Ed25519
   signature from the dev app verifies; anything else 401s.
4. Confirm the join landed in the **non-prod** database (game participants row
   for the clicking user).
5. Confirm the production bot and the production application's Interactions
   Endpoint URL are untouched throughout.

## 6. Non-prod Mail Sandbox — Mailpit on the Homelab (D281)

All **non-prod mail** (local dev + homelab non-prod) lands in a shared
**Mailpit** instance that runs **outside the application stack** — an Unraid
Community Applications container (`axllent/mailpit`) on the homelab,
provisioned and verified working on 2026-11-14:

| Property | Value |
| --- | --- |
| SMTP | `192.168.178.31:1025` (no auth, plaintext, LAN-only) |
| Web UI + REST API | `http://192.168.178.31:8025` |
| Persistent storage | `/mnt/user/appdata/mailpit` |
| Network | shared `proxynet` docker network |

There is deliberately **no mailpit service in `docker-compose.yml`** (D281:
no in-stack service — one instance serves multiple projects, and the stack
ships nothing it does not own).

### Local dev wiring

Point the mailer at the homelab SMTP (already wired on the main checkout's
`.env`; replicate in worktrees):

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=192.168.178.31
MAIL_PORT=1025
MAIL_PROJECT_TAG=roundup-games-dev
```

This is pure env configuration — **no transport code change**. Because
`MAIL_MAILER` is `smtp` in every non-prod slot, **Resend is never used
outside production** (zero Resend quota consumption for verification
sends — D281/D283; Resend stays the production-only transport).

A commented template block lives in `.env.example` (search `Mailpit`).

### Confirming the instance is up

The REST API answers before anything sends mail:

```bash
curl -s http://192.168.178.31:8025/api/v1/messages | head -c 300
```

Any JSON response means SMTP will be accepted on `:1025`. If the container
is down, the Unraid UI (Docker → Mailpit) shows it; it should auto-restart
(see follow-ups below).

### Multi-project separation — the X-Tags convention

The mailbox is **shared across projects**, so this project's mail must be
separable from every other project's. **The app stamps the tag
automatically**: whenever `MAIL_PROJECT_TAG` is set (every non-prod slot),
the auto-discovered `TagProjectMail` `MessageSending` listener adds
`X-Tags: roundup-games-dev` to **every outgoing send** — notification
mail (MailChannel) and Mailables/`Mail::raw()` alike — so no sender in
this app stamps anything by hand. Production leaves `MAIL_PROJECT_TAG`
unset **by design**: the header never rides the Resend API `headers`
field into real user mail. The manual-header convention remains for
**other** homelab projects/senders, each tagging its own slug:

- Header name is **`X-Tags`** — PLURAL, comma-separated for multiple tags.
  A singular `X-Tag` header is **silently ignored** by Mailpit.
- Other homelab projects send their own slug (e.g. `X-Tags: <their-slug>`)
  by hand; this project's `roundup-games-dev` is stamped automatically
  (see above).
- In the Mailpit UI, filter with the tag search (`tag:roundup-games-dev`)
  to see only this project's mail.
- Plus-addressing (e.g. `ops+roundup-games-dev@…`) auto-tags the mail by
  default — the explicit header stays the canonical convention, and
  verification sends should use **plus-free** addresses so the auto-tag
  adds no extra tags.

### Operator follow-ups (homelab)

- [ ] Confirm the container's **Autostart** toggle in the Unraid Docker
      UI (the restart policy is already hardened to `unless-stopped`).
- [ ] Optionally set an **`MP_MAX_MESSAGES`** retention cap (e.g. `5000`)
      so the shared mailbox cannot grow unbounded on the appdata share.

## 7. Machine Backbone — the Notification Corpus Render Test

`tests/Feature/Notifications/NotificationCorpusRenderTest.php` renders
**every** concrete `BaseNotification` subclass (all of `App\Notifications\*`)
on every channel method it defines (`toMail`/`toDatabase`/`toPush`/
`toDiscord`), in **both `en` and `de`** locales, asserting non-empty
subjects/HTML/payloads and — for mail — the signed unsubscribe line
(`signature=`). It is the machine half of verification: Mailpit (§6) is
where a **human** reviews the rendered corpus; the test is what makes that
review a checklist instead of spelunking, and it is the regression net the
M081 theme work leans on.

Conventions enforced by the test:

- **Channel gating** — channel methods are called only when they exist
  (`method_exists`). SessionReminder has no `toMail()`; WeeklyDigest's
  `toDatabase()` is a category-identity payload for the engagement counters,
  not a bell-row declaration (`supportedChannels()` stays mail-only); an
  ungated call fatals the corpus loop.
- **Null opt-outs respected** — `toPush()`/`toDiscord()` returning `null` is
  a deliberate channel opt-out (D130/D247), not a render failure.
- **Conscious unsubscribe exclusions only** — the four classes legitimately
  lacking the signed line (AccountSuspended, ContentRemoved,
  ContentReportWarning, ReviewReported — moderation/system one-offs, not
  category mail) are listed with one-line justifications in
  `corpusUnsubscribeExclusions()`; a hygiene test keeps that list honest,
  and an empty list remains the goal.
- **Registry completeness** — a reflection test fails when a new
  notification class appears in `app/Notifications` without being added to
  the corpus registry, so the harness cannot silently rot.

Run it standalone:

```bash
vendor/bin/pest tests/Feature/Notifications/NotificationCorpusRenderTest.php
```

## 8. Deliverability DNS Baseline — SPF / DKIM / DMARC (D288)

Production mail goes through **Resend** with a custom sending domain.
Deliverability requires the three DNS mechanisms to be published and
**aligned** (the `From:` domain matches the domain Resend signs and bounces
for). Walk this checklist whenever the sending domain changes, and record
the result in the table below (this runbook is the record D288 asks for).

Replace `<sending-domain>` with the production From domain throughout.

### SPF — sender permitted

1. `dig TXT <sending-domain> +short` — the answer must contain the SPF
   record Resend's domain dashboard instructed you to publish.
2. There must be **exactly one** SPF TXT record (multiple SPF records are
   invalid per RFC 7208 and can break SPF entirely).
3. Under 10 DNS lookups total (the SPF lookup limit) — watch `include:`
   chains when other services send for the same domain.

### DKIM — signature publishes and verifies

1. In the **Resend dashboard → Domains → <sending-domain>**, confirm both
   verification states read **Verified** (SPF block + DKIM block).
2. `dig TXT <selector>._domainkey.<sending-domain> +short` — the selector
   is the one shown in the Resend dashboard record table; the answer must
   match the published key.
3. Send a probe mail to an external mailbox (e.g. Gmail → "show original")
   and confirm `DKIM: PASS` with `d=<sending-domain>`.

### DMARC — policy + alignment

1. `dig TXT _dmarc.<sending-domain> +short` — a DMARC record exists
   (starts `v=DMARC1`) with an explicit `p=` policy.
2. Alignment holds by construction when Resend is the only sender and the
   DKIM `d=` domain equals the `From:` domain (relaxed alignment).
3. Confirm the `rua=` reporting address receives aggregate reports (a
   mailbox or a report analyzer); alignment failures land there first.

### Results record (fill in on each run)

| Date | SPF single + verified | DKIM verified + `d=` match | DMARC policy present | Aligned probe mail | Notes |
| --- | --- | --- | --- | --- | --- |
| — | ☐ | ☐ | ☐ | ☐ | baseline not yet recorded |

## 9. Real-Device Push Test — `notifications:push-test` (dev only)

The mail corpus (§6/§7) verifies rendering; this command verifies
**delivery to a real device**. It sends a representative push for any
[`NotificationCategory`](
`app/Enums/NotificationCategory.php`) value to the account named via
`--user` — the operator's own — through the real `PushChannel` + WebPush
stack (synchronous, no queue, no notification preferences consulted).

### Prerequisites

- `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` set in `.env` (the same keys
  the PWA's service worker was subscribed with — a different key pair
  means endpoints reject the push as unauthorized).
- The `--user` account must have at least one push subscription (log in
  as that account in the browser, then **notification settings → Enable
  push**).

### Usage

```bash
php artisan notifications:push-test waitlist_promoted --user=you@example.com
php artisan notifications:push-test new_follower --user=you@example.com --locale=de
```

Behavior:

- **Refuses in production** (exit 1; also registered only outside
  production, same gate as `demo:seed`).
- **Unknown category** → error listing all valid slugs (exit 1).
- **Missing / unknown `--user`** → clear error (exit 1). The option is
  required because artisan has no authenticated user — naming the account
  is what makes the real-device demo executable.
- **Payload provenance** — where the category's notification class
  defines `toPush()`, that real class is built with small "Push Test …"
  factory fixtures and its real payload is sent; categories whose classes
  deliberately carry no push voice (D130 opt-outs — see `NO_PUSH_VOICE`
  in the command) get the shared-envelope fallback payload so every
  category stays testable end-to-end.
- The payload JSON is **printed for copy-paste review** and logged as a
  structured `push.test` line (category, user_id, notification class,
  locale, subscription count, payload) **before** delivery is attempted.
- Delivery outcomes surface per device as `push.sent` /
  `push.send_failed` / `push.subscription_expired` log lines.

Fixtures the command creates (games/campaigns/etc. named
`Push Test …`) land in the dev database per run and are never attached
to the operator's own records; run `demo:teardown`-style cleanup manually
if they bother you.

### Coverage guard

`tests/Feature/Console/NotificationsPushTestCommandTest.php` pins the
guards, both payload paths (transport-mocked queueNotification +
payload-shape assertions), and a **coverage hygiene test**: every
`NotificationCategory` case maps to either a real notification with
`toPush()` or a conscious `NO_PUSH_VOICE` entry — a new category fails
loudly until it is classified.

## 10. Live evidence record

Round-0 M080 validation deferred four live-evidence items; this section
is the durable record of them. Evidence discipline for every item:

- **EXISTING records only** — live sends and flows reuse users, games,
  and campaigns already in the non-prod database; no models are created
  for evidence (the `notifications:push-test` fixtures in §9 are the one
  documented exception, owned by that command).
- **Log excerpts carry `app_id_short` only** — the last 4 characters of
  `services.discord.bot_application_id`, never the full ID or token.
- **Secrets are named but never recorded** — VAPID keys
  (`VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY`) and the Discord bot token
  live in the local `.env` (gitignored); values never appear here.

Rows marked *pending* are operator steps recorded when executed.

### 10.0 Environment fingerprint

Fingerprint of the non-prod checkout at the time item D was captured
(2026-11-14):

| Key | Value |
| --- | --- |
| Date | 2026-11-14 |
| `git rev-parse HEAD` | `bf091e964a5fb23ced495d7e47fb0b6e238ec340` |
| `APP_ENV` | `local` |
| `MAIL_MAILER` | `smtp` |
| `MAIL_HOST` | `192.168.178.31` |
| `MAIL_PORT` | `1025` |
| `MAIL_PROJECT_TAG` | `roundup-games-dev` |
| `QUEUE_CONNECTION` | `sync` (sends run inline, no worker) |
| Discord `app_id_short` | `7525` (last 4 of `services.discord.bot_application_id`) |
| VAPID configured | **no** — boot log: "VAPID keys not configured, push notifications disabled"; keys land in local `.env` with item B (names only here) |

### 10.1 Evidence item A — test-guild RSVP click-through (operator)

**Status: procedures captured 2026-11-14 (S07-T03); execution pending
(operator) — NEEDS-HUMAN at UAT.** Every route, class, and log context
below was re-verified against the source tree when this procedure was
written; the operator runs the session and fills the evidence table at
the bottom. Use the same live session as §10.3 (one tunnel, one card).

#### Preconditions (check every box — never assume)

- [ ] **(a) roundup-dev bot in the test guild** — §1/§2 done: the dev
      application's bot is a member of the test guild and the non-prod
      `.env` carries the dev keyset (§2 matrix; `app_id_short` `7525`
      per §10.0).
- [ ] **(b) Interactions Endpoint URL → live non-prod endpoint.** The
      realistic path is §3 **Option B**:

      ```bash
      cloudflared tunnel --url http://localhost:8000
      ```

      Paste the printed tunnel host into the dev application's
      Developer Portal → General Information → Interactions Endpoint
      URL as `https://<tunnel-host>/discord/interactions` (route
      re-verified: `POST discord/interactions`, name
      `discord.interactions`, behind the Ed25519 `discord.signature`
      middleware — fail closed). The tunnel host is **ephemeral per
      session**: re-paste it every time. §3 **Option A (homelab edge)
      does not exist as infrastructure** — its hostname in §3 is a
      speculative example, not a deployable URL; do not plan around it.
- [ ] **(c) Local server running at `APP_URL`** — `php artisan serve`
      (or `composer run dev`) on `http://localhost:8000`, the same host
      the tunnel forwards to.
- [ ] **(d) A live card in the test guild, posted by the roundup-dev
      bot.** The game owner connects the guild via `GET discord/install`
      (route `discord.install.redirect`; the D119 `publish_enabled`
      opt-in is enforced by `DiscordPublisher::targetGuilds()`), then
      publishes an EXISTING game — via the UI publish action or a
      tinker dispatch of `PublishGameToDiscord`:

      ```bash
      php artisan tinker --no-interaction --execute 'App\Jobs\PublishGameToDiscord::dispatch("<game-id>");'
      ```

      ⚠️ The `demo:seed` guild rows use **fake snowflakes**
      (`900000000000000101` / `900000000000000102`) and cannot host a
      live card — connect a real guild first. Pick a game whose signup
      window is open and that is neither canceled nor completed; the
      owner cannot RSVP their own game. Confirm the card posts **as the
      roundup-dev bot** (§4 member-list check).
- [ ] **(e) Clicker identity.** Phase 1 below deliberately starts with
      an UNLINKED Discord member; the deep-link flow links it, and
      phase 2 clicks again. The operator's member must ultimately
      resolve via `linked_accounts` (provider `discord`).

#### Two-phase demo script (both outcomes on the record)

**Phase 1 — unlinked click → deep-link on-ramp.** From a real Discord
client in the test guild, click the card's RSVP ("My seat") button as
a member whose Discord account is NOT linked:

1. Expect an **ephemeral deep-link reply** visible only to the
   clicker — PRIMARY button "Link Discord to grab your seat",
   secondary "RSVP on roundup" (copy in the guild's configured
   locale). Backend logs `discord_interaction.unlinked_deep_link`
   (game_id, guild_id).
2. Click the primary button → the browser opens
   `GET discord/join/{game}` (route `discord.join`,
   `DiscordJoinController`) → logs `discord_join_intent.set` →
   redirects to `oauth.redirect` provider `discord` (Socialite;
   `DISCORD_CLIENT_ID` set; callback resolves to
   `config('app.url')/auth/discord/callback`, i.e.
   `http://localhost:8000/auth/discord/callback` locally).
3. Complete OAuth login/register → the Discord account is now linked
   (`linked_accounts` row, provider `discord`).

**Phase 2 — linked click → menu → join.** Click the card's RSVP
button again as the now-linked member:

1. Expect the **ephemeral RSVP menu** (roster state + action buttons,
   copy in the clicker's client locale — §10.3 exercises this in
   German). Backend logs `discord_interaction.menu_shown` (game_id,
   user_id, is_owner, current_status).
2. Click **Join** ("✅ Zusagen" in a German client). Backend logs
   `discord_interaction.join_dispatched` (game_id, user_id, guild_id)
   — `join`/`leave` are the `$logKey` arguments to the controller's
   shared `handleDeferredAction()` helper — and the dispatched
   `ProcessDiscordRsvp` job writes the participant row through
   `ParticipantLifecycle::createOrReactivate()` with
   `JoinSource::Discord`, then PATCHes `@original` with the ephemeral
   confirmation.

#### Evidence capture (backend log)

Run every literal (file-logging slots: `storage/logs/laravel.log`; on
the local slot with `LOG_CHANNEL=stderr` — §10.2 — the same lines
stream to the `php artisan serve` process output and the tinker
terminal, so tee the output to a file first and grep that):

```bash
grep discord_interaction.unlinked_deep_link storage/logs/laravel.log
grep discord_interaction.menu_shown storage/logs/laravel.log
grep discord_interaction.join_dispatched storage/logs/laravel.log
grep discord_rsvp.completed storage/logs/laravel.log
grep notification.discord_dm_sent storage/logs/laravel.log
grep notification.discord_dm_skipped storage/logs/laravel.log
grep notification.discord_dm_api_error storage/logs/laravel.log
```

- The join write emits the full structured chain:
  `discord_interaction.menu_shown` →
  `discord_interaction.join_dispatched` → `participant.joined`
  (context carries `join_source: discord`) → `discord_rsvp.completed`
  (status approved / waitlisted / benched). On a fresh join whose
  clicker does not yet follow the host, the auto-follow side-effect
  also logs `community.auto_followed_host_on_join` (corroborating
  signal only — its NewFollower notification is deliberately
  suppressed on auto-follows).
- **`app_id_short` attribution — the source-accurate expectation.**
  Every `notification.discord_dm_*` context carries `app_id_short`
  (the last 4 of
  `php artisan config:show services.discord.bot_application_id`),
  but a plain join write fires **no DM leg**: the join intake
  (`ParticipantLifecycle::createOrReactivate`) has no
  `NotificationService` send — the `ParticipantJoined` send site is
  invitation-acceptance only. The session's `app_id_short` line is
  therefore produced by a deliberate send in the same slot: right
  after the join, run the first §10.4 re-send one-liner
  (WaitlistPromoted to `uat-mail-en@roundup.test` — pre-verified
  unlinked) and read the tinker output; it lands
  `notification.discord_dm_skipped` with reason `no_linked_account`
  carrying the DEV `app_id_short`. Live-verified 2026-11-14
  (S07-T03): that exact one-liner logged
  `"reason":"no_linked_account","app_id_short":"7525"`. That
  pair — join row + dev-attributed `app_id_short` line in the same
  backend slot — is this item's roadmap evidence.
- Join-row read query (read-only; substitute the real ids):

```bash
php artisan tinker --no-interaction --execute 'App\Models\GameParticipant::where("game_id", "<game-id>")->where("user_id", "<user-id>")->first(["status", "join_source"]);'
```

  `join_source` must be `JoinSource::Discord` (value `'discord'`) —
  written by `ParticipantLifecycle::createOrReactivate` from the
  `ProcessDiscordRsvp` job (the same intake seam as a web RSVP).

#### Sync-queue caveat — the 3-second ack window

`QUEUE_CONNECTION=sync` (§10.0) runs `ProcessDiscordRsvp` **inline
inside the interaction request**: the participant transaction + card
refresh + confirmation PATCH all execute before the controller's
type-5 DEFERRED ack returns, so the ack may exceed Discord's strict
**3-second** interaction window — the client may briefly show a
failed interaction even though the join row AND the `@original`
confirmation PATCH still land (the interaction token stays valid
15 min). For a clean client session switch to
`QUEUE_CONNECTION=database` + `php artisan queue:work` first;
otherwise accept the degraded ack — the evidence is unaffected
either way.

#### Acceptance criteria (all must hold)

1. `discord_interaction.menu_shown` logged for the clicker (game_id +
   user_id) — the interaction verifiably reached the non-prod
   endpoint (only a dev-app Ed25519 signature passes; anything else
   401s).
2. `discord_interaction.join_dispatched` logged for the same
   game/user.
3. The join row exists: `GameParticipant` for game+user with
   `join_source` = `JoinSource::Discord` (`'discord'`).
4. The `@original` ephemeral confirmation is visible to the clicker
   in the channel, matching the `discord_rsvp.completed` status.
5. ≥1 `notification.discord_dm_*` line in the same slot carries the
   DEV `app_id_short` (`7525` — via the §10.4 re-send; never the
   prod id).

#### Evidence table

| Step | Evidence | Status |
| --- | --- | --- |
| Phase 1 unlinked click | `discord_interaction.unlinked_deep_link` line + ephemeral deep-link observed | ✅ 2026-11-15 (operator session §10.5): log line game 01a1de7e… guild 1529…144; ephemeral "Link Discord to grab your seat" observed |
| OAuth link via deep-link | `discord_join_intent.set` + `linked_accounts` row (provider `discord`) | ✅ 2026-11-15: intent set for game 01a1de7e…; linked_accounts row created for new user elisavhelesburg@yahoo.com (Discord-register path) |
| Phase 2 menu | `discord_interaction.menu_shown` line + ephemeral menu observed | ✅ 2026-11-15: menu_shown {is_owner:false, current_status:null} then {…"approved"}; operator observed 🎟️ 0/4 seats · 4 left — claim yours. + ✅ Zusagen menu |
| Join dispatched | `discord_interaction.join_dispatched` line | ✅ 2026-11-15: join_dispatched logged for the linked user + guild |
| Join row | read query returns row with `join_source` = `discord` | ✅ 2026-11-15: GameParticipant status=approved join_source=JoinSource::Discord created 2026-11-15 09:31:18 |
| `@original` confirmation | ephemeral confirmation visible in the channel | ⚠️ 2026-11-15 degraded: client showed "Roundup Games didn't respond in time" (sync-queue >3s ack) and the follow-up PATCH 401'd… see §10.5 — join row + log chain unaffected; QUEUE_CONNECTION=database remains the clean-ack path |
| `app_id_short` attribution | ≥1 `notification.discord_dm_*` line with dev `app_id_short` after the §10.4 re-send | ✅ 2026-11-15: notification.discord_dm_skipped reason=no_linked_account app_id_short=7525 via the §10.4 one-liner |

### 10.2 Evidence item B — real-device push

**Status: command side captured 2026-11-14 (S07-T02); device legs
pending (operator).**

#### VAPID key setup (executor — done)

A VAPID key pair is generated and written to the LOCAL `.env` (gitignored)
at the template position of `.env.example` lines 157–159 (the
`# VAPID (Web Push Notifications)` block): `VAPID_PUBLIC_KEY` /
`VAPID_PRIVATE_KEY`. The package ships no artisan generator, so the pair
is created via tinker (`\Minishlink\WebPush\VAPID::createVapidKeys()`)
and appended with `file_put_contents(..., FILE_APPEND)` (leading newline —
existing formatting untouched), then `php artisan config:clear` reloads
config. **Key NAMES only are ever recorded in this document; values are
never echoed, logged, or committed.**

**Ordering rule (verbatim):** VAPID keys must exist
before any device subscribes. The subscription's endpoint authorization
is tied to the key pair the browser was given (`api/v1/push/vapid-public-key`
feeds `pushManager.subscribe({applicationServerKey})`; the un-versioned
`api/push/…` spellings still resolve as 308 redirects): subscriptions created
under a different — or absent — pair are orphaned by a later key change,
endpoints reject pushes for them, and re-subscribing under the current
keys is the only repair. The 2026-11-14 run below shows this failure
mode live: a synthetic subscription row created **before** the pair
existed cannot deliver.

Post-setup verification: `config('services.vapid.public_key')` resolves
to a non-empty string (checked via `is_string(...)`, value never printed)
and the `WebPush` singleton resolves to a real client — the
`VAPID keys not configured, push notifications disabled` boot warning
from `AppServiceProvider` is gone (the §10.0 "VAPID configured: no" row
is the pre-setup fingerprint and stays as captured).

#### (a) Operator procedure — subscribe a real device

1. Serve the local app (`php artisan serve` / `composer run dev`) and
   log in as `demo-admin@roundup.games`.
2. **First** open notification settings → **Disable push** if it is on:
   the app issues session-auth `DELETE api/v1/push/subscribe`, removing
   stored subscription rows — including the synthetic
   `uat-push-endpoint.invalid` UAT artifact recorded below. A stale row
   aborts every future flush batch (`push.flush_failed`) even after a
   real device subscribes, so shed it first.
3. Then **Enable push**: the browser asks for notification permission;
   the service worker registers via `POST api/v1/push/subscribe` with the
current public key.
4. Verify the fresh subscription row (read-only):

   ```bash
   php artisan tinker --no-interaction --execute 'echo App\Models\User::where("email", "demo-admin@roundup.games")->first()->pushSubscriptions()->count();'
   ```

#### (b) Run command and how to read its output

```bash
php artisan notifications:push-test waitlist_promoted --user=demo-admin@roundup.games --no-interaction
# German payload: append --locale=de
```

Anatomy (command source: `app/Console/Dev/NotificationsPushTestCommand.php`,
runbook §9):

- Info lines name category, notification class, recipient and
  subscription count; then the rendered payload JSON is printed for
  copy-paste review.
- A structured `push.test` INFO line is logged **before** delivery
  (category, user_id, notification, fallback_envelope, locale,
  subscriptions count, payload) — even a refused send leaves this trace.
  Locally it lands on the stderr channel (`LOG_CHANNEL=stderr` in the
  local `.env`), i.e. in the artisan process output; slots logging to
  file carry it in their log files.
- Exit codes are the command's contract: exit 1 on missing `--user`,
  unknown user, unknown category, or unsupported locale (all checked
  BEFORE any payload is rendered or logged); exit 1 on missing VAPID
  keys or zero subscriptions (both AFTER the payload and `push.test`
  record exist); exit 0 once a dispatch is attempted (delivery outcomes
  are log-side, not exit-side).
- With VAPID keys present and a subscription registered, the send runs
  synchronously through the real `PushChannel` + WebPush stack. Per-device
  receipts: `push.sent` (INFO, the only success record), `push.send_failed`
  (WARNING, with reason + HTTP status), `push.subscription_expired` (INFO,
  row auto-deleted). A batch-level transport error aborts the flush and
  logs `push.flush_failed` (WARNING) instead — no per-device lines in
  that run.

#### (c) Evidence table

| Step | Evidence | Status |
| --- | --- | --- |
| VAPID keys in local `.env` | `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` appended non-empty at the `.env.example:157–159` template position; `config:clear` run; names only recorded | ✅ 2026-11-14 (executor) |
| Boot warning gone | `WebPush` singleton resolves to a real client, no `VAPID keys not configured` warning | ✅ 2026-11-14 (executor) |
| `Command-side outcome:` | full run record below | ✅ 2026-11-14 (executor) |
| Device subscription on real hardware | procedure (a): fresh row created under the current VAPID pair | ✅ 2026-11-15: Firefox 161/macOS subscription (updates.push.services.mozilla.com) for the operator account; Safari subscription also created (web.push.apple.com) |
| Real-device receipt | per-device `push.sent` line from a (b) run on the subscribed device | ✅ 2026-11-15: push.sent logged for the Mozilla endpoint (push-test exit 0) and the notification banner was human-confirmed on the device; Safari/APNs leg 403s on localhost (§10.5 finding B2) |

**Command-side outcome:** run 2026-11-14 16:50:08,
`php artisan notifications:push-test waitlist_promoted --user=demo-admin@roundup.games --no-interaction`
— **exit 0** (a dispatch was attempted; one subscription row pre-existed,
so the zero-subscriptions refusal did not fire — recorded as observed,
not as expected). stdout: category `waitlist_promoted` (Waitlist
Promotion), notification `App\Notifications\WaitlistPromoted`, recipient
`demo-admin@roundup.games` (1 subscription), payload JSON (title "Spot
Available!", body "A spot opened up in Push Test Game. Confirm by Mon,
Nov 16, 2026 4:50 PM.", icon `/icons/pwa-192x192.png`, deep link to the
fixture game, tag `waitlist-promoted-game-…`), then "Dispatch attempted
to 1 subscription(s)." stderr carried the structured records: a
`push.test` INFO line with `subscriptions: 1` (all fields incl. full
payload), then `push.flush_failed` WARNING — `user_id` `01a1d026-…`,
`notification_type` `App\Notifications\WaitlistPromoted`, error "Invalid
data: only uncompressed keys are supported." **No `push.sent` line
exists — nothing was delivered.** Root cause (verified read-only): the
single pre-existing subscription row is a synthetic UAT artifact
(created 2026-11-14 12:41:32, endpoint host `uat-push-endpoint.invalid`,
key material that is not a valid uncompressed P-256 point), created
before any VAPID keys existed — the ordering-rule failure mode made
visible: RFC 8291 payload encryption rejects the synthetic key at flush
time, the whole batch aborts (`push.flush_failed`), and the command
still exits 0 because a dispatch was attempted. Command-side
conclusion: the full path (payload render → `push.test` → VAPID gate →
subscription gate → `PushChannel::send` → queueNotification → flush)
executed for real against the live non-prod stack; only the last hop —
a VAPID-authorized real device — remains (operator legs above).

#### (d) Dev-DB exception (documented)

`notifications:push-test` builds recognizable "Push Test …" fixtures per
run (§9); this run created "Push Test Game" and its "Push Test Owner"
and nothing else — the one documented exception to §10's
EXISTING-records-only discipline, owned by the command. No live
`NotificationService` send was touched (A/C evidence discipline intact).
Fixture cleanup, if wanted, is manual per §9.

### 10.3 Evidence item C — German-client RSVP menu and native timestamps (operator)

**Status: procedures captured 2026-11-14 (S07-T03); execution pending
(operator) — NEEDS-HUMAN at UAT.** Same live session as §10.1 (same
tunnel, same card, same linked clicker) — do this immediately after the
§10.1 phase-2 click, before the tunnel comes down.

#### Operator steps

1. In the Discord client: **Settings → Language → Deutsch**, then
   restart/refresh the client if prompted.
2. Click the card's RSVP ("My seat") button as the linked member. The
   ephemeral menu must render in German: menu copy resolves from the
   interaction payload's `locale` field — the clicker's client locale,
   normalized to its primary subtag (`de-AT` → `de`) and validated
   against the app's available locales
   (`DiscordInteractionController::interactionLocale()`).
3. Compare the observed menu copy **verbatim** against the live values
   in `lang/de/notifications.php` (never invented copy). For a clicker
   not yet on the roster, expect one of the joiner states:

   - with seats left — `notifications.content_rsvp_joiner_seats_left`:
     `🎟️ :approved/:max Plätze · :left frei — hol Dir Deinen.`
     (rendered with real counts; the pinned test state is 0/4 with
     4 left)
   - open roster (no max) —
     `notifications.content_rsvp_joiner_open_roster`:
     `🎟️ :approved dabei · offene Liste — hol Dir Deinen Platz.`
   - Join button — `notifications.action_rsvp_join`: `✅ Zusagen`

   After joining (same session), clicking "My seat" again must show
   `notifications.content_rsvp_approved`:
   `✅ Du bist dabei — Dein Platz ist sicher. 🎲` with the leave
   button `notifications.action_rsvp_leave`: `🚪 Verlassen`
   (waitlisted/benched variants say
   `Du rückst nach, sobald ein Platz frei wird.`). Canon DE markers
   to confirm (docs/GLOSSARY.md, K001): du-form („Du bist dabei"),
   Runde, nachrücken, Ersatzbank, Warteliste.
4. Card datetimes: the card's When field and the session thread's 📅
   line carry native Discord timestamps (`<t:F>` / `<t:R>` epochs,
   MEM1714) — Discord renders them **in the viewer's client timezone
   and language**, so in the German client the datetime renders as a
   German-formatted local time for THIS viewer, independent of the
   guild's configured locale (documented exception: thread names keep
   the server-rendered community wall date).

#### Acceptance criteria (all must hold)

1. The ephemeral menu copy matches the `lang/de/notifications.php`
   values above verbatim (the variant depends on roster state; all
   canon DE markers present).
2. The card's When field / thread 📅 line renders a viewer-local,
   German-formatted datetime via native `<t:…>` timestamps.

#### Machine corroboration (already pinned by tests)

The live row records the human observation; the behavior itself is
machine-pinned:

- `tests/Feature/Http/DiscordInteractionEndpointTest.php` —
  `a_menu_request_with_a_german_locale_interaction_returns_a_german_menu`
  (payload locale `de` → the German content line + `✅ Zusagen`
  button label, asserted via `__()` per K004); the same file pins
  regional tags (`de-AT` → `de`) and unsupported-locale fallback to
  the app locale.
- `tests/Feature/Services/Discord/DiscordCardRendererTest.php` and
  `tests/Feature/Services/Discord/DiscordDigestRendererTest.php` —
  pin the `<t:…>` epochs the card and digest render in markup-rendering
  positions (embed field values). The digest's per-date embed TITLES stay
  server-rendered on the community clock — Discord renders `<t:…>` markup
  only in message content, embed descriptions, and embed field values, so
  a `<t:…:D>` code in a title would show literally.
- `tests/Feature/Notifications/SessionReminderTimezoneTest.php` —
  pins the `<t:…>` epochs on the reminder surface.

These tests pin the behavior; the live row is the human confirmation
that a real German client renders it as pinned.

#### Evidence table

| Step | Evidence | Status |
| --- | --- | --- |
| German menu copy | observed menu copy quoted verbatim, matches the `lang/de/notifications.php` values above | ✅ 2026-11-15: post-join approved menu matched canon verbatim — „✅ Du bist dabei — Dein Platz ist sicher. 🎲“ with 🚪 Verlassen (operator-confirmed match against the pinned strings) |
| Native timestamps | card When field / thread 📅 line renders viewer-local German datetime via `<t:F>`/`<t:R>` | ✅ 2026-11-15: German-client When field rendered viewer-local German datetime (operator-confirmed); menu_shown {current_status: approved} logged for the same click |

### 10.4 Evidence item D — Mailpit browser review (EN/DE + tag filter)

**Status: CAPTURED 2026-11-14 (executor-executable, S06-T03 pattern).**

Representative live sends through `NotificationService::send()` —
existing records only: recipients `uat-mail-en@roundup.test`
(`preferred_language=en`) and `uat-mail-de@roundup.test`
(`preferred_language=de`), game `UAT S02 Mailpit Probe`
(`01a1da12-16c6-710a-b3ae-730f99045d7f`), inviter
`demo-admin@roundup.games`. No models were created.

Scope: 2 classes × 2 locales. `WaitlistPromoted` (lifecycle cluster) and
`EntityInvitation` (invitation cluster) — both mail-enabled by category
default for settings-null recipients. `ParticipantJoined` was dropped:
`defaultMailEnabled()` is `false` for that category, so it would produce
no Mailpit row for these recipients (recorded as the scope decision).

Safety pre-verification (read-only queries, both recipients): no
`linked_accounts` row with `provider=discord` → the Discord DM leg
provably hits its `no_linked_account` no-op path; push structurally
inert (VAPID unconfigured, §10.0).

Per-send evidence (all four verified: non-empty HTML part, text part
present, signed unsubscribe line containing `signature=`, headers
`X-Tags: roundup-games-dev` + `X-Metadata-Roundup_category`, tag chip on
the Mailpit row):

| Class | Recipient | Locale | Observed subject (Mailpit) | HTML | Text | Unsub | Tags |
| --- | --- | --- | --- | --- | --- | --- | --- |
| WaitlistPromoted | uat-mail-en@roundup.test | en | A spot opened in UAT S02 Mailpit Probe! | ✓ | ✓ | ✓ sig | ✓ |
| WaitlistPromoted | uat-mail-de@roundup.test | de | Ein Platz ist frei in UAT S02 Mailpit Probe! | ✓ | ✓ | ✓ sig | ✓ |
| EntityInvitation | uat-mail-en@roundup.test | en | Local Admin invited you to a game | ✓ | ✓ | ✓ sig | ✓ |
| EntityInvitation | uat-mail-de@roundup.test | de | Local Admin hat dich zu einem Spiel eingeladen | ✓ | ✓ | ✓ sig | ✓ |

Locale separation held: no subject collides across the EN/DE recipients;
body copy is locale-correct in both detail views (EN greeting "Hey
Nicolas Donnelly" / DE "Hallo Stephan Brakus", DE CTA "Spiel ansehen").

Browser review (gsd-browser, Mailpit UI at `http://192.168.178.31:8025`;
screenshots captured 2026-11-14):

| Check | Result | Evidence |
| --- | --- | --- |
| UI search `tag:roundup-games-dev` (`/search?q=tag%3Aroundup-games-dev`) | 6 rows, every row carries the `roundup-games-dev` tag chip; the 6 pre-listener S02 messages are absent | screenshot 1 (filtered view) |
| DE message detail | German subject + `To: uat-mail-de@roundup.test` + tag badge on detail header | screenshot 2 |
| EN message detail | About panel: Subject "Local Admin invited you to a game", To `uat-mail-en@roundup.test`, Tags `roundup-games-dev` | screenshot 3 |
| Headers tab (EN detail) | `X-Tags` and `X-Metadata-Roundup_category` lines visible | screenshot 4 |

REST crosschecks (assert on the `messages` array only — MEM1722: this
Mailpit build's `/api/v1/messages?query=` silently ignores the filter
and `/api/v1/search`'s `total` reports the unfiltered count):

| Check | Result |
| --- | --- |
| Positive — `GET /api/v1/search?query=tag:roundup-games-dev` | PASS: 6 messages, every one `Tags == ["roundup-games-dev"]`; all 4 newly-sent subjects present; locale-separated subject sets |
| Per-message detail (`/api/v1/message/{id}` + `/headers`) | PASS: 4/4 sends verified (HTML 12.1–12.4 kB, text present, `signature=` unsubscribe, `X-Tags: roundup-games-dev`, `X-Metadata-Roundup_category: game_invitation`/`waitlist_promoted`) |
| Negative — `GET /api/v1/search?query=tag:nonexistent-zzz-xyz` | PASS: empty `messages` array — the tag filter genuinely excludes non-matching mail |

Completeness proof: the live sends above prove transport, tagging, and
browser review for representative classes — NOT class completeness.
All-classes coverage is machine-proven by
`tests/Feature/Notifications/NotificationCorpusRenderTest.php`
(44 classes × en/de × channels, 761 assertions, registry-pinned; §7).

Re-send preconditions — a shared-mailbox flush must be recoverable, so
the exact tinker one-liners are recorded (single quotes outer, double
quotes inner; each sends inline via `QUEUE_CONNECTION=sync`):

```bash
php artisan tinker --no-interaction --execute '$u = App\Models\User::where("email", "uat-mail-en@roundup.test")->first(); $g = App\Models\Game::find("01a1da12-16c6-710a-b3ae-730f99045d7f"); app(App\Services\NotificationService::class)->send($u, new App\Notifications\WaitlistPromoted($g, "Friday, 18:00"), App\Enums\NotificationCategory::WaitlistPromoted); echo "SENT".PHP_EOL;'
php artisan tinker --no-interaction --execute '$u = App\Models\User::where("email", "uat-mail-de@roundup.test")->first(); $g = App\Models\Game::find("01a1da12-16c6-710a-b3ae-730f99045d7f"); app(App\Services\NotificationService::class)->send($u, new App\Notifications\WaitlistPromoted($g, "Friday, 18:00"), App\Enums\NotificationCategory::WaitlistPromoted); echo "SENT".PHP_EOL;'
php artisan tinker --no-interaction --execute '$u = App\Models\User::where("email", "uat-mail-en@roundup.test")->first(); $g = App\Models\Game::find("01a1da12-16c6-710a-b3ae-730f99045d7f"); $inviter = App\Models\User::where("email", "demo-admin@roundup.games")->first(); app(App\Services\NotificationService::class)->send($u, new App\Notifications\EntityInvitation($g, $inviter), App\Enums\NotificationCategory::GameInvitation); echo "SENT".PHP_EOL;'
php artisan tinker --no-interaction --execute '$u = App\Models\User::where("email", "uat-mail-de@roundup.test")->first(); $g = App\Models\Game::find("01a1da12-16c6-710a-b3ae-730f99045d7f"); $inviter = App\Models\User::where("email", "demo-admin@roundup.games")->first(); app(App\Services\NotificationService::class)->send($u, new App\Notifications\EntityInvitation($g, $inviter), App\Enums\NotificationCategory::GameInvitation); echo "SENT".PHP_EOL;'
```

Post-send review anchors on invariants (every filtered message tagged;
sent subjects present; negative filter empty), never exact mailbox
totals — the shared mailbox accrues S02/S06 messages by design.

### 10.5 Operator session record — 2026-11-15 (items A/B/C executed)

The §10.1–§10.3 operator legs were executed live in one session on
2026-11-15. Environment: ephemeral tunnel
`feb-surveillance-thoroughly-diy.trycloudflare.com` (Interactions
Endpoint URL pasted in the dev portal; quick tunnels are per-process and
must be re-pasted each session), local `php artisan serve` on :8000 with
`LOG_CHANNEL=stderr` teed to `.gsd/session-logs/serve-8000.log`, and
`QUEUE_CONNECTION=sync` throughout. Single-Discord-account adaptation:
the operator's Discord linked to the host account (demo-admin) to claim
the D119 publish opt-in and publish the card; the link row was then
removed (no user-facing unlink exists — removed via tinker) so phase 1
ran as a genuinely unlinked clicker, whose deep-link OAuth registered a
second roundup account (elisavhelesburg@yahoo.com) that performed the
phase-2 join on the host's game.

**Item A — all evidence green except one documented degradation.**
Full log chain captured (serve log): `discord_interaction.unlinked_deep_link`
→ `discord_join_intent.set` → linked_accounts row → `menu_shown`
(is_owner:false) → Join click → `join_dispatched` →
`participant.joined {join_source:"discord", status:"approved"}` →
`discord_rsvp.completed {status:"approved"}`; join-row read query
returned `status=approved, join_source=JoinSource::Discord`;
`notification.discord_dm_skipped {reason:no_linked_account,
app_id_short:7525}` produced via the §10.4 one-liner. Degradation: with
sync queue the deferred ack exceeded Discord's 3-second window — the
client flashed "Roundup Games didn't respond in time" AND the `@original`
PATCH returned `404 Unknown Webhook (10015)` (the runbook's "token stays
valid 15 min" expectation did not hold once the ack was missed), so no
ephemeral confirmation was visible; the join row, logs, and attribution
were unaffected. Clean-ack sessions should use `QUEUE_CONNECTION=database`
+ `queue:work`.

**Item B — device leg completed on Firefox; two live findings.**

- *B1 (fixed in-repo, same session):* Enable/Disable push had never
  worked from the UI — `POST/DELETE api/v1/push/subscribe` sit behind
  `auth:sanctum` without Sanctum's stateful middleware, so first-party
  session cookies (push.js `credentials: 'same-origin'`) were ignored and
  every browser subscribe/unsubscribe 401'd (instant rejection; the only
  subscription row in history was the synthetic one, which Disable could
  not shed either). Fixed by adding
  `EnsureFrontendRequestsAreStateful` to the authenticated api/v1 group
  (routes/api.php) + two regression tests in PushSubscriptionApiTest
  (structural middleware pin + cookie-less 401 pin; actingAs-based tests
  seed the guard directly and cannot catch this class). Suite 18 passed;
  pre-commit chain green.
- *B2 (documented limitation):* Safari subscriptions (web.push.apple.com)
  are rejected by APNs with `403 Forbidden` on localhost: the VAPID `sub`
  claim is APP_URL (`http://localhost:8000`) and Apple requires an https
  origin. Real-device pushes on localhost should use Firefox or Chrome;
  production (https APP_URL) is unaffected.

Final device proof: Firefox 161/macOS subscription under the current
VAPID pair; `notifications:push-test waitlist_promoted` exit 0 with a
per-device `push.sent` line for the Mozilla endpoint; notification
banner human-confirmed on the device. The Safari row remains
(403-ing); the synthetic row was shed.

**Item C — verbatim canon match.** German-client post-join menu matched
`lang/de/notifications.php` exactly („✅ Du bist dabei — Dein Platz ist
sicher. 🎲“ + 🚪 Verlassen) and the card's When field rendered a
viewer-local German datetime; `menu_shown {current_status:"approved"}`
logged for the same click.

**Session side-findings (recorded, follow-ups):** onboarding broken by
`TypeError: can't redefine non-configurable property "$persist"`
(MEM1728, adoption-critical, suspected Alpine Persist double-load);
settings-page `ReferenceError: activeTab is not defined` (MEM1730); the
unlinked-onramp "one tap from your seat" promise gap when onboarding
blocks the post-link redirect (MEM1729). Push-test fixture games from this session are manual-cleanup per §9. The
tunnel was retired after this record (ephemeral per session anyway); the
local serve process was left running for the operator's app session.
