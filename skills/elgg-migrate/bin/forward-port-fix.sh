#!/usr/bin/env bash
# forward-port-fix.sh — propagate a bug fix from its ORIGIN version branch up the
# migrate/elgg-N.x chain, per the skill's "Retrospective bug fixing" process.
#
# You first fix + commit the bug on the EARLIEST affected branch (migrate/elgg-<from>.x).
# This script then cherry-picks that commit forward through each higher branch,
# falling back to flagging branches whose code diverged (cherry-pick conflict)
# for a manual direct-edit, and finally audits every branch with a bug signature.
#
# Usage:
#   forward-port-fix.sh --from 3 --to 7 --commit <sha> \
#                       [--audit-file <path> --signature <grep-ERE>]
#   # --commit defaults to HEAD of migrate/elgg-<from>.x
#
# Run inside the plugin's git repo. It NEVER full-merges branches (per-version
# composer/docker differ); it cherry-picks or flags for manual fix.
set -u
FROM=""; TO=7; COMMIT=""; FILE=""; SIG=""
while [ $# -gt 0 ]; do case "$1" in
  --from) FROM="$2"; shift 2;; --to) TO="$2"; shift 2;;
  --commit) COMMIT="$2"; shift 2;; --audit-file) FILE="$2"; shift 2;;
  --signature) SIG="$2"; shift 2;; *) echo "unknown arg: $1" >&2; exit 2;; esac; done
[ -n "$FROM" ] || { echo "usage: forward-port-fix.sh --from <N> [--to <M>] [--commit <sha>] [--audit-file <p> --signature <re>]" >&2; exit 2; }

br() { echo "migrate/elgg-$1.x"; }
git rev-parse --is-inside-work-tree >/dev/null 2>&1 || { echo "not a git repo" >&2; exit 2; }

# This script checks out several branches in the user's working repo. A dirty tree
# would either block the checkout half-way through the loop or be dragged onto the
# wrong branch and swept into a cherry-pick. Refuse up front.
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  echo "REFUSING: working tree has uncommitted changes." >&2
  echo "  Commit or stash them first — this script checks out migrate/elgg-*.x branches." >&2
  git status --short --untracked-files=no >&2
  exit 2
fi

# Return the caller to the branch they started on, whatever happens.
ORIG_REF="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || true)"
restore_branch() {
  [ -n "$ORIG_REF" ] && [ "$ORIG_REF" != "HEAD" ] || return 0
  [ "$(git rev-parse --abbrev-ref HEAD 2>/dev/null)" = "$ORIG_REF" ] && return 0
  git checkout "$ORIG_REF" >/dev/null 2>&1 && echo "restored branch: $ORIG_REF"
}
trap restore_branch EXIT

git checkout "$(br "$FROM")" 2>/dev/null || { echo "no $(br "$FROM")" >&2; exit 2; }
[ -n "$COMMIT" ] || COMMIT="$(git rev-parse HEAD)"
echo "Forward-porting $COMMIT from $(br "$FROM") up to $(br "$TO")"

MANUAL=""
for n in $(seq $((FROM+1)) "$TO"); do
  b="$(br "$n")"
  git rev-parse --verify "$b" >/dev/null 2>&1 || { echo "  $b: MISSING — skip"; continue; }
  git checkout "$b" 2>/dev/null
  # guard against detached HEAD (e.g. after a prior aborted cherry-pick)
  [ "$(git rev-parse --abbrev-ref HEAD)" = "$b" ] || { echo "  $b: NOT on branch ref — abort"; exit 1; }
  if git cherry-pick -x "$COMMIT" >/dev/null 2>&1; then
    echo "  $b: cherry-picked OK"
  else
    git cherry-pick --abort 2>/dev/null
    echo "  $b: CONFLICT (code diverged) — needs manual direct-edit on this branch"
    MANUAL="$MANUAL $b"
  fi
done

if [ -n "$FILE" ] && [ -n "$SIG" ]; then
  echo "── audit: '$SIG' in $FILE per branch (want 0 everywhere) ──"
  bad=0
  for n in $(seq "$FROM" "$TO"); do
    b="$(br "$n")"; git rev-parse --verify "$b" >/dev/null 2>&1 || continue
    c=$(git show "$b:$FILE" 2>/dev/null | grep -cE "$SIG")
    printf "  %-18s %s\n" "$b" "$c"; [ "${c:-0}" -gt 0 ] && bad=1
  done
  [ "$bad" -eq 0 ] && echo "✓ signature gone on all branches" || echo "✗ signature still present on some branches"
fi

[ -n "$MANUAL" ] && { echo "MANUAL direct-edit still required on:$MANUAL"; exit 1; }
echo "✓ forward-port complete — review, then: git push origin $(for n in $(seq "$FROM" "$TO"); do printf '%s ' "$(br "$n")"; done)"
