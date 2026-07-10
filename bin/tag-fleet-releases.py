#!/usr/bin/env python3
"""
tag-fleet-releases.py — Tag migrate/elgg-N.x branch tips with N.0.0 and
populate compatibility tables in README.md on main.

For each plugin directory (skipping _legacy and notifications_mass_mail_7x_tmp):

  Step A: Tag each migrate/elgg-N.x branch tip with N.0.0
    - Skip if any tag starting with "N." already points to the branch tip
    - Skip with warning if N.0.0 tag exists at a *different* commit
    - Otherwise: git tag N.0.0 on branch tip and git push origin N.0.0

  Step B: Update README.md on main with a full compatibility table
    - Deduplicates multiple ## Compatibility sections
    - Resolves leftover conflict markers (takes the 7.x / "theirs" block)
    - Only includes rows for branches that actually exist locally
    - Commits and pushes if changed

  Step C: Ensure composer.json on main has no "version" field
    - Removes the field if present (it was removed fleet-wide by fix(ci))
    - Commits and pushes if changed
"""

import os
import sys
import re
import json
import subprocess
import shutil
from pathlib import Path


def _resolve_plugins_dir():
    """Resolve plugin workspace: ELGG_PLUGINS_DIR env > discover-plugins.sh > error."""
    d = os.environ.get('ELGG_MIGRATE_PLUGINS') or os.environ.get('ELGG_PLUGINS_DIR') or os.environ.get('PLUGINS_DIR')
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


# PLUGINS_DIR is resolved lazily in main() to avoid import-time errors
PLUGINS_DIR = Path(".")

SKIP_DIRS = {"_legacy", "notifications_mass_mail_7x_tmp"}

ELGG_VERSIONS = [3, 4, 5, 6, 7]

COMPAT_TABLE_HEADER = "## Compatibility\n\n| Plugin version | Elgg version |\n|---|---|\n"


def run(cmd, cwd, check=True, capture=True):
    """Run a shell command, return (stdout, returncode)."""
    result = subprocess.run(
        cmd,
        cwd=cwd,
        capture_output=capture,
        text=True,
    )
    if check and result.returncode != 0:
        raise RuntimeError(
            f"Command {cmd!r} failed (rc={result.returncode}):\n"
            f"  stdout: {result.stdout.strip()}\n"
            f"  stderr: {result.stderr.strip()}"
        )
    return result.stdout.strip(), result.returncode


def get_local_branches(plugin_dir):
    """Return list of local branch names."""
    out, _ = run(["git", "branch", "--format=%(refname:short)"], plugin_dir)
    return [b.strip() for b in out.splitlines() if b.strip()]


def get_current_branch(plugin_dir):
    out, _ = run(["git", "branch", "--show-current"], plugin_dir)
    return out.strip()


def checkout_branch(plugin_dir, branch):
    run(["git", "checkout", branch], plugin_dir)


def get_branch_tip(plugin_dir, branch):
    out, rc = run(["git", "rev-parse", branch], plugin_dir, check=False)
    if rc != 0:
        return None
    return out.strip()


def get_tags_at_commit(plugin_dir, commit):
    """Return list of tags pointing to this commit."""
    out, _ = run(["git", "tag", "--contains", commit], plugin_dir)
    return [t.strip() for t in out.splitlines() if t.strip()]


def tag_exists(plugin_dir, tag):
    out, _ = run(["git", "tag", "--list", tag], plugin_dir)
    return bool(out.strip())


def push_tag(plugin_dir, tag):
    run(["git", "push", "origin", tag], plugin_dir)


def create_tag(plugin_dir, tag, commit):
    run(["git", "tag", tag, commit], plugin_dir)


# ─── Step A ──────────────────────────────────────────────────────────────────

def step_a_tag_branches(plugin_dir, plugin_name, results):
    """Tag each migrate/elgg-N.x tip with N.0.0 if not already tagged."""
    branches = get_local_branches(plugin_dir)

    for n in ELGG_VERSIONS:
        branch = f"migrate/elgg-{n}.x"
        if branch not in branches:
            results["skipped_no_branch"].append((plugin_name, branch))
            continue

        tip = get_branch_tip(plugin_dir, branch)
        if not tip:
            results["warnings"].append(f"{plugin_name}: could not get tip of {branch}")
            continue

        # Check if any tag starting with "N." is already at the tip
        tags_at_tip = get_tags_at_commit(plugin_dir, tip)
        major_tags_at_tip = [t for t in tags_at_tip if t.startswith(f"{n}.")]
        if major_tags_at_tip:
            results["already_tagged"].append((plugin_name, branch, major_tags_at_tip[0]))
            continue

        # Check if N.0.0 exists at a different commit
        target_tag = f"{n}.0.0"
        if tag_exists(plugin_dir, target_tag):
            results["warnings"].append(
                f"{plugin_name}: {target_tag} exists but not at {branch} tip — skipping"
            )
            continue

        # Create and push tag
        try:
            create_tag(plugin_dir, target_tag, tip)
            push_tag(plugin_dir, target_tag)
            results["tagged"].append((plugin_name, branch, target_tag))
            print(f"  [TAG] {plugin_name}: {target_tag} → {branch} tip {tip[:8]}")
        except RuntimeError as e:
            results["errors"].append(f"{plugin_name}: failed to tag {target_tag}: {e}")


