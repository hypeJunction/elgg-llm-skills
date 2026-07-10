#!/usr/bin/env bash
# create-missing-migration-branches.sh
# Creates missing migrate/elgg-3.x (and optionally migrate/elgg-4.x) branches
# for plugins that were migrated directly from 2.x to 5.x (or 4.x) without
# creating the intermediate 3.x step.
#
# Usage:
#   ./bin/create-missing-migration-branches.sh
#
# Requirements:
#   - ELGG_PLUGINS_DIR: directory containing plugin repos. If not set, falls
#     back to bin/discover-plugins.sh output. Required.
#   - ELGG_MIGRATE_DIR: directory of the elgg-migrate repo. Default: directory
#     containing this script's parent.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ELGG_MIGRATE_DIR="${ELGG_MIGRATE_DIR:-$(cd "$SCRIPT_DIR/.." && pwd)}"

# Resolve PLUGINS_DIR: ELGG_PLUGINS_DIR env > discover-plugins.sh > error
PLUGINS_DIR=""
if [[ -n "${ELGG_MIGRATE_PLUGINS:-${ELGG_PLUGINS_DIR:-}}" ]]; then
    PLUGINS_DIR="${ELGG_MIGRATE_PLUGINS:-${ELGG_PLUGINS_DIR:-}}"
elif [[ -x "$SCRIPT_DIR/discover-plugins.sh" ]]; then
    _disc_out="$(bash "$SCRIPT_DIR/discover-plugins.sh" 2>/dev/null || true)"
    PLUGINS_DIR="$(echo "$_disc_out" | grep 'PLUGINS_DIR=' | head -1 | cut -d= -f2-)"
    unset _disc_out
fi
if [[ ! -d "${PLUGINS_DIR:-}" ]]; then
    echo "ERROR: Set ELGG_PLUGINS_DIR=/path/to/your/plugins or run:" >&2
    echo "  bin/discover-plugins.sh --root /path/to/plugins --save-config" >&2
    exit 1
fi
MANIFEST_2X_3X="$ELGG_MIGRATE_DIR/skills/elgg-migrate/rules/2x-to-3x/manifest.json"
MANIFEST_3X_4X="$ELGG_MIGRATE_DIR/skills/elgg-migrate/rules/3x-to-4x/manifest.json"
MIGRATE_PHP="$ELGG_MIGRATE_DIR/skills/elgg-migrate/bin/migrate.php"

# Plugins that need migrate/elgg-3.x (22 total)
PLUGINS_NEED_3X=(
  hypeajax
  hypeautocomplete
  hypecapabilities
  hypedownloads
  hypegit
  hypegroups
  hypehero
  hypemapsopen
  hypemarkup
  hypepayments
  hypepaywall
  hypepost
  hypepostadmin
  hypeprofile
  hypeshortcode
  hypeslug
  hypestash
  hypetheme
  hypetime
  hypetrees
  hypetwig
  hypevue
)

# Plugins that ALSO need migrate/elgg-4.x (5 total, all subset of above)
PLUGINS_NEED_4X=(
  hypedownloads
  hypegit
  hypemarkup
  hypepayments
)

# Summary tracking
DONE_3X=()
DONE_4X=()
SKIPPED=()
FAILED=()

log() { echo "[$(date '+%H:%M:%S')] $*"; }
log_ok() { echo "  ✓ $*"; }
log_skip() { echo "  ~ $* (skipped)"; }
log_err() { echo "  ✗ $*" >&2; }

# Find the 2.x base commit for a plugin
# Strategy:
#   1. If oldest migrate branch is 4.x: use merge-base(4.x, main/master)
#   2. If oldest migrate branch is 5.x+: use last pre-2020 commit on main/master
#      (these plugins jumped directly from 2.x to 5.x)
find_2x_base() {
  local dir="$1"
  local oldest
  oldest=$(git -C "$dir" branch -r 2>/dev/null | grep 'migrate/elgg-' | sort | head -1 | tr -d ' ')

  if [ -z "$oldest" ]; then
    # No migrate branches at all — use current main/master tip
    local main_branch
    main_branch=$(git -C "$dir" symbolic-ref refs/remotes/origin/HEAD 2>/dev/null | sed 's|refs/remotes/origin/||' || echo "main")
    git -C "$dir" rev-parse "origin/$main_branch" 2>/dev/null || git -C "$dir" rev-parse HEAD
    return 0
  fi

  local main_branch
  main_branch=$(git -C "$dir" symbolic-ref refs/remotes/origin/HEAD 2>/dev/null | sed 's|refs/remotes/origin/||' || echo "")
  if [ -z "$main_branch" ]; then
    main_branch=$(git -C "$dir" remote show origin 2>/dev/null | grep 'HEAD branch' | awk '{print $NF}' || echo "main")
  fi

  local base
  # Try main first, then master
  base=$(git -C "$dir" merge-base "$oldest" "origin/$main_branch" 2>/dev/null || \
         git -C "$dir" merge-base "$oldest" "origin/main" 2>/dev/null || \
         git -C "$dir" merge-base "$oldest" "origin/master" 2>/dev/null || echo "")

  if [ -z "$base" ]; then
    log_err "Could not find merge-base for $oldest"
    return 1
  fi

  # Check if this base is a recent commit (2020+) — meaning the plugin jumped from 2.x to 5.x
  local commit_year
  commit_year=$(git -C "$dir" log -1 --format="%ci" "$base" 2>/dev/null | cut -c1-4)
  if [ -n "$commit_year" ] && [ "$commit_year" -ge 2020 ]; then
    # The 2.x base is BEFORE these 2020+ commits — find last pre-2020 commit
    local pre2020
    pre2020=$(git -C "$dir" log "origin/$main_branch" --before="2020-01-01" --format="%H" | head -1)
    if [ -n "$pre2020" ]; then
      echo "$pre2020"
      return 0
    fi
    # Fallback: use the oldest commit available
    git -C "$dir" log "origin/$main_branch" --format="%H" | tail -1
    return 0
  fi

  echo "$base"
}

