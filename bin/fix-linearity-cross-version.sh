#!/usr/bin/env bash
# fix-linearity-cross-version.sh — forward-port critical fixes across Elgg migration branches
#
# Problem: migrate/elgg-N.x branches were developed in parallel from the same ancestor
# rather than built sequentially. This means critical fixes on lower branches (N-1.x)
# are not reachable from higher branches (N.x).
#
# Approach: cherry-pick critical commits from lower branches to higher branches.
# Only commits matching safe, non-version-specific patterns are ported.
# Docker files and version-specific migration commits are NEVER ported.
#
# Handles transitions: 3x→4x, 4x→5x, 5x→6x
# The 6x→7x transition is handled by bin/fix-branch-linearity.sh
#
# Usage:
#   fix-linearity-cross-version.sh [--yes] [--skip-conflicts] [--push] <plugin-dir>
#
#   --yes              Skip confirmation prompts
#   --skip-conflicts   Continue on cherry-pick conflicts (skip the failing commit)
#   --push             Push modified branches to origin after applying
#   <plugin-dir>       Path to the plugin git repository
#
# Exit codes:
#   0  Success (even if nothing was applied)
#   1  Fatal error (bad args, dirty worktree, missing branches)

set -euo pipefail

die()  { echo "ERROR: $*" >&2; exit 1; }
info() { echo "INFO:  $*"; }
warn() { echo "WARN:  $*" >&2; }

AUTO_YES=0
SKIP_CONFLICTS=0
DO_PUSH=0
PLUGIN_DIR=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --yes)            AUTO_YES=1; shift ;;
        --skip-conflicts) SKIP_CONFLICTS=1; shift ;;
        --push)           DO_PUSH=1; shift ;;
        -*)               die "Unknown flag: $1" ;;
        *)                PLUGIN_DIR="$1"; shift ;;
    esac
done

[[ -n "$PLUGIN_DIR" ]] || die "Usage: $0 [--yes] [--skip-conflicts] [--push] <plugin-dir>"
[[ -d "$PLUGIN_DIR/.git" ]] || die "Not a git repository: $PLUGIN_DIR"

PLUGIN_NAME=$(basename "$PLUGIN_DIR")
info "Processing plugin: $PLUGIN_NAME"

cd "$PLUGIN_DIR"

if ! git diff --quiet HEAD 2>/dev/null || ! git diff --cached --quiet 2>/dev/null; then
    die "Uncommitted changes in $PLUGIN_DIR — commit or stash first"
fi

ORIGINAL_BRANCH=$(git rev-parse --abbrev-ref HEAD)

# ── helpers ───────────────────────────────────────────────────────────────────

has_branch() {
    git show-ref --verify --quiet "refs/heads/$1" 2>/dev/null || \
    git show-ref --verify --quiet "refs/remotes/origin/$1" 2>/dev/null
}

ensure_local_branch() {
    local branch="$1"
    if ! git show-ref --verify --quiet "refs/heads/$branch" 2>/dev/null; then
        git fetch origin "$branch":"$branch" 2>/dev/null || true
    fi
}

# Find which worktree has a branch checked out (returns path or empty string)
worktree_for_branch() {
    local branch="$1"
    local full_ref="refs/heads/$branch"
    # Use grep + awk to avoid awk regex issues with "/" in branch names
    git worktree list --porcelain 2>/dev/null | \
        awk -v ref="$full_ref" '
            /^worktree / { wt=$2 }
            /^branch /   { if ($2 == ref) print wt }
        ' | head -1
}

# Switch to the target branch, respecting existing worktrees.
# Sets global $WORK_DIR to the directory where the branch is checked out.
checkout_target_branch() {
    local branch="$1"
    local wt
    wt=$(worktree_for_branch "$branch")
    if [[ -n "$wt" && -d "$wt" ]]; then
        info "Branch $branch is checked out in worktree: $wt"
        WORK_DIR="$wt"
    else
        ensure_local_branch "$branch"
        git checkout "$branch"
        WORK_DIR="$PLUGIN_DIR"
    fi
}

