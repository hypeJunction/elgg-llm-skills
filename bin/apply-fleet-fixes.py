#!/usr/bin/env python3
"""
apply-fleet-fixes.py  [--ndjson /tmp/fleet-verification-results.ndjson]
                       [--plugins-dir $ELGG_MIGRATE_PLUGINS]
                       [--infra-dir <repo>/skills/elgg-migrate/infra]
                       [--dry-run]

Applies all scriptable fixes to plugin repos based on fleet verification results:
  - composer.json: correct elgg/elgg version, PHP constraint, add missing deps
  - start.php: delete from Elgg 4+ branches
  - docker/: copy infra template where missing
  - README.md: ensure Elgg version badge is present

Serializes through plugins one at a time (no parallel writes, no git conflicts).
Commits once per plugin per branch, then pushes once per plugin.

Exit code: 0 = all done, 1 = some plugins had errors.
"""
import json
import os
import re
import shutil
import subprocess
import sys
from collections import defaultdict
from pathlib import Path


def get_plugins_dir():
    d = os.environ.get('ELGG_PLUGINS_DIR')
    if d:
        return Path(d).expanduser()
    try:
        script_dir = os.path.dirname(os.path.abspath(__file__))
        result = subprocess.run(
            [os.path.join(script_dir, 'discover-plugins.sh'), '--list'],
            capture_output=True, text=True, check=True
        )
        lines = [l.strip() for l in result.stdout.strip().splitlines() if l.strip()]
        if lines:
            # discover-plugins.sh --list prints plugin IDs, not the dir;
            # run without --list to get the PLUGINS_DIR from written .env
            # Fall back: try to read PLUGINS_DIR from the .env it writes
            pass
        # Try running without --list to capture written .env PLUGINS_DIR
        result2 = subprocess.run(
            [os.path.join(script_dir, 'discover-plugins.sh')],
            capture_output=True, text=True
        )
        for line in result2.stdout.splitlines():
            stripped = line.strip()
            if 'PLUGINS_DIR=' in stripped:
                d = stripped.split('PLUGINS_DIR=', 1)[1].strip()
                if d and os.path.isdir(d):
                    return Path(d)
    except Exception:
        pass
    raise RuntimeError(
        "ELGG_PLUGINS_DIR not set and discover-plugins.sh returned nothing.\n"
        "Set ELGG_PLUGINS_DIR=/path/to/your/plugins or run:\n"
        "  bin/discover-plugins.sh --root /path/to/plugins --save-config"
    )

# ── canonical values per branch ───────────────────────────────────────────────
CANONICAL_ELGG = {
    "migrate/elgg-3.x": "^3.0",
    "migrate/elgg-4.x": "^4.0",
    "migrate/elgg-5.x": "~5.1.0",
    "migrate/elgg-6.x": "~6.1.0",
    "migrate/elgg-7.x": "~7.0.0",
}

CANONICAL_PHP = {
    "migrate/elgg-3.x": ">=7.2",
    "migrate/elgg-4.x": ">=7.4",
    "migrate/elgg-5.x": ">=8.0",   # Elgg 5 minimum per version-matrix.md
    "migrate/elgg-6.x": ">=8.1",   # Elgg 6 minimum per version-matrix.md
    "migrate/elgg-7.x": ">=8.3",
}

ELGG_MAJOR = {
    "migrate/elgg-3.x": "3",
    "migrate/elgg-4.x": "4",
    "migrate/elgg-5.x": "5",
    "migrate/elgg-6.x": "6",
    "migrate/elgg-7.x": "7",
}

INFRA_DIR_NAME = {
    "migrate/elgg-3.x": "elgg3",
    "migrate/elgg-4.x": "elgg4",
    "migrate/elgg-5.x": "elgg5",
    "migrate/elgg-6.x": "elgg6",
    "migrate/elgg-7.x": "elgg7",
}

BRANCHES_ORDER = [
    "migrate/elgg-3.x",
    "migrate/elgg-4.x",
    "migrate/elgg-5.x",
    "migrate/elgg-6.x",
    "migrate/elgg-7.x",
]

