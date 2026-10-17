# Agent Worktrees & GSD — Operating Policy

This repo runs two worktree factories plus one bridge. Keep the roles strict,
or GSD silently splits its project state (this happened once — see "Failure
mode" below).

## Roles

| Factory | Owns | Location | GSD state |
|---|---|---|---|
| **GSD** | Execution worktrees (milestone/slice work) | `<repo>/.gsd-worktrees/<id>` | **Shared** with the main checkout: full history, requirements, knowledge, one DB |
| **Orca** | Workspace UX, agent launch, orchestration, lineage | `~/orca/workspaces/...` | **None** for plain workspaces (GSD-void) |
| **Orca + GSD bridge** | Concurrent feature workspaces that need full GSD meta | `~/.gsd/projects/<hash>/worktrees/<name>` (relocated by `bin/orca-gsd-workspace.sh`, orca auto-follows) | **Shared** — first-class GSD worktrees in GSD's own external layout |

## Rules

1. **All GSD workflow commands** (`gsd_*` tools, `/gsd`) run in the main
   checkout, in a GSD worktree (`.gsd-worktrees/<id>`), or in a bridged orca
   workspace (under `~/.gsd/projects/<hash>/worktrees/`).
2. **The location decides everything.** GSD exempts a worktree from its
   one-DB-per-checkout guard only when the worktree *physically* lives in a
   recognized layout. A worktree still under `~/orca/workspaces/...` is a
   foreign checkout: running GSD there bootstraps split-brain state, and
   symlinking `.gsd` there is refused (`checkout-unbound`).
3. **Concurrent GSD-live orca workspaces** — create them with the bridge:
   ```
   bin/orca-gsd-workspace.sh create <name> [-- --agent codex --prompt "..."]
   bin/orca-gsd-workspace.sh relocate <name>   # adopt an existing orca workspace
   ```
   The wrapper relocates the worktree into the GSD external layout, links the
   shared state, keeps a convenience symlink at the old orca path, and runs
   provisioning. Orca re-registers the workspace at its new path; address it
   via `name:<name>`, `branch:<branch>`, or the new path. Multiple bridged
   workspaces + the main checkout share one DB concurrently (WAL, by design).
   Before relocating a workspace with a live agent session, close the session
   first (its cwd is about to move).
4. **Plain orca workspaces** (`orca worktree create` without the bridge) are
   for GSD-void work: plain git/Laravel changes, no `gsd_*`, no `/gsd`.
5. **Provisioning** comes from `bin/setup-worktree.sh` — one script used by
   every path (orca.yaml setup, GSD's `worktree_post_create` fast hook, the
   bridge wrapper). GSD hook budget is 30s: `--fast` does env + MCP seed
   synchronously, dependencies continue in the background
   (`.worktree-setup.log` in the worktree root). First agent in a fresh GSD
   worktree: check that log if tests fail on missing dependencies.
6. **No migrations from worktrees.** Tests boot their own throwaway Postgres
   testcontainer (`tests/bootstrap.php`), exactly like CI.
7. **One executor per worktree.** Never run GSD auto *and* an orca agent in
   the same worktree simultaneously — two writers on one checkout.
8. **Merge back** with `/gsd worktree merge <name>` (GSD-owned) or normal
   git/PR flow (orca workspaces). `.gsd-worktrees/` is gitignored; GSD
   worktree branches follow `worktree/<name>`.

## Why this design

GSD stores project state in a per-repo external directory
(`~/.gsd/projects/<hash>`, hash of the git remote) and binds its database to
one owning checkout. Worktrees are exempt from that binding **only** when
they physically live in a recognized layout (`.gsd-worktrees/`,
`.gsd/worktrees/`, or the external `projects/<hash>/worktrees/`). Foreign
paths are treated as new projects — a deliberate guard so two checkouts never
share one workflow DB unsafely. The bridge puts orca workspaces into that
recognized layout, which makes them first-class GSD participants with zero
GSD patches. Probes verified: shared state queries, orca terminal attach, and
orca registry auto-resync all work from the relocated layout.

## Failure mode (observed 2026-10-17)

An agent session ran GSD inside an orca worktree
(`~/orca/workspaces/roundup-games-laravel/salp`). GSD bootstrapped a fresh
state beside the main one; sessions dispatched subagents against an empty
history until noticed. The session's valuable planning work was rescued
2026-10-17 into the main project (milestones M069–M071, decisions D184–D188,
requirements R079–R088; see `.gsd/rescue/salp-20261017/` for the frozen
snapshot and `RESCUE-REPORT.md` for the ID mapping). The worktree and its
branch were merged into main and retired.