# ── per-transition critical commit patterns ───────────────────────────────────
# These patterns match commit SUBJECTS (not code content).
# They are intentionally conservative: only commits that are safe to apply to
# a higher-version branch (no version-specific docker or migration commits).
#
# Excluded intentionally:
#   migrate(N.x):   full migration commits — version-specific, would conflict
#   fix(N.x):       version-specific fixes touching docker/, composer.json, etc.
#   fix(elgg-Nx):   typically touches docker/ infra (version-specific scripts)
#   fix(docker):    version-specific docker configuration
#   chore(composer):version-specific composer requirements
#   fix(deps):.*elgg version-specific elgg/elgg version pins

# 3x→4x: Only safe structural commits. Most 3.x-specific commits conflict with 4.x.
# Practical value is low; skip by default unless commit touches only safe files.
PATTERN_3TO4="^ci:|^feat\(seed\)|^fix\(seed\)|^feat\(seeder\)|^fix\(seeder\)"

# 4x→5x: CI setup, seed subclasses, installer name, DI fixes
# (Elgg\Hook→Event is already handled by the 5.x migration itself)
PATTERN_4TO5="^ci:|^feat\(seed\)|^fix\(seed\)|^feat\(seeder\)|^fix\(seeder\)|^fix\(installer\):|^fix\(di\):"

# 5x→6x: CI, seed subclasses, license/docs, installer
# (The 6.x migration already handles Hook→Event API changes)
PATTERN_5TO6="^ci:|^feat\(seed\)|^fix\(seed\)|^feat\(seeder\)|^fix\(seeder\)|^chore:.*license|^fix\(installer\):|^fix\(di\):"

# ── apply one transition ───────────────────────────────────────────────────────