# ── helpers ───────────────────────────────────────────────────────────────────
DRY_RUN = "--dry-run" in sys.argv


def run(cmd, cwd=None, check=False, capture=True):
    if DRY_RUN and any(v in cmd for v in ("checkout", "commit", "push", "rm", "add")):
        print(f"  [dry-run] {' '.join(cmd)}")
        return subprocess.CompletedProcess(cmd, 0, "", "")
    r = subprocess.run(cmd, cwd=cwd, capture_output=capture, text=True)
    if check:
        r.check_returncode()
    return r


def branch_exists(branch, cwd):
    r = run(["git", "show-ref", "--verify", f"refs/heads/{branch}"], cwd)
    if r.returncode == 0:
        return True
    r = run(["git", "show-ref", "--verify", f"refs/remotes/origin/{branch}"], cwd)
    return r.returncode == 0


def current_branch(cwd):
    r = run(["git", "rev-parse", "--abbrev-ref", "HEAD"], cwd)
    return r.stdout.strip()


def stash_if_dirty(cwd):
    r = run(["git", "status", "--porcelain"], cwd)
    if r.stdout.strip():
        run(["git", "stash"], cwd)
        return True
    return False


def pop_stash(cwd):
    run(["git", "stash", "pop"], cwd)


def checkout(branch, cwd):
    r = run(["git", "checkout", branch], cwd)
    if r.returncode != 0:
        # try remote
        r = run(["git", "checkout", "-b", branch, f"origin/{branch}"], cwd)
    return r.returncode == 0


def has_staged_changes(cwd):
    r = run(["git", "diff", "--cached", "--quiet"], cwd)
    return r.returncode != 0


def commit_and_push(cwd, branch, message):
    if not has_staged_changes(cwd):
        return True
    r = run(["git", "commit", "-m", message], cwd)
    if r.returncode != 0:
        print(f"  ✗ commit failed: {r.stderr.strip()}")
        return False
    r = run(["git", "pull", "--rebase", "origin", branch], cwd)
    if r.returncode != 0:
        print(f"  ✗ pull --rebase failed: {r.stderr.strip()}")
        return False
    if not DRY_RUN:
        r = run(["git", "push", "origin", branch], cwd)
        if r.returncode != 0:
            print(f"  ✗ push failed: {r.stderr.strip()}")
            return False
    return True


def read_json(path):
    with open(path) as f:
        return json.load(f)


def write_json(path, data):
    with open(path, "w") as f:
        json.dump(data, f, indent="\t", ensure_ascii=False)
        f.write("\n")


# ── fix functions ─────────────────────────────────────────────────────────────
def fix_composer(plugin_dir, branch, needed_fixes):
    cwd = str(plugin_dir)
    composer_path = plugin_dir / "composer.json"
    if not composer_path.exists():
        return False

    try:
        composer = read_json(composer_path)
    except json.JSONDecodeError:
        print(f"  ✗ composer.json is invalid JSON")
        return False

    changed = False
    req = composer.setdefault("require", {})

    # Fix or add elgg/elgg
    if any(f in needed_fixes for f in ("missing_elgg_dep", "wrong_elgg_version")):
        target = CANONICAL_ELGG[branch]
        old = req.get("elgg/elgg", "MISSING")
        if old != target:
            req["elgg/elgg"] = target
            changed = True
            print(f"  ✓ elgg/elgg: {old!r} → {target!r}")

    # Fix PHP constraint
    if any(f in needed_fixes for f in ("php_constraint_too_low", "missing_php_constraint")):
        target = CANONICAL_PHP[branch]
        old = req.get("php", "MISSING")
        if old != target:
            req["php"] = target
            changed = True
            print(f"  ✓ php: {old!r} → {target!r}")

    if changed:
        # Re-order keys: name, version, type, description, ... require (php first), ...
        if "require" in composer:
            r = composer["require"]
            ordered_req = {}
            for k in ("composer/installers", "php", "elgg/elgg"):
                if k in r:
                    ordered_req[k] = r[k]
            for k, v in r.items():
                if k not in ordered_req:
                    ordered_req[k] = v
            composer["require"] = ordered_req
        if not DRY_RUN:
            write_json(composer_path, composer)
            run(["git", "add", "composer.json"], cwd)
    return changed