# ─── README helpers ──────────────────────────────────────────────────────────

def resolve_conflict_markers(text):
    """
    Remove git conflict markers from text, preferring the 'theirs' (>>>>>) block.
    Handles both <<<<<<< HEAD / ======= / >>>>>>> ... and stash variants.
    """
    # Pattern: <<<<<<< ... \n (ours) \n ======= \n (theirs) \n >>>>>>> ...
    pattern = re.compile(
        r'<<<<<<[^\n]*\n(.*?)=======[^\n]*\n(.*?)>>>>>>>[^\n]*\n',
        re.DOTALL
    )

    def pick_theirs(m):
        return m.group(2)  # take the "theirs" (7.x / migrate) block

    return pattern.sub(pick_theirs, text)


def build_compat_table(existing_branches, plugin_dir):
    """
    Build a compatibility table for branches that exist locally.
    Returns string with header + rows.
    """
    rows = []
    for n in sorted(ELGG_VERSIONS, reverse=True):
        branch = f"migrate/elgg-{n}.x"
        if branch in existing_branches:
            rows.append(f"| {n}.0.0   | {n}.x  |")

    if not rows:
        return ""

    return COMPAT_TABLE_HEADER + "\n".join(rows) + "\n"


def deduplicate_compat_tables(text, table):
    """
    Replace all occurrences of ## Compatibility sections with a single canonical one.
    """
    # Remove all existing ## Compatibility sections (and their content up to next ##)
    # A section starts with "## Compatibility" and ends before next "## " or end of string
    cleaned = re.sub(
        r'## Compatibility\s*\n(?:\|[^\n]*\n)*(?:\|[^\n]*\n?)*',
        '',
        text,
        flags=re.MULTILINE
    )
    # Also remove stray table rows not preceded by a header
    cleaned = re.sub(r'(?m)^\|---\|---\|\n', '', cleaned)

    # Strip trailing whitespace from cleaned text
    cleaned = cleaned.rstrip() + "\n"

    # Append the canonical table
    cleaned += "\n" + table

    return cleaned


def step_b_update_readme(plugin_dir, plugin_name, results):
    """Update README.md on main with canonical compatibility table."""
    readme_path = plugin_dir / "README.md"
    if not readme_path.exists():
        results["warnings"].append(f"{plugin_name}: no README.md found")
        return False

    branches = get_local_branches(plugin_dir)
    migrate_branches = [b for b in branches if b.startswith("migrate/elgg-")]

    if not migrate_branches:
        results["warnings"].append(f"{plugin_name}: no migrate branches found")
        return False

    text = readme_path.read_text(encoding="utf-8")
    original = text

    # Step 1: resolve any conflict markers
    text = resolve_conflict_markers(text)

    # Step 2: build canonical table
    table = build_compat_table(migrate_branches, plugin_dir)
    if not table:
        return False

    # Step 3: replace all compat sections with one canonical one
    compat_count = text.count("## Compatibility")
    if compat_count == 0:
        # Append table before last section (or at end)
        text = text.rstrip() + "\n\n" + table
    else:
        text = deduplicate_compat_tables(text, table)

    if text == original:
        return False

    readme_path.write_text(text, encoding="utf-8")
    return True


# ─── Step C ──────────────────────────────────────────────────────────────────

def step_c_fix_composer(plugin_dir, plugin_name, results):
    """Remove version field from composer.json if present."""
    composer_path = plugin_dir / "composer.json"
    if not composer_path.exists():
        return False

    try:
        with open(composer_path) as f:
            data = json.load(f)
    except json.JSONDecodeError as e:
        results["warnings"].append(f"{plugin_name}: composer.json parse error: {e}")
        return False

    if "version" not in data:
        return False

    version = data.pop("version")
    print(f"  [COMPOSER] {plugin_name}: removing version '{version}'")
    with open(composer_path, "w") as f:
        json.dump(data, f, indent=4)
        f.write("\n")

    return True


# ─── Main per-plugin logic ────────────────────────────────────────────────────

