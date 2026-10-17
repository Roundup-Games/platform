#!/usr/bin/env bash
# Create or relocate an Orca workspace that is GSD-live: it shares the main
# project's GSD state (decisions, requirements, knowledge, milestones) with
# full read/write, concurrently with other workspaces and the main checkout.
#
# How it works: GSD only exempts worktrees from its one-DB-per-checkout guard
# when they physically live in a recognized layout — including
# ~/.gsd/projects/<hash>/worktrees/<name>/ (its own external-state layout).
# This wrapper relocates the Orca-created worktree there. Orca auto-resyncs
# its registration to the new path; a convenience symlink stays at the old
# ~/orca path. Every process inside then sees the recognized path and gets
# the shared DB via the worktree's .gsd symlink.
#
# Usage:
#   bin/orca-gsd-workspace.sh create <name> [-- extra orca worktree-create flags]
#   bin/orca-gsd-workspace.sh relocate <name>   # adopt an existing orca workspace
#
# Do NOT use for workspaces that must stay GSD-void (plain git scratch work) —
# create those directly with `orca worktree create` and never run GSD in them.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
REPO_NAME="$(basename "$REPO_ROOT")"
ORCA_WS_ROOT="$HOME/orca/workspaces/$REPO_NAME"

gsd_external_dir() {
  local target
  target="$(readlink "$REPO_ROOT/.gsd" || true)"
  if [ -z "$target" ]; then
    echo "ERROR: $REPO_ROOT/.gsd is not a symlink to external GSD state" >&2
    exit 1
  fi
  cd "$REPO_ROOT/.gsd" && pwd -P
}

EXTERNAL_DIR="$(gsd_external_dir)"
WORKTREES_DIR="$EXTERNAL_DIR/worktrees"
mkdir -p "$WORKTREES_DIR"

MODE="${1:-}"; NAME="${2:-}"
if [ -z "$MODE" ] || [ -z "$NAME" ]; then
  sed -n 'p' <<< "Usage: $0 create <name> [-- extra orca flags] | relocate <name>" >&2
  exit 1
fi
shift 2

ORCA_PATH="$ORCA_WS_ROOT/$NAME"
NEW_PATH="$WORKTREES_DIR/$NAME"

case "$MODE" in
  create)
    if [ -e "$ORCA_PATH" ] || [ -e "$NEW_PATH" ]; then
      echo "ERROR: workspace '$NAME' already exists ($ORCA_PATH or $NEW_PATH)" >&2
      exit 1
    fi
    echo "[orca-gsd-workspace] creating orca worktree '$NAME' (setup deferred)"
    orca worktree create --name "$NAME" --setup skip --no-parent \
      --repo "path:$REPO_ROOT" "$@"
    if [ ! -d "$ORCA_PATH" ]; then
      echo "ERROR: expected orca to create $ORCA_PATH — check the name/dir mapping" >&2
      exit 1
    fi
    ;;
  relocate)
    if [ ! -d "$ORCA_PATH" ]; then
      echo "ERROR: no orca workspace at $ORCA_PATH" >&2
      exit 1
    fi
    if [ -e "$NEW_PATH" ]; then
      echo "ERROR: $NEW_PATH already exists" >&2
      exit 1
    fi
    echo "[orca-gsd-workspace] adopting existing orca workspace at $ORCA_PATH"
    ;;
  *)
    echo "ERROR: unknown mode '$MODE' (create|relocate)" >&2
    exit 1
    ;;
esac

echo "[orca-gsd-workspace] moving worktree into GSD external layout"
git -C "$REPO_ROOT" worktree move "$ORCA_PATH" "$NEW_PATH"

echo "[orca-gsd-workspace] linking shared GSD state"
ln -sfn "$EXTERNAL_DIR" "$NEW_PATH/.gsd"

echo "[orca-gsd-workspace] convenience symlink at old orca path"
ln -sfn "$NEW_PATH" "$ORCA_PATH"

echo "[orca-gsd-workspace] provisioning (full setup)"
( cd "$NEW_PATH" && bash "$REPO_ROOT/bin/setup-worktree.sh" )

echo "[orca-gsd-workspace] done: $NEW_PATH"
echo "[orca-gsd-workspace] orca selectors: name:$NAME branch:<branch> path:$NEW_PATH"