def fix_start_php(plugin_dir, branch, needed_fixes):
    if "has_start_php" not in needed_fixes:
        return False
    cwd = str(plugin_dir)
    start_php = plugin_dir / "start.php"
    if not start_php.exists():
        return False
    print(f"  ✓ removing start.php")
    if not DRY_RUN:
        run(["git", "rm", "start.php"], cwd)
    return True


def fix_readme(plugin_dir, branch, needed_fixes):
    if "readme_version_mismatch" not in needed_fixes:
        return False
    cwd = str(plugin_dir)
    major = ELGG_MAJOR[branch]
    badge = f"![Elgg {major}.x](https://img.shields.io/badge/Elgg-{major}.x-orange.svg?style=flat-square)"
    readme_path = plugin_dir / "README.md"

    if readme_path.exists():
        content = readme_path.read_text()
        # Check if any Elgg badge already exists
        old_badge = re.search(r"!\[Elgg [0-9]+\.x\]\(https://img\.shields\.io/badge/Elgg-[0-9]+\.x-[^)]+\)", content)
        if old_badge:
            new_content = content.replace(old_badge.group(), badge)
            if new_content == content:
                return False
            print(f"  ✓ README: updated Elgg badge → Elgg {major}.x")
        else:
            # Insert badge after first H1
            lines = content.split("\n")
            insert_at = 1
            for i, line in enumerate(lines):
                if line.startswith("# "):
                    insert_at = i + 1
                    break
            lines.insert(insert_at, "")
            lines.insert(insert_at + 1, badge)
            new_content = "\n".join(lines)
            print(f"  ✓ README: added Elgg {major}.x badge")
        if not DRY_RUN:
            readme_path.write_text(new_content)
            run(["git", "add", "README.md"], cwd)
        return True
    else:
        # Create minimal README
        plugin_name = plugin_dir.name
        content = f"# {plugin_name}\n\n{badge}\n\n"
        print(f"  ✓ README: created with Elgg {major}.x badge")
        if not DRY_RUN:
            readme_path.write_text(content)
            run(["git", "add", "README.md"], cwd)
        return True


def fix_docker(plugin_dir, branch, needed_fixes, infra_base):
    if "missing_docker" not in needed_fixes:
        return False
    cwd = str(plugin_dir)
    plugin_name = plugin_dir.name.lower()
    docker_dir = plugin_dir / "docker"
    infra_src = infra_base / INFRA_DIR_NAME[branch]

    if not infra_src.exists():
        print(f"  ✗ infra template not found: {infra_src}")
        return False

    if not DRY_RUN:
        docker_dir.mkdir(exist_ok=True)
        for src_file in infra_src.iterdir():
            dest = docker_dir / src_file.name
            if not dest.exists():
                shutil.copy2(src_file, dest)

        # Write docker/.env with PLUGIN_ID set
        env_file = docker_dir / ".env"
        env_example = docker_dir / ".env.example"
        env_content = f"PLUGIN_ID={plugin_name}\n"
        env_file.write_text(env_content)

        run(["git", "add", "docker/"], cwd)

    print(f"  ✓ docker/: copied {INFRA_DIR_NAME[branch]} template (PLUGIN_ID={plugin_name})")
    return True