# Process a single plugin: create migrate/elgg-3.x
create_3x_branch() {
  local plugin="$1"
  local dir="$PLUGINS_DIR/$plugin"

  log "Processing $plugin → migrate/elgg-3.x"

  if [ ! -d "$dir/.git" ]; then
    log_err "$plugin: not a git repo at $dir"
    FAILED+=("$plugin:no-git")
    return 1
  fi

  # Check if 3.x already exists (local or remote)
  if git -C "$dir" branch -a | grep -q 'migrate/elgg-3\.x'; then
    log_skip "$plugin: migrate/elgg-3.x already exists"
    SKIPPED+=("$plugin")
    return 0
  fi

  # Fetch latest
  git -C "$dir" fetch origin --quiet 2>/dev/null || true

  # Re-check after fetch
  if git -C "$dir" branch -r | grep -q 'origin/migrate/elgg-3\.x'; then
    log_skip "$plugin: migrate/elgg-3.x already exists on remote"
    SKIPPED+=("$plugin")
    return 0
  fi

  # Find 2.x base
  local base
  base=$(find_2x_base "$dir") || {
    log_err "$plugin: could not determine 2.x base"
    FAILED+=("$plugin:no-base")
    return 1
  }

  log "  Base commit: $(git -C "$dir" log -1 --oneline "$base")"

  # Save and restore current branch
  local original_branch
  original_branch=$(git -C "$dir" rev-parse --abbrev-ref HEAD 2>/dev/null || echo "HEAD")
  local original_sha
  original_sha=$(git -C "$dir" rev-parse HEAD 2>/dev/null || echo "")

  # Create the branch
  git -C "$dir" checkout -b migrate/elgg-3.x "$base" 2>&1

  # Run 2x→3x migration
  log "  Running 2x→3x migration..."
  local migrate_output
  if migrate_output=$(php "$MIGRATE_PHP" \
      "$MANIFEST_2X_3X" \
      "$dir" \
      --verify --security 2>&1); then
    log_ok "Migration succeeded"
    echo "$migrate_output" | tail -5
  else
    local exit_code=$?
    # Exit code 2 = LLM-guided rules pending (not a failure)
    if [ $exit_code -eq 2 ]; then
      log_ok "Migration ran; LLM-guided rules need manual review"
      echo "$migrate_output" | tail -5
    else
      log_err "Migration failed (exit $exit_code): $migrate_output"
      # Cleanup and restore
      git -C "$dir" checkout "$original_sha" 2>/dev/null || true
      git -C "$dir" branch -D migrate/elgg-3.x 2>/dev/null || true
      FAILED+=("$plugin:migration-failed")
      return 1
    fi
  fi

  # Commit whatever changed
  local changed
  changed=$(git -C "$dir" status --porcelain 2>/dev/null)
  if [ -n "$changed" ]; then
    git -C "$dir" add -A
    git -C "$dir" commit -m "feat(3.x): migrate to Elgg 3.x"
    log_ok "Committed migration changes"
  else
    log "  No file changes from migration (2.x code may already be 3.x compatible)"
    # Still commit a no-op so the branch exists with proper history
    git -C "$dir" commit --allow-empty -m "feat(3.x): migrate to Elgg 3.x (no-op — code already compatible)"
    log_ok "Created empty migration commit"
  fi

  # Push
  git -C "$dir" push origin migrate/elgg-3.x 2>&1
  log_ok "Pushed migrate/elgg-3.x"

  # Restore original branch
  git -C "$dir" checkout "$original_sha" 2>/dev/null || git -C "$dir" checkout "$original_branch" 2>/dev/null || true

  DONE_3X+=("$plugin")
  return 0
}