def process_plugin(plugin_dir, plugin_name, results):
    print(f"\n{'='*60}")
    print(f"Processing: {plugin_name}")
    print(f"{'='*60}")

    # ── Step A: Tag branches ──────────────────────────────────────
    try:
        step_a_tag_branches(plugin_dir, plugin_name, results)
    except Exception as e:
        results["errors"].append(f"{plugin_name} Step A: {e}")
        print(f"  [ERROR] Step A: {e}")

    # ── Switch to main ────────────────────────────────────────────
    try:
        branches = get_local_branches(plugin_dir)
        if "main" not in branches:
            results["warnings"].append(f"{plugin_name}: no 'main' branch")
            return
        checkout_branch(plugin_dir, "main")
    except Exception as e:
        results["errors"].append(f"{plugin_name}: failed to checkout main: {e}")
        return

    # ── Step B: README ────────────────────────────────────────────
    readme_changed = False
    try:
        readme_changed = step_b_update_readme(plugin_dir, plugin_name, results)
    except Exception as e:
        results["errors"].append(f"{plugin_name} Step B: {e}")
        print(f"  [ERROR] Step B: {e}")

    # ── Step C: composer.json ─────────────────────────────────────
    composer_changed = False
    try:
        composer_changed = step_c_fix_composer(plugin_dir, plugin_name, results)
    except Exception as e:
        results["errors"].append(f"{plugin_name} Step C: {e}")
        print(f"  [ERROR] Step C: {e}")

    # ── Commit and push if changed ────────────────────────────────
    if readme_changed or composer_changed:
        files = []
        if readme_changed:
            files.append("README.md")
        if composer_changed:
            files.append("composer.json")

        try:
            for f in files:
                run(["git", "add", f], plugin_dir)

            msg_parts = []
            if readme_changed:
                msg_parts.append("populate compatibility table")
            if composer_changed:
                msg_parts.append("remove version field")
            msg = "docs: " + ", ".join(msg_parts)

            run(["git", "commit", "-m", msg], plugin_dir)
            run(["git", "push", "origin", "main"], plugin_dir)
            results["committed"].append((plugin_name, msg))
            print(f"  [COMMIT] {plugin_name}: {msg}")
        except RuntimeError as e:
            results["errors"].append(f"{plugin_name}: commit/push failed: {e}")
            print(f"  [ERROR] commit/push: {e}")
    else:
        print(f"  [OK] {plugin_name}: no changes needed on main")


# ─── Entry point ─────────────────────────────────────────────────────────────

def main():
    global PLUGINS_DIR

    # Parse optional --plugins-dir flag
    args = sys.argv[1:]
    plugins_dir_arg = None
    for i, a in enumerate(args):
        if a == "--plugins-dir" and i + 1 < len(args):
            plugins_dir_arg = Path(args[i + 1]).expanduser()

    if plugins_dir_arg is not None:
        PLUGINS_DIR = plugins_dir_arg
    else:
        try:
            PLUGINS_DIR = _resolve_plugins_dir()
        except RuntimeError as e:
            print(f"ERROR: {e}", file=sys.stderr)
            sys.exit(1)

    if not PLUGINS_DIR.exists():
        print(f"ERROR: PLUGINS_DIR does not exist: {PLUGINS_DIR}", file=sys.stderr)
        sys.exit(1)

    results = {
        "tagged": [],
        "already_tagged": [],
        "skipped_no_branch": [],
        "committed": [],
        "warnings": [],
        "errors": [],
    }

    plugins = sorted([
        d for d in PLUGINS_DIR.iterdir()
        if d.is_dir()
        and (d / ".git").exists()
        and d.name not in SKIP_DIRS
    ])

    print(f"Found {len(plugins)} plugin directories in {PLUGINS_DIR}")

    for plugin_dir in plugins:
        try:
            process_plugin(plugin_dir, plugin_dir.name, results)
        except Exception as e:
            results["errors"].append(f"{plugin_dir.name}: unhandled error: {e}")
            print(f"  [ERROR] unhandled: {e}")

    # ── Summary ───────────────────────────────────────────────────
    print(f"\n{'='*60}")
    print("SUMMARY")
    print(f"{'='*60}")

    print(f"\nNew tags created ({len(results['tagged'])}):")
    for plugin, branch, tag in results["tagged"]:
        print(f"  {plugin}: {tag} → {branch}")

    print(f"\nAlready tagged ({len(results['already_tagged'])}):")
    for plugin, branch, tag in results["already_tagged"]:
        print(f"  {plugin}: {branch} already has {tag}")

    print(f"\nSkipped (no branch) ({len(results['skipped_no_branch'])}):")
    for plugin, branch in results["skipped_no_branch"]:
        print(f"  {plugin}: {branch} does not exist")

    print(f"\nCommits pushed ({len(results['committed'])}):")
    for plugin, msg in results["committed"]:
        print(f"  {plugin}: {msg}")

    if results["warnings"]:
        print(f"\nWarnings ({len(results['warnings'])}):")
        for w in results["warnings"]:
            print(f"  WARNING: {w}")

    if results["errors"]:
        print(f"\nErrors ({len(results['errors'])}):")
        for e in results["errors"]:
            print(f"  ERROR: {e}")
        sys.exit(1)
    else:
        print("\nDone — no errors.")


if __name__ == "__main__":
    main()
