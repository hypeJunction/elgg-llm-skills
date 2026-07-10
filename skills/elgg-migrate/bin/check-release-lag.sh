#!/usr/bin/env bash
# check-release-lag.sh — detect deployed-vs-source drift ("release-lag"): a
# composer.lock pins a plugin TAG (or a floating dev branch) that lags fixes
# already committed on the plugin's migration branch. The site deploys the
# pinned ref, so SOURCE-only gates (scan-frontend-residue.sh, migrate.php
# --check) pass while the running code is still broken. This is the failure
# mode behind repeated 7.x-preview regressions (add_translation 500s, dead
# ESM specifiers, /members 404): the fix was in source but never released, or
# released to a newer tag than the one pinned.
#
# For each pinned <vendor>/* package this compares the pinned version against
# the plugin source repo's migration-branch tip and flags:
#   LAG     — branch is N commits AHEAD of the pinned tag (unreleased fixes)
#   FLOAT   — pinned to a dev-* branch, not a tag (non-reproducible; Iron Law 4)
#   NOTAG   — pinned version is not a tag in the source repo (can't compare)
#
# Usage:
#   check-release-lag.sh <composer.lock> [<plugins-src-dir>] [--branch <ref>] \
#                        [--vendor-prefix <p>] [--quiet]
# Env fallbacks:
#   ELGG_MIGRATE_PLUGINS  plugins source dir (if arg omitted). ELGG_PLUGINS_DIR is
#                         accepted as a back-compat alias.
#   ELGG_MIGRATE_BRANCH   migration branch to compare against (default migrate/elgg-7.x)
#   ELGG_VENDOR_PREFIX    package-name prefix to check (default: empty = all
#                         packages; those without a matching source repo in the
#                         plugins dir are skipped. Narrow with --vendor-prefix.)
# Exit: 0 = every pin is current, 1 = release-lag/float found, 2 = usage error.
set -u

QUIET=0
BRANCH="${ELGG_MIGRATE_BRANCH:-migrate/elgg-7.x}"
PREFIX="${ELGG_VENDOR_PREFIX:-}"
LOCK=""
# ELGG_MIGRATE_PLUGINS is the canonical plugins-root variable across the skills;
# ELGG_PLUGINS_DIR is the older name the top-level fleet scripts use. Accept both
# so a user who exported only one of them isn't silently left with no plugin dir.
PLUGINS="${ELGG_PLUGINS_DIR:-${ELGG_MIGRATE_PLUGINS:-}}"

while [ $# -gt 0 ]; do
	case "$1" in
		--quiet) QUIET=1; shift ;;
		--branch) BRANCH="${2:-}"; shift 2 ;;
		--vendor-prefix) PREFIX="${2:-}"; shift 2 ;;
		-*) echo "unknown flag: $1" >&2; exit 2 ;;
		*) if [ -z "$LOCK" ]; then LOCK="$1"; elif [ -z "$PLUGINS" ]; then PLUGINS="$1"; fi; shift ;;
	esac
done

[ -n "$LOCK" ] && [ -f "$LOCK" ] || { echo "usage: check-release-lag.sh <composer.lock> [<plugins-src-dir>] [--branch REF] [--vendor-prefix P] [--quiet]" >&2; exit 2; }
[ -n "$PLUGINS" ] && [ -d "$PLUGINS" ] || { echo "plugins source dir not found (arg, ELGG_MIGRATE_PLUGINS or ELGG_PLUGINS_DIR): '$PLUGINS'" >&2; exit 2; }

command -v python3 >/dev/null 2>&1 || { echo "python3 required to parse composer.lock" >&2; exit 2; }

# name<TAB>version for every pinned package under the vendor prefix
PINS="$(python3 - "$LOCK" "$PREFIX" <<'PY'
import json, sys
lock = json.load(open(sys.argv[1]))
prefix = sys.argv[2]
for p in lock.get("packages", []) + lock.get("packages-dev", []):
    if p.get("name", "").startswith(prefix):
        print(p["name"] + "\t" + p.get("version", "?"))
PY
)" || { echo "failed to parse $LOCK" >&2; exit 2; }

lag=0 float=0 checked=0 skipped=0
report() { [ "$QUIET" = 1 ] || echo "$1"; }

while IFS=$'\t' read -r name ver; do
	[ -n "$name" ] || continue
	base="${name#*/}"
	repo="$PLUGINS/$base"
	if [ ! -d "$repo/.git" ]; then skipped=$((skipped+1)); report "  skip   $base (no source repo at $repo)"; continue; fi
	tip="$(git -C "$repo" rev-parse --verify --quiet "$BRANCH" 2>/dev/null)" || { skipped=$((skipped+1)); report "  skip   $base (no $BRANCH branch)"; continue; }
	checked=$((checked+1))

	case "$ver" in
		dev-*)
			float=$((float+1))
			report "  FLOAT  $base pinned '$ver' (branch, not a tag) — non-reproducible"
			continue ;;
	esac

	# Is the pinned version a real tag/ref in the repo?
	if ! git -C "$repo" rev-parse --verify --quiet "${ver}^{commit}" >/dev/null 2>&1; then
		report "  NOTAG  $base pinned '$ver' not found as a tag in source — cannot compare"
		continue
	fi

	ahead="$(git -C "$repo" rev-list --count "${ver}..${BRANCH}" 2>/dev/null)"
	if [ -n "$ahead" ] && [ "$ahead" != "0" ]; then
		lag=$((lag+1))
		subj="$(git -C "$repo" log -1 --format='%h %s' "$BRANCH" 2>/dev/null)"
		report "  LAG    $base pinned $ver, $BRANCH is +$ahead ahead -> $subj"
	fi
done <<< "$PINS"

total=$((lag+float))
if [ "$total" -eq 0 ]; then
	echo "── release-lag: 0 (checked $checked, skipped $skipped) — all pins current ──"
	exit 0
fi
echo "── release-lag: $lag LAG + $float FLOAT (checked $checked, skipped $skipped) ──"
exit 1