# Process a single plugin: create migrate/elgg-4.x (must run after 3.x is done)
create_4x_branch() {
  local plugin="$1"
  local dir="$PLUGINS_DIR/$plugin"

  log "Processing $plugin → migrate/elgg-4.x"

  if [ ! -d "$dir/.git" ]; then
    log_err "$plugin: not a git repo at $dir"
    FAILED+=("$plugin:no-git-4x")
    return 1
  fi

  # Check if 4.x already exists
  if git -C "$dir" branch -a | grep -q 'migrate/elgg-4\.x'; then
    log_skip "$plugin: migrate/elgg-4.x already exists"
    return 0
  fi

  # Fetch
  git -C "$dir" fetch origin --quiet 2>/dev/null || true

  if git -C "$dir" branch -r | grep -q 'origin/migrate/elgg-4\.x'; then
    log_skip "$plugin: migrate/elgg-4.x already exists on remote"
    return 0
  fi

  # Ensure 3.x branch exists locally
  if ! git -C "$dir" branch -a | grep -q 'migrate/elgg-3\.x'; then
    log_err "$plugin: migrate/elgg-3.x not found — cannot create 4.x"
    FAILED+=("$plugin:no-3x-for-4x")
    return 1
  fi

  # Get the 3.x branch ref (prefer local, fall back to remote)
  local base_3x="migrate/elgg-3.x"
  if ! git -C "$dir" rev-parse "migrate/elgg-3.x" >/dev/null 2>&1; then
    base_3x="origin/migrate/elgg-3.x"
  fi

  # Save and restore current position
  local original_sha
  original_sha=$(git -C "$dir" rev-parse HEAD 2>/dev/null || echo "")

  # Create 4.x from tip of 3.x
  git -C "$dir" checkout -b migrate/elgg-4.x "$base_3x" 2>&1

  # Run 3x→4x migration
  log "  Running 3x→4x migration..."
  local migrate_output
  if migrate_output=$(php "$MIGRATE_PHP" \
      "$MANIFEST_3X_4X" \
      "$dir" \
      --verify --security 2>&1); then
    log_ok "Migration succeeded"
    echo "$migrate_output" | tail -5
  else
    local exit_code=$?
    if [ $exit_code -eq 2 ]; then
      log_ok "Migration ran; LLM-guided rules need manual review"
      echo "$migrate_output" | tail -5
    else
      log_err "Migration failed (exit $exit_code): $migrate_output"
      git -C "$dir" checkout "$original_sha" 2>/dev/null || true
      git -C "$dir" branch -D migrate/elgg-4.x 2>/dev/null || true
      FAILED+=("$plugin:migration-4x-failed")
      return 1
    fi
  fi

  # Commit
  local changed
  changed=$(git -C "$dir" status --porcelain 2>/dev/null)
  if [ -n "$changed" ]; then
    git -C "$dir" add -A
    git -C "$dir" commit -m "feat(4.x): migrate to Elgg 4.x"
    log_ok "Committed migration changes"
  else
    git -C "$dir" commit --allow-empty -m "feat(4.x): migrate to Elgg 4.x (no-op — code already compatible)"
    log_ok "Created empty migration commit"
  fi

  # Push
  git -C "$dir" push origin migrate/elgg-4.x 2>&1
  log_ok "Pushed migrate/elgg-4.x"

  # Restore
  git -C "$dir" checkout "$original_sha" 2>/dev/null || true

  DONE_4X+=("$plugin")
  return 0
}

# ── Main ──────────────────────────────────────────────────────────────────────

log "=== Creating missing migrate/elgg-3.x branches (${#PLUGINS_NEED_3X[@]} plugins) ==="

for plugin in "${PLUGINS_NEED_3X[@]}"; do
  create_3x_branch "$plugin" || true
  echo ""
done

log "=== Creating missing migrate/elgg-4.x branches (${#PLUGINS_NEED_4X[@]} plugins) ==="

for plugin in "${PLUGINS_NEED_4X[@]}"; do
  create_4x_branch "$plugin" || true
  echo ""
done

# ── Summary ───────────────────────────────────────────────────────────────────
echo ""
echo "═══════════════════════════════════════════════════════"
echo "SUMMARY"
echo "═══════════════════════════════════════════════════════"
echo "Created migrate/elgg-3.x (${#DONE_3X[@]}): ${DONE_3X[*]:-none}"
echo "Skipped 3.x (${#SKIPPED[@]}): ${SKIPPED[*]:-none}"
echo "Created migrate/elgg-4.x (${#DONE_4X[@]}): ${DONE_4X[*]:-none}"
echo "Failed (${#FAILED[@]}): ${FAILED[*]:-none}"
echo "═══════════════════════════════════════════════════════"
