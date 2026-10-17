# Agent Worktrees & GSD — Operating Policy

This repo runs two worktree factories. Keep their roles strict, or GSD
silently splits its project state (this happened once — see "Failure mode"
below).

## Roles

| Factory | Owns | Location | GSD state |
|---|---|---|---|
| **GSD** | Execution worktrees (milestone/slice work) | `<repo>/.gsd-worktrees/<id>` | **Shared** with the main checkout: full history, requirements, knowledge, one DB |
| **Orca** | Workspace UX, agent launch, orchestration, lineage | `~/orca/workspaces/...` | **None.** Plain git work only. |

## Rules

1. **All GSD workflow commands** (`gsd_*` tools, `/gsd`) run in the main
   checkout or in a GSD worktree (`.gsd-worktrees/<id>`) — never anywhere
   else.
2. **Never run GSD commands from orca-managed worktrees.** GSD resolves
   `~/orca/workspaces/...` as a *separate project* (its worktree detection
   is path-layout based) and bootstraps a fresh, empty, competing state —
   split-brain: no history in, no history out.
3. **Orca attaches to GSD worktrees, it does not create GSD workspaces:**
   ```
   orca agent launch --worktree path:/Users/elis/Work/roundup-games-laravel/.gsd-worktrees/<id>
   orca orchestration worker-start --worktree path:<same path> ...
   ```
   External worktree visibility is enabled for this repo, so GSD worktrees
   appear in Orca's UI.
4. **Provisioning** comes from `bin/setup-worktree.sh` — one script used by
   both factories:
   - orca.yaml `scripts.setup` → full mode (blocking, at worktree creation);
   - GSD `git.worktree_post_create` (`.gsd/PREFERENCES.md`) → `--fast` mode:
     `.env` + `laravel-boost` MCP seed synchronous, dependencies continue in
     the background (`.worktree-setup.log` in the worktree root). If you are
     the first agent in a fresh GSD worktree and tests fail on missing
     dependencies, check that log first.
5. **No migrations from worktrees.** Tests boot their own throwaway Postgres
   testcontainer (`tests/bootstrap.php`), exactly like CI.
6. **Merge back** with `/gsd worktree merge <name>` or leave it to auto
   mode's per-milestone merge. `.gsd-worktrees/` is gitignored; worktree
   branches follow `worktree/<name>`.

## Why this design

GSD stores project state in a per-repo external directory
(`~/.gsd/projects/<hash>`, hash of the git remote) and binds its database to
one owning checkout. Worktrees are exempt from that binding **only** when
they live in a recognized layout (`.gsd-worktrees/`, `.gsd/worktrees/`, or
the external `projects/<hash>/worktrees/`). Foreign paths are treated as new
projects — a deliberate guard so two checkouts never share one workflow DB
unsafely. Aligning with the recognized layout is therefore the only
supported way to give isolated worktrees full GSD history.

## Failure mode (observed 2026-10-17)

An agent session ran GSD inside an orca worktree
(`~/orca/workspaces/roundup-games-laravel/salp`). GSD bootstrapped a fresh
state beside the main one; sessions dispatched subagents against an empty
history until noticed. The session's valuable planning work was rescued
2026-10-17 into the main project (milestones M069–M071, decisions D184–D188,
requirements R079–R088; see `.gsd/rescue/salp-20261017/` for the frozen
snapshot and `RESCUE-REPORT.md` for the ID mapping). The worktree's orphaned
state remains beside it as `.gsd.orphaned-salp-20261017` and can be deleted
once the rescue is confirmed.