apply_transition() {
    local from_branch="$1"   # e.g. migrate/elgg-4.x
    local to_branch="$2"     # e.g. migrate/elgg-5.x
    local pattern="$3"
    local label="$4"         # e.g. "4x→5x"

    if ! has_branch "$from_branch"; then
        info "Skip $label: branch $from_branch not found"
        return 0
    fi
    if ! has_branch "$to_branch"; then
        info "Skip $label: branch $to_branch not found"
        return 0
    fi

    # Commits on from_branch that are NOT reachable from to_branch, oldest first.
    # Use "%H %s" format: full SHA + subject. Pattern matches against SUBJECT ONLY
    # (grep -E on subject only, because --oneline prepends the short SHA which breaks ^ anchors).
    MISSING_COMMITS=$(git log \
        --format="%H %s" \
        --reverse \
        "${to_branch}..${from_branch}" 2>/dev/null \
        | while IFS= read -r line; do
            sha="${line%% *}"
            msg="${line#* }"
            if echo "$msg" | grep -qE "$pattern"; then
                echo "$sha"
            fi
          done \
        || true)

    if [[ -z "$MISSING_COMMITS" ]]; then
        info "$label: No critical commits missing — nothing to do"
        return 0
    fi

    info "$label critical commits to forward-port (oldest first):"
    while IFS= read -r sha; do
        msg=$(git log -1 --format="%s" "$sha")
        echo "  $sha  $msg"
    done <<< "$MISSING_COMMITS"
    echo ""

    if [[ $AUTO_YES -eq 0 ]]; then
        read -r -p "Cherry-pick these commits onto $to_branch? [y/N] " answer
        [[ "${answer,,}" == "y" ]] || { info "Skipped $label by user."; return 0; }
    else
        info "Auto-yes: proceeding with $label."
    fi

    # Remember where we are before switching branches
    local prev_dir="$PWD"
    local prev_branch
    prev_branch=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "")

    # Switch to target branch (handles worktree case)
    local WORK_DIR
    checkout_target_branch "$to_branch"

    # Pull if remote exists
    cd "$WORK_DIR"
    git pull --rebase origin "$to_branch" 2>/dev/null || \
        warn "Could not pull $to_branch — proceeding with local branch"

    local APPLIED=0
    local FAILED=0
    local SKIPPED=0

    while IFS= read -r sha; do
        msg=$(git log -1 --format="%s" "$sha")
        info "Cherry-picking: $sha  $msg"

        # Try cherry-pick; capture output and exit code separately
        cherry_out=$(git cherry-pick --no-edit "$sha" 2>&1) && cherry_rc=0 || cherry_rc=$?

        if [[ $cherry_rc -eq 0 ]]; then
            # Success — check if it was an empty (no-op) apply
            if echo "$cherry_out" | grep -q "empty\|nothing to commit\|already exists"; then
                warn "  Already applied (empty) — skipping"
                SKIPPED=$((SKIPPED + 1))
            else
                APPLIED=$((APPLIED + 1))
                info "  Applied OK"
            fi
        else
            # Failure — check if the cherry-pick is in "empty" state
            if echo "$cherry_out" | grep -q "is now empty\|cherry-pick is now empty"; then
                warn "  Already applied (empty cherry-pick) — skipping"
                git cherry-pick --skip 2>/dev/null || true
                SKIPPED=$((SKIPPED + 1))
            else
                warn "  Conflict during cherry-pick of $sha: ${cherry_out%%$'\n'*}"
                git cherry-pick --abort 2>/dev/null || true

                if [[ $SKIP_CONFLICTS -eq 1 ]]; then
                    warn "  Skipping (--skip-conflicts). Apply manually:"
                    warn "    cd $WORK_DIR && git cherry-pick $sha"
                    FAILED=$((FAILED + 1))
                else
                    cd "$prev_dir" 2>/dev/null || true
                    die "Cherry-pick failed at $sha — resolve manually or rerun with --skip-conflicts."
                fi
            fi
        fi
    done <<< "$MISSING_COMMITS"

    echo ""
    info "$label Done: applied $APPLIED, skipped-empty $SKIPPED, failed-conflict $FAILED on $to_branch"

    if [[ $APPLIED -gt 0 && $DO_PUSH -eq 1 ]]; then
        info "Pushing $to_branch ..."
        git pull --rebase origin "$to_branch" 2>/dev/null || warn "pull --rebase failed"
        git push origin "$to_branch" || warn "push failed — push manually"
    fi

    # Return to original worktree/branch if we switched
    cd "$prev_dir" 2>/dev/null || true
    if [[ "$WORK_DIR" == "$PLUGIN_DIR" && -n "$prev_branch" ]]; then
        git checkout "$prev_branch" 2>/dev/null || true
    fi

    return 0
}

# ── main ──────────────────────────────────────────────────────────────────────

echo ""
info "=== Cross-version linearity fix for $PLUGIN_NAME ==="
echo ""

TOTAL_APPLIED=0
TOTAL_FAILED=0

# Run each transition. Order matters: lower first.
apply_transition \
    "migrate/elgg-3.x" \
    "migrate/elgg-4.x" \
    "$PATTERN_3TO4" \
    "3x→4x"

apply_transition \
    "migrate/elgg-4.x" \
    "migrate/elgg-5.x" \
    "$PATTERN_4TO5" \
    "4x→5x"

apply_transition \
    "migrate/elgg-5.x" \
    "migrate/elgg-6.x" \
    "$PATTERN_5TO6" \
    "5x→6x"

echo ""
info "=== Completed all transitions for $PLUGIN_NAME ==="
echo ""
echo "Next steps:"
echo "  1. Review branches: git log --oneline -10 migrate/elgg-{4,5,6}.x"
echo "  2. Run 6x→7x forward-port: bin/fix-branch-linearity.sh [flags] $PLUGIN_DIR"
echo "  3. Verify with Docker test stacks"
[[ $DO_PUSH -eq 0 ]] && echo "  4. Push: git push origin migrate/elgg-{4,5,6}.x"
echo ""
