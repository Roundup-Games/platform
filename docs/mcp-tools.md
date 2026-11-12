# MCP Tool Catalog

The agent surface of Roundup Games: one curated MCP server (`App\Mcp\Servers\RoundupServer`)
whose every registered tool is listed below. This catalog is **machine-checked** —
`tests/Feature/Mcp/ToolCatalogParityTest.php` parses the list items and fails CI when the
documented set and the registry drift in either direction: a registered tool without a doc
line, a doc line without a registry entry, a wrong scope, or a wrong argument list all fail
the build.

- **Transport:** `POST /mcp` (JSON-RPC, MCP protocol `2026-07-28`), CSRF-free for agents.
- **Auth:** bearer Sanctum personal-access token linked to an `mcp_clients` consumer row;
  every dispatch resolves to a live, unrevoked consumer owned by the token's user.
- **Scopes:** tool-class abilities on the token — `mcp:read`, `mcp:write`, `mcp:host`,
  `mcp:ops` (checked with `tokenCan()` before dispatch). `read`/`write` are self-service
  from user settings; `host` is self-service for holders of the active Game Master role
  (a GM subscription perk, R097/D278) and dual-gated: the token needs the ability **and**
  its owner must still hold the Game Master role (Platform Admins pass); `ops` is
  admin-issued and dual-gated: the token needs the ability **and** its owner must still
  be a Platform Admin.
- **Usage telemetry:** the admin report at `/admin/mcp-usage` (System group) aggregates the
  `mcp_calls` audit trail per consumer and per tool.

## Registered tools

Exactly one line per registered tool, in registry order. Format (parsed by the parity test):
`- tool \`name\` — scope \`ability\` — class \`read|write|mixed\` — args \`comma, separated\``
where `args` is the tool's declared argument list (`none` when argument-free; parity-checked against
the schema) and `class` is the read/write character of the tool (`mixed` = one tool spanning both,
always narrowly; descriptive only — not parity-checked).

- tool `platform.info` — scope `mcp:read` — class `read` — args `none`
- tool `games.search` — scope `mcp:read` — class `read` — args `q, city, radius, language, experience, game_system, date, limit, page`
- tool `games.detail` — scope `mcp:read` — class `read` — args `game_id`
- tool `my.sessions` — scope `mcp:read` — class `read` — args `limit`
- tool `my.dashboard` — scope `mcp:read` — class `read` — args `none`
- tool `games.join` — scope `mcp:write` — class `write` — args `game_id, idempotency_key`
- tool `games.apply` — scope `mcp:write` — class `write` — args `game_id, message, idempotency_key`
- tool `games.leave` — scope `mcp:write` — class `write` — args `game_id, idempotency_key`
- tool `host.roster` — scope `mcp:host` — class `read` — args `game_id, campaign_id`
- tool `host.charter` — scope `mcp:host` — class `read` — args `campaign_id`
- tool `host.attendance-summary` — scope `mcp:host` — class `read` — args `game_id`
- tool `host.attendance.report` — scope `mcp:host` — class `write` — args `game_id, reports, idempotency_key`
- tool `host.attendance.resolve` — scope `mcp:host` — class `write` — args `game_id, idempotency_key`
- tool `host.recap.write` — scope `mcp:host` — class `write` — args `game_id, body, idempotency_key`
- tool `host.charter.amend` — scope `mcp:host` — class `write` — args `campaign_id, title, content, changelog, expected_base_version, idempotency_key`
- tool `ops.tickets` — scope `mcp:ops` — class `mixed` — args `action, ticket_id, assignee_id, note, status, priority, ticket_type, department, open, search, per_page, idempotency_key`
- tool `ops.member.lookup` — scope `mcp:ops` — class `read` — args `user_id, email`
- tool `ops.moderation` — scope `mcp:ops` — class `write` — args `action, ticket_id, note, idempotency_key`

### What the tools do

