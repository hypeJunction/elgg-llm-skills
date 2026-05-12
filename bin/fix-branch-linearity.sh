#!/usr/bin/env bash
# fix-branch-linearity.sh — forward-port critical 6.x bug fixes into 7.x for ONE plugin
#
# BACKGROUND
# ----------
# migrate/elgg-N.x branches were developed from the same ancestor rather than
# sequentially, so bug fixes applied to 6.x after the 7.x branch point were
# never forward-ported. This script cherry-picks the critical functional fixes
# from 6.x that are NOT already present in 7.x.
#
# WHAT "critical" MEANS HERE
# ---------------------------
# Commits on migrate/elgg-6.x that are NOT reachable from migrate/elgg-7.x,
# where the commit subject matches Elgg-6.x-specific API removals:
#   - Hook API removed (elgg_register_plugin_hook_handler, ServiceFacade, \Elgg\Hook)
#   - CSS/JS API removed (elgg_load_css, elgg_require_js, elgg_unregister_css/js)
#   - start.php rejected by Elgg 6.x
#   - Seeder abstract method signature (getType/getCountOptions) added in Elgg 6.1
#   - installer-name lowercase requirement
#
# WHAT THIS SCRIPT DOES NOT DO
# ------------------------------
# - Does NOT rebase branches (no history rewrite, no force push)
# - Does NOT merge 6.x into 7.x (too many conflicts from parallel work)
# - Does NOT touch commits that are cosmetic/docs-only or version-specific migrations
# - Does NOT run if there are uncommitted changes in the target plugin dir
# - Aborts on any cherry-pick conflict without leaving the repo dirty
#
# USAGE
# -----
#   fix-branch-linearity.sh [--yes] [--skip-conflicts] [--push] <plugin-dir>
#
#   --yes              Skip the confirmation prompt (for agent/automated use)
#   --skip-conflicts   On cherry-pick conflict, skip that commit and continue
#                      (default: abort and exit 1 on first conflict)
#   --push             Push migrate/elgg-7.x to origin after successful cherry-picks
#   plugin-dir         Absolute path to the plugin git repository
#
# EXIT CODES
#   0   Success (or nothing to do)
#   1   Error (bad args, missing branches, dirty workdir, cherry-pick conflict)
#
# EXAMPLE
#   bin/fix-branch-linearity.sh --yes --push "$ELGG_PLUGINS_DIR/hypegallery"
#   bin/fix-branch-linearity.sh --yes --push ~/plugins/my-plugin

set -euo pipefail

# ── helpers ───────────────────────────────────────────────────────────────────

die() { echo "ERROR: $*" >&2; exit 1; }
info() { echo "INFO:  $*"; }
warn() { echo "WARN:  $*" >&2; }

# ── args ──────────────────────────────────────────────────────────────────────

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

# ── safety: no uncommitted changes ────────────────────────────────────────────

cd "$PLUGIN_DIR"

if ! git diff --quiet HEAD 2>/dev/null || ! git diff --cached --quiet 2>/dev/null; then
    die "Uncommitted changes in $PLUGIN_DIR — commit or stash first"
fi

CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)

# ── branch existence ──────────────────────────────────────────────────────────

has_branch() {
    git show-ref --verify --quiet "refs/heads/$1" 2>/dev/null || \
    git show-ref --verify --quiet "refs/remotes/origin/$1" 2>/dev/null
}

has_branch "migrate/elgg-6.x" || die "migrate/elgg-6.x not found in $PLUGIN_DIR"
has_branch "migrate/elgg-7.x" || die "migrate/elgg-7.x not found in $PLUGIN_DIR"

# ── identify missing critical commits ─────────────────────────────────────────
# Commits on 6.x but NOT reachable from 7.x, ordered oldest-first for cherry-pick

CRITICAL_PATTERN="fix\(6[.]?x\)|fix\(6x\)|remove start\.php|ServiceFacade|PluginHooksService|Event API|hook callback|hook handler|elgg_load_css|elgg_require_js|elgg_unregister_css|elgg_load_external_file|elgg_import_esm|getType|getCountOptions|installer-name"

# git log outputs newest-first; reverse so we cherry-pick oldest→newest
MISSING_COMMITS=$(git log \
    --oneline \
    --reverse \
    migrate/elgg-7.x..migrate/elgg-6.x 2>/dev/null \
    | grep -E "$CRITICAL_PATTERN" \
    | awk '{print $1}' \
    || true)

if [[ -z "$MISSING_COMMITS" ]]; then
    info "No critical 6.x fixes missing from 7.x — nothing to do"
    exit 0
fi

info "Critical commits to forward-port (oldest first):"
while IFS= read -r sha; do
    msg=$(git log -1 --format="%s" "$sha")
    echo "  $sha  $msg"
done <<< "$MISSING_COMMITS"

echo ""
if [[ $AUTO_YES -eq 0 ]]; then
    read -r -p "Cherry-pick these commits onto migrate/elgg-7.x? [y/N] " answer
    [[ "${answer,,}" == "y" ]] || { info "Aborted by user."; exit 0; }
else
    info "Auto-yes: proceeding without prompt."
fi

# ── switch to 7.x ─────────────────────────────────────────────────────────────

git checkout migrate/elgg-7.x
git pull --rebase origin migrate/elgg-7.x 2>/dev/null || \
    warn "Could not pull — proceeding with local branch (verify remote state)"

# ── cherry-pick each commit ───────────────────────────────────────────────────

APPLIED=0
FAILED=0

while IFS= read -r sha; do
    msg=$(git log -1 --format="%s" "$sha")
    info "Cherry-picking: $sha  $msg"

    if git cherry-pick --no-edit "$sha" 2>/dev/null; then
        APPLIED=$((APPLIED + 1))
        info "  Applied OK"
    else
        warn "  Conflict during cherry-pick of $sha"
        git cherry-pick --abort 2>/dev/null || true
        if [[ $SKIP_CONFLICTS -eq 1 ]]; then
            warn "  Skipping (--skip-conflicts). Apply manually:"
            warn "    git checkout migrate/elgg-7.x && git cherry-pick $sha"
            FAILED=$((FAILED + 1))
        else
            git checkout "$CURRENT_BRANCH" 2>/dev/null || true
            die "Cherry-pick failed at $sha — resolve manually or rerun with --skip-conflicts.\n\nTo apply manually:\n  git checkout migrate/elgg-7.x\n  git cherry-pick $sha"
        fi
    fi
done <<< "$MISSING_COMMITS"

# ── summary ───────────────────────────────────────────────────────────────────

echo ""
info "Done. Applied $APPLIED commit(s), skipped $FAILED conflict(s) on migrate/elgg-7.x for $PLUGIN_NAME"

if [[ $APPLIED -gt 0 && $DO_PUSH -eq 1 ]]; then
    info "Pushing migrate/elgg-7.x ..."
    git pull --rebase origin migrate/elgg-7.x 2>/dev/null || warn "pull --rebase failed — manual push may be needed"
    git push origin migrate/elgg-7.x || warn "push failed — review and push manually"
fi

echo ""
echo "Next steps:"
echo "  1. Review: git log --oneline -10 migrate/elgg-7.x"
[[ $FAILED -gt 0 ]] && echo "  2. Manually resolve $FAILED conflicting commit(s) listed above"
echo "  3. Run local Docker test stack to verify"
[[ $DO_PUSH -eq 0 ]] && echo "  4. Push: git push origin migrate/elgg-7.x"
echo ""

git checkout "$CURRENT_BRANCH" 2>/dev/null || true
