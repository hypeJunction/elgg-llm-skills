#!/usr/bin/env python3
"""
post-fix-corrections.py [--plugins-dir /path/to/plugins] [--dry-run]

Two post-fleet-fix corrections:

1. PHP constraint over-correction: apply-fleet-fixes.py used wrong canonical
   values for Elgg 5.x (set >=8.1 instead of >=8.0) and Elgg 6.x (set >=8.2
   instead of >=8.1). This script reverts those over-corrections.

2. main branch stale: wherever FleetFixer committed to migrate/elgg-7.x but
   main hasn't been updated, fast-forward (merge) main → 7.x and push.

Plugins directory is resolved from (in order):
  1. --plugins-dir <path> CLI flag
  2. $ELGG_PLUGINS_DIR environment variable
  3. bin/discover-plugins.sh output
"""
import json
import os
import subprocess
import sys
from pathlib import Path


def get_plugins_dir():
    d = os.environ.get('ELGG_MIGRATE_PLUGINS') or os.environ.get('ELGG_PLUGINS_DIR')
    if d:
        return Path(d).expanduser()
    try:
        script_dir = os.path.dirname(os.path.abspath(__file__))
        result = subprocess.run(
            [os.path.join(script_dir, 'discover-plugins.sh')],
            capture_output=True, text=True
        )
        for line in result.stdout.splitlines():
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

DRY_RUN = "--dry-run" in sys.argv

OVER_CORRECTIONS = {
    # branch → (wrong_value_set_by_fleet_fixer, correct_canonical_value)
    "migrate/elgg-5.x": (">=8.1", ">=8.0"),
    "migrate/elgg-6.x": (">=8.2", ">=8.1"),
}


def run(cmd, cwd=None, capture=True):
    if DRY_RUN and any(v in cmd for v in ("checkout", "commit", "push", "merge")):
        print(f"  [dry-run] {' '.join(cmd)}")
        return subprocess.CompletedProcess(cmd, 0, "", "")
    return subprocess.run(cmd, cwd=cwd, capture_output=capture, text=True)


def branch_exists(branch, cwd):
    r = run(["git", "show-ref", "--verify", f"refs/heads/{branch}"], cwd)
    if r.returncode == 0:
        return True
    r = run(["git", "show-ref", "--verify", f"refs/remotes/origin/{branch}"], cwd)
    return r.returncode == 0


def current_branch(cwd):
    r = run(["git", "rev-parse", "--abbrev-ref", "HEAD"], cwd)
    return r.stdout.strip()


def checkout(branch, cwd):
    r = run(["git", "checkout", branch], cwd)
    if r.returncode != 0:
        r = run(["git", "checkout", "-b", branch, f"origin/{branch}"], cwd)
    return r.returncode == 0


def main():
    plugins_dir_arg = None
    args = sys.argv[1:]
    for i, a in enumerate(args):
        if a == "--plugins-dir" and i + 1 < len(args):
            plugins_dir_arg = Path(args[i + 1]).expanduser()

    if plugins_dir_arg is not None:
        plugins_dir = plugins_dir_arg
    else:
        try:
            plugins_dir = get_plugins_dir()
        except RuntimeError as e:
            print(f"ERROR: {e}", file=sys.stderr)
            sys.exit(1)

    if DRY_RUN:
        print("=== DRY RUN ===\n")

    plugins = sorted(p for p in plugins_dir.iterdir() if p.is_dir() and (p / ".git").exists())

    php_corrections = 0
    main_updates = 0
    errors = []

    for plugin_dir in plugins:
        plugin = plugin_dir.name
        cwd = str(plugin_dir)
        orig = current_branch(cwd)

        plugin_changed = False

        # ── 1. PHP over-correction ────────────────────────────────────────────
        for branch, (wrong, correct) in OVER_CORRECTIONS.items():
            if not branch_exists(branch, cwd):
                continue
            if not checkout(branch, cwd):
                continue
            composer_path = plugin_dir / "composer.json"
            if not composer_path.exists():
                continue
            try:
                data = json.loads(composer_path.read_text())
            except json.JSONDecodeError:
                continue
            php_val = data.get("require", {}).get("php", "")
            if php_val == wrong:
                data["require"]["php"] = correct
                print(f"  {plugin} / {branch}: php {wrong!r} → {correct!r}")
                if not DRY_RUN:
                    with open(composer_path, "w") as f:
                        json.dump(data, f, indent="\t", ensure_ascii=False)
                        f.write("\n")
                    run(["git", "add", "composer.json"], cwd)
                    r = run(["git", "commit", "-m",
                             f"fix({branch.replace('migrate/', '').replace('.', '')}): "
                             f"correct php constraint from {wrong} to {correct}"], cwd)
                    if r.returncode == 0:
                        run(["git", "pull", "--rebase", "origin", branch], cwd)
                        run(["git", "push", "origin", branch], cwd)
                        php_corrections += 1
                        plugin_changed = True
                    else:
                        # unstage if commit failed
                        run(["git", "reset", "HEAD", "composer.json"], cwd)
                        errors.append(f"{plugin}/{branch}: commit failed")

        # ── 2. main → 7.x fast-forward ────────────────────────────────────────
        main_branch = None
        for mb in ("main", "master"):
            if branch_exists(mb, cwd):
                main_branch = mb
                break

        if main_branch and branch_exists("migrate/elgg-7.x", cwd):
            # Check if 7.x is already an ancestor of main
            r = run(["git", "merge-base", "--is-ancestor", "migrate/elgg-7.x", main_branch], cwd)
            if r.returncode != 0:
                # 7.x is NOT in main — merge it
                if not checkout(main_branch, cwd):
                    errors.append(f"{plugin}/{main_branch}: checkout failed")
                else:
                    print(f"  {plugin}: merging migrate/elgg-7.x into {main_branch}")
                    if not DRY_RUN:
                        r = run(["git", "merge", "--no-edit", "migrate/elgg-7.x"], cwd)
                        if r.returncode == 0:
                            run(["git", "pull", "--rebase", "origin", main_branch], cwd)
                            run(["git", "push", "origin", main_branch], cwd)
                            main_updates += 1
                        else:
                            run(["git", "merge", "--abort"], cwd)
                            errors.append(f"{plugin}/{main_branch}: merge failed (conflicts?)")

        # Restore original branch
        checkout(orig, cwd)

    print(f"\nDone. PHP corrections: {php_corrections}, main updates: {main_updates}")
    if errors:
        print(f"Errors ({len(errors)}):")
        for e in errors:
            print(f"  {e}")
        sys.exit(1)


if __name__ == "__main__":
    main()