| Tool | Scope | Purpose |
|------|-------|---------|
| `platform.info` | read | Deployment metadata (app name, canonical URL, locale) — the first call every agent makes to confirm which deployment it talks to. |
| `games.search` | read | Upcoming open-for-discovery games, same result set and visibility scoping as the website feed; free-text, city, radius, language, experience, system, date-window filters; paginated. |
| `games.detail` | read | One game's details: date, graduated venue disclosure, seats, safety tools, systems, cutoff, web link. |
| `my.sessions` | read | The acting user's upcoming sessions and active campaigns with next scheduled sessions. |
| `my.dashboard` | read | Compact weekly summary: session counts, games grouped by day, next session. |
| `games.join` | write | Join an open game via the same intake as the web join (full → waitlist/bench); `idempotency_key` makes retries safe. |
| `games.apply` | write | Apply to a protected game, optionally with a short host message. |
| `games.leave` | write | Leave a game (seat, waitlist, bench, or pending application) with the same seat-freeing cascade as the web leave. |
| `host.roster` | host | Roster of a hosted game/campaign: participants by status with role, join date, reliability summary. |
| `host.charter` | host | Charter status of an owned campaign: lifecycle, version, amendments, signing and countersignature progress. |
| `host.attendance-summary` | host | Resolved attendance summary of a completed hosted game. |
| `host.attendance.report` | host | Submit host attendance reports for a completed game (attended / no_show / excused; excused requires a reason). |
| `host.attendance.resolve` | host | Resolve consensus attendance for a completed game: weighted votes, reliability recompute, notifications. |
| `host.recap.write` | host | Write the final recap of a completed campaign session (write-once, no draft state). |
| `host.charter.amend` | host | Amend a published charter: immutable next version, changelog, stale-version refusal via `expected_base_version`. |
| `ops.tickets` | ops | Escalated helpdesk triage for Platform Admins: list (filters, search, pagination), assign, resolve. |
| `ops.member.lookup` | ops | Support lookup of one member by id or email: identity, ban state, roles, reliability tier, membership. |
| `ops.moderation` | ops | Safety-department moderation actions over content/report tickets — 1:1 with the admin panel actions of the same names. |

## Boundary map

The rules every tool obeys — the reason this surface can be exposed to autonomous consumers:

1. **No arbitrary SQL.** Tools never compose raw SQL or query across arbitrary tables; they
   read through the same Eloquent models and query services the web surface uses
   (discovery reads go through the `DiscoveryQueryService` seam).
2. **No god-tools.** One tool per web affordance, never a `do_anything` escape hatch; even
   the widest tool (`ops.tickets`) is triage-scoped to list/assign/resolve on the helpdesk
   queue, and `ops.moderation` actions map 1:1 to existing admin panel actions. Tools are
   hand-registered in the registry — never auto-discovered — so the surface cannot grow by
   accident.
3. **Service-layer-only writes.** Every write dispatches through the identical domain
   service the web controller calls (participant intake, charter, attendance resolution,
   journal, helpdesk services); tool classes hold no domain logic, no policy decisions, and
   zero participant-status writes — the INV-15 invariant holds (participant creation and
   status writes are confined to the nine sanctioned modules; `scripts/check_platform_invariants.sh`
   pins it, and the tool classes' write-free discipline is asserted by the MCP feature
   suites).
4. **Fail-closed gate.** `McpAuthentication` runs auth → rate limit → dispatch policy →
   dispatch, in order; every rejection answers precise JSON (401/403/400/429) carrying a
   stable snake_case `error.reason` (unauthenticated / no_mcp_abilities / ability_denied /
   host_requires_gm / ops_requires_admin / tool_disabled / undeclared_arguments /
   rate_limited; 500s sanitize to `dispatch_exception`) and an
   exception anywhere past auth becomes a sanitized 500, never a stack trace.

Additional agent-safety mechanics: write tools accept `idempotency_key` (a retried call
replays the first answer instead of double-executing); a cache-TTL'd kill-switch can refuse
any tool with a 403 without a release; undeclared arguments are refused with the accepted
list named; argument values are never logged or stored in the clear.

## Rate limits

Per-consumer buckets keyed on the consumer uuid with a read/write suffix (tier from
`mcp_clients.rate_limit_tier`; unknown tiers fail safe to `standard`):

| Tier | Bucket | Limit | Window |
|------|--------|-------|--------|
| standard | read | 60 | 60s |
| standard | write | 10 | 60s |

Read-class tools share the read bucket; write/host/ops tools draw from the tighter write
bucket. Exceeding a bucket answers 429 with `Retry-After` and an `mcp.throttle` structured
log event.

## Audit trail

Every dispatched `tools/call` leaves an append-only `mcp_calls` row: consumer
(`mcp_client_id`), acting user, tool, a **sha256 hash** of the arguments (values never in
the clear), latency, `actor=agent` provenance marker, and — for ops calls — the
`target_type`/`target_id` of the entity operated on. Calls rejected before dispatch
(auth, throttle, kill-switch, argument discipline) leave no audit row; they surface as
`mcp.reject` / `mcp.throttle` structured log events instead.

Retention: deleting a user purges their MCP audit trail (cascade `mcp_clients` →
`mcp_calls`) — a deliberate GDPR-clean purge, unlike the FK-free `alertable_errors`
precedent. Revoked consumers retain history; deleted users do not.

## Where docs meet code

- Registry (single source of truth): `app/Mcp/Servers/RoundupServer.php`
- Gate (auth, throttling, dispatch policy, audit): `app/Http/Middleware/McpAuthentication.php`
- Drift guard (this document ↔ registry): `tests/Feature/Mcp/ToolCatalogParityTest.php`
- Usage telemetry (audit trail → admin report): `app/Filament/Pages/System/McpUsagePage.php` at `/admin/mcp-usage`