# ── per-plugin per-branch orchestrator ───────────────────────────────────────
def apply_fixes_for_plugin(plugin_dir, branch_issues, infra_base):
    plugin = plugin_dir.name
    cwd = str(plugin_dir)
    errors = []

    orig_branch = current_branch(cwd)
    stashed = stash_if_dirty(cwd)

    for branch in BRANCHES_ORDER:
        fixes = branch_issues.get(branch, set())
        if not fixes:
            continue
        if not branch_exists(branch, cwd):
            print(f"  ⚠ branch {branch} missing — skipping")
            continue

        if not checkout(branch, cwd):
            print(f"  ✗ could not checkout {branch}")
            errors.append(f"{branch}: checkout failed")
            continue

        print(f"  [{branch}] fixes: {sorted(fixes)}")

        changed = False
        changed |= fix_composer(plugin_dir, branch, fixes)
        changed |= fix_start_php(plugin_dir, branch, fixes)
        changed |= fix_readme(plugin_dir, branch, fixes)
        changed |= fix_docker(plugin_dir, branch, fixes, infra_base)

        if changed:
            # Only include the issue types that this script actually handles
            HANDLED = {
                "missing_elgg_dep", "wrong_elgg_version",
                "php_constraint_too_low", "missing_php_constraint",
                "has_start_php",
            }
            composer_fixes = sorted(fixes & HANDLED)
            readme_part = "README: Elgg badge" if "readme_version_mismatch" in fixes else ""
            docker_part = "docker: add infra" if "missing_docker" in fixes else ""
            msg_parts = composer_fixes + ([readme_part] if readme_part else []) + ([docker_part] if docker_part else [])
            if not msg_parts:
                msg_parts = ["cosmetic fixes"]
            scope = branch.replace("migrate/", "").replace(".", "")  # e.g. elgg-7x
            msg = f"fix({scope}): {', '.join(msg_parts)}"
            ok = commit_and_push(cwd, branch, msg)
            if not ok:
                errors.append(f"{branch}: commit/push failed")

    # Restore original branch
    checkout(orig_branch, cwd)
    if stashed:
        pop_stash(cwd)

    return errors


# ── main ──────────────────────────────────────────────────────────────────────
def main():
    # Parse args
    ndjson_path = "/tmp/fleet-verification-results.ndjson"
    plugins_dir_arg = None
    infra_base_arg = None

    args = sys.argv[1:]
    for i, a in enumerate(args):
        if a == "--ndjson" and i + 1 < len(args):
            ndjson_path = args[i + 1]
        elif a == "--plugins-dir" and i + 1 < len(args):
            plugins_dir_arg = Path(args[i + 1]).expanduser()
        elif a == "--infra-dir" and i + 1 < len(args):
            infra_base_arg = Path(args[i + 1]).expanduser()

    # Resolve plugins_dir: CLI arg > env/discover-plugins.sh
    if plugins_dir_arg is not None:
        plugins_dir = plugins_dir_arg
    else:
        try:
            plugins_dir = get_plugins_dir()
        except RuntimeError as e:
            print(f"ERROR: {e}", file=sys.stderr)
            sys.exit(1)

    # Resolve infra_base: CLI arg > relative to this script's location
    if infra_base_arg is not None:
        infra_base = infra_base_arg
    else:
        script_dir = Path(os.path.abspath(__file__)).parent
        infra_base = script_dir.parent / "skills" / "elgg-migrate" / "infra"

    if DRY_RUN:
        print("=== DRY RUN MODE — no files will be modified ===\n")

    # Load verification results
    issues_by_plugin_branch = defaultdict(lambda: defaultdict(set))
    with open(ndjson_path) as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            issue = json.loads(line)
            # Skip readme-only issues — they're low priority and handled separately
            # unless explicitly requested
            if issue["type"] in (
                "linearity",        # structural — requires git history rewrite
                "missing_branch",   # requires branch creation from correct base
                "main_not_7x",      # requires merge — handled separately
            ):
                continue
            issues_by_plugin_branch[issue["plugin"]][issue["branch"]].add(issue["type"])

    all_errors = {}
    total = len(issues_by_plugin_branch)
    for idx, (plugin, branch_issues) in enumerate(sorted(issues_by_plugin_branch.items()), 1):
        plugin_dir = plugins_dir / plugin
        if not plugin_dir.is_dir():
            print(f"[{idx}/{total}] {plugin}: directory not found — skipping")
            continue

        print(f"\n[{idx}/{total}] {plugin}")
        errors = apply_fixes_for_plugin(plugin_dir, branch_issues, infra_base)
        if errors:
            all_errors[plugin] = errors

    print("\n" + "=" * 60)
    if all_errors:
        print(f"Completed with errors in {len(all_errors)} plugin(s):")
        for plugin, errs in all_errors.items():
            print(f"  {plugin}: {errs}")
        sys.exit(1)
    else:
        print("All fixes applied successfully.")
        sys.exit(0)


if __name__ == "__main__":
    main()
