{{-- Public agent self-onboarding document served at /agents.md — written
     FOR an AI personal assistant, second person, EN-only (the D274
     markdown-alternates precedent: agent-consumption docs are EN-only).
     Sync duty: the tool catalog section is GENERATED from
     AgentsMarkdownController::catalog() (RoundupServer::TOOL_REGISTRY +
     each tool's Description first sentence), the protocol versions from
     ProtocolVersion::initializeSupported(), the rate-limit clauses from
     config('mcp.rate_limits.tiers.standard'), and the idempotency replay
     window from config('mcp.idempotency.ttl_seconds') — never hand-copy
     a tool name, version, or operating-rule number into this file;
     AgentsMarkdownTest pins parity. Hand-maintained prose lists with a
     sync duty of their own: the gate reasons under "The wire" (every
     snake_case reason McpAuthentication can emit) and the denial codes
     under "Denials are coded" (the codes the tools' denial helpers
     return) — update both lists when middleware or tools gain codes.
     The rest of the copy is hand-authored and honest (D179: only shipped
     behavior). NOTE: every interpolation is raw ({!! !!}) on purpose —
     this view serves text/markdown, and Blade's {{ }} would entity-encode
     apostrophes/ampersands into the body (&#039; etc.). Pinned by
     AgentsMarkdownTest's HTML-chrome/entity ban. --}}
# Roundup Games: agent onboarding

You are an AI assistant about to act for a human player on Roundup Games — a platform for finding and hosting in-person tabletop games, from one-evening board games to long-running RPG campaigns.

You act only on behalf of the human who owns your credential: the same person, the same rules, and the same limits as the website. Every call you make is recorded on the same audit trail as web actions and marked as agent-made — nothing you do is anonymous. This page tells you how to connect, what you can call, and how to act well.

## Getting connected

Connecting is a human-in-the-loop step — you cannot mint your own credential.

1. Ask your owner to open **Settings → Agents** (the "Agents" tab) on the website.
2. They create an agent credential with the preset that fits what you should be able to do:
   - **Read-only companion** — search games, read their sessions and their week.
   - **Participant** (read + write) — also join, apply to, and leave games on their behalf.
   - **GM workspace** — adds the host tools (rosters, attendance, charters, recaps); requires an active Game Master membership.
3. The website shows the token exactly once, and your owner hands it to you. Never ask them to send it through a channel they do not already trust — it grants access to their account.
4. Your owner can revoke the credential anytime from the same tab; revocation is immediate.

The server URL is the platform root plus `/mcp` — for this deployment, `{!! $serverUrl !!}`.

If your host app has a native "connect MCP server" or "add connector" flow (ChatGPT, Claude, Gemini), use it: enter the server URL above and the token as the bearer token. For any other client, raw HTTP works:

```
curl -X POST {!! $serverUrl !!} \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -H 'Authorization: Bearer $ROUNDUP_MCP_TOKEN' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"my-assistant","version":"1.0"}}}'

# then your first tool call:
curl -X POST {!! $serverUrl !!} \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -H 'Authorization: Bearer $ROUNDUP_MCP_TOKEN' \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"platform.info","arguments":{}}}'
```

## The wire

- Transport: JSON-RPC 2.0 over streamable HTTP. One endpoint — `POST /mcp`. A response may arrive as `application/json` or as a `text/event-stream` body; handle both.
- Protocol versions this deployment supports for the `initialize` handshake (newest first): {!! $protocolVersions !!}. Pass one in `params.protocolVersion`; an unsupported request is answered with the supported list.
- Authentication: `Authorization: Bearer` with the personal access token your owner created — on every request.
- Rejections at the gate are structured JSON, never HTML: `{"error":{"reason":"...","message":"..."}}` with a precise status and a stable snake_case `reason` you can branch on — `unauthenticated` (401), `no_mcp_abilities` / `ability_denied` / `host_requires_gm` / `ops_requires_admin` / `tool_disabled` (403), `undeclared_arguments` (400, the message names the accepted arguments), `rate_limited` (429, with `Retry-After`), `dispatch_exception` (500, sanitized — never a stack trace).

## Tools

Every tool this deployment exposes, generated from the live registry. The scope is the token ability a tool requires — a tool your credential lacks answers 403 `ability_denied`. Call `tools/list` for each tool's full input schema.

@foreach($toolGroups as $group)
### {!! $group['heading'] !!} ({!! $group['scope'] !!})

{!! $group['note'] !!}

@foreach($group['tools'] as $tool)
- `{!! $tool['name'] !!}` — scope `{!! $tool['scope'] !!}` — {!! $tool['purpose'] !!}
@endforeach

@endforeach
## Operating rules

### Scopes

Credentials carry abilities: `mcp:read` (discovery and your owner's sessions), `mcp:write` (join, apply, leave), `mcp:host` (the host workspace, requiring your owner's active Game Master membership), `mcp:ops` (platform operations). Each tool above names the ability it requires.

### Rate limits

Read-class calls: {!! $rateLimits['read'] !!}. Write-class calls: {!! $rateLimits['write'] !!} (standard tier). The buckets are per credential and enforced server-side. When you get a 429, stop and honor `Retry-After` — do not retry sooner.

### Idempotency

Every write tool accepts an optional `idempotency_key`. Send one on any action you might retry — a join, an application, a leave, a host write. A retried call with the same key replays the first stored answer instead of executing twice; the stored answer persists for {!! $idempotencyWindow !!} — a retry after that executes anew, so use a fresh key when you retry across days. Use a fresh key for every distinct action.

### Denials are coded

Tool refusals carry a stable snake_case code before the prose message (for example `not_found: Game not found.`). Branch on the code, never parse the message. Codes you will meet include `not_found`, `own_game`, `application_required`, `signup_closed`, `already_participant`, and `base_version_stale`.

### Confirmations belong to your owner

A join or apply can return `status: confirmation_required` with a `confirmation_handle` and a deadline: your owner was promoted from a waitlist and must confirm the seat themselves in the web app before it expires. Never attempt to confirm on your owner's behalf — no tool exists for it, and that is deliberate. Surface the deadline and the game link to your owner and let them act.

### Join versus apply

`games.join` is for open games; `games.apply` is for screened ones. The tool tells you which: joining a screened game answers `application_required`, applying to an open one answers `game_open`.

### Attribution

Writes go through the same services as web actions and land on the same audit trail, attributed to your owner and marked agent-made.

### Over the long run

- **If every call begins answering 401 `unauthenticated`**, your credential was revoked or expired. Do not retry in a loop: tell your owner, who can issue a fresh credential from Settings → Agents and reconnect you with it.
- **If host tools begin answering 403 `host_requires_gm`**, your owner's Game Master membership lapsed — the host tools return the moment it is active again; nothing is lost.
- **The tool catalog can grow.** When a call answers with an unknown-tool error, re-fetch `tools/list` before assuming a tool is gone.

## A good starter flow

1. `platform.info` — confirm which deployment you are talking to.
2. `games.search` for games your owner might like, or `my.dashboard` for their week.
3. `games.detail` on the interesting ones.
4. Discuss the options with your owner.
5. `games.join` or `games.apply` — with a fresh `idempotency_key`.
